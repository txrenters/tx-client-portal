# Lease Expiry Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every morning at 9:00 Houston time, tell owners and tenants (push + a stored notification list on web and mobile) that a lease ends within 90 days.

**Architecture:** A daily artisan command in `tx-client` reads expiring leases from the Propertyware REST API, resolves portal accounts by Propertyware ID, records each lease once, and sends a Laravel notification over the `database` channel and the existing `ExpoChannel`. A new API lists those notices for the app; the web bell and Notifications page gain a "notices" section. `tx-mobile` gets a bell on Home, a Notifications screen and detail, push routing, and a settings switch.

**Tech Stack:** Laravel 12 + Pest (sqlite in-memory tests, `Http::preventStrayRequests()`), Inertia/Vue 3; Expo SDK 57, Expo Router, TanStack Query.

**Spec:** `docs/superpowers/specs/2026-10-03-lease-expiry-notifications-design.md` (tx-client)

## Global Constraints

- Source is Propertyware `GET /leases` with `endDateStart` / `endDateEnd`; never the JSON report URL.
- Only `status === 'Active'` leases are notified (skip `Terminated`, `Active - Notice Given`, anything else).
- Schedule: `dailyAt('09:00')->timezone('America/Chicago')->withoutOverlapping()`.
- No email. Channels: `database` always, `ExpoChannel` only when `lease_expiry_push` is on (default on).
- Owner notices never contain tenant names, the lease name, emails or phones. Only `LeaseNoticeText` turns a lease into notice text.
- Tenant contacts notified: `namedOnLease === true` and role in `Primary`, `Secondary`, `Other`. Guarantors never.
- One record per `(propertyware_lease_id, end_date)`; a re-run never sends a second copy.
- First run: `--seed` notifies only leases ending 80–90 days out, records the rest with `notified_at = null`.
- Renewal deadline = end date − 60 days. Push dates `M j, Y` ("Dec 30, 2026"); letter dates `m/d/Y` ("12/30/2026").
- Tenant greeting uses the recipient's portal name (`User::name`) — the spec's `first_name`/`last_name` are replaced by one `greeting_name` field.
- Stored notices older than 90 days are deleted daily.
- Mobile: run `npx expo lint` and `npx tsc --noEmit` before any mobile task is done. Use `npx expo install` for any dependency (none expected).
- tx-client: run `php artisan test` for touched tests and `vendor/bin/pint --dirty` before each commit; run `graphify update .` after code changes.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Owner and tenant on one login: a user with both an owner and a tenant account must see each notice only under its own account (API filters on `data->account_id`). Pinned in Task 7.
2. Propertyware failing mid-run (a building/unit lookup or a contact lookup throws): the command must not record a lease it did not notify, and one bad owner contact must not stop the other owners. Pinned in Tasks 3 and 6.
3. A lease with no `unitID` (single-family homes): unit name falls back to the building name, no crash. Pinned in Task 2.
4. A user who turned `lease_expiry_push` off still gets the stored notice (bell) but no push. Pinned in Task 5.
5. A notice opened from another account's id (guessing an id): read endpoints must 404. Pinned in Task 7.

---

## File Map

tx-client

- Create `database/migrations/2026_10_03_000001_create_notifications_table.php` — Laravel notifications table (generated).
- Create `database/migrations/2026_10_03_000002_create_lease_expiry_notices_table.php`
- Create `app/Models/LeaseExpiryNotice.php` — the once-per-lease record.
- Modify `app/Models/User.php` — `lease_expiry_push` default.
- Modify `routes/console.php` — schedule the command and the notice prune.
- Create `app/Services/Leases/ExpiringLease.php` — value object, IDs and property labels only.
- Create `app/Services/Leases/ExpiringLeases.php` — reads and filters leases from Propertyware.
- Create `app/Services/Leases/LeaseExpiryRecipients.php` — lease → owner and tenant `ClientAccount`s.
- Create `app/Services/Leases/LeaseNoticeText.php` — the only place notice wording is built.
- Create `app/Notifications/LeaseExpiringNotification.php`
- Create `app/Console/Commands/NotifyExpiringLeases.php`
- Create `app/Services/Notifications/NeedsAttention.php` — unread messages + approvals, shared by web and API.
- Modify `app/Http/Controllers/Client/NotificationController.php` — use `NeedsAttention`, add `notices`, add `read`.
- Create `app/Http/Controllers/Api/V1/NotificationController.php`
- Modify `routes/api.php`, `routes/client.php`
- Modify `resources/js/components/NotificationBell.vue`, `resources/js/pages/client/Notifications.vue`
- Tests: `tests/Feature/Leases/*`, `tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php`, `tests/Feature/Api/V1/NotificationTest.php`, `tests/Feature/Client/LeaseNoticeWebTest.php`

tx-mobile

- Modify `src/api/types.ts`, `src/api/queries.ts`
- Modify `src/notifications/useNotifications.ts`
- Modify `src/app/(app)/account/notifications.tsx`
- Modify `src/app/_layout.tsx` — register the `notifications` stack.
- Create `src/app/notifications/_layout.tsx`, `src/app/notifications/index.tsx`, `src/app/notifications/[id].tsx`
- Create `src/components/NotificationBell.tsx`
- Modify `src/app/(app)/index.tsx` — bell in the Home header.

---

### Task 1: Storage and the preference (tx-client)

**Files:**

- Create: `database/migrations/2026_10_03_000001_create_notifications_table.php` (via artisan)
- Create: `database/migrations/2026_10_03_000002_create_lease_expiry_notices_table.php`
- Create: `app/Models/LeaseExpiryNotice.php`
- Modify: `app/Models/User.php` (`NOTIFICATION_DEFAULTS`, ~line 188)
- Modify: `routes/console.php`
- Test: `tests/Feature/Leases/LeaseExpiryStorageTest.php`

**Interfaces:**

- Produces: `App\Models\LeaseExpiryNotice` with fillable `propertyware_lease_id` (int), `end_date` (date, cast `immutable_date`), `notified_at` (nullable datetime), `recipients` (int). Preference key `'lease_expiry_push'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leases/LeaseExpiryStorageTest.php

use App\Models\LeaseExpiryNotice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

test('lease reminders are pushed by default and can be turned off', function () {
    $user = User::factory()->create();
    expect($user->wantsNotification('lease_expiry_push'))->toBeTrue();

    Sanctum::actingAs($user);
    $this->putJson(route('api.v1.notification-preferences.update'), ['lease_expiry_push' => false])
        ->assertOk()
        ->assertJsonPath('data.lease_expiry_push', false);

    expect($user->fresh()->wantsNotification('lease_expiry_push'))->toBeFalse();
});

test('a lease and end date is recorded only once', function () {
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2027-01-05', 'notified_at' => now(), 'recipients' => 2]);

    expect(fn () => LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2027-01-05', 'recipients' => 0]))
        ->toThrow(UniqueConstraintViolationException::class);

    // An extension is a new end date, so it can be recorded again.
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2028-01-05', 'recipients' => 0]);
    expect(LeaseExpiryNotice::count())->toBe(2);
});

test('stored notices older than 90 days are pruned', function () {
    $user = User::factory()->create();
    foreach ([91, 89] as $age) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => [],
            'created_at' => now()->subDays($age),
        ]);
    }

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(DatabaseNotification::count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leases/LeaseExpiryStorageTest.php`
Expected: FAIL — `lease_expiry_push` is false/unknown, `lease_expiry_notices` table and `notifications:prune` command missing.

- [ ] **Step 3: Implement**

Generate the notifications table, then rename the file so it sorts with this feature:

```bash
php artisan make:notifications-table
mv database/migrations/*_create_notifications_table.php database/migrations/2026_10_03_000001_create_notifications_table.php
```

`database/migrations/2026_10_03_000002_create_lease_expiry_notices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_expiry_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('propertyware_lease_id');
            $table->date('end_date');
            // Null when the first run recorded the lease without telling anyone.
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();

            $table->unique(['propertyware_lease_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_expiry_notices');
    }
};
```

`app/Models/LeaseExpiryNotice.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One lease, for one end date, that the 90-day reminder has dealt with.
 *
 * An extension gives the lease a new end date, and so a new row and a new
 * reminder 90 days before it.
 */
#[Fillable(['propertyware_lease_id', 'end_date', 'notified_at', 'recipients'])]
class LeaseExpiryNotice extends Model
{
    protected function casts(): array
    {
        return [
            'end_date' => 'immutable_date',
            'notified_at' => 'immutable_datetime',
        ];
    }
}
```

