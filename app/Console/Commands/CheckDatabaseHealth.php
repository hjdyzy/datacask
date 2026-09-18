<?php

namespace App\Console\Commands;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckDatabaseHealth extends Command
{
    private const FAILURE_THRESHOLD = 2;

    private const RECOVERY_THRESHOLD = 2;

    private const BATCH_SIZE = 50;

    protected $signature = 'databases:check-health';

    protected $description = 'Check registered database connections and notify on outage or recovery';

    public function handle(DatabaseProvider $provider, NotificationService $notifications): int
    {
        DatabaseServer::query()->whereNull('agent_id')->chunkById(self::BATCH_SIZE, function ($servers) use ($provider, $notifications) {
            foreach ($servers as $server) {
                try {
                    $reachable = $provider->testConnectionForServer($server)['success'];
                } catch (\Throwable $exception) {
                    Log::warning('Database health probe failed', ['server_id' => $server->id, 'exception' => get_class($exception)]);
                    $reachable = false;
                }

                $transition = $this->recordResult($server, $reachable);
                if ($transition === 'offline') {
                    Log::warning('Database health changed', ['server_id' => $server->id, 'status' => 'offline']);
                    $notifications->notifyDatabaseOffline($server);
                } elseif ($transition === 'recovered') {
                    Log::info('Database health changed', ['server_id' => $server->id, 'status' => 'recovered']);
                    $notifications->notifyDatabaseRecovered($server);
                }
            }
        });

        return self::SUCCESS;
    }

    private function recordResult(DatabaseServer $server, bool $reachable): ?string
    {
        return DB::transaction(function () use ($server, $reachable) {
            $current = DatabaseServer::query()->lockForUpdate()->findOrFail($server->id);
            $failures = $reachable ? 0 : min($current->health_failure_count + 1, self::FAILURE_THRESHOLD);
            $successes = $reachable ? min($current->health_success_count + 1, self::RECOVERY_THRESHOLD) : 0;
            $nextState = match (true) {
                ! $reachable && $failures >= self::FAILURE_THRESHOLD => false,
                $reachable && ($current->health_is_online !== false || $successes >= self::RECOVERY_THRESHOLD) => true,
                default => $current->health_is_online,
            };
            $transition = null;

            if ($current->health_is_online === false && $nextState === true) {
                $transition = 'recovered';
            } elseif ($current->health_is_online !== false && $nextState === false) {
                $transition = 'offline';
            }

            $current->update([
                'health_is_online' => $nextState,
                'health_failure_count' => $failures,
                'health_success_count' => $successes,
                'health_checked_at' => now(),
            ]);

            return $transition;
        });
    }
}
