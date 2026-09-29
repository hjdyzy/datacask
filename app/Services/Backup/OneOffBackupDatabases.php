<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Validation\ValidationException;

class OneOffBackupDatabases
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private DatabaseProvider $provider) {}

    /** @return list<string> */
    public function forServer(DatabaseServer $server): array
    {
        if ($server->agent_id !== null) {
            throw ValidationException::withMessages([
                'databases' => __('One-off database selection is not available for agent-backed servers.'),
            ]);
        }

        if ($server->database_type === DatabaseType::REDIS) {
            throw ValidationException::withMessages([
                'databases' => __('Redis / Valkey snapshots cover the whole instance. Use Backup now instead.'),
            ]);
        }

        if ($server->database_type->identifiesDatabasesByPath()) {
            return array_values(array_unique($server->resolveDatabaseNames()));
        }

        // Use a copy so the interactive timeout never changes saved connection settings.
        $connection = clone $server;
        $connection->extra_config = array_merge($server->extra_config ?? [], [
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        try {
            return array_values($this->provider->listDatabasesForServer($connection));
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'databases' => __('Cannot load databases. Check the connection and permissions, then refresh the list.'),
            ]);
        }
    }
}
