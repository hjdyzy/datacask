<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array SYSTEM_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
        'admin',
        'local',
        'config',
        'master',
        'tempdb',
        'model',
        'msdb',
        'rdsadmin',
        'azure_maintenance',
        'azure_sys',
    ];

    public function up(): void
    {
        DB::table('backups')
            ->where('database_selection_mode', 'excluded')
            ->orderBy('id')
            ->get()
            ->each(function (object $backup): void {
                $names = json_decode($backup->database_names ?? '[]', true);
                $names = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
                $filtered = array_values(array_filter(
                    $names,
                    fn (string $name): bool => ! in_array($name, self::SYSTEM_DATABASES, true),
                ));

                if ($filtered !== $names) {
                    DB::table('backups')->where('id', $backup->id)->update([
                        'database_names' => json_encode($filtered),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // System database names are not restored: excluded mode never backs them up.
    }
};
