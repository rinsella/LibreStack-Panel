<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

/**
 * Ownership-based authorization for websites (and, by extension, the file
 * manager and SSL actions which operate on a website's resources).
 *
 *  - super_admin / admin : manage every website.
 *  - auditor             : read-only.
 *  - reseller/site_owner : may only manage websites they own (user_id match).
 */
class WebsitePolicy
{
    /**
     * Admins bypass every check; auditors are read-only.
     */
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

    public function view(User $user, Website $website): bool
    {
        return $this->owns($user, $website);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Website $website): bool
    {
        return $this->owns($user, $website);
    }

    public function delete(User $user, Website $website): bool
    {
        return $this->owns($user, $website);
    }

    /**
     * A user owns a website only when it is explicitly assigned to them.
     */
    protected function owns(User $user, Website $website): bool
    {
        return $website->user_id !== null && (int) $website->user_id === (int) $user->id;
    }
}
