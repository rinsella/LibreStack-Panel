<?php

namespace App\Console\Commands;

use App\Services\System\SafeOps;
use Illuminate\Console\Command;
use Throwable;

/**
 * Root-callable privileged operation helper.
 *
 * This command is the ONLY privileged entry point for the panel. It is invoked
 * as root through `sudo /opt/librestack/scripts/librestack-safe-op …` (see the
 * scoped /etc/sudoers.d/librestack allowlist). Every subcommand is explicitly
 * allowlisted and delegates to SafeOps, which performs strict path/identifier
 * validation. Arbitrary shell is never executed.
 */
class SystemOperationCommand extends Command
{
    protected $signature = 'librestack:safe-op
        {operation : The privileged operation to perform}
        {args?* : Operation arguments}';

    protected $description = 'Perform an allowlisted privileged filesystem operation (root only).';

    public function handle(SafeOps $ops): int
    {
        $operation = (string) $this->argument('operation');
        /** @var array<int, string> $args */
        $args = (array) $this->argument('args');

        // Read operations print their payload (base64) and nothing else.
        if ($operation === 'read-nginx-config') {
            try {
                $this->output->writeln(base64_encode((string) app(SafeOps::class)->readNginxConfig($args[0] ?? '')));

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            match ($operation) {
                'write-nginx-config' => $ops->writeNginxConfig($args[0] ?? '', $this->b64($args[1] ?? '')),
                'enable-nginx-site'  => $ops->enableNginxSite($args[0] ?? ''),
                'disable-nginx-site' => $ops->disableNginxSite($args[0] ?? ''),
                'delete-nginx-site'  => $ops->deleteNginxSite($args[0] ?? ''),
                'create-site-dirs'   => $ops->createSiteDirs($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
                'write-site-index'   => $ops->writeSiteIndex($args[0] ?? '', $args[1] ?? '', $args[2] ?? '', $this->b64($args[3] ?? '')),
                'delete-site-base'   => $ops->deleteSiteBase($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
                'ensure-owner'       => $ops->ensureOwner($args[0] ?? '', $args[1] ?? ''),
                'set-web-permissions' => $ops->setWebPermissions($args[0] ?? '', $args[1] ?? ''),
                'purge-docroot'      => $ops->purgeDocroot($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
                'chmod-path'         => $ops->chmodPath($args[0] ?? '', (int) octdec($args[1] ?? '0')),
                'chown-path'         => $ops->chownPath($args[0] ?? '', $args[1] ?? '', $args[2] ?? ($args[1] ?? '')),
                'create-directory'   => $ops->createDirectory($args[0] ?? '', (int) octdec($args[1] ?? '0755'), $args[2] ?? null, $args[3] ?? null),
                'remove-file'        => $ops->removeFile($args[0] ?? ''),
                'copy-tree'          => $ops->copyTree($args[0] ?? '', $args[1] ?? ''),
                'extract-tar'        => $ops->extractTar($args[0] ?? '', $args[1] ?? ''),
                'create-tar'         => $ops->createTar($args[0] ?? '', array_slice($args, 1)),
                default              => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('ok');

        return self::SUCCESS;
    }

    protected function b64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64 argument.');
        }

        return $decoded;
    }
}
