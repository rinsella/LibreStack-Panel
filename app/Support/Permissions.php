<?php

namespace App\Support;

/**
 * Central registry of roles and permissions used across the panel.
 */
class Permissions
{
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_WEBSITES = 'manage_websites';
    public const MANAGE_DATABASES = 'manage_databases';
    public const MANAGE_SSL = 'manage_ssl';
    public const MANAGE_BACKUPS = 'manage_backups';
    public const MANAGE_SERVICES = 'manage_services';
    public const MANAGE_FIREWALL = 'manage_firewall';
    public const VIEW_LOGS = 'view_logs';
    public const VIEW_AUDIT_LOGS = 'view_audit_logs';
    public const MANAGE_SETTINGS = 'manage_settings';

    /**
     * @return array<string, string> permission name => label
     */
    public static function all(): array
    {
        return [
            self::MANAGE_USERS     => 'Manage users',
            self::MANAGE_WEBSITES  => 'Manage websites',
            self::MANAGE_DATABASES => 'Manage databases',
            self::MANAGE_SSL       => 'Manage SSL certificates',
            self::MANAGE_BACKUPS   => 'Manage backups',
            self::MANAGE_SERVICES  => 'Manage services',
            self::MANAGE_FIREWALL  => 'Manage firewall',
            self::VIEW_LOGS        => 'View logs',
            self::VIEW_AUDIT_LOGS  => 'View audit logs',
            self::MANAGE_SETTINGS  => 'Manage settings',
        ];
    }

    /**
     * @return array<string, array{label:string, description:string, permissions: array<int, string>}>
     */
    public static function roles(): array
    {
        $all = array_keys(self::all());

        return [
            'super_admin' => [
                'label' => 'Super Admin',
                'description' => 'Full, unrestricted access to everything.',
                'permissions' => $all,
            ],
            'admin' => [
                'label' => 'Administrator',
                'description' => 'Manage everything except global settings escalation.',
                'permissions' => array_values(array_diff($all, [])),
            ],
            'reseller' => [
                'label' => 'Reseller',
                'description' => 'Manage websites, databases, SSL and backups for clients.',
                'permissions' => [
                    self::MANAGE_WEBSITES,
                    self::MANAGE_DATABASES,
                    self::MANAGE_SSL,
                    self::MANAGE_BACKUPS,
                    self::VIEW_LOGS,
                ],
            ],
            'site_owner' => [
                'label' => 'Site Owner',
                'description' => 'Manage their own websites, databases and backups.',
                'permissions' => [
                    self::MANAGE_WEBSITES,
                    self::MANAGE_DATABASES,
                    self::MANAGE_BACKUPS,
                    self::VIEW_LOGS,
                ],
            ],
            'auditor' => [
                'label' => 'Auditor',
                'description' => 'Read-only access to logs and audit trails.',
                'permissions' => [
                    self::VIEW_LOGS,
                    self::VIEW_AUDIT_LOGS,
                ],
            ],
        ];
    }
}
