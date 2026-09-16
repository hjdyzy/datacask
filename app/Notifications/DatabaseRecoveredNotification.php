<?php

namespace App\Notifications;

use App\Models\DatabaseServer;

class DatabaseRecoveredNotification extends BaseSuccessNotification
{
    public function __construct(public DatabaseServer $server) {}

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: __('Database Recovered: :server', ['server' => $this->server->name]),
            body: __('The database server is reachable again.'),
            actionUrl: route('database-servers.show', $this->server),
            fields: [__('Server') => $this->server->name],
            actionText: __('View Server'),
        );
    }
}
