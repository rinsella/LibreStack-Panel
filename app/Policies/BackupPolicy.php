<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

/**
 * Ownership-based authorization for backups. Ownership is derived from the
 * backup's creator or the owning website's assignment.
 */
class BackupPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAuditor()) {
            return in_array($ability, ['viewAny', 'view'], true) ? true : false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Backup $backup): bool
    {
        return $this->owns($user, $backup);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Backup $backup): bool
    {
        return $this->owns($user, $backup);
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $this->owns($user, $backup);
    }

    protected function owns(User $user, Backup $backup): bool
    {
        if ($backup->created_by !== null && (int) $backup->created_by === (int) $user->id) {
            return true;
        }

        return $backup->website !== null
            && $backup->website->user_id !== null
            && (int) $backup->website->user_id === (int) $user->id;
    }
}