(If `ClientAccount` uses `protected $fillable` instead of the `#[Fillable]` attribute, match whichever this codebase's newest model uses — `ClientAccount.php:18` uses the attribute.)

`app/Models/User.php` — add to `NOTIFICATION_DEFAULTS` after `message_replies_push`:

```php
        // A lease is 90 days from ending: the owner decides whether to renew or
        // relist, the tenant whether to renew. Always kept in the app's list;
        // this only decides whether the phone is buzzed.
        'lease_expiry_push' => true,
```

`routes/console.php` — add (with `use Illuminate\Notifications\DatabaseNotification;` at the top):

```php
Artisan::command('notifications:prune', function () {
    $deleted = DatabaseNotification::where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Deleted {$deleted} notification(s) older than 90 days.");
})->purpose('Delete stored notifications older than 90 days');

Schedule::command('notifications:prune')->daily();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Leases/LeaseExpiryStorageTest.php tests/Feature/NotificationPreferencesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/LeaseExpiryNotice.php app/Models/User.php routes/console.php tests/Feature/Leases
git commit -m "Store notifications, record lease reminders once, and add the lease reminder setting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Reading expiring leases from Propertyware (tx-client)

**Files:**

- Create: `app/Services/Leases/ExpiringLease.php`
- Create: `app/Services/Leases/ExpiringLeases.php`
- Test: `tests/Feature/Leases/ExpiringLeasesTest.php`

**Interfaces:**

- Consumes: `App\Services\Propertyware\PropertywareClient::listAll(string $path, array $query = [])`, `::getMany(array $paths): array`.
- Produces:
    - `ExpiringLease` (readonly): `int $id`, `int $portfolioId`, `int $buildingId`, `string $building`, `string $unit`, `?CarbonImmutable $startDate`, `CarbonImmutable $endDate`, `array<int,string> $tenantContactIds`; methods `renewalDeadline(): CarbonImmutable`, `daysLeft(CarbonImmutable $today): int`.
    - `ExpiringLeases::between(CarbonImmutable $from, CarbonImmutable $to): array<int, ExpiringLease>` — throws `PropertywareException`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leases/ExpiringLeasesTest.php

use App\Services\Leases\ExpiringLeases;
use App\Services\Propertyware\PropertywareClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function leaseRecord(array $overrides = []): array
{
    return [
        'id' => 55, 'status' => 'Active', 'active' => true, 'portfolioID' => 2, 'buildingID' => 7, 'unitID' => 70,
        'leaseName' => 'Tenant - Cotenant', 'startDate' => '2025-01-06', 'endDate' => '2027-01-05',
        'contacts' => [
            ['id' => 900, 'role' => 'Primary', 'namedOnLease' => true, 'firstName' => 'Taylor', 'lastName' => 'Tenant'],
            ['id' => 901, 'role' => 'Secondary', 'namedOnLease' => true, 'firstName' => 'Casey', 'lastName' => 'Cotenant'],
            ['id' => 902, 'role' => 'Guarantor', 'namedOnLease' => true, 'firstName' => 'Gina', 'lastName' => 'Guarantor'],
            ['id' => 903, 'role' => 'Tenant', 'namedOnLease' => false, 'firstName' => 'Kid', 'lastName' => 'Tenant'],
        ],
        ...$overrides,
    ];
}

function fakeLeases(array $leases): ExpiringLeases
{
    Http::fake(function (Request $request) use ($leases) {
        return match (true) {
            str_contains($request->url(), '/leases') => Http::response($leases),
            str_contains($request->url(), '/buildings/7') => Http::response(['id' => 7, 'name' => '7734 Park Falls, ', 'address' => ['address' => '7734 Park Falls Dr']]),
            str_contains($request->url(), '/buildings/8') => Http::response(['id' => 8, 'name' => '', 'address' => ['address' => '12 Elm St']]),
            str_contains($request->url(), '/units/70') => Http::response(['id' => 70, 'name' => 'Unit A']),
            default => Http::response([], 404),
        };
    });

    return new ExpiringLeases(new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system'));
}

test('asks Propertyware for leases ending in the window', function () {
    fakeLeases([])->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/leases')
        && $request->data()['endDateStart'] === '2026-10-03'
        && $request->data()['endDateEnd'] === '2027-01-01');
});

test('keeps active leases only, with property labels and the tenants named on them', function () {
    $leases = fakeLeases([
        leaseRecord(),
        leaseRecord(['id' => 56, 'status' => 'Terminated']),
        leaseRecord(['id' => 57, 'status' => 'Active - Notice Given']),
    ])->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    expect($leases)->toHaveCount(1);
    $lease = $leases[0];

    expect($lease->id)->toBe(55)
        ->and($lease->portfolioId)->toBe(2)
        ->and($lease->buildingId)->toBe(7)
        ->and($lease->building)->toBe('7734 Park Falls')
        ->and($lease->unit)->toBe('Unit A')
        ->and($lease->startDate?->toDateString())->toBe('2025-01-06')
        ->and($lease->endDate->toDateString())->toBe('2027-01-05')
        ->and($lease->tenantContactIds)->toBe(['900', '901'])
        ->and($lease->renewalDeadline()->toDateString())->toBe('2026-11-06')
        ->and($lease->daysLeft(CarbonImmutable::parse('2026-10-07')))->toBe(90);
});

test('a home without a unit takes the building name, and a nameless building its address', function () {
    $leases = fakeLeases([leaseRecord(['buildingID' => 8, 'unitID' => null])])
        ->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    expect($leases[0]->building)->toBe('12 Elm St')
        ->and($leases[0]->unit)->toBe('12 Elm St');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leases/ExpiringLeasesTest.php`
Expected: FAIL — class `App\Services\Leases\ExpiringLeases` not found.

- [ ] **Step 3: Implement**

`app/Services/Leases/ExpiringLease.php`:

```php
<?php

namespace App\Services\Leases;

use Carbon\CarbonImmutable;

/**
 * A lease the 90-day reminder is about.
 *
 * It carries the property's labels and the ids of the people named on the
 * lease, and nothing else about them: no names, no lease name (it is built
 * from tenant surnames), no contact details. That way the owner's notice
 * cannot leak who lives there, whatever builds it.
 */
final class ExpiringLease
{
    /**
     * @param  array<int, string>  $tenantContactIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $portfolioId,
        public readonly int $buildingId,
        public readonly string $building,
        public readonly string $unit,
        public readonly ?CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly array $tenantContactIds,
    ) {}

    /** The last day renewal papers can be approved: the home is relisted 60 days out. */
    public function renewalDeadline(): CarbonImmutable
    {
        return $this->endDate->subDays(60);
    }

    public function daysLeft(CarbonImmutable $today): int
    {
        return (int) $today->startOfDay()->diffInDays($this->endDate->startOfDay(), false);
    }
}
```

`app/Services/Leases/ExpiringLeases.php`:

```php
<?php

namespace App\Services\Leases;

use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;

/**
 * Leases that end inside a window, as Propertyware has them today.
 */
class ExpiringLeases
{
    /** Only these lease contacts are tenants worth telling; guarantors are not. */
    private const TENANT_ROLES = ['Primary', 'Secondary', 'Other'];

    public function __construct(private PropertywareClient $propertyware) {}

    /**
     * Active leases ending on or between the two dates. A tenant who has given
     * notice, or a lease already terminated, gets no renewal prompt.
     *
     * @return array<int, ExpiringLease>
     *
     * @throws PropertywareException
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $leases = collect($this->propertyware->listAll('leases', [
            'endDateStart' => $from->toDateString(),
            'endDateEnd' => $to->toDateString(),
        ]))->filter(fn (array $lease): bool => ($lease['status'] ?? null) === 'Active' && filled($lease['endDate'] ?? null))->values();

        if ($leases->isEmpty()) {
            return [];
        }

        $paths = $leases->flatMap(fn (array $lease): array => array_filter([
            'building:'.(int) $lease['buildingID'] => "buildings/{$lease['buildingID']}",
            'unit:'.(int) ($lease['unitID'] ?? 0) => empty($lease['unitID']) ? null : "units/{$lease['unitID']}",
        ]))->all();

        $records = $this->propertyware->getMany($paths);

        return $leases->map(function (array $lease) use ($records): ExpiringLease {
            $building = self::buildingLabel($records['building:'.(int) $lease['buildingID']] ?? [], (int) $lease['buildingID']);
            $unit = $records['unit:'.(int) ($lease['unitID'] ?? 0)] ?? [];

            return new ExpiringLease(
                id: (int) $lease['id'],
                portfolioId: (int) ($lease['portfolioID'] ?? 0),
                buildingId: (int) $lease['buildingID'],
                building: $building,
                unit: filled($unit['name'] ?? null) ? trim((string) $unit['name']) : $building,
                startDate: filled($lease['startDate'] ?? null) ? CarbonImmutable::parse($lease['startDate']) : null,
                endDate: CarbonImmutable::parse($lease['endDate']),
                tenantContactIds: collect($lease['contacts'] ?? [])
                    ->filter(fn (mixed $contact): bool => is_array($contact)
                        && ($contact['namedOnLease'] ?? false) === true
                        && in_array($contact['role'] ?? null, self::TENANT_ROLES, strict: true)
                        && isset($contact['id']))
                    ->map(fn (array $contact): string => (string) $contact['id'])
                    ->values()
                    ->all(),
            );
        })->all();
    }

    /**
     * The name staff gave the building, trimmed the way the portal shows it
     * elsewhere, or its street address when it has none.
     *
     * @param  array<string, mixed>  $record
     */
    private static function buildingLabel(array $record, int $id): string
    {
        $name = trim((string) ($record['name'] ?? ''), " \t\n\r,;-");

        if ($name !== '') {
            return $name;
        }

        $address = is_array($record['address'] ?? null) ? trim((string) ($record['address']['address'] ?? '')) : '';

        return $address !== '' ? $address : "Property {$id}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Leases/ExpiringLeasesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Leases tests/Feature/Leases/ExpiringLeasesTest.php
git commit -m "Read active leases ending within a window from Propertyware

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Who gets told (tx-client)

**Files:**

- Create: `app/Services/Leases/LeaseExpiryRecipients.php`
- Test: `tests/Feature/Leases/LeaseExpiryRecipientsTest.php`

**Interfaces:**

- Consumes: `ExpiringLease` (Task 2); `PropertywareDirectory::findContact(string $contactId): ?Contact` (`Contact->role`, `Contact->portfolioIds`); `ClientAccount` (`role`, `propertyware_id`, `user`).
- Produces: `LeaseExpiryRecipients::owners(ExpiringLease $lease): Collection<int, ClientAccount>` and `::tenants(ExpiringLease $lease): Collection<int, ClientAccount>`, each with `user` loaded.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leases/LeaseExpiryRecipientsTest.php

use App\Models\ClientAccount;
use App\Services\Leases\ExpiringLease;
use App\Services\Leases\LeaseExpiryRecipients;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function expiringLease(array $tenantContactIds = ['900', '901'], int $portfolioId = 2): ExpiringLease
{
    return new ExpiringLease(55, $portfolioId, 7, '7734 Park Falls', '7734 Park Falls', CarbonImmutable::parse('2025-01-06'), CarbonImmutable::parse('2027-01-05'), $tenantContactIds);
}

function recipientsWithOwners(array $contacts): LeaseExpiryRecipients
{
    Http::fake(function (Request $request) use ($contacts) {
        preg_match('#/contacts/(\d+)#', $request->url(), $match);
        $contact = $contacts[$match[1] ?? ''] ?? null;

        return $contact === 'error' ? Http::response([], 500) : Http::response($contact ?? [], $contact === null ? 404 : 200);
    });

    return new LeaseExpiryRecipients(new PropertywareDirectory(
        new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system'),
        cacheSeconds: 0,
    ));
}

function ownerContact(string $id, array $portfolioIds): array
{
    return ['id' => $id, 'type' => 'OWNER', 'firstName' => 'O', 'lastName' => $id, 'attachedLeases' => [],
        'attachedPortfolios' => array_map(fn (int $p): array => ['id' => $p, 'name' => "P{$p}"], $portfolioIds)];
}

test('every owner of the lease portfolio is told, and nobody else', function () {
    $first = ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    $second = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);
    ClientAccount::factory()->owner()->create(['propertyware_id' => '502']);

    $recipients = recipientsWithOwners([
        '500' => ownerContact('500', [2]),
        '501' => ownerContact('501', [2, 3]),
        '502' => ownerContact('502', [9]),
    ]);

    expect($recipients->owners(expiringLease())->pluck('id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('one owner Propertyware cannot read does not stop the others', function () {
    ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    $readable = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);

    $recipients = recipientsWithOwners(['500' => 'error', '501' => ownerContact('501', [2])]);

    expect($recipients->owners(expiringLease())->pluck('id')->all())->toBe([$readable->id]);
});

test('tenants named on the lease with a portal login are told; others are not', function () {
    $primary = ClientAccount::factory()->resident()->create(['propertyware_id' => '900']);
    $secondary = ClientAccount::factory()->resident()->create(['propertyware_id' => '901']);
    ClientAccount::factory()->resident()->create(['propertyware_id' => '902']); // guarantor, filtered in Task 2
    ClientAccount::factory()->owner()->create(['propertyware_id' => '900']); // same id, wrong role

    $recipients = recipientsWithOwners([]);

    expect($recipients->tenants(expiringLease(['900', '901']))->pluck('id')->sort()->values()->all())
        ->toBe([$primary->id, $secondary->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leases/LeaseExpiryRecipientsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/Leases/LeaseExpiryRecipients.php`:

```php
<?php

namespace App\Services\Leases;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The portal accounts a lease's reminder goes to: every owner of its
 * portfolio, and every tenant named on it who has a login.
 *
 * Owners are matched the way the portal decides which properties an owner
 * may see — through the portfolios on their Propertyware contact — so the
 * reminder reaches exactly the owners who can open that property.
 */
class LeaseExpiryRecipients
{
    /** @var array<int, array<int, ClientAccount>>|null portfolio id → owner accounts, built once per run */
    private ?array $ownersByPortfolio = null;

    public function __construct(private PropertywareDirectory $directory) {}

    /**
     * @return Collection<int, ClientAccount>
     */
    public function owners(ExpiringLease $lease): Collection
    {
        return collect($this->ownersByPortfolio()[$lease->portfolioId] ?? []);
    }

    /**
     * @return Collection<int, ClientAccount>
     */
    public function tenants(ExpiringLease $lease): Collection
    {
        if ($lease->tenantContactIds === []) {
            return collect();
        }

        return ClientAccount::query()
            ->where('role', ClientRole::Resident)
            ->whereIn('propertyware_id', $lease->tenantContactIds)
            ->with('user')
            ->get();
    }

    /**
     * @return array<int, array<int, ClientAccount>>
     */
    private function ownersByPortfolio(): array
    {
        if ($this->ownersByPortfolio !== null) {
            return $this->ownersByPortfolio;
        }

        $map = [];

        foreach (ClientAccount::query()->where('role', ClientRole::Owner)->with('user')->get() as $account) {
            try {
                $contact = $this->directory->findContact($account->propertyware_id);
            } catch (PropertywareException $exception) {
                // Skipped today, tried again tomorrow for any lease still waiting.
                Log::warning('Could not read an owner contact for lease reminders.', [
                    'client_account_id' => $account->id,
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($contact?->role !== ClientRole::Owner) {
                continue;
            }

            foreach ($contact->portfolioIds as $portfolioId) {
                $map[$portfolioId][] = $account;
            }
        }

        return $this->ownersByPortfolio = $map;
    }
}
```

Note: a skipped owner is not retried for leases already recorded that day. That is acceptable for a reminder; the log line makes it visible.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Leases/LeaseExpiryRecipientsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Leases/LeaseExpiryRecipients.php tests/Feature/Leases/LeaseExpiryRecipientsTest.php
git commit -m "Find the owners and tenants a lease reminder goes to

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The wording (tx-client)

**Files:**

- Create: `app/Services/Leases/LeaseNoticeText.php`
- Test: `tests/Feature/Leases/LeaseNoticeTextTest.php`

**Interfaces:**

- Consumes: `ExpiringLease` (Task 2), `ClientAccount` (id, role, `user->name`).
- Produces: `LeaseNoticeText::for(ExpiringLease $lease, ClientAccount $account, CarbonImmutable $today): array` returning keys `type` ('lease_expiring'), `role` ('owner'|'resident'), `account_id`, `lease_id`, `building_id`, `building`, `unit`, `start_date` (Y-m-d|null), `end_date` (Y-m-d), `renewal_deadline` (Y-m-d), `days_left` (int), `title`, `body`, `message`, `url`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leases/LeaseNoticeTextTest.php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Leases\ExpiringLease;
use App\Services\Leases\LeaseNoticeText;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->lease = new ExpiringLease(55, 2, 7, '7734 Park Falls', '7734 Park Falls', CarbonImmutable::parse('2024-12-27'), CarbonImmutable::parse('2026-12-30'), ['900', '901']);
    $this->today = CarbonImmutable::parse('2026-10-01');
});

