<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionWebsiteJob;
use App\Models\Website;
use App\Services\System\PhpSettings;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-site PHP settings manager.
 *
 * Lets operators raise the upload size limit, memory, execution time, etc. for
 * a single website without editing the global php.ini. Settings are stored on
 * the website and injected into its dedicated PHP-FPM pool (and drive nginx's
 * client_max_body_size) the next time it is provisioned — which we trigger on
 * the queue worker so the panel's own PHP-FPM is never reloaded inline.
 */
class PhpSettingController extends Controller
{
    public function edit(Website $website)
    {
        $this->authorize('update', $website);

        if (! $this->supportsPhp($website)) {
            return redirect()
                ->route('websites.show', $website)
                ->with('error', 'PHP settings only apply to PHP and WordPress sites.');
        }

        return view('php-settings.edit', [
            'website'     => $website,
            'definitions' => PhpSettings::definitions(),
            'values'      => $website->phpSettings(),
        ]);
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        if (! $this->supportsPhp($website)) {
            return redirect()
                ->route('websites.show', $website)
                ->with('error', 'PHP settings only apply to PHP and WordPress sites.');
        }

        $definitions = PhpSettings::definitions();

        // Validate each managed field against its declared type + bounds. Errors
        // are reported per-field; nothing is saved unless every value is valid.
        $errors = [];
        $raw = [];
        foreach ($definitions as $key => $def) {
            $value = trim((string) $request->input($key, ''));
            if ($value === '') {
                $errors[$key] = "{$def['label']} is required.";
                continue;
            }
            if (! PhpSettings::isValidValue((string) ($def['type'] ?? 'size'), $value, $def)) {
                $errors[$key] = "{$def['label']} is not a valid value.";
                continue;
            }
            $raw[$key] = $value;
        }

        // post_max_size should be >= upload_max_filesize or large uploads fail.
        if (! isset($errors['post_max_size'], $errors['upload_max_filesize'])
            && isset($raw['post_max_size'], $raw['upload_max_filesize'])
            && PhpSettings::toBytes($raw['post_max_size']) < PhpSettings::toBytes($raw['upload_max_filesize'])) {
            $errors['post_max_size'] = 'Maximum POST size must be at least the maximum upload file size.';
        }

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        $clean = PhpSettings::sanitize($raw);

        $meta = (array) $website->meta;
        $meta['php_settings'] = $clean;
        $website->update(['meta' => $meta]);

        // Apply on the queue worker: rewrites the per-user PHP-FPM pool and
        // redeploys the nginx config (client_max_body_size). Running it here
        // would reload the php-fpm that serves the panel and 502 it.
        ProvisionWebsiteJob::dispatch($website->id);

        Audit::log('website.php_settings_updated', 'website', (string) $website->id, [
            'domain' => $website->domain, 'settings' => array_keys($clean),
        ]);

        return redirect()
            ->route('php-settings.edit', $website)
            ->with('success', 'PHP settings saved and queued to apply.');
    }

    protected function supportsPhp(Website $website): bool
    {
        return in_array($website->type, ['php', 'wordpress'], true);
    }
}
