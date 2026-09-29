<?php

namespace App\Livewire\DatabaseServer;

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Backup\OneOffBackupDatabases;
use App\Services\Backup\TriggerOneOffBackupAction;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class OneOffBackupModal extends Component
{
    use AuthorizesRequests, Toast;

    #[Locked]
    public ?string $serverId = null;

    #[Locked]
    public string $serverName = '';

    /** @var list<array{id: string, name: string}> */
    #[Locked]
    public array $backupOptions = [];

    /** @var list<string> */
    #[Locked]
    public array $availableDatabases = [];

    /** @var list<string> */
    public array $databases = [];

    public string $backupId = '';

    public string $search = '';

    public string $comment = '';

    public bool $locked = false;

    public bool $showModal = false;

    #[On('open-one-off-backup')]
    public function openModal(string $serverId): void
    {
        $this->reset();
        $this->resetValidation();
        $server = DatabaseServer::with(['backups.volumes', 'backups.backupSchedule'])->findOrFail($serverId);
        $this->authorize('backup', $server);
        abort_if(auth()->user()->isDemo(), 403);
        $this->serverId = $server->id;
        $this->serverName = $server->name;
        $this->backupOptions = array_values($server->backups->map(fn (Backup $backup) => [
            'id' => $backup->id,
            'name' => $backup->getDisplayLabel(),
        ])->all());
        $this->backupId = count($this->backupOptions) === 1 ? $this->backupOptions[0]['id'] : '';
        $this->showModal = true;
        $this->refreshDatabases();
    }

    public function refreshDatabases(): void
    {
        $server = DatabaseServer::findOrFail($this->serverId);
        $this->authorize('backup', $server);
        abort_if(auth()->user()->isDemo(), 403);
        $this->resetValidation();
        $this->availableDatabases = [];
        $this->availableDatabases = app(OneOffBackupDatabases::class)->forServer($server);
        $this->databases = array_values(array_intersect($this->databases, $this->availableDatabases));
    }

    public function submit(TriggerOneOffBackupAction $action): void
    {
        $this->validate(['backupId' => ['required', 'string']]);
        $snapshots = $action->execute(
            DatabaseServer::findOrFail($this->serverId), $this->backupId, $this->databases,
            auth()->user(), $this->comment, $this->locked,
        );
        $this->showModal = false;
        $this->databases = [];
        $this->success(__('Queued :count one-off database backups. View progress in Snapshots.', ['count' => count($snapshots)]));
    }

    public function render(): View
    {
        $filteredDatabases = array_values(array_filter($this->availableDatabases,
            fn (string $name) => $this->search === '' || mb_stripos($name, $this->search) !== false));

        return view('livewire.database-server.one-off-backup-modal', compact('filteredDatabases'));
    }
}