test('the owner is told about the property and the dates, nothing about who lives there', function () {
    $owner = ClientAccount::factory()->owner()->for(User::factory()->create(['name' => 'Olivia Owner']))->create();

    $notice = LeaseNoticeText::for($this->lease, $owner, $this->today);

    expect($notice)->toMatchArray([
        'type' => 'lease_expiring',
        'role' => 'owner',
        'account_id' => $owner->id,
        'lease_id' => 55,
        'building_id' => 7,
        'end_date' => '2026-12-30',
        'renewal_deadline' => '2026-10-31',
        'days_left' => 90,
        'title' => 'Lease ending at 7734 Park Falls',
        'body' => "The lease ends on Dec 30, 2026, 90 days from now. It's time to decide whether to renew it or list the property on the market.",
        'url' => route('owner.properties.show', 7),
    ]);

    expect($notice['message'])->toContain('Lease Expiration Date: 12/30/2026')
        ->and($notice['message'])->toContain('Building Name: 7734 Park Falls')
        ->and(json_encode($notice))->not->toContain('Olivia')
        ->and($notice)->not->toHaveKey('greeting_name');
});

test('the tenant gets the office letter, addressed to them', function () {
    $tenant = ClientAccount::factory()->resident()->for(User::factory()->create(['name' => 'Grayling Vital']))->create();

    $notice = LeaseNoticeText::for($this->lease, $tenant, $this->today);

    expect($notice['role'])->toBe('resident')
        ->and($notice['title'])->toBe('Your lease ends Dec 30, 2026')
        ->and($notice['body'])->toBe('To renew, your documents must be submitted and approved by Oct 31, 2026. After that, the home goes back on the market.')
        ->and($notice['url'])->toBe(route('resident.lease'))
        ->and($notice['message'])->toBe(<<<'TEXT'
            Dear Grayling Vital,

            This notification is to inform you that your lease will be expiring soon. The details of your current lease are shown below:

            Building Name: 7734 Park Falls
            Unit Name: 7734 Park Falls
            Lease Start Date: 12/27/2024
            Lease Expiration Date: 12/30/2026

            Per your lease agreement, your home will be placed back on the market 60 days before the lease ends. If you've been offered a renewal or extension, all required documents must be submitted and approved at least 60 days prior to your lease end date.

            Best regards,
            The TexasRenters.com Property Management Team
            TEXT);
});

