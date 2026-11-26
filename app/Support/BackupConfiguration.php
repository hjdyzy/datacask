<?php

namespace App\Support;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Models\Backup;

/**
 * Normalization and persistence shape of one backup configuration entry,
 * shared by the database server form and the API.
 */
class BackupConfiguration
{
    /**
     * Whether a database name is a built-in system database.
     */
    public static function isSystemDatabaseName(string $name): bool
    {
        return in_array($name, [
            'information_schema', 'performance_schema', 'mysql', 'sys',
            'admin', 'local', 'config',
            'master', 'tempdb', 'model', 'msdb',
            'rdsadmin', 'azure_maintenance', 'azure_sys',
        ], true);
    }

    /**
     * Remove built-in system database names from an exclusion list.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public static function filterSystemDatabaseNames(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => ! self::isSystemDatabaseName($name),
        ));
    }

    /**
     * Convert one validated entry into the array shape accepted by
     * `Backup::create()` / `Backup::update()`.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function toPersistedData(array $entry): array
    {
        $retentionPolicy = $entry['retention_policy'] ?? Backup::RETENTION_DAYS;

        $data = [
            'path' => ! empty($entry['path']) ? $entry['path'] : null,
            'backup_schedule_id' => $entry['backup_schedule_id'] ?? '',
            'retention_policy' => $retentionPolicy,
            'retention_days' => null,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
            'database_selection_mode' => $entry['database_selection_mode'] ?? DatabaseSelectionMode::All->value,
            'database_names' => $entry['database_names'] ?? null,
            'database_include_pattern' => ! empty($entry['database_include_pattern']) ? $entry['database_include_pattern'] : null,
            // The API sends the list without the form's toggle, so a missing toggle means enabled.
            'excluded_tables' => ($entry['exclude_tables'] ?? true)
                ? (Backup::parseExcludedTables($entry['excluded_tables'] ?? null) ?: null)
                : null,
        ];

        if ($retentionPolicy === Backup::RETENTION_DAYS) {
            $data['retention_days'] = $entry['retention_days'] ?? null;
        } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
            $data['gfs_keep_daily'] = ! empty($entry['gfs_keep_daily']) ? (int) $entry['gfs_keep_daily'] : null;
            $data['gfs_keep_weekly'] = ! empty($entry['gfs_keep_weekly']) ? (int) $entry['gfs_keep_weekly'] : null;
            $data['gfs_keep_monthly'] = ! empty($entry['gfs_keep_monthly']) ? (int) $entry['gfs_keep_monthly'] : null;
        }

        return $data;
    }

    /**
     * Normalize selection-mode related fields based on the parent server's
     * database type.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function normalizeSelection(array &$entry, DatabaseType $serverType): void
    {
        if (! $serverType->supportsExcludedTables()) {
            $entry['excluded_tables'] = null;
        }

        if ($serverType === DatabaseType::REDIS) {
            $entry['database_selection_mode'] = DatabaseSelectionMode::All->value;
            $entry['database_names'] = null;
            $entry['database_include_pattern'] = null;

            return;
        }

        // Path-based types (SQLite, Firebird) keep their file paths in
        // `database_names` and always run in Selected mode — there's no
        // server-side enumeration to support All / Pattern.
        if ($serverType->identifiesDatabasesByPath()) {
            $entry['database_selection_mode'] = DatabaseSelectionMode::Selected->value;
            $entry['database_include_pattern'] = null;
            $paths = $entry['database_names'] ?? [];
            $entry['database_names'] = array_values(array_filter(
                array_map('trim', is_array($paths) ? $paths : []),
            ));

            return;
        }

        $mode = $entry['database_selection_mode'] ?? null;

        if ($mode === DatabaseSelectionMode::Excluded->value) {
            $entry['database_names'] = self::filterSystemDatabaseNames((array) ($entry['database_names'] ?? []));
        } elseif ($mode !== DatabaseSelectionMode::Selected->value) {
            $entry['database_names'] = null;
        }

        if ($mode !== DatabaseSelectionMode::Pattern->value) {
            $entry['database_include_pattern'] = null;
        }
    }
}
