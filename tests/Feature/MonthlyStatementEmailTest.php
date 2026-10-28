<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\OwnerStatementEmail;
use App\Models\User;
use App\Notifications\MonthlyStatementNotification;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\IncomeStatement;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->owner = User::factory()->create(['notification_preferences' => ['monthly_statement_email' => true]]);
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create();

    $this->mock(PropertywareDirectory::class)
        ->shouldReceive('scopeFor')
        ->andReturn(new ClientScope(ClientRole::Owner, '500', portfolioIds: [2, 3]));

    $statements = $this->mock(IncomeStatement::class);
    $statements->shouldReceive('for')->andReturnUsing(fn (ClientScope $scope, int $portfolioId, string $from, string $to): array => [
        'portfolio' => ['id' => $portfolioId, 'name' => "Portfolio {$portfolioId}"], 'label' => "{$from} to {$to}",
    ]);
    $statements->shouldReceive('fileName')->andReturnUsing(fn (array $report): string => "Income Statement - {$report['portfolio']['name']}.pdf");
    $statements->shouldReceive('pdfContents')->andReturn('%PDF-1.7');
});

test('emails last month\'s statement to owners who turned it on, a PDF per portfolio, once', function () {
    Notification::fake();
    $this->travelTo('2026-11-05 08:00');

    $this->artisan('statements:email-monthly')->assertSuccessful();
    $this->artisan('statements:email-monthly')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MonthlyStatementNotification::class, 1);
    Notification::assertSentTo($this->owner, fn (MonthlyStatementNotification $notification): bool => $notification->monthLabel === 'October 2026'
        && array_column($notification->statements, 'name') === ['Income Statement - Portfolio 2.pdf', 'Income Statement - Portfolio 3.pdf']);
    expect(OwnerStatementEmail::sole())->month->toBe('2026-10')->portfolios->toBe(2);
});

test('does not email owners who left monthly statements off', function () {
    Notification::fake();
    $this->owner->forceFill(['notification_preferences' => []])->save();

    $this->artisan('statements:email-monthly', ['--month' => '2026-10'])->assertSuccessful();

    Notification::assertNothingSent();
});

test('does not email an owner whose email address is not confirmed', function () {
    Notification::fake();
    $this->owner->forceFill(['email_verified_at' => null])->save();

    $this->artisan('statements:email-monthly', ['--month' => '2026-10'])->assertSuccessful();

    Notification::assertNothingSent();
});

test('owners can turn monthly statements on in their settings', function () {
    $this->owner->forceFill(['notification_preferences' => []])->save();

    $this->actingAs($this->owner)
        ->get(route('notifications.edit'))
        ->assertInertia(fn ($page) => $page->where('isOwner', true)->where('preferences.monthly_statement_email', false));

    $this->put(route('notifications.update'), ['monthly_statement_email' => true])->assertRedirect(route('notifications.edit'));

    expect($this->owner->refresh()->wantsNotification('monthly_statement_email'))->toBeTrue();
});

test('the email links to the Statements page and attaches each PDF', function () {
    $mail = (new MonthlyStatementNotification('October 2026', [
        ['name' => 'Income Statement - Portfolio 2.pdf', 'contents' => '%PDF-1.7'],
    ]))->toMail($this->owner);

    expect($mail->subject)->toBe('Your income statement for October 2026')
        ->and($mail->actionUrl)->toBe(route('owner.statements'))
        ->and($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toBe('Income Statement - Portfolio 2.pdf')
        ->and((string) $mail->render())->toContain('Your income statement for October 2026 is attached.');
});
