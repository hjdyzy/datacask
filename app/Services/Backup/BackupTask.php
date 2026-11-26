<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\JobRevokedException;
use App\Exceptions\Backup\StorageQuotaExceededException;
use App\Exceptions\Backup\VolumeTransferException;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use App\Support\Formatters;
use Illuminate\Support\Str;

class BackupTask
{
    use UsesSshTunnel;

    public function __construct(
        private readonly DatabaseProvider $databaseProvider,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly CompressorFactory $compressorFactory,
        private readonly SshTunnelService $sshTunnelService,
        private readonly PostScriptRunner $postScriptRunner,
    ) {}

    protected function getSshTunnelService(): SshTunnelService
    {
        return $this->sshTunnelService;
    }

    /**
     * Execute the core backup workflow: dump, compress, transfer, checksum.
     *
     * This is the pure backup engine with no model persistence.
     * `ProcessBackupJob` delegates to this method.
     */
    public function execute(BackupConfig $config, BackupLogger $logger): BackupResult
    {
        $this->shellProcessor->setLogger($logger);
        $db = $config->database;
        $filename = null;

        try {
            if ($db->requiresSshTunnel()) {
                $this->establishSshTunnel($db, $logger);
            }

            $dumpFormat = is_string($value = ($db->extraConfig['dump_format'] ?? null)) ? $value : null;
            $workingFile = $config->workingDirectory.'/dump.'.$db->databaseType->dumpExtension($dumpFormat);

            // Database dump
            $database = $this->databaseProvider->makeFromConfig(
                $db,
                $config->databaseName,
                $this->getConnectionHost($db),
                $this->getConnectionPort($db),
                excludedTables: $config->excludedTables,
            );

            $compressor = $this->compressorFactory->make($config->compressionType, $config->compressionLevel, $config->compressionMultithread);
            $archive = $this->dumpAndCompress($database, $workingFile, $compressor, $logger);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }

            // Generate the filename once — the same archive is uploaded to
            // every target volume under the same name.
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $filename = $this->generateFilename($db->serverName, $config->databaseName, $db->databaseType->dumpExtension($dumpFormat), $compressor, $config->backupPath);

            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $result = $this->uploadArchive($config, $logger, $archive, new BackupResult($filename, $fileSize, $checksum, []));

            $logger->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'filename' => $filename,
            ]);

            $this->runPostBackupScript($config, $logger, $result);

            return $result;
        } catch (JobRevokedException $e) {
            if ($filename !== null) {
                $this->deleteUploads($config->volumes, $filename);
            }

            throw $e;
        } finally {
            $this->cleanUpRun($config->workingDirectory, $logger);
        }
    }

    public function retryUploads(BackupConfig $config, BackupLogger $logger, VolumeConfig $source, BackupResult $previous): BackupResult
    {
        $this->shellProcessor->setLogger($logger);
        $archive = $config->workingDirectory.'/archive';

        try {
            $logger->log('Downloading the existing backup archive for transfer retry.', 'info');
            $this->filesystemProvider->downloadFromConfig($source, $previous->filename, $archive);
            $checksum = hash_file('sha256', $archive);
            if (filesize($archive) !== $previous->fileSize || $checksum === false
                || ($previous->checksum !== null && $previous->checksum !== '' && $checksum !== $previous->checksum)) {
                throw new \RuntimeException('The existing backup archive does not match its recorded size and checksum.');
            }

            $metadata = new BackupResult($previous->filename, $previous->fileSize, $checksum);
            $result = $this->uploadArchive($config, $logger, $archive, $metadata);
            $this->runPostBackupScript($config, $logger, $result);

            return $result;
        } catch (JobRevokedException $exception) {
            $this->deleteUploads($config->volumes, $previous->filename);

            throw $exception;
        } finally {
            $this->cleanUpRun($config->workingDirectory, $logger);
        }
    }

    private function runPostBackupScript(BackupConfig $config, BackupLogger $logger, BackupResult $result): void
    {
        $database = $config->database;
        $this->postScriptRunner->run(
            $this->shellProcessor,
            $logger,
            'post-backup-script',
            $config->postBackupScript,
            $config->workingDirectory,
            [
                'BACKUP_SERVER_ID' => $database->serverId,
                'BACKUP_SERVER_NAME' => $database->serverName,
                'BACKUP_DATABASE_NAME' => $config->databaseName,
                'BACKUP_DATABASE_TYPE' => $database->databaseType->value,
                'BACKUP_FILENAME' => $result->filename,
                'BACKUP_FILE_SIZE' => (string) $result->fileSize,
                'BACKUP_CHECKSUM' => (string) $result->checksum,
                'BACKUP_VOLUME_NAME' => implode(', ', array_map(
                    fn (VolumeConfig $volume) => $volume->name,
                    $config->volumes,
                )),
            ],
        );
    }

    private function uploadArchive(BackupConfig $config, BackupLogger $logger, string $archive, BackupResult $metadata): BackupResult
    {
        $humanFileSize = Formatters::humanFileSize($metadata->fileSize);
        $volumeResults = [];
        foreach ($config->volumes as $volume) {
            $volumeResults[] = $this->transferToVolume($volume, $archive, $metadata->filename, $metadata->fileSize, $humanFileSize, $logger);
        }

        $result = new BackupResult($metadata->filename, $metadata->fileSize, $metadata->checksum, $volumeResults);
        if ($result->hasFailures()) {
            $failedNames = array_map(fn (VolumeTransferResult $failure) => $failure->volumeName, $result->failedResults());

            throw new VolumeTransferException(
                $result,
                __('Upload failed for volume(s): :volumes', ['volumes' => implode(', ', $failedNames)]),
            );
        }

        return $result;
    }

    /**
     * Dump the database into a compressed archive. A dump written to stdout is
     * piped straight into the compressor, so only the archive ever lands on
     * disk; otherwise the dump file is written first, then compressed.
     */
    private function dumpAndCompress(DatabaseInterface $database, string $workingFile, CompressorInterface $compressor, BackupLogger $logger): string
    {
        $result = $database->dump($workingFile);

        if ($result->writesToStdout && $result->command !== null) {
            $archive = $compressor->compressCommandOutput($result->command, $workingFile);
        } else {
            if ($result->command !== null) {
                $this->shellProcessor->process($result->command);
            }
            $archive = $compressor->compress($workingFile);
        }

        if ($result->log !== null) {
            $logger->log($result->log->message, $result->log->level, $result->log->context ?? []);
        }

        return $archive;
    }

    /**
     * Remove the copies of an archive that no snapshot will record, on a best
     * effort basis: a volume it never reached has nothing to delete.
     *
     * @param  array<int, VolumeConfig>  $volumes
     */
    private function deleteUploads(array $volumes, string $filename): void
    {
        foreach ($volumes as $volume) {
            rescue(fn () => $this->filesystemProvider->getForVolumeConfig($volume)->delete($filename), report: false);
        }
    }

    /**
     * Upload the archive to one target volume, converting quota blocks and
     * transfer errors into a per-volume result instead of aborting the run —
     * every volume gets its attempt before the caller decides to fail the job.
     */
    private function transferToVolume(
        VolumeConfig $volume,
        string $archive,
        string $filename,
        int $fileSize,
        string $humanFileSize,
        BackupLogger $logger,
    ): VolumeTransferResult {
        try {
            $storageWarning = $this->assertWithinStorageQuota($volume, $fileSize, $logger);
        } catch (StorageQuotaExceededException $e) {
            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Failed,
                error: $e->getMessage(),
                quotaExceeded: true,
            );
        }

        $logger->log("Transferring backup ({$humanFileSize}) to volume: {$volume->name}", 'info', [
            'volume_type' => $volume->type,
            'source' => $archive,
            'destination' => $filename,
        ]);

        $transferStart = microtime(true);

        try {
            $this->filesystemProvider->transferFromConfig($volume, $archive, $filename);
        } catch (\Throwable $e) {
            $logger->log("Transfer to volume {$volume->name} failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
            ]);

            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Failed,
                error: $e->getMessage(),
            );
        }

        $transferDuration = Formatters::humanDuration(Formatters::elapsedMs($transferStart));
        $logger->log('Transfer completed successfully in '.$transferDuration, 'success');

        return new VolumeTransferResult(
            volumeId: $volume->id,
            volumeName: $volume->name,
            status: SnapshotFileStatus::Completed,
            storageWarning: $storageWarning,
        );
    }

    /**
     * Enforce a target volume's storage limit before upload when this backup
     * would exceed it. Skipped (returns null) when no limit is set or the current
     * usage is unknown (remote agents).
     *
     * In block mode (default) this throws and the file is never uploaded. In
     * notify-only mode it logs a warning and returns the message so the caller
     * can notify the user while still uploading the backup.
     *
     * @return string|null The overage message when notify-only mode is on and the
     *                     limit was exceeded; null otherwise.
     *
     * @throws StorageQuotaExceededException
     */
    private function assertWithinStorageQuota(VolumeConfig $volume, int $fileSize, BackupLogger $logger): ?string
    {
        $limit = $volume->config['max_storage_bytes'] ?? null;

        if ($limit === null || $volume->usedBytes === null) {
            return null;
        }

        $limit = (int) $limit;
        $projected = $volume->usedBytes + $fileSize;

        if ($projected <= $limit) {
            return null;
        }

        $notifyOnly = (bool) ($volume->config['max_storage_notify_only'] ?? false);

        $context = [
            'volume' => $volume->name,
            'used_bytes' => $volume->usedBytes,
            'file_size' => $fileSize,
            'limit_bytes' => $limit,
        ];

        if ($notifyOnly) {
            $message = __('Storage limit reached for volume ":volume". This backup (:size) brings total usage to :projected, over the :limit limit. The backup was still uploaded (notify-only) — free up space by deleting old snapshots.', [
                'volume' => $volume->name,
                'size' => Formatters::humanFileSize($fileSize),
                'projected' => Formatters::humanFileSize($projected),
                'limit' => Formatters::humanFileSize($limit),
            ]);

            $logger->log($message, 'warning', $context);

            return $message;
        }

        $message = __('Storage limit reached for volume ":volume". This backup (:size) would bring total usage to :projected, over the :limit limit. The file was not uploaded — free up space by deleting old snapshots.', [
            'volume' => $volume->name,
            'size' => Formatters::humanFileSize($fileSize),
            'projected' => Formatters::humanFileSize($projected),
            'limit' => Formatters::humanFileSize($limit),
        ]);

        $logger->log($message, 'error', $context);

        throw new StorageQuotaExceededException($message);
    }

    /**
     * Generate the filename to store in the volume.
     * Includes optional path prefix for organizing backups.
     *
     * The random suffix keeps two backups of the same database started in
     * the same second from sharing a file, where one would overwrite the
     * other and deleting either would remove both.
     */
    private function generateFilename(string $serverName, string $databaseName, string $baseExtension, CompressorInterface $compressor, string $backupPath): string
    {
        $timestamp = now()->setTimezone(config('app.display_timezone'))->format('Y-m-d-His');
        $sanitizedServerName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $serverName) ?? $serverName;
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName) ?? $databaseName;
        $compressionExtension = $compressor->getExtension();

        $suffix = Str::lower(Str::random(6));

        $filename = sprintf('%s-%s-%s-%s.%s.%s', $sanitizedServerName, $sanitizedDbName, $timestamp, $suffix, $baseExtension, $compressionExtension);

        if (! empty($backupPath)) {
            $path = Formatters::resolveDatePlaceholders(trim($backupPath, '/'));
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }
}
