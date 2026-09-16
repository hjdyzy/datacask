<?php

namespace App\Notifications;

use App\Models\DatabaseServer;

class DatabaseOfflineNotification extends BaseFailedNotification
{
    public function __construct(public DatabaseServer $server)
    {
        parent::__construct(new \RuntimeException(__('Connection check failed twice in a row.')));
    }

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: __('Database Offline: :server', ['server' => $this->server->name]),
            body: __('The database server is unreachable. Check its connection and credentials.'),
            actionUrl: route('database-servers.show', $this->server),
            fields: [__('Server') => $this->server->name],
            actionText: __('View Server'),
        );
    }
}
