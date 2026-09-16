<?php

use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Notifications\DatabaseOfflineNotification;
use App\Notifications\DatabaseRecoveredNotification;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Notification;

test('checks each direct server and sends one outage and one recovery notification', function () {
    Notification::fake();
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'dba@example.test']]);
    $server = DatabaseServer::factory()->create(['notification_trigger' => 'failure']);

    $provider = $this->mock(DatabaseProvider::class);
    $provider->shouldReceive('testConnectionForServer')->times(6)->andReturn(
        ['success' => false], ['success' => false], ['success' => false],
        ['success' => true], ['success' => true], ['success' => true],
    );

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->artisan('databases:check-health')->assertExitCode(0);
    }

    Notification::assertSentTimes(DatabaseOfflineNotification::class, 1);
    Notification::assertSentTimes(DatabaseRecoveredNotification::class, 0);
    expect($server->fresh()->health_is_online)->toBeFalse();

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->artisan('databases:check-health')->assertExitCode(0);
    }

    Notification::assertSentTimes(DatabaseOfflineNotification::class, 1);
    Notification::assertSentTimes(DatabaseRecoveredNotification::class, 1);
    expect($server->fresh()->health_is_online)->toBeTrue()
        ->and($server->fresh()->health_failure_count)->toBe(0)
        ->and($server->fresh()->health_success_count)->toBe(2)
        ->and($server->fresh()->health_checked_at)->not->toBeNull();
});

test('first successful check does not send a recovery alert and transient failure is ignored', function () {
    Notification::fake();
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'dba@example.test']]);
    $server = DatabaseServer::factory()->create();

    $provider = $this->mock(DatabaseProvider::class);
    $provider->shouldReceive('testConnectionForServer')->times(3)->andReturn(
        ['success' => true], ['success' => false], ['success' => true],
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->artisan('databases:check-health')->assertExitCode(0);
    }

    Notification::assertNothingSent();
    expect($server->fresh()->health_is_online)->toBeTrue();
});

test('agent-managed servers are not probed through the web container', function () {
    Notification::fake();
    $server = DatabaseServer::factory()->create(['agent_id' => Agent::factory()->create()->id]);
    $this->mock(DatabaseProvider::class)->shouldNotReceive('testConnectionForServer');

    $this->artisan('databases:check-health')->assertExitCode(0);

    expect($server->fresh()->health_checked_at)->toBeNull();
    Notification::assertNothingSent();
});

test('server notification preferences suppress health alerts', function () {
    Notification::fake();
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'dba@example.test']]);
    DatabaseServer::factory()->create(['notification_trigger' => 'none']);

    $this->mock(DatabaseProvider::class)
        ->shouldReceive('testConnectionForServer')->twice()
        ->andReturn(['success' => false], ['success' => false]);

    $this->artisan('databases:check-health')->assertExitCode(0);
    $this->artisan('databases:check-health')->assertExitCode(0);

    Notification::assertNothingSent();
});
