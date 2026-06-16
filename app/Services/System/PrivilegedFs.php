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

    // ---------------------------------------------------------------------
    // File manager operations
    // ---------------------------------------------------------------------
    //
    // Unlike the provisioning operations above, file-manager writes must work
    // in EVERY mode: on a dev box the runtime user owns the website files (run
    // in-process), and on a real VPS the files are owned by the site user so
    // the work is performed as root through the sudo helper.

    public function fileWrite(string $username, string $domain, string $relative, string $content): CommandResult
    {
        return $this->dispatchFs(
            ['file-write', $username, $domain, $relative, base64_encode($content)],
            fn () => $this->ops->fileWrite($username, $domain, $relative, $content),
        );
    }

    public function fileUpload(string $username, string $domain, string $relative, string $sourceTmp): CommandResult
    {
        return $this->dispatchFs(
            ['file-upload', $username, $domain, $relative, $sourceTmp],
            fn () => $this->ops->fileUpload($username, $domain, $relative, $sourceTmp),
        );
    }

    public function fileCreate(string $username, string $domain, string $relative): CommandResult
    {
        return $this->dispatchFs(
            ['file-create', $username, $domain, $relative],
            fn () => $this->ops->fileCreate($username, $domain, $relative),
        );
    }

    public function dirCreate(string $username, string $domain, string $relative): CommandResult
    {
        return $this->dispatchFs(
            ['dir-create', $username, $domain, $relative],
            fn () => $this->ops->dirCreate($username, $domain, $relative),
        );
    }

    public function fileRename(string $username, string $domain, string $from, string $to): CommandResult
    {
        return $this->dispatchFs(
            ['file-rename', $username, $domain, $from, $to],
            fn () => $this->ops->fileRename($username, $domain, $from, $to),
        );
    }

    public function fileMove(string $username, string $domain, string $from, string $to): CommandResult
    {
        return $this->dispatchFs(
            ['file-move', $username, $domain, $from, $to],
            fn () => $this->ops->fileMove($username, $domain, $from, $to),
        );
    }

    public function fileDelete(string $username, string $domain, string $relative): CommandResult
    {
        return $this->dispatchFs(
            ['file-delete', $username, $domain, $relative],
            fn () => $this->ops->fileDelete($username, $domain, $relative),
        );
    }

    public function fileCopy(string $username, string $domain, string $from, string $to): CommandResult
    {
        return $this->dispatchFs(
            ['file-copy', $username, $domain, $from, $to],
            fn () => $this->ops->fileCopy($username, $domain, $from, $to),
        );
    }

    public function fileChmod(string $username, string $domain, string $relative, string $octalMode): CommandResult
    {
        return $this->dispatchFs(
            ['file-chmod', $username, $domain, $relative, $octalMode],
            fn () => $this->ops->fileChmod($username, $domain, $relative, (int) octdec($octalMode)),
        );
    }

    public function fileZip(string $username, string $domain, string $sourceRel, string $zipRel): CommandResult
    {
        return $this->dispatchFs(
            ['file-zip', $username, $domain, $sourceRel, $zipRel],
            fn () => $this->ops->fileZip($username, $domain, $sourceRel, $zipRel),
        );
    }

    public function fileUnzip(string $username, string $domain, string $zipRel, string $destRel): CommandResult
    {
        return $this->dispatchFs(
            ['file-unzip', $username, $domain, $zipRel, $destRel],
            fn () => $this->ops->fileUnzip($username, $domain, $zipRel, $destRel),
        );
    }

    // ---------------------------------------------------------------------
    // PHP-FPM per-user pools (privileged; gated on system mode)
    // ---------------------------------------------------------------------

    public function phpFpmPoolWrite(string $username, string $phpVersion, string $poolConfig): CommandResult
    {
        return $this->dispatch(
            ['php-fpm-pool-write', $username, $phpVersion, base64_encode($poolConfig)],
            fn () => $this->ops->phpFpmPoolWrite($username, $phpVersion, $poolConfig),
        );
    }

    public function phpFpmPoolDelete(string $username, string $phpVersion): CommandResult
    {
        return $this->dispatch(
            ['php-fpm-pool-delete', $username, $phpVersion],
            fn () => $this->ops->phpFpmPoolDelete($username, $phpVersion),
        );
    }

    public function phpFpmTest(string $phpVersion): CommandResult
    {
        return $this->dispatch(
            ['php-fpm-test', $phpVersion],
            fn () => $this->ops->phpFpmTest($phpVersion),
        );
    }

    public function phpFpmReload(string $phpVersion): CommandResult
    {
        return $this->dispatch(
            ['php-fpm-reload', $phpVersion],
            fn () => $this->ops->phpFpmReload($phpVersion),
        );
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
     * Dispatch a file-manager operation. These always perform the work (they are
     * not gated on system_enabled): in-process when not using sudo, or through
     * the root helper when use_sudo is on.
     *
     * @param  array<int, string>  $opArgs
     * @param  callable():void  $inProcess
     */
    protected function dispatchFs(array $opArgs, callable $inProcess): CommandResult
    {
        if (config('librestack.use_sudo')) {
            return $this->runViaSudo($opArgs);
        }

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
