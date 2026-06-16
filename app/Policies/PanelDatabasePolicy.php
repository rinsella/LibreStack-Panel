<?php

namespace App\Policies;

use App\Models\PanelDatabase;
use App\Models\User;

/**
 * Ownership-based authorization for panel databases.
 */
class PanelDatabasePolicy
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

    public function view(User $user, PanelDatabase $database): bool
    {
        return $this->owns($user, $database);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PanelDatabase $database): bool
    {
        return $this->owns($user, $database);
    }

    public function delete(User $user, PanelDatabase $database): bool
    {
        return $this->owns($user, $database);
    }

    protected function owns(User $user, PanelDatabase $database): bool
    {
        if ($database->user_id !== null && (int) $database->user_id === (int) $user->id) {
            return true;
        }

        // Fall back to the owning website's assignment.
        return $database->website !== null
            && $database->website->user_id !== null
            && (int) $database->website->user_id === (int) $user->id;
    }
}
