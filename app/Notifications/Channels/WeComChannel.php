<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WeComChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{msgtype: string, markdown: array{content: string}} $payload */
        $payload = $notification->toWeCom($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $url = $config['webhook_url'] ?? null;

        if (! $url) {
            return;
        }

        $response = Http::timeout(10)->post($url, $payload)->throw();
        $result = $response->json();

        if (! is_array($result) || ! array_key_exists('errcode', $result) || (int) $result['errcode'] !== 0) {
            $code = is_array($result) ? ($result['errcode'] ?? 'unknown') : 'unknown';
            $message = is_array($result) ? ($result['errmsg'] ?? 'Invalid response') : 'Invalid response';

            throw new RuntimeException(__('WeCom webhook rejected the message (error :code): :message', [
                'code' => $code,
                'message' => $message,
            ]));
        }
    }
}
