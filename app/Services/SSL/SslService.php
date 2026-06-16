<?php

namespace App\Services\SSL;

use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Issues and manages Let's Encrypt certificates with certbot.
 *
 * Certificates are obtained with `certbot certonly --webroot`, which only writes
 * the cert to /etc/letsencrypt and NEVER edits nginx. The panel then regenerates
 * the site's vhost itself (NginxService emits the :443 server block), so a later
 * redeploy (e.g. saving PHP settings) can never clobber the HTTPS config — which
 * is exactly what happened with the old `--nginx` installer flow.
 *
 * Domains and email are strictly validated before any command is built.
 */
class SslService
{
    public function __construct(
        protected CommandRunner $runner,
        protected NginxService $nginx,
    ) {
    }

    public function issue(Website $website, string $email): CommandResult
    {
        if (! Validators::isValidDomain($website->domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }
        if (! Validators::isValidEmail($email)) {
            throw new InvalidArgumentException('Invalid email.');
        }

        // -d flags: primary domain (+ www when the alias is enabled). The webroot
        // is the live document root, where the current :80 vhost already serves
        // /.well-known/acme-challenge for the http-01 challenge.
        $domains = ['-d', $website->domain];
        if ($website->www_alias) {
            $domains[] = '-d';
            $domains[] = 'www.' . $website->domain;
        }

        $args = array_merge(
            ['certonly', '--webroot', '-w', $website->document_root],
            $domains,
            ['--non-interactive', '--agree-tos', '-m', $email, '--keep-until-expiring'],
        );

        $result = $this->runner->run('certbot', $args, 180);

        $this->record($website, $result->ok ? 'active' : 'failed', $email);

        // On success, regenerate the vhost so it gains the panel-owned HTTPS
        // server block (cert paths, body size, per-user FPM socket) and the
        // :80 → :443 redirect.
        if ($result->ok && ! $result->disabled) {
            $deploy = $this->nginx->deploy($website->fresh());
            if (! $deploy->ok && ! $deploy->disabled) {
                return $deploy;
            }
        }

        return $result;
    }

    public function renew(Website $website): CommandResult
    {
        if (! Validators::isValidDomain($website->domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $result = $this->runner->run('certbot', [
            'renew', '--cert-name', $website->domain, '--non-interactive',
        ], 180);

        if ($result->ok) {
            $this->record($website, 'active');
            // Reload nginx so the freshly renewed certificate is picked up.
            $this->nginx->reload();
        }

        return $result;
    }

    public function delete(Website $website): CommandResult
    {
        if (! Validators::isValidDomain($website->domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $result = $this->runner->run('certbot', [
            'delete', '--cert-name', $website->domain, '--non-interactive',
        ], 60);

        SslCertificate::where('website_id', $website->id)->delete();
        $website->update(['ssl_enabled' => false, 'force_https' => false]);

        // Regenerate the vhost without the HTTPS block so the site keeps serving
        // over plain :80 instead of pointing at a now-missing certificate.
        $this->nginx->deploy($website->fresh());

        return $result;
    }

    protected function record(Website $website, string $status, ?string $email = null): void
    {
        SslCertificate::updateOrCreate(
            ['website_id' => $website->id, 'domain' => $website->domain],
            [
                'status'     => $status,
                'issuer'     => "Let's Encrypt",
                'issued_at'  => $status === 'active' ? now() : null,
                'expires_at' => $status === 'active' ? now()->addDays(90) : null,
                'meta'       => $email ? ['email' => $email] : null,
            ]
        );

        if ($status === 'active') {
            $website->update(['ssl_enabled' => true, 'force_https' => true]);
        }
    }
}
