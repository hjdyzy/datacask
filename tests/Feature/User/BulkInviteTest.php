<?php

use App\Actions\User\ImportUserInvitationsAction;
use App\Enums\Ability;
use App\Livewire\User\Create;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

test('CSV import creates pending users and reports skipped and invalid rows', function () {
    $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
    $existing = User::factory()->create(['email' => 'registered@example.test']);
    $existing->organizations()->sync([Organization::factory()->create()->id]);
    $csv = "\xEF\xBB\xBFname,email\r\n\"Chen, Alex\",alex@example.test\r\nDuplicate,ALEX@example.test\r\nExisting,registered@example.test\r\nInvalid,wrong\r\nMissing column";

    Livewire::actingAs($actor)->test(Create::class)
        ->set('mode', 'batch')
        ->set('batchRole', 'viewer')
        ->set('batchCsv', $csv)
        ->call('importInvitations')
        ->assertSet('showBulkResults', true)
        ->assertSee('Succeeded: 1 · Skipped: 2 · Failed: 2')
        ->assertSee('CSV row 6')
        ->assertSee('/invitation/');

    $imported = User::where('email', 'alex@example.test')->firstOrFail();
    $organization = Organization::default();
    expect($imported->name)->toBe('Chen, Alex')
        ->and($imported->isPending())->toBeTrue()
        ->and($imported->belongsToOrganization($organization))->toBeTrue()
        ->and($imported->roleNameIn($organization))->toBe('viewer')
        ->and($imported->isSuperAdmin())->toBeFalse()
        ->and($existing->belongsToOrganization($organization))->toBeFalse();
});

test('a CSV file can be uploaded to invite users', function () {
    $actor = User::factory()->superAdmin()->create();
    $file = UploadedFile::fake()->createWithContent('users.csv', "姓名,邮箱\n李明,li@example.test\n");

    Livewire::actingAs($actor)->test(Create::class)
        ->set('batchRole', 'viewer')
        ->set('batchFile', $file)
        ->call('importInvitations')
        ->assertHasNoErrors()
        ->assertSee('Succeeded: 1 · Skipped: 0 · Failed: 0')
        ->assertSet('batchFile', null);

    expect(User::where('email', 'li@example.test')->firstOrFail()->isPending())->toBeTrue();
});

test('invalid roles and oversized batches are rejected before creating invitations', function () {
    $actor = User::factory()->superAdmin()->create();
    $csv = implode("\n", array_map(fn (int $n): string => "User {$n},bulk{$n}@example.test", range(1, ImportUserInvitationsAction::MAX_ROWS + 1)));

    Livewire::actingAs($actor)->test(Create::class)
        ->set('batchCsv', $csv)
        ->set('batchRole', 'missing-role')
        ->call('importInvitations')
        ->assertHasErrors('batchRole')
        ->set('batchRole', 'viewer')
        ->call('importInvitations')
        ->assertHasErrors('batchCsv');

    expect(User::where('email', 'bulk1@example.test')->exists())->toBeFalse();
});