test('a lease with no start date leaves that line out', function () {
    $lease = new ExpiringLease(55, 2, 7, 'A', 'A', null, CarbonImmutable::parse('2026-12-30'), []);
    $tenant = ClientAccount::factory()->resident()->create();

    expect(LeaseNoticeText::for($lease, $tenant, $this->today)['message'])->not->toContain('Lease Start Date');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leases/LeaseNoticeTextTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/Leases/LeaseNoticeText.php`:

```php
<?php

namespace App\Services\Leases;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use Carbon\CarbonImmutable;

/**
 * Turns an expiring lease into what one person reads, on the lock screen and
 * in the app's notification list.
 *
 * The only place this wording is built. The owner's version is assembled from
 * the property and the dates alone; the tenant's is the office's letter,
 * addressed to the tenant reading it and to nobody else on the lease.
 */
final class LeaseNoticeText
{
    /**
     * @return array<string, mixed>
     */
    public static function for(ExpiringLease $lease, ClientAccount $account, CarbonImmutable $today): array
    {
        $owner = $account->role === ClientRole::Owner;
        $days = $lease->daysLeft($today);
        $end = $lease->endDate->format('M j, Y');

        $details = implode("\n", array_filter([
            "Building Name: {$lease->building}",
            "Unit Name: {$lease->unit}",
            $lease->startDate === null ? null : 'Lease Start Date: '.$lease->startDate->format('m/d/Y'),
            'Lease Expiration Date: '.$lease->endDate->format('m/d/Y'),
        ]));

        if ($owner) {
            $title = "Lease ending at {$lease->building}";
            $body = "The lease ends on {$end}, {$days} days from now. It's time to decide whether to renew it or list the property on the market.";
            $message = "{$body}\n\n{$details}";
            $url = route('owner.properties.show', $lease->buildingId);
        } else {
            $deadline = $lease->renewalDeadline()->format('M j, Y');
            $title = "Your lease ends {$end}";
            $body = "To renew, your documents must be submitted and approved by {$deadline}. After that, the home goes back on the market.";
            $message = implode("\n\n", [
                'Dear '.trim((string) $account->user?->name).',',
                "This notification is to inform you that your lease will be expiring soon. The details of your current lease are shown below:\n\n{$details}",
                "Per your lease agreement, your home will be placed back on the market 60 days before the lease ends. If you've been offered a renewal or extension, all required documents must be submitted and approved at least 60 days prior to your lease end date.",
                "Best regards,\nThe TexasRenters.com Property Management Team",
            ]);
            $url = route('resident.lease');
        }

        return [
            'type' => 'lease_expiring',
            'role' => $account->role->value,
            'account_id' => $account->id,
            'lease_id' => $lease->id,
            'building_id' => $lease->buildingId,
            'building' => $lease->building,
            'unit' => $lease->unit,
            'start_date' => $lease->startDate?->toDateString(),
            'end_date' => $lease->endDate->toDateString(),
            'renewal_deadline' => $lease->renewalDeadline()->toDateString(),
            'days_left' => $days,
            'title' => $title,
            'body' => $body,
            'message' => $message,
            'url' => $url,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Leases/LeaseNoticeTextTest.php`
Expected: PASS. (If the heredoc indentation differs, Pest's closing-marker indentation strips it — keep the closing `TEXT);` aligned with the body.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Leases/LeaseNoticeText.php tests/Feature/Leases/LeaseNoticeTextTest.php
git commit -m "Word the lease reminder for owners and tenants

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The notification (tx-client)

**Files:**

- Create: `app/Notifications/LeaseExpiringNotification.php`
- Test: `tests/Feature/Leases/LeaseExpiringNotificationTest.php`

**Interfaces:**

- Consumes: notice array from `LeaseNoticeText::for` (Task 4); `ExpoChannel`, `PushMessage(title, body, data, channelId)`.
- Produces: `new LeaseExpiringNotification(array $notice)`; stored `data` = the notice array; push `data` = `['type' => 'lease_expiring', 'accountId', 'leaseId', 'buildingId', 'notificationId', 'url']`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Leases/LeaseExpiringNotificationTest.php

use App\Models\PushToken;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function leaseNotice(array $overrides = []): array
{
    return ['type' => 'lease_expiring', 'role' => 'resident', 'account_id' => 42, 'lease_id' => 55, 'building_id' => 7,
        'title' => 'Your lease ends Dec 30, 2026', 'body' => 'To renew…', 'message' => 'Dear…', 'url' => 'https://portal.test/resident/lease', ...$overrides];
}

test('is kept in the list and pushed to every phone', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]])]);
    $user = User::factory()->create();
    PushToken::factory()->for($user)->create(['token' => 'ExponentPushToken[one]']);

    $user->notify(new LeaseExpiringNotification(leaseNotice()));

    $stored = $user->notifications()->sole();
    expect($stored->data)->toMatchArray(['type' => 'lease_expiring', 'account_id' => 42, 'title' => 'Your lease ends Dec 30, 2026']);

    Http::assertSent(function (Request $request) use ($stored) {
        $push = $request->data()[0];

        return $push['title'] === 'Your lease ends Dec 30, 2026'
            && $push['data'] === ['type' => 'lease_expiring', 'accountId' => 42, 'leaseId' => 55, 'buildingId' => 7, 'notificationId' => $stored->id, 'url' => 'https://portal.test/resident/lease'];
    });
});

test('with lease reminders switched off the phone stays quiet but the list still has it', function () {
    Http::fake();
    $user = User::factory()->create(['notification_preferences' => ['lease_expiry_push' => false]]);
    PushToken::factory()->for($user)->create();

    $user->notify(new LeaseExpiringNotification(leaseNotice()));

    expect($user->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leases/LeaseExpiringNotificationTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Notifications/LeaseExpiringNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * A lease is 90 days from ending. Always kept in the client's notification
 * list; pushed to their phones unless they turned lease reminders off.
 *
 * Sent straight from the daily command, not queued: the command records the
 * lease only after this returns, so a failure leaves it for tomorrow.
 */
class LeaseExpiringNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $notice  built by LeaseNoticeText
     */
    public function __construct(public array $notice) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable instanceof User && $notifiable->wantsNotification('lease_expiry_push');

        return $push ? ['database', ExpoChannel::class] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->notice;
    }

    /**
     * `notificationId` lets the app open this notice itself; `accountId`
     * switches to the account it belongs to first.
     */
    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->notice['title'],
            body: $this->notice['body'],
            data: [
                'type' => 'lease_expiring',
                'accountId' => $this->notice['account_id'],
                'leaseId' => $this->notice['lease_id'],
                'buildingId' => $this->notice['building_id'],
                'notificationId' => $this->id,
                'url' => $this->notice['url'],
            ],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Leases/LeaseExpiringNotificationTest.php tests/Feature/PushNotificationTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Notifications/LeaseExpiringNotification.php tests/Feature/Leases/LeaseExpiringNotificationTest.php
git commit -m "Keep and push the lease reminder

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The daily command (tx-client)

**Files:**

- Create: `app/Console/Commands/NotifyExpiringLeases.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php`

**Interfaces:**

- Consumes: `ExpiringLeases::between` (Task 2), `LeaseExpiryRecipients::owners/tenants` (Task 3), `LeaseNoticeText::for` (Task 4), `LeaseExpiringNotification` (Task 5), `LeaseExpiryNotice` (Task 1).
- Produces: `php artisan leases:notify-expiring [--seed]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php

use App\Models\ClientAccount;
use App\Models\LeaseExpiryNotice;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-03 09:00:00');
    config(['services.propertyware.discover_accounts' => false]);
    $this->leases = [];
    $this->buildingsFail = false;

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/leases') => Http::response($this->leases),
            str_contains($url, '/buildings/') => $this->buildingsFail ? Http::response([], 500) : Http::response(['id' => 7, 'name' => '7734 Park Falls']),
            str_contains($url, '/units/') => Http::response([]),
            str_contains($url, '/contacts/500') => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'P']]]),
            default => Http::response([], 404),
        };
    });

    $this->owner = ClientAccount::factory()->owner()->for(User::factory()->create())->create(['propertyware_id' => '500']);
    $this->tenant = ClientAccount::factory()->resident()->for(User::factory()->create())->create(['propertyware_id' => '900']);
});

function activeLease(int $id, string $endDate, array $overrides = []): array
{
    return ['id' => $id, 'status' => 'Active', 'portfolioID' => 2, 'buildingID' => 7, 'unitID' => null, 'endDate' => $endDate, 'startDate' => '2025-01-01',
        'contacts' => [['id' => 900, 'role' => 'Primary', 'namedOnLease' => true]], ...$overrides];
}

