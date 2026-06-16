<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'system_username',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Check whether the user has a given permission through any of their roles.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->contains('name', $permission);
    }

    public function permissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return Permission::query()->pluck('name')->all();
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
