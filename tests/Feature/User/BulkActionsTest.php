<?php

use App\Enums\Ability;
use App\Livewire\User\Index;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use Livewire\Livewire;

test('bulk delete requires confirmation and only deletes eligible users in the current organization', function () {
    $actor = User::factory()->superAdmin()->create();
    $regular = User::factory()->create();
    $protected = User::factory()->superAdmin()->create();
    $multiple = User::factory()->create();
    $notConfirmed = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    attachUserToOrg($multiple, $otherOrg, 'member');
    $outsider = User::factory()->create();
    $outsider->organizations()->sync([$otherOrg->id]);

    Livewire::actingAs($actor)->test(Index::class)
        ->set('selected', [$regular->id, $protected->id, $multiple->id, $outsider->id, $actor->id])
        ->call('applyBulkAction')
        ->assertSet('showBulkResults', false)
        ->call('openBulkAction', 'delete')
        ->assertSet('showBulkConfirmation', true)
        ->set('selected', [$notConfirmed->id])
        ->call('applyBulkAction')
        ->assertSet('showBulkResults', true)
        ->assertSee('Succeeded: 1 · Skipped: 4 · Failed: 0')
        ->assertSee('Your own account is protected from bulk changes.')
        ->assertSee('Super administrators are protected from bulk changes.')
        ->assertDontSee($outsider->email);

    expect($regular->fresh())->toBeNull();
    expect(User::whereKey([$actor->id, $multiple->id, $protected->id, $outsider->id, $notConfirmed->id])->count())->toBe(5);
});

test('bulk removal preserves the account and other memberships and skips single organization users', function () {
    $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
    $single = User::factory()->create();
    $multiple = User::factory()->create();
    $current = app(CurrentOrganization::class)->model();
    $other = Organization::factory()->create();
    attachUserToOrg($multiple, $other, 'member');

    Livewire::actingAs($actor)->test(Index::class)
        ->set('selected', [$single->id, $multiple->id])
        ->call('openBulkAction', 'remove')
        ->call('applyBulkAction')
        ->assertSee('Succeeded: 1 · Skipped: 1 · Failed: 0');

    expect($single->belongsToOrganization($current))->toBeTrue()
        ->and($multiple->belongsToOrganization($current))->toBeFalse()
        ->and($multiple->belongsToOrganization($other))->toBeTrue();
});

test('bulk role assignment validates the role and changes only the current organization role', function () {
    $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
    $selected = User::factory()->create();
    $current = app(CurrentOrganization::class)->model();
    $other = Organization::factory()->create();
    attachUserToOrg($selected, $other, 'member');

    Livewire::actingAs($actor)->test(Index::class)
        ->set('selected', [$selected->id, $actor->id])
        ->call('openBulkAction', 'role')
        ->set('bulkRole', 'missing-role')
        ->call('applyBulkAction')
        ->assertHasErrors('bulkRole')
        ->set('bulkRole', 'viewer')
        ->call('applyBulkAction')
        ->assertSee('Succeeded: 1 · Skipped: 1 · Failed: 0');

    expect($selected->roleNameIn($current))->toBe('viewer')
        ->and($selected->roleNameIn($other))->toBe('member');
});

test('bulk invitations include only pending users in the current organization', function () {
    $actor = User::factory()->superAdmin()->create();
    $active = User::factory()->create();
    $pending = User::factory()->create(['password' => null, 'invitation_accepted_at' => null, 'invitation_token' => 'selected-token']);
    $outsider = User::factory()->create(['password' => null, 'invitation_accepted_at' => null, 'invitation_token' => 'foreign-token']);
    $outsider->organizations()->sync([Organization::factory()->create()->id]);

    Livewire::actingAs($actor)->test(Index::class)
        ->set('selected', [$pending->id, $active->id, $outsider->id])
        ->call('openBulkAction', 'invite')
        ->assertSet('showBulkConfirmation', false)
        ->assertSet('showBulkResults', true)
        ->assertSee(route('invitation.accept', 'selected-token'))
        ->assertDontSee('foreign-token')
        ->call('closeBulkResults')
        ->assertSet('bulkResults', []);
});

test('changing filters clears the selection and cancels an open confirmation', function () {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($actor)->test(Index::class)
        ->set('selected', [$target->id])
        ->call('openBulkAction', 'delete')
        ->set('search', $target->name)
        ->assertSet('selected', [])
        ->assertSet('showBulkConfirmation', false);
});

test('selection survives pagination and page size changes', function () {
    $actor = User::factory()->superAdmin()->create();
    $users = User::factory()->count(18)->create();
    $selected = $users->pluck('id')->all();

    Livewire::actingAs($actor)->test(Index::class)
        ->assertViewHas('users', fn ($page) => $page->perPage() === 15)
        ->call('togglePageSelection')
        ->assertSet('selected', fn ($ids) => count($ids) === 15)
        ->call('setPage', 2)
        ->assertSet('selected', fn ($ids) => count($ids) === 15)
        ->call('togglePageSelection')
        ->assertSet('selected', fn ($ids) => count($ids) === 19)
        ->call('togglePageSelection')
        ->assertSet('selected', fn ($ids) => count($ids) === 15)
        ->set('perPage', 30)
        ->assertViewHas('users', fn ($page) => $page->perPage() === 30 && $page->currentPage() === 1)
        ->assertSet('selected', fn ($ids) => count($ids) === 15)
        ->set('selected', $selected)
        ->call('openBulkAction', 'role')
        ->assertSet('bulkIds', $selected);
});

test('all matching users selects across pages in the current organization only', function () {
    $actor = User::factory()->superAdmin()->create();
    $matches = User::factory()->count(16)->create(['name' => 'Matching user']);
    $outsider = User::factory()->create(['name' => 'Matching user']);
    $outsider->organizations()->sync([Organization::factory()->create()->id]);
    User::factory()->create(['name' => 'Different user']);

    Livewire::actingAs($actor)->test(Index::class)
        ->set('search', 'Matching')
        ->call('selectAllMatching')
        ->assertSet('selected', $matches->pluck('id')->all())
        ->call('openBulkAction', 'delete')
        ->assertSet('showBulkConfirmation', true)
        ->assertSet('bulkIds', $matches->pluck('id')->all());
});