test('tells the owner and the tenant once, and records the lease', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01')];

    $this->artisan('leases:notify-expiring')->assertSuccessful();
    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->owner->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentTo($this->owner->user, fn (LeaseExpiringNotification $n) => $n->notice['role'] === 'owner' && $n->notice['account_id'] === $this->owner->id);

    $record = LeaseExpiryNotice::sole();
    expect($record->propertyware_lease_id)->toBe(55)
        ->and($record->recipients)->toBe(2)
        ->and($record->notified_at)->not->toBeNull();
});

test('asks for leases ending between today and 90 days out', function () {
    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/leases')
        && $request->data()['endDateStart'] === '2026-10-03'
        && $request->data()['endDateEnd'] === '2027-01-01');
});

test('an extended lease is reminded again before its new end date', function () {
    Notification::fake();
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2026-06-01', 'notified_at' => now()->subYear(), 'recipients' => 2]);
    $this->leases = [activeLease(55, '2027-01-01')];

    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
});

test('the first run tells only leases 80 to 90 days out and quietly records the rest', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01'), activeLease(56, '2026-11-01')];

    $this->artisan('leases:notify-expiring', ['--seed' => true])->assertSuccessful();

    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
    expect(LeaseExpiryNotice::where('propertyware_lease_id', 56)->sole()->notified_at)->toBeNull();

    // Recorded, so the normal daily run leaves it alone too.
    $this->artisan('leases:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
});

test('when Propertyware fails nothing is recorded, so tomorrow tries again', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01')];
    $this->buildingsFail = true;

    $this->artisan('leases:notify-expiring')->assertFailed();

    Notification::assertNothingSent();
    expect(LeaseExpiryNotice::count())->toBe(0);
});

