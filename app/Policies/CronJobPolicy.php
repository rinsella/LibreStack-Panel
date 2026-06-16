<?php

namespace App\Policies;

use App\Models\CronJob;
use App\Models\User;

/**
 * Ownership-based authorization for cron jobs.
 */
class CronJobPolicy
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

    public function view(User $user, CronJob $cron): bool
    {
        return $this->owns($user, $cron);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CronJob $cron): bool
    {
        return $this->owns($user, $cron);
    }

    public function delete(User $user, CronJob $cron): bool
    {
        return $this->owns($user, $cron);
    }

    protected function owns(User $user, CronJob $cron): bool
    {
        return $cron->user_id !== null && (int) $cron->user_id === (int) $user->id;
    }
}
