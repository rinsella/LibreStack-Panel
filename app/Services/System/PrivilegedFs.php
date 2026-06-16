<?php

namespace App\Services\System;

use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Facade used by application services to perform privileged filesystem
 * operations. It never touches root-owned paths directly from the web process.
 *
 * Dispatch strategy:
 *  - Dev / non-system mode  → no-op, returns a "disabled" CommandResult.
 *  - use_sudo = true        → runs `sudo -n <wrapper> <op> <args…>` so the work
 *                             happens in the root-owned librestack:safe-op
 *                             command (the wrapper is the only thing in sudoers).
 *  - use_sudo = false       → calls SafeOps in-process (only valid when the
 *                             worker itself already runs as root).
 *
 * Arguments that may contain arbitrary bytes (nginx config, index HTML) are
 * base64-encoded before being passed on the command line.
 */
class PrivilegedFs
{
    public function __construct(
        protected CommandRunner $runner,
        protected SafeOps $ops,
    ) {
    }

    public function writeNginxConfig(string $domain, string $config): CommandResult
    {
        return $this->dispatch(
            ['write-nginx-config', $domain, base64_encode($config)],
            fn () => $this->ops->writeNginxConfig($domain, $config),
        );
    }

    /**
     * Read the current nginx config for a domain (privileged). Returns null if
     * the file does not exist or cannot be read.
     */
    public function readNginxConfig(string $domain): ?string
    {
        if (! config('librestack.system_enabled')) {
            return null;
        }

        if (! config('librestack.use_sudo')) {
            try {
                return $this->ops->readNginxConfig($domain);
            } catch (Throwable) {
                return null;
            }
        }

        $script = (string) config('librestack.safe_op_script');
        $result = Process::timeout(30)->run(['sudo', '-n', $script, 'read-nginx-config', $domain]);
        if (! $result->successful()) {
            return null;
        }

        $decoded = base64_decode(trim($result->output()), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    public function enableNginxSite(string $domain): CommandResult
    {
        return $this->dispatch(['enable-nginx-site', $domain], fn () => $this->ops->enableNginxSite($domain));
    }

    public function disableNginxSite(string $domain): CommandResult
    {
        return $this->dispatch(['disable-nginx-site', $domain], fn () => $this->ops->disableNginxSite($domain));
    }

    public function deleteNginxSite(string $domain): CommandResult
    {
        return $this->dispatch(['delete-nginx-site', $domain], fn () => $this->ops->deleteNginxSite($domain));
    }

    public function createSiteDirs(string $username, string $domain, string $documentRoot): CommandResult
    {
        return $this->dispatch(
            ['create-site-dirs', $username, $domain, $documentRoot],
            fn () => $this->ops->createSiteDirs($username, $domain, $documentRoot),
        );
    }

    public function writeSiteIndex(string $username, string $domain, string $documentRoot, string $content): CommandResult
    {
        return $this->dispatch(
            ['write-site-index', $username, $domain, $documentRoot, base64_encode($content)],
            fn () => $this->ops->writeSiteIndex($username, $domain, $documentRoot, $content),
        );
    }

    public function deleteSiteBase(string $username, string $domain, string $basePath): CommandResult
    {
        return $this->dispatch(
            ['delete-site-base', $username, $domain, $basePath],
            fn () => $this->ops->deleteSiteBase($username, $domain, $basePath),
        );
    }

    public function ensureOwner(string $path, string $username): CommandResult
    {
        return $this->dispatch(['ensure-owner', $path, $username], fn () => $this->ops->ensureOwner($path, $username));
    }

    public function setWebPermissions(string $documentRoot, string $username): CommandResult
    {
        return $this->dispatch(
            ['set-web-permissions', $documentRoot, $username],
            fn () => $this->ops->setWebPermissions($documentRoot, $username),
        );
    }

    public function purgeDocroot(string $username, string $domain, string $documentRoot): CommandResult
    {
        return $this->dispatch(
            ['purge-docroot', $username, $domain, $documentRoot],
            fn () => $this->ops->purgeDocroot($username, $domain, $documentRoot),
        );
    }

    public function chmodPath(string $path, string $octalMode): CommandResult
    {
        return $this->dispatch(
            ['chmod-path', $path, $octalMode],
            fn () => $this->ops->chmodPath($path, (int) octdec($octalMode)),
        );
    }

    public function chownPath(string $path, string $username, ?string $group = null): CommandResult
    {
        $group ??= $username;

        return $this->dispatch(
            ['chown-path', $path, $username, $group],
            fn () => $this->ops->chownPath($path, $username, $group),
        );
    }

    public function createDirectory(string $path, string $octalMode = '0755', ?string $owner = null, ?string $group = null): CommandResult
    {
        $args = ['create-directory', $path, $octalMode];
        if ($owner !== null) {
            $args[] = $owner;
            $args[] = $group ?? $owner;
        }

        return $this->dispatch($args, fn () => $this->ops->createDirectory($path, (int) octdec($octalMode), $owner, $group));
    }

    public function removeFile(string $path): CommandResult
    {
        return $this->dispatch(['remove-file', $path], fn () => $this->ops->removeFile($path));
    }

    public function copyTree(string $source, string $destination): CommandResult
    {
        return $this->dispatch(['copy-tree', $source, $destination], fn () => $this->ops->copyTree($source, $destination));
    }

    public function extractTar(string $archive, string $destination): CommandResult
    {
        return $this->dispatch(['extract-tar', $archive, $destination], fn () => $this->ops->extractTar($archive, $destination));
    }

    /**
     * @param  array<int, string>  $tarArgs
     */
    public function createTar(string $archive, array $tarArgs): CommandResult
    {
        return $this->dispatch(
            array_merge(['create-tar', $archive], array_values($tarArgs)),
            fn () => $this->ops->createTar($archive, $tarArgs),
        );
    }

    public function isEnabled(): bool
    {
        return (bool) config('librestack.system_enabled');
    }

    /**
     * @param  array<int, string>  $opArgs
     * @param  callable():void  $inProcess
     */
    protected function dispatch(array $opArgs, callable $inProcess): CommandResult
    {
        if (! config('librestack.system_enabled')) {
            return CommandResult::disabled();
        }

        if (config('librestack.use_sudo')) {
            return $this->runViaSudo($opArgs);
        }

        // Already-root worker: run the operation directly and surface errors.
        try {
            $inProcess();

            return new CommandResult(true, 0, 'ok', '');
        } catch (Throwable $e) {
            return new CommandResult(false, 1, '', $e->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $opArgs
     */
    protected function runViaSudo(array $opArgs): CommandResult
    {
        $script = (string) config('librestack.safe_op_script');
        $command = array_merge(['sudo', '-n', $script], $opArgs);

        $result = Process::timeout(600)->run($command);

        return new CommandResult(
            ok: $result->successful(),
            exitCode: $result->exitCode() ?? -1,
            output: $result->output(),
            error: $result->errorOutput(),
        );
    }
}