test('runs every morning at nine, Houston time', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'leases:notify-expiring'));

    expect($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('America/Chicago')
        ->and($event->withoutOverlapping)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php`
Expected: FAIL — command `leases:notify-expiring` not defined.

- [ ] **Step 3: Implement**

`app/Console/Commands/NotifyExpiringLeases.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\LeaseExpiryNotice;
use App\Notifications\LeaseExpiringNotification;
use App\Services\Leases\ExpiringLeases;
use App\Services\Leases\LeaseExpiryRecipients;
use App\Services\Leases\LeaseNoticeText;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leases:notify-expiring {--seed : First run: tell only leases 80–90 days out and record the rest without telling anyone}')]
#[Description('Tell owners and tenants about leases ending within 90 days')]
class NotifyExpiringLeases extends Command
{
    /**
     * Leases ending anywhere inside the next 90 days are considered, not only
     * those on day 90, so a morning the job did not run is caught up the next.
     * The record stops anyone hearing about the same lease twice.
     */
    public function handle(ExpiringLeases $expiring, LeaseExpiryRecipients $recipients): int
    {
        $today = CarbonImmutable::today();

        try {
            $leases = $expiring->between($today, $today->addDays(90));
        } catch (PropertywareException $exception) {
            $this->error("Could not read leases from Propertyware: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $done = LeaseExpiryNotice::query()
            ->whereIn('propertyware_lease_id', array_map(fn ($lease) => $lease->id, $leases))
            ->get()
            ->map(fn (LeaseExpiryNotice $record): string => $record->propertyware_lease_id.'@'.$record->end_date->toDateString())
            ->all();

        $sent = 0;
        $quiet = 0;

        foreach ($leases as $lease) {
            if (in_array($lease->id.'@'.$lease->endDate->toDateString(), $done, strict: true)) {
                continue;
            }

            if ($this->option('seed') && $lease->daysLeft($today) < 80) {
                LeaseExpiryNotice::create(['propertyware_lease_id' => $lease->id, 'end_date' => $lease->endDate, 'recipients' => 0]);
                $quiet++;

                continue;
            }

            $accounts = $recipients->owners($lease)->concat($recipients->tenants($lease))
                ->filter(fn ($account) => $account->user !== null);

            foreach ($accounts as $account) {
                $account->user->notify(new LeaseExpiringNotification(LeaseNoticeText::for($lease, $account, $today)));
            }

            LeaseExpiryNotice::create([
                'propertyware_lease_id' => $lease->id,
                'end_date' => $lease->endDate,
                'notified_at' => now(),
                'recipients' => $accounts->count(),
            ]);
            $sent++;
        }

        $this->info("Sent reminders for {$sent} lease(s); recorded {$quiet} without a reminder.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — after the `approvals:push` line:

```php
Schedule::command('leases:notify-expiring')->dailyAt('09:00')->timezone('America/Chicago')->withoutOverlapping();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Console/Commands/NotifyExpiringLeases.php routes/console.php tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php
git commit -m "Remind owners and tenants every morning about leases ending within 90 days

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Notifications API for the app (tx-client)

**Files:**

- Create: `app/Services/Notifications/NeedsAttention.php`
- Modify: `app/Http/Controllers/Client/NotificationController.php` (replace private `unreadMessages` / `summary` with `NeedsAttention`)
- Create: `app/Http/Controllers/Api/V1/NotificationController.php`
- Modify: `routes/api.php` (inside the `accounts/{account}` group)
- Test: `tests/Feature/Api/V1/NotificationTest.php`

**Interfaces:**

- Produces:
    - `NeedsAttention::messages(User $user, ?ClientAccount $account, int $limit): ?array{count:int, items:array}`, `NeedsAttention::approvals(?ClientAccount $owner, int $limit): ?array{count:int, items:array}`, `NeedsAttention::notices(User $user, ClientAccount $account): Builder` (database notifications of type `lease_expiring` for that account, newest first).
    - Routes: `api.v1.accounts.notifications.index` (GET `accounts/{account}/notifications`), `api.v1.accounts.notifications.read` (POST `…/notifications/{notification}/read`), `api.v1.accounts.notifications.read-all` (POST `…/notifications/read-all`).
    - Index JSON: `{ data: Notice[], meta: { unread: int, messages: Section|null, approvals: Section|null } }` where `Notice = { id: string, type: 'lease_expiring', title, body, message, building, unit, startDate, endDate, renewalDeadline, buildingId, role, readAt: string|null, createdAt: string }`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Api/V1/NotificationTest.php

use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false, 'services.chat_hub.url' => null]);
    Http::fake(['*' => Http::response([])]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->tenant = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '900']);
    $this->owner = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
});

function noticeFor(ClientAccount $account, string $title): void
{
    $account->user->notify(new LeaseExpiringNotification([
        'type' => 'lease_expiring', 'role' => $account->role->value, 'account_id' => $account->id, 'lease_id' => 55, 'building_id' => 7,
        'building' => '7734 Park Falls', 'unit' => '7734 Park Falls', 'start_date' => '2024-12-27', 'end_date' => '2026-12-30',
        'renewal_deadline' => '2026-10-31', 'days_left' => 90, 'title' => $title, 'body' => 'b', 'message' => 'm', 'url' => 'https://x',
    ]));
}

test('lists only the notices for the account asked about, with an unread count', function () {
    noticeFor($this->tenant, 'Tenant notice');
    noticeFor($this->owner, 'Owner notice');

    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Tenant notice')
        ->assertJsonPath('data.0.type', 'lease_expiring')
        ->assertJsonPath('data.0.endDate', '2026-12-30')
        ->assertJsonPath('data.0.readAt', null)
        ->assertJsonPath('meta.unread', 1);
});

test('reads one notice, then all of them', function () {
    noticeFor($this->tenant, 'One');
    noticeFor($this->tenant, 'Two');
    $id = $this->user->notifications()->latest()->first()->id;

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, $id]))->assertNoContent();
    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertJsonPath('meta.unread', 1);

    $this->postJson(route('api.v1.accounts.notifications.read-all', $this->tenant))->assertNoContent();
    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertJsonPath('meta.unread', 0);
});

test('a notice from another account cannot be read through this one', function () {
    noticeFor($this->owner, 'Owner notice');
    $id = $this->user->notifications()->sole()->id;

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, $id]))->assertNotFound();
});

test('another user cannot list this account', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/V1/NotificationTest.php`
Expected: FAIL — route `api.v1.accounts.notifications.index` not defined.

- [ ] **Step 3: Implement**

`app/Services/Notifications/NeedsAttention.php` — the two helpers moved out of `Client\NotificationController`, plus the notice query:

```php
<?php

namespace App\Services\Notifications;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What the bell counts, for the web portal and the app alike: unread replies,
 * work orders waiting for an owner, and stored notices such as lease
 * reminders. A section is null when its source could not be read.
 */
class NeedsAttention
{
    public function __construct(
        private ClientConversations $conversations,
        private PropertywareDirectory $directory,
        private WorkOrderApprovals $approvals,
    ) {}

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    public function messages(User $user, ?ClientAccount $account, int $limit): ?array
    {
        if ($account === null || ! $this->conversations->isAvailable()) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $this->conversations->unread($user, $account, $limit);
        } catch (ChatHubException) {
            return null;
        }
    }

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    public function approvals(?ClientAccount $owner, int $limit): ?array
    {
        if ($owner === null) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $this->approvals->summaryFor($this->directory->scopeFor($owner), $owner, $limit);
        } catch (PropertywareException) {
            return null;
        }
    }

    /**
     * The stored notices that belong to one of the user's accounts, newest first.
     */
    public function notices(User $user, ClientAccount $account): MorphMany
    {
        return $user->notifications()
            ->where('data->type', 'lease_expiring')
            ->where('data->account_id', $account->id);
    }

    /**
     * A stored notice as both clients read it.
     *
     * @return array<string, mixed>
     */
    public static function present(\Illuminate\Notifications\DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'],
            'role' => $data['role'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'],
            'message' => $data['message'],
            'building' => $data['building'] ?? null,
            'unit' => $data['unit'] ?? null,
            'buildingId' => $data['building_id'] ?? null,
            'startDate' => $data['start_date'] ?? null,
            'endDate' => $data['end_date'] ?? null,
            'renewalDeadline' => $data['renewal_deadline'] ?? null,
            'url' => $data['url'] ?? null,
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/Client/NotificationController.php` — inject `NeedsAttention` in place of the three services, delete the private `unreadMessages` and `summary` methods, and call `$attention->messages($request->user(), $account, 25)` / `$attention->approvals($owner, 50)` (index) and `…, 5` (latest). Behaviour is unchanged; the existing `tests/Feature/MessagesTest.php` and `tests/Feature/Owner/WorkOrderApprovalTest.php` bell tests must still pass. (Notices are added to this controller in Task 8.)

`app/Http/Controllers/Api/V1/NotificationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Notifications\NeedsAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    /**
     * The app's bell: stored notices for this account, plus the unread replies
     * and approvals the web bell counts.
     */
    public function index(Request $request, ClientAccount $account, NeedsAttention $attention): JsonResponse
    {
        $notices = $attention->notices($request->user(), $account)->latest()->limit(50)->get();
        $messages = $attention->messages($request->user(), $account, 25);
        $approvals = $attention->approvals($account->role === ClientRole::Owner ? $account : null, 50);

        return response()->json([
            'data' => $notices->map(fn ($notice) => NeedsAttention::present($notice))->values(),
            'meta' => [
                'unread' => $attention->notices($request->user(), $account)->whereNull('read_at')->count()
                    + ($messages['count'] ?? 0) + ($approvals['count'] ?? 0),
                'messages' => $messages,
                'approvals' => $approvals,
            ],
        ]);
    }

    public function read(Request $request, ClientAccount $account, string $notification, NeedsAttention $attention): Response
    {
        $attention->notices($request->user(), $account)->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    public function readAll(Request $request, ClientAccount $account, NeedsAttention $attention): Response
    {
        $attention->notices($request->user(), $account)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }
}
```

`routes/api.php` — inside the `accounts/{account}` group, after `approvals` (import the class as `NotificationController as ApiNotificationController` if the name clashes):

```php
            Route::get('notifications', [ApiNotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [ApiNotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [ApiNotificationController::class, 'read'])->name('notifications.read');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/NotificationTest.php tests/Feature/MessagesTest.php tests/Feature/Owner/WorkOrderApprovalTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Notifications app/Http/Controllers/Client/NotificationController.php app/Http/Controllers/Api/V1/NotificationController.php routes/api.php tests/Feature/Api/V1/NotificationTest.php
git commit -m "Give the app its notification list, and share what the bell counts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Lease notices in the web bell and Notifications page (tx-client)

**Files:**

- Modify: `app/Http/Controllers/Client/NotificationController.php`
- Modify: `routes/client.php` (~line 111)
- Modify: `resources/js/components/NotificationBell.vue`
- Modify: `resources/js/pages/client/Notifications.vue`
- Modify: `resources/js/types/portal.ts` (add `Notice` type)
- Test: `tests/Feature/Client/LeaseNoticeWebTest.php`

**Interfaces:**

- Consumes: `NeedsAttention::notices`, `NeedsAttention::present` (Task 7).
- Produces: `latest()` JSON gains `notices: { count: int, items: Notice[] }`, `count` includes unread notices; `index()` props gain `notices`; route `notifications.read` (POST `notifications/{notification}/read`) redirecting to the notice's `url`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Client/LeaseNoticeWebTest.php

use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);
    Http::fake(['*' => Http::response([])]);
    $this->user = User::factory()->create();
    $this->tenant = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '900']);
    $this->user->notify(new LeaseExpiringNotification([
        'type' => 'lease_expiring', 'role' => 'resident', 'account_id' => $this->tenant->id, 'lease_id' => 55, 'building_id' => 7,
        'title' => 'Your lease ends Dec 30, 2026', 'body' => 'b', 'message' => 'm', 'url' => route('resident.lease'),
    ]));
});

test('the bell counts and lists an unread lease notice', function () {
    $this->actingAs($this->user)->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notices.count', 1)
        ->assertJsonPath('notices.items.0.title', 'Your lease ends Dec 30, 2026')
        ->assertJsonPath('count', 1);
});

test('opening a notice marks it read and goes where it points', function () {
    $id = $this->user->notifications()->sole()->id;

    $this->actingAs($this->user)->post(route('notifications.read', $id))
        ->assertRedirect(route('resident.lease'));

    expect($this->user->notifications()->sole()->read_at)->not->toBeNull();
});
```

(Use the same "act as a verified client with a chosen portal" helper that `tests/Feature/MessagesTest.php` uses around line 490 if `actingAs` alone does not pick the resident account.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Client/LeaseNoticeWebTest.php`
Expected: FAIL — `notices` missing / route `notifications.read` not defined.

- [ ] **Step 3: Implement**

`Client\NotificationController::latest()` — add before building the response:

```php
        $notices = $account === null ? collect() : $attention->notices($request->user(), $account)->whereNull('read_at')->latest()->get();
```

and in the JSON: `'count' => … + $notices->count()`, plus

```php
            'notices' => ['count' => $notices->count(), 'items' => $notices->take(5)->map(fn ($n) => NeedsAttention::present($n))->values()],
```

`index()` — add prop:

```php
            'notices' => $account === null ? [] : $attention->notices($request->user(), $account)->latest()->limit(50)->get()
                ->map(fn ($n) => NeedsAttention::present($n))->values(),
```

New action:

```php
    /**
     * Open a stored notice: mark it read and go to what it is about.
     */
    public function read(Request $request, string $notification, NeedsAttention $attention): RedirectResponse
    {
        $account = $this->currentClientAccount($request);
        abort_if($account === null, 404);

        $notice = $attention->notices($request->user(), $account)->whereKey($notification)->firstOrFail();
        $notice->markAsRead();

        return redirect()->to($notice->data['url'] ?? route('notifications.index'));
    }
```

`routes/client.php` — next to `notifications.latest`:

```php
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
```

`resources/js/types/portal.ts` — add:

```ts
export type Notice = {
    id: string;
    type: 'lease_expiring';
    role: 'owner' | 'resident' | null;
    title: string;
    body: string;
    message: string;
    building: string | null;
    unit: string | null;
    buildingId: number | null;
    startDate: string | null;
    endDate: string | null;
    renewalDeadline: string | null;
    url: string | null;
    readAt: string | null;
    createdAt: string | null;
};
```

`NotificationBell.vue` — add `notices: Section<Notice>` to `Latest`, import `CalendarClock` from `@lucide/vue` and `read as readNotice` from `@/routes/notifications` (regenerate Wayfinder routes with `php artisan wayfinder:generate` if they are not auto-generated on build). Insert this block first inside `<template v-else>`:

```vue
<template v-if="latest.notices && latest.notices.count > 0">
    <DropdownMenuLabel class="text-muted-foreground text-xs font-normal"
        >Reminders ({{ latest.notices.count }})</DropdownMenuLabel
    >
    <DropdownMenuItem
        v-for="notice in latest.notices.items"
        :key="`notice-${notice.id}`"
        as-child
    >
        <Link
            :href="readNotice(notice.id)"
            method="post"
            as="button"
            class="flex w-full cursor-pointer items-start gap-3 text-left"
        >
            <CalendarClock
                class="text-primary mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <span class="flex min-w-0 flex-col">
                <span class="truncate font-medium">{{ notice.title }}</span>
                <span class="text-muted-foreground line-clamp-2 text-xs">{{
                    notice.body
                }}</span>
            </span>
        </Link>
    </DropdownMenuItem>
    <DropdownMenuSeparator
        v-if="
            (latest.messages?.count ?? 0) + (latest.approvals?.count ?? 0) > 0
        "
    />
</template>
```

`client/Notifications.vue` — add a `notices: Notice[]` prop and a "Reminders" card above the existing sections listing each notice's `title`, `body`, and `message` (with `whitespace-pre-line`), unread ones with a dot, each linking via `readNotice(notice.id)` as a POST `Link` (same markup pattern as the existing message rows in that file).

- [ ] **Step 4: Run tests and build**

Run: `php artisan test tests/Feature/Client/LeaseNoticeWebTest.php tests/Feature/MessagesTest.php && npm run build && npm run lint`
Expected: PASS, build and lint clean. Then open `/notifications` in the browser as a seeded tenant to see the card.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Client/NotificationController.php routes/client.php resources/js tests/Feature/Client/LeaseNoticeWebTest.php
git commit -m "Show lease reminders in the portal's bell and Notifications page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
graphify update .
```

---

### Task 9: Mobile data layer, push routing and the setting (tx-mobile)

Work on a branch: `git switch -c feature/lease-expiry-notifications` in `tx-mobile`.

**Files:**

- Modify: `src/api/types.ts` (near `PushPayload` ~line 478 and `NotificationPreferences` ~line 467)
- Modify: `src/api/queries.ts`
- Modify: `src/notifications/useNotifications.ts`
- Modify: `src/app/(app)/account/notifications.tsx`

**Interfaces:**

- Consumes: API from Task 7.
- Produces: types `Notice`, `NotificationsPage`; hooks `useNotificationsList(accountId: number): UseQueryResult<NotificationsPage | null>` (query key `['notifications', accountId]`; `null` = older portal), `useReadNotification(accountId: number): UseMutationResult<unknown, Error, string>`, `useReadAllNotifications(accountId: number): UseMutationResult<unknown, Error, void>`; route `/notifications/[id]`.

- [ ] **Step 1: Types** — in `src/api/types.ts`:

```ts
/** A stored notice from the portal, e.g. a lease that ends in 90 days. */
export type Notice = {
    id: string;
    type: 'lease_expiring';
    role: 'owner' | 'resident' | null;
    title: string;
    body: string;
    /** The full text, line breaks included. */
    message: string;
    building: string | null;
    unit: string | null;
    buildingId: number | null;
    startDate: string | null;
    endDate: string | null;
    renewalDeadline: string | null;
    readAt: string | null;
    createdAt: string | null;
};

type Section<T> = { count: number; items: T[] } | null;

export type NotificationsPage = {
    data: Notice[];
    meta: {
        unread: number;
        messages: Section<ChatThread>;
        approvals: Section<WorkOrder>;
    };
};
```

Extend `PushPayload`: `type?: 'message' | 'work_order_approval' | 'lease_expiring' | string;` and add `notificationId?: string; buildingId?: number;`. Add `lease_expiry_push: boolean;` to `NotificationPreferences['data']`.

- [ ] **Step 2: Queries** — in `src/api/queries.ts`, after the approvals hooks:

```ts
/* Notifications ----------------------------------------------------------- */

/**
 * The bell's list. An older portal answers 404; that is reported as `null`
 * so the bell hides instead of showing an error.
 */
export function useNotificationsList(
    accountId: number,
): UseQueryResult<NotificationsPage | null> {
    return useQuery({
        enabled: accountId > 0,
        queryKey: ['notifications', accountId],
        queryFn: async ({ signal }) => {
            try {
                return await request<NotificationsPage>(
                    `accounts/${accountId}/notifications`,
                    { signal },
                );
            } catch (error) {
                if (error instanceof ApiError && error.status === 404) {
                    return null;
                }

                throw error;
            }
        },
    });
}

export function useReadNotification(
    accountId: number,
): UseMutationResult<unknown, Error, string> {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (id) =>
            request(`accounts/${accountId}/notifications/${id}/read`, {
                method: 'POST',
            }),
        onSuccess: () =>
            void client.invalidateQueries({
                queryKey: ['notifications', accountId],
            }),
    });
}

export function useReadAllNotifications(
    accountId: number,
): UseMutationResult<unknown, Error, void> {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () =>
            request(`accounts/${accountId}/notifications/read-all`, {
                method: 'POST',
            }),
        onSuccess: () =>
            void client.invalidateQueries({
                queryKey: ['notifications', accountId],
            }),
    });
}
```

(Import `ApiError` from `./errors` and the new types if `queries.ts` does not already. Check that `request` resolves an empty 204 body without throwing; if it tries to parse JSON, pass whatever option it uses for empty responses.)

- [ ] **Step 3: Push routing** — in `src/notifications/useNotifications.ts`:
    - Add `| { screen: 'notice'; id: string }` to `Destination`.
    - In `destinationOf`, first:

```ts
if (
    payload.type === 'lease_expiring' &&
    typeof payload.notificationId === 'string'
) {
    return { screen: 'notice', id: payload.notificationId };
}
```

- In `go`, first:

```ts
if (destination.screen === 'notice') {
    router.push({
        pathname: '/notifications/[id]',
        params: { id: destination.id },
    });

    return;
}
```

- In `refresh`, add `void queryClient.invalidateQueries({ queryKey: ['notifications', accountId] });`.

- [ ] **Step 4: Setting** — in `src/app/(app)/account/notifications.tsx`, inside the granted `rows` view after the approvals row:

```tsx
<Row
    label="Lease ending reminders"
    hint={
        owner
            ? 'Ninety days before a lease on your property ends, so you can renew or relist in time.'
            : 'Ninety days before your lease ends, so you have time to renew.'
    }
    value={data.data.lease_expiry_push ?? true}
    disabled={update.isPending}
    onChange={(value) => update.mutate({ lease_expiry_push: value })}
/>
```

- [ ] **Step 5: Verify** — `npx tsc --noEmit` will fail until Task 10 adds `/notifications/[id]` (typed routes). Run `npx expo lint` now; run tsc at the end of Task 10. Commit together with Task 10.

---

### Task 10: Mobile bell and Notifications screens (tx-mobile)

**Files:**

- Create: `src/components/NotificationBell.tsx`
- Create: `src/app/notifications/_layout.tsx`, `src/app/notifications/index.tsx`, `src/app/notifications/[id].tsx`
- Modify: `src/app/_layout.tsx` (~line 180, signed-in guard)
- Modify: `src/app/(app)/index.tsx` (~line 97, header `right`)

**Interfaces:**

- Consumes: `useNotificationsList`, `useReadNotification`, `useReadAllNotifications`, `Notice` (Task 9); `Screen`, `Card`, `EmptyState`, `ErrorState`, `SkeletonDetails`, `Button` from `src/components/ui`; `formatDate` from `@/lib/format` if present (otherwise use `new Date(…).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })`).

- [ ] **Step 1: Register the stack** — `src/app/notifications/_layout.tsx`:

```tsx
import { Stack } from 'expo-router';
import { colors } from '@/theme/theme';

export default function NotificationsLayout() {
    return (
        <Stack
            screenOptions={{
                headerShown: false,
                contentStyle: { backgroundColor: colors.canvas },
            }}
        />
    );
}
```

In `src/app/_layout.tsx`, inside the `Stack.Protected` that guards `(app)`:

```tsx
<Stack.Screen name="notifications" />
```

- [ ] **Step 2: The bell** — `src/components/NotificationBell.tsx`:

```tsx
/**
 * The bell in the Home header: the unread count across reminders, replies and
 * approvals, opening the Notifications screen. Hidden on a portal that does
 * not have the list yet.
 */
import { Pressable, StyleSheet, Text, View } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { useNotificationsList } from '@/api/queries';
import { colors, fontSize } from '@/theme/theme';

export function NotificationBell({ accountId }: { accountId: number }) {
    const { data } = useNotificationsList(accountId);

    if (data === null || data === undefined) {
        return null;
    }

    const unread = data.meta.unread;

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={
                unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'
            }
            hitSlop={10}
            onPress={() => router.push('/notifications')}
            style={styles.bell}
        >
            <Ionicons
                name="notifications-outline"
                size={22}
                color={colors.white}
            />
            {unread > 0 ? (
                <View style={styles.badge}>
                    <Text style={styles.badgeText}>
                        {unread > 99 ? '99+' : unread}
                    </Text>
                </View>
            ) : null}
        </Pressable>
    );
}

const styles = StyleSheet.create({
    bell: {
        width: 36,
        height: 36,
        alignItems: 'center',
        justifyContent: 'center',
    },
    badge: {
        position: 'absolute',
        top: 2,
        right: 0,
        minWidth: 16,
        height: 16,
        paddingHorizontal: 4,
        borderRadius: 8,
        backgroundColor: colors.destructive,
        alignItems: 'center',
        justifyContent: 'center',
    },
    badgeText: {
        color: colors.white,
        fontSize: fontSize.xxs,
        fontWeight: '700',
    },
});
```

In `src/app/(app)/index.tsx`, change the header `right` to show the bell and keep the switcher:

```tsx
          right={
            <View style={styles.headerActions}>
              {accounts.length > 1 ? (
                /* existing switcher Pressable, unchanged */
              ) : null}
              <NotificationBell accountId={account.id} />
            </View>
          }
```

with `headerActions: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm }` added to that file's styles.

- [ ] **Step 3: The list** — `src/app/notifications/index.tsx`:

```tsx
/**
 * Everything the bell counts: stored reminders first, then unread replies and
 * work orders waiting for approval, each opening its own screen.
 */
import {
    FlatList,
    Pressable,
    RefreshControl,
    StyleSheet,
    Text,
    View,
} from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { useNotificationsList, useReadAllNotifications } from '@/api/queries';
import { ApiError } from '@/api/errors';
import { useAuth } from '@/auth/auth-context';
import { Screen } from '@/components/ui/Screen';
import { EmptyState, ErrorState } from '@/components/ui/StateViews';
import { SkeletonDetails } from '@/components/ui/Skeleton';
import { colors, fontSize, radius, spacing } from '@/theme/theme';

type Row =
    | {
          key: string;
          kind: 'notice';
          id: string;
          title: string;
          detail: string;
          unread: boolean;
      }
    | {
          key: string;
          kind: 'message';
          thread: number;
          title: string;
          detail: string;
      }
    | {
          key: string;
          kind: 'approval';
          id: string;
          title: string;
          detail: string;
      };

export default function NotificationsScreen() {
    const { account } = useAuth();
    const accountId = account?.id ?? 0;
    const { data, error, isPending, isRefetching, refetch } =
        useNotificationsList(accountId);
    const readAll = useReadAllNotifications(accountId);

    const rows: Row[] = [
        ...(data?.data ?? []).map((notice): Row => ({
            key: `notice-${notice.id}`,
            kind: 'notice',
            id: notice.id,
            title: notice.title,
            detail: notice.body,
            unread: notice.readAt === null,
        })),
        ...(data?.meta.messages?.items ?? []).map((thread): Row => ({
            key: `message-${thread.id}`,
            kind: 'message',
            thread: thread.id,
            title: thread.subject,
            detail: thread.lastMessage
                ? `${thread.lastMessage.author}: ${thread.lastMessage.preview}`
                : 'New reply',
        })),
        ...(data?.meta.approvals?.items ?? []).map((order): Row => ({
            key: `approval-${order.id}`,
            kind: 'approval',
            id: order.id,
            title: 'Approval needed',
            detail:
                [order.category, order.property].filter(Boolean).join(' · ') ||
                order.title,
        })),
    ];

    const anyUnread = (data?.data ?? []).some(
        (notice) => notice.readAt === null,
    );

    function open(row: Row): void {
        if (row.kind === 'notice')
            router.push({
                pathname: '/notifications/[id]',
                params: { id: row.id },
            });
        if (row.kind === 'message')
            router.push({
                pathname: '/messages/[thread]',
                params: { thread: String(row.thread) },
            });
        if (row.kind === 'approval')
            router.push({
                pathname: '/maintenance/[id]',
                params: { id: row.id },
            });
    }

    return (
        <Screen
            title="Notifications"
            right={
                anyUnread ? (
                    <Pressable
                        accessibilityRole="button"
                        hitSlop={10}
                        onPress={() => readAll.mutate()}
                    >
                        <Text style={styles.markAll}>Mark all read</Text>
                    </Pressable>
                ) : undefined
            }
        >
            {isPending ? (
                <View style={styles.padded}>
                    <SkeletonDetails cards={3} rows={1} />
                </View>
            ) : error !== null ? (
                <ErrorState
                    message={
                        error instanceof ApiError
                            ? error.message
                            : 'Notifications could not be loaded.'
                    }
                    onRetry={() => void refetch()}
                />
            ) : (
                <FlatList
                    data={rows}
                    keyExtractor={(row) => row.key}
                    contentContainerStyle={
                        rows.length === 0 ? styles.emptyContent : styles.content
                    }
                    refreshControl={
                        <RefreshControl
                            refreshing={isRefetching}
                            onRefresh={() => void refetch()}
                            tintColor={colors.primary}
                        />
                    }
                    ListEmptyComponent={
                        <EmptyState
                            title="You're all caught up"
                            message="Reminders and replies will show up here."
                            icon="notifications-outline"
                        />
                    }
                    renderItem={({ item }) => (
                        <Pressable
                            accessibilityRole="button"
                            onPress={() => open(item)}
                            style={({ pressed }) => [
                                styles.row,
                                pressed ? styles.pressed : null,
                            ]}
                        >
                            <Ionicons
                                name={
                                    item.kind === 'notice'
                                        ? 'calendar-outline'
                                        : item.kind === 'message'
                                          ? 'chatbubble-outline'
                                          : 'construct-outline'
                                }
                                size={20}
                                color={colors.primary}
                            />
                            <View style={styles.rowText}>
                                <Text numberOfLines={1} style={styles.title}>
                                    {item.title}
                                </Text>
                                <Text numberOfLines={2} style={styles.detail}>
                                    {item.detail}
                                </Text>
                            </View>
                            {item.kind === 'notice' && item.unread ? (
                                <View style={styles.dot} />
                            ) : null}
                        </Pressable>
                    )}
                />
            )}
        </Screen>
    );
}

const styles = StyleSheet.create({
    padded: { padding: spacing.lg },
    content: { padding: spacing.lg, gap: spacing.sm },
    emptyContent: { flexGrow: 1, justifyContent: 'center' },
    row: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.md,
        padding: spacing.md,
        borderRadius: radius.lg,
        backgroundColor: colors.background,
    },
    pressed: { opacity: 0.7 },
    rowText: { flex: 1, gap: 2 },
    title: {
        fontSize: fontSize.base,
        fontWeight: '600',
        color: colors.foreground,
    },
    detail: { fontSize: fontSize.sm, color: colors.mutedForeground },
    dot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        backgroundColor: colors.primary,
    },
    markAll: {
        color: colors.primary,
        fontSize: fontSize.sm,
        fontWeight: '600',
    },
});
```

(Check that `ChatThread.lastMessage` and `WorkOrder.category/property/title` exist with those names in `src/api/types.ts`; adjust field names to match. Add `paddingBottom: clearance.tabBar` only if this screen ends up under the tab bar — it does not, it sits above `(app)`.)

- [ ] **Step 4: The detail** — `src/app/notifications/[id].tsx`:

```tsx
/**
 * One reminder in full, opened from the list or from the push. Reading it
 * marks it read; the button goes to what it is about.
 */
import { useEffect } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useNotificationsList, useReadNotification } from '@/api/queries';
import { useAuth } from '@/auth/auth-context';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Screen } from '@/components/ui/Screen';
import { EmptyState } from '@/components/ui/StateViews';
import { SkeletonDetails } from '@/components/ui/Skeleton';
import { colors, fontSize, spacing } from '@/theme/theme';

export default function NoticeScreen() {
    const { id } = useLocalSearchParams<{ id: string }>();
    const { account } = useAuth();
    const accountId = account?.id ?? 0;
    const { data, isPending } = useNotificationsList(accountId);
    const read = useReadNotification(accountId);
    const notice = data?.data.find((candidate) => candidate.id === id);

    useEffect(() => {
        if (notice !== undefined && notice.readAt === null && !read.isPending) {
            read.mutate(notice.id);
        }
        // Only when the notice itself changes, not on every mutation state change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [notice?.id, notice?.readAt]);

    return (
        <Screen title="Lease reminder">
            {isPending ? (
                <SkeletonDetails cards={1} rows={4} />
            ) : notice === undefined ? (
                <EmptyState
                    title="Not found"
                    message="This notification is no longer available."
                    icon="notifications-off-outline"
                />
            ) : (
                <ScrollView contentContainerStyle={styles.content}>
                    <Card>
                        <Text style={styles.title}>{notice.title}</Text>
                        <Text style={styles.message}>{notice.message}</Text>
                    </Card>
                    {notice.role === 'owner' && notice.buildingId !== null ? (
                        <Button
                            label="View property"
                            icon="home-outline"
                            onPress={() =>
                                router.push({
                                    pathname: '/account/properties/[property]',
                                    params: {
                                        property: String(notice.buildingId),
                                    },
                                })
                            }
                        />
                    ) : (
                        <Button
                            label="View my lease"
                            icon="document-text-outline"
                            onPress={() => router.push('/account/lease')}
                        />
                    )}
                </ScrollView>
            )}
        </Screen>
    );
}

const styles = StyleSheet.create({
    content: { padding: spacing.lg, gap: spacing.lg },
    title: {
        fontSize: fontSize.lg,
        fontWeight: '700',
        color: colors.foreground,
        marginBottom: spacing.sm,
    },
    message: {
        fontSize: fontSize.base,
        lineHeight: 22,
        color: colors.foreground,
    },
});
```

(Confirm `/account/properties/[property]` takes the building id the portal's `properties/{property}` API uses — `src/app/(app)/account/properties/[property].tsx` — and `fontSize.lg` exists in `@/theme/theme`; use the nearest existing size if not.)

- [ ] **Step 5: Typecheck and lint**

Run: `npx tsc --noEmit && npx expo lint`
Expected: no errors.

- [ ] **Step 6: Check on the simulator**

Run the portal locally with a seeded notice (`php artisan tinker`: notify a test user's tenant account with `LeaseExpiringNotification`), point the app's dev server field at it, `npx expo start`, open iOS simulator:

- Home shows the bell with a badge of 1.
- Bell → list shows "Your lease ends …"; tapping opens the full letter; back → dot gone, badge 0.
- Settings → Notifications shows "Lease ending reminders".
- With the portal on `main` (no API), the bell is hidden and Home has no error.

- [ ] **Step 7: Commit (Tasks 9 and 10)**

```bash
git add src
git commit -m "Add the notification bell, list and lease reminders to the app

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Release (both repos)

- [ ] Open a PR for `tx-client` `feature/lease-expiry-notifications`; after merge and deploy run `php artisan migrate` then once `php artisan leases:notify-expiring --seed`. Confirm the scheduler is running (`php artisan schedule:list` shows `leases:notify-expiring` at `0 9 * * *` America/Chicago).
- [ ] Merge the `tx-mobile` branch only after the portal is live; pushes to `main` ship as an OTA update (commit `632755e`). No native change is needed: no new modules.
