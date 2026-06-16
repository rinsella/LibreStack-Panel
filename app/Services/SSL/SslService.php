<?php

namespace App\Services\SSL;

use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Issues and manages Let's Encrypt certificates via certbot's nginx plugin.
 * Domains and email are strictly validated before any command is built.
 */
class SslService
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    public function issue(Website $website, string $email): CommandResult
    {
        if (! Validators::isValidDomain($website->domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }
        if (! Validators::isValidEmail($email)) {
            throw new InvalidArgumentException('Invalid email.');
        }

        $args = [
            '--nginx',
            '-d', $website->domain,
            '--non-interactive',
            '--agree-tos',
            '-m', $email,
            '--redirect',
        ];

        if ($website->www_alias) {
            // Insert www domain right after the primary -d flag.
            array_splice($args, 3, 0, ['-d', 'www.' . $website->domain]);
        }

        $result = $this->runner->run('certbot', $args, 180);

        $this->record($website, $result->ok ? 'active' : 'failed', $email);

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
        $website->update(['ssl_enabled' => false]);

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
