# Demo Accounts for App Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Apple's reviewer a demo owner login and a demo tenant login that show realistic data on every screen and let every action work, without touching Propertyware, the chat hub, the leads webhook or staff.

**Architecture:** A `users.is_demo` flag marks the two logins. A `DemoMode` middleware does the following for their requests only:
- points every outside service's config at unreachable `*.demo.invalid` hosts;
- answers those hosts through `Http::fake(DemoBackend)` with made-up records (`DemoData`) merged with whatever the reviewer created (`demo_records`);
- swaps SOAP for a no-op;
- isolates the cache under a demo prefix;
- runs jobs inline and sends mail to the `array` mailer.

The real services (`PropertywareDirectory`, `MaintenanceSummary`, `DocumentLibrary`, `OwnerLedger`, `Financials` and the rest) run unchanged against those answers.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, Inertia (Vue), Sanctum, PostgreSQL in production and SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-10-06-demo-accounts-design.md`

## Global Constraints

- Demo logins: `appreview.owner@texasrenters.com` "Demo Owner" (owner, Propertyware ID `900000001`) and `appreview.tenant@texasrenters.com` "Demo Tenant" (tenant, `900000002`).
- Every demo entity ID is 9 digits, at or above `900000000`. Records the reviewer creates get `900500000 + demo_records.id`.
- `is_demo` is set only by `demo:accounts`. It is never mass-assignable.
- Demo hosts end in `.demo.invalid`: `propertyware.demo.invalid`, `chat-hub.demo.invalid`, `tx-cma.demo.invalid`, `leads.demo.invalid`, `expo.demo.invalid`.
- Scheduling stays real: it is read-only and keyed by email.
- No real tenant name, owner name or address appears in demo data.
- `demo:reset` runs daily at 03:00 America/Chicago.
- The app runs on PHP-FPM. `DemoMode` changes container and config state for one request only. Do not adopt Octane without revisiting `DemoMode`.
- Follow the codebase's style:
  - `#[Fillable]` attributes on models.
  - Docblocks that explain *why*.
  - `__()` for user-facing text.
  - Run `composer lint` (Pint) before each commit.
- Run a single test with `php artisan test --compact --filter=<name>`, and the suite with `composer test`.

## Review Focus

1. **A real user's request after a demo request** in the same PHP process would inherit the demo config. This is safe only because FPM gives each request its own process. Pin it with a test that a real login's request never reaches `*.demo.invalid`, never uses the demo cache store, and still discovers accounts (Task 5).
2. **Scheduled jobs running outside any request.** `approvals:push` must never send a demo approval over SOAP when `approval_push` is off and the approval is left unsynced (Task 7).
3. **A deferred Inertia prop that fails quietly.** Controllers turn a `PropertywareException` into an "unavailable" message with status 200. Screen tests must therefore assert that no demo host answered 4xx, not just that the page returned 200 (Task 6).
4. **The reviewer changes the demo email or deletes the account mid-review.** Both must be refused, on the web settings page and the API (Task 8).
5. **Dates near month boundaries.** Ledger and lease fixtures are computed from today. The lease end must always fall 1–90 days ahead, whatever the run date. Pin it with a test that pins the clock to the 1st and to the 31st (Task 2).

---

## File Structure

| File | Responsibility |
| --- | --- |
| `database/migrations/2026_10_06_100000_add_is_demo_to_users_table.php` | `users.is_demo` boolean |
| `database/migrations/2026_10_06_100100_create_demo_records_table.php` | What the reviewer creates |
| `app/Models/DemoRecord.php` | Store and read created records |
| `app/Services/Demo/DemoData.php` | The made-up records, shaped like Propertyware and the chat hub |
| `app/Services/Demo/DemoPdf.php` | A tiny valid PDF for document downloads |
| `app/Services/Demo/DemoPropertyware.php` | Answers Propertyware REST paths |
| `app/Services/Demo/DemoChatHub.php` | Answers chat hub paths |
| `app/Services/Demo/DemoBackend.php` | Routes faked requests by host |
| `app/Services/Demo/DemoPropertywareSoap.php` | No-op work order approval |
| `app/Services/Demo/DemoReset.php` | Wipes what the reviewer created |
| `app/Http/Middleware/DemoMode.php` | Switches a demo login's request over |
| `app/Console/Commands/DemoAccounts.php` | `demo:accounts` |
| `app/Console/Commands/DemoResetCommand.php` | `demo:reset` |
| Modify `app/Models/User.php` | `is_demo` cast |
| Modify `database/factories/UserFactory.php` | `demo()` state |
| Modify `bootstrap/app.php` | Register `DemoMode` |
| Modify `app/Services/Propertyware/WorkOrderApprovals.php` | `pushPending` skips demo users |
| Modify `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `ProfileDeleteRequest.php` | Lock the demo email and deletion |
| Modify `app/Services/Admin/UserDirectory.php`, `resources/js/pages/admin/Users.vue`, `resources/js/pages/admin/UserShow.vue` | "Demo" badge |
| Modify `routes/console.php` | Schedule `demo:reset` |
| Tests in `tests/Feature/Demo/` | One file per task |

---

### Task 1: Mark demo users

**Files:**
- Create: `database/migrations/2026_10_06_100000_add_is_demo_to_users_table.php`
- Modify: `app/Models/User.php` (the `casts()` method, near line 178)
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Services/Admin/UserDirectory.php` (`present()`, near line 207)
- Modify: `resources/js/pages/admin/Users.vue` (the badge row, near line 149), `resources/js/pages/admin/UserShow.vue`
- Test: `tests/Feature/Demo/DemoUserTest.php`

**Interfaces:**
- Produces: `User::$is_demo` (bool, cast), `User::factory()->demo()`, admin user array key `isDemo` (bool).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

test('a user is not a demo user unless marked', function () {
    expect(User::factory()->create()->is_demo)->toBeFalse()
        ->and(User::factory()->demo()->create()->is_demo)->toBeTrue();
});

test('the demo flag cannot be mass-assigned', function () {
    $user = User::factory()->create();

    $user->fill(['is_demo' => true])->save();

    expect($user->fresh()->is_demo)->toBeFalse();
});

test('the admin users list marks demo users', function () {
    $admin = User::factory()->create(['email' => config('admin.emails')[0] ?? 'owner@texasrenters.com']);
    config(['admin.emails' => [$admin->email]]);
    User::factory()->demo()->create(['email' => 'appreview.owner@texasrenters.com']);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page->where('users.data', fn ($users) => collect($users)->firstWhere('email', 'appreview.owner@texasrenters.com')['isDemo'] === true));
});
```

Before running it, check how `tests/Feature/Admin/*UserTest*.php` signs in an admin, and copy that setup in place of the first two lines of the third test. The config key for admin emails might not be `admin.emails`. Also confirm the users prop path: if it isn't `users.data`, use the path those tests assert on.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoUserTest`
Expected: FAIL with "Call to undefined method ... demo()".

- [ ] **Step 3: Implement**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The App Review logins, which see made-up records instead of
            // Propertyware. Only `php artisan demo:accounts` sets it.
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
```

In `User::casts()`, add `'is_demo' => 'boolean',`. Leave `#[Fillable(['name', 'email', 'password'])]` unchanged. Add a docblock line `@property bool $is_demo One of the App Review logins; see App\Http\Middleware\DemoMode.` beside the existing `@property` lines.

In `UserFactory`, add:

```php
    /**
     * One of the App Review logins, which see made-up records.
     */
    public function demo(): static
    {
        return $this->state(fn (array $attributes): array => ['is_demo' => true]);
    }
```

`is_demo` is not fillable. If the factory's `state` doesn't set it (factories use `forceFill`, so it should), use `->afterMaking(fn (User $user) => $user->forceFill(['is_demo' => true]))` instead.

In `UserDirectory::present()`, add `'isDemo' => $user->is_demo,` after `'isStaff'`.

In `Users.vue`, add `isDemo: boolean;` to the user type. Add this next to the Suspended badge:

```vue
<Badge v-if="user.isDemo" variant="outline">Demo</Badge>
```

Add the same badge beside the name heading in `UserShow.vue`, plus the matching `isDemo` field in its user type.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DemoUserTest && npm run types:check`
Expected: PASS, and no type errors.

- [ ] **Step 5: Commit**

```bash
composer lint
git add database/migrations/2026_10_06_100000_add_is_demo_to_users_table.php app/Models/User.php database/factories/UserFactory.php app/Services/Admin/UserDirectory.php resources/js/pages/admin/Users.vue resources/js/pages/admin/UserShow.vue tests/Feature/Demo/DemoUserTest.php
git commit -m "Mark the App Review logins as demo users"
```

---

### Task 2: The demo records

**Files:**
- Create: `database/migrations/2026_10_06_100100_create_demo_records_table.php`
- Create: `app/Models/DemoRecord.php`
- Create: `app/Services/Demo/DemoData.php`
- Create: `app/Services/Demo/DemoPdf.php`
- Test: `tests/Feature/Demo/DemoDataTest.php`

**Interfaces:**
- Produces:
  - `DemoRecord::store(string $kind, Closure(int $id): array $payload): array` — saves a record and returns its payload.
  - `DemoRecord::payloads(string $kind): array<int, array>`
  - `DemoData` constants: `OWNER_CONTACT`, `TENANT_CONTACT`, `PORTFOLIO`, `HOME_LEASED`, `HOME_VACANT`, `LEASE`, `VENDOR`, `WORK_ORDER_DONE`, `WORK_ORDER_AC`, `WORK_ORDER_APPROVAL`, `WORK_ORDER_GARAGE`, `DOC_LEASE`, `DOC_STATEMENT`, `DOC_INSPECTION`, `DOC_INVOICE`, `DOC_WELCOME`, `OWNER_THREAD`, `TENANT_THREAD`, `RENT`.
  - `DemoData` methods, each returning a Propertyware- or hub-shaped array:
    - `contact(string|int $id): ?array`, `portfolio(int $id): ?array`
    - `buildings(): array`, `leases(): array`, `leaseAdjustments(): array`
    - `glAccounts(): array`, `generalLedger(): array`, `bills(): array`, `vendors(): array`
    - `workOrders(): array`, `documents(): array`, `conversations(): array`
    - `chatThreads(): array`, `chatMessages(): array`
  - `DemoPdf::make(string $title): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\DemoRecord;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoPdf;
use Carbon\CarbonImmutable;

test('the demo lease always ends inside the 90-day reminder window', function (string $today) {
    $this->travelTo(CarbonImmutable::parse($today, 'America/Chicago'));

    $lease = collect(app(DemoData::class)->leases())->firstWhere('id', DemoData::LEASE);
    $daysLeft = CarbonImmutable::today('America/Chicago')->diffInDays(CarbonImmutable::parse($lease['endDate']), false);

    expect($daysLeft)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(90);
})->with(['2026-10-01', '2026-10-31', '2027-02-28', '2028-02-29', '2026-12-31']);

test('every demo id is far outside Propertyware\'s own range', function () {
    $data = app(DemoData::class);
    $ids = collect([
        ...$data->buildings(), ...$data->leases(), ...$data->workOrders(), ...$data->documents(), ...$data->vendors(),
    ])->pluck('id')->map(fn ($id) => (int) $id);

    expect($ids->min())->toBeGreaterThanOrEqual(900000000);
});

test('the owner has one leased and one vacant home, and the tenant lives in the leased one', function () {
    $data = app(DemoData::class);

    expect(collect($data->contact(DemoData::OWNER_CONTACT)['attachedPortfolios'])->pluck('id')->all())->toBe([DemoData::PORTFOLIO])
        ->and(collect($data->contact(DemoData::TENANT_CONTACT)['attachedLeases'])->pluck('id')->all())->toBe([DemoData::LEASE])
        ->and(collect($data->buildings())->pluck('leaseID', 'id')->all())->toBe([DemoData::HOME_LEASED => DemoData::LEASE, DemoData::HOME_VACANT => null]);
});

test('one work order is waiting for the owner\'s approval', function () {
    $waiting = collect(app(DemoData::class)->workOrders())
        ->filter(fn (array $order) => $order['status'] === 'Open' && $order['approved'] === false);

    expect($waiting->pluck('id')->all())->toBe([(string) DemoData::WORK_ORDER_APPROVAL]);
});

test('the tenant owes this month\'s rent and has paid every earlier month', function () {
    $balance = collect(app(DemoData::class)->leaseAdjustments())->sum('amount');

    expect($balance)->toBe(DemoData::RENT);
});

test('a stored record gets an id in the demo range and is read back', function () {
    $payload = DemoRecord::store('workorder', fn (int $id) => ['id' => (string) $id, 'description' => 'Test']);

    expect((int) $payload['id'])->toBeGreaterThanOrEqual(900500000)
        ->and(DemoRecord::payloads('workorder'))->toBe([$payload]);
});

test('the demo pdf is a pdf', function () {
    expect(DemoPdf::make('Lease Agreement'))->toStartWith('%PDF-1.4')->toContain('Lease Agreement')->toEndWith("%%EOF\n");
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoDataTest`
Expected: FAIL with "Class App\Services\Demo\DemoData not found".

- [ ] **Step 3: Implement**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What the App Review logins create: work orders, documents and chat
        // messages that never leave the portal. `demo:reset` empties it nightly.
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->unsignedBigInteger('external_id')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
```

`app/Models/DemoRecord.php`:

```php
<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an App Review login created, kept in place of Propertyware or the hub.
 *
 * @property string $kind workorder, document, chat_thread or chat_message
 * @property int|null $external_id The id the demo hands out, 900500000 and up
 * @property array<string, mixed> $payload The record as the real API would return it
 */
#[Fillable(['kind', 'external_id', 'payload'])]
class DemoRecord extends Model
{
    /**
     * Where created records' ids start, past the made-up records and Propertyware's own.
     */
    public const FIRST_ID = 900500000;

    protected function casts(): array
    {
        return ['payload' => 'array', 'external_id' => 'integer'];
    }

    /**
     * Save a record, building its payload once its id is known.
     *
     * @param  Closure(int): array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function store(string $kind, Closure $payload): array
    {
        $record = static::create(['kind' => $kind, 'payload' => []]);
        $id = self::FIRST_ID + $record->id;
        $record->update(['external_id' => $id, 'payload' => $payload($id)]);

        return $record->payload;
    }

    /**
     * Every stored payload of one kind, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payloads(string $kind): array
    {
        return static::query()->where('kind', $kind)->orderBy('id')->pluck('payload')->all();
    }
}
```

`app/Services/Demo/DemoPdf.php`:

```php
<?php

namespace App\Services\Demo;

/**
 * A one-page PDF with a title, so demo documents open like real ones.
 */
final class DemoPdf
{
    public static function make(string $title): string
    {
        $escape = fn (string $text): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = 'BT /F1 20 Tf 72 720 Td ('.$escape($title).") Tj ET\n"
            .'BT /F1 11 Tf 72 690 Td ('.$escape('Sample document for the TexasRenters demo account.').') Tj ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
```

`app/Services/Demo/DemoData.php`. The shapes are copied from the fixtures in `tests/Feature/Api/V1/AccountDataTest.php`, `tests/Feature/Resident/LeaseTest.php`, `tests/Feature/Resident/LeaseLedgerTest.php`, `tests/Feature/Services/OwnerLedgerTest.php`, `tests/Feature/Owner/FinancialTest.php`, `tests/Feature/ConversationTest.php` and `tests/Feature/MessagesTest.php`.

```php
<?php

namespace App\Services\Demo;

use Carbon\CarbonImmutable;

/**
 * The made-up records the App Review logins see, shaped exactly like
 * Propertyware's REST API and the chat hub return them, so the real services
 * read them unchanged.
 *
 * Dates count from today: the lease always ends within the 90-day reminder
 * window and the ledger always covers the last six months. Every id is 9
 * digits from 900000000, far past Propertyware's own. Names and addresses are
 * invented; "Demo" is in each so nobody mistakes them for a client.
 */
final class DemoData
{
    public const OWNER_CONTACT = 900000001;

    public const TENANT_CONTACT = 900000002;

    public const PORTFOLIO = 900000010;

    public const HOME_LEASED = 900000021;

    public const HOME_VACANT = 900000022;

    public const LEASE = 900000031;

    public const VENDOR = 900000041;

    public const WORK_ORDER_DONE = 900000051;

    public const WORK_ORDER_AC = 900000052;

    public const WORK_ORDER_APPROVAL = 900000053;

    public const WORK_ORDER_GARAGE = 900000054;

    public const DOC_LEASE = 900000061;

    public const DOC_STATEMENT = 900000062;

    public const DOC_INSPECTION = 900000063;

    public const DOC_INVOICE = 900000064;

    public const DOC_WELCOME = 900000065;

    public const OWNER_THREAD = 900000071;

    public const TENANT_THREAD = 900000072;

    public const RENT = 1850.0;

    public const MANAGEMENT_FEE = 148.0;

    /**
     * How many days after today the lease ends: inside the 90-day reminder window.
     */
    public const LEASE_DAYS_LEFT = 75;

    private const GL_RENT = 4000;

    private const GL_FEES = 4201;

    private const GL_REPAIRS = 5100;

    private const GL_RECEIVABLE = 1000;

    private const GL_BANK = 1100;

    private const OFFICE = 'TexasRenters Office';

    /**
     * @return array<string, mixed>|null
     */
    public function contact(string|int $id): ?array
    {
        return match ((int) $id) {
            self::OWNER_CONTACT => [
                'id' => (string) self::OWNER_CONTACT, 'type' => 'OWNER', 'firstName' => 'Demo', 'lastName' => 'Owner',
                'email' => 'appreview.owner@texasrenters.com', 'mobilePhone' => '(281) 555-0101',
                'attachedLeases' => [], 'attachedPortfolios' => [['id' => self::PORTFOLIO, 'name' => 'Demo Owner Portfolio']],
            ],
            self::TENANT_CONTACT => [
                'id' => (string) self::TENANT_CONTACT, 'type' => 'TENANT', 'firstName' => 'Demo', 'lastName' => 'Tenant',
                'email' => 'appreview.tenant@texasrenters.com', 'mobilePhone' => '(281) 555-0102',
                'attachedLeases' => [['id' => self::LEASE, 'name' => 'Lease', 'role' => 'Primary']], 'attachedPortfolios' => [],
            ],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function portfolio(int $id): ?array
    {
        return $id === self::PORTFOLIO ? [
            'id' => self::PORTFOLIO,
            'name' => 'Demo Owner Portfolio',
            'createdDateTime' => $this->today()->subMonths(8)->startOfMonth()->toIso8601ZuluString(),
            'targetOperatingReserve' => 500.0,
        ] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildings(): array
    {
        $home = fn (int $id, string $street, string $city, ?int $leaseId, string $status, int $beds, float $baths, int $area, int $built, ?string $available): array => [
            'id' => $id, 'name' => $street, 'abbreviation' => strtoupper(str_replace(' ', '', substr($street, 0, 10))),
            'portfolioID' => self::PORTFOLIO, 'leaseID' => $leaseId, 'type' => 'Single Family Home', 'category' => 'Residential',
            'status' => $status, 'numberOfBedrooms' => $beds, 'numberOfBathrooms' => $baths, 'totalArea' => $area, 'areaUnits' => 'Sq Ft',
            'yearBuilt' => $built, 'county' => 'Harris', 'neighborhood' => 'Demo Meadows',
            'address' => ['address' => $street, 'city' => $city, 'stateRegion' => 'TX', 'postalCode' => '77000'],
            'marketing' => ['availableDate' => $available, 'smokingAllowed' => false],
            'propertyManagerList' => [['name' => self::OFFICE, 'roleAsString' => 'Property Manager']],
        ];

        return [
            $home(self::HOME_LEASED, '1234 Demo Meadow Ln', 'Houston', self::LEASE, 'Occupied', 3, 2.0, 1850, 2008, null),
            $home(self::HOME_VACANT, '5678 Sample Oak Dr', 'Katy', null, 'Vacant', 4, 2.5, 2240, 2014, $this->today()->addDays(14)->toDateString()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leases(): array
    {
        $end = $this->today()->addDays(self::LEASE_DAYS_LEFT);
        $start = $end->subYear()->addDay();

        return [[
            'id' => self::LEASE,
            'buildingID' => self::HOME_LEASED,
            'portfolioID' => self::PORTFOLIO,
            'status' => 'Active',
            'active' => true,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'moveInDate' => $start->toDateString(),
            'moveOutDate' => null,
            'scheduleMoveOutDate' => null,
            'noticeGivenDate' => null,
            'baseRent' => self::RENT,
            'leaseBalance' => self::RENT,
            'lateFeeRule' => '10.00% of rent charges after due day 3',
            'leaseName' => 'Tenant, D.',
            'location' => 'Demo Owner Portfolio | 1234 Demo Meadow Ln',
            'contacts' => [['id' => (string) self::TENANT_CONTACT, 'name' => 'Demo Tenant']],
        ]];
    }

    /**
     * Six months of rent charged on the 1st and paid on the 3rd, with this month's still due.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaseAdjustments(): array
    {
        $rows = [];

        foreach (range(5, 0) as $monthsAgo) {
            $month = $this->today()->startOfMonth()->subMonths($monthsAgo);
            $rows[] = ['id' => 900400000 + $monthsAgo * 2, 'leaseID' => self::LEASE, 'date' => $month->toDateString(), 'amount' => self::RENT, 'glAccountID' => self::GL_RENT, 'comments' => 'Rent'];

            if ($monthsAgo > 0) {
                $rows[] = [
                    'id' => 900400001 + $monthsAgo * 2, 'leaseID' => self::LEASE, 'date' => $month->addDays(2)->toDateString(),
                    'amount' => -self::RENT, 'glAccountID' => self::GL_RECEIVABLE, 'refNo' => 'EP-'.(4100 + $monthsAgo), 'comments' => 'ePayment received',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function glAccounts(): array
    {
        return [
            ['id' => self::GL_RENT, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => self::GL_FEES, 'name' => 'Management Fees', 'accountType' => 'Expense'],
            ['id' => self::GL_REPAIRS, 'name' => 'Repairs & Maintenance', 'accountType' => 'Expense'],
            ['id' => self::GL_RECEIVABLE, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
            ['id' => 3000, 'name' => 'Owner Draw', 'accountType' => 'Equity'],
            ['id' => self::GL_BANK, 'name' => 'Operating Bank', 'accountType' => 'Bank'],
        ];
    }

    /**
     * The portfolio's general ledger: rent in on the 3rd, the management fee
     * out on the 10th, and one repair two months ago. Only dates up to today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generalLedger(): array
    {
        $entry = fn (int $account, float $credit, float $debit, string $comment, ?int $building = self::HOME_LEASED): array => [
            'portfolioID' => self::PORTFOLIO, 'buildingID' => $building, 'glaccountID' => $account, 'credit' => $credit, 'debit' => $debit, 'comment' => $comment,
        ];
        $transaction = fn (int $id, CarbonImmutable $date, string $type, string $comment, array $entries): array => [
            'id' => $id, 'txDate' => $date->toDateString(), 'postDate' => $date->toDateString(), 'transactionType' => $type, 'comment' => $comment, 'glentries' => $entries,
        ];

        $rows = [];

        foreach (range(6, 0) as $monthsAgo) {
            $month = $this->today()->startOfMonth()->subMonths($monthsAgo);
            $rent = $month->addDays(2);
            $fee = $month->addDays(9);

            if ($rent->lessThanOrEqualTo($this->today())) {
                $rows[] = $transaction(900200000 + $monthsAgo * 10, $rent, 'Payment', 'Rent', [
                    $entry(self::GL_RENT, self::RENT, 0.0, 'Rent'),
                    $entry(self::GL_BANK, 0.0, self::RENT, 'Rent'),
                ]);
            }

            if ($fee->lessThanOrEqualTo($this->today())) {
                $rows[] = $transaction(900200001 + $monthsAgo * 10, $fee, 'Bill Payment', 'Management fee', [
                    $entry(self::GL_FEES, 0.0, self::MANAGEMENT_FEE, 'Management fee'),
                    $entry(self::GL_BANK, self::MANAGEMENT_FEE, 0.0, 'Management fee'),
                ]);
            }
        }

        $repair = $this->today()->startOfMonth()->subMonths(2)->addDays(14);
        $rows[] = $transaction(900200099, $repair, 'Bill Payment', 'Replaced kitchen faucet', [
            $entry(self::GL_REPAIRS, 0.0, 325.0, 'Replaced kitchen faucet'),
            $entry(self::GL_BANK, 325.0, 0.0, 'Replaced kitchen faucet'),
        ]);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bills(): array
    {
        $repair = $this->today()->startOfMonth()->subMonths(2)->addDays(14);
        $lawn = $this->today()->subDays(5);

        return [
            [
                'id' => 900000081, 'vendorID' => self::VENDOR, 'billNumber' => 70081, 'billDate' => $repair->toDateString(),
                'dueDate' => $repair->addDays(15)->toDateString(), 'paymentDate' => $repair->addDays(5)->toDateString(),
                'comments' => 'Replaced kitchen faucet', 'workOrderID' => (string) self::WORK_ORDER_DONE, 'amount' => 325.0,
                'lastModifiedDateTime' => $repair->addDays(5)->toIso8601ZuluString(),
                'billSplits' => [['portfolioID' => self::PORTFOLIO, 'buildingID' => self::HOME_LEASED, 'buildingAbbr' => '1234DEMO', 'glAccountID' => self::GL_REPAIRS, 'amount' => 325.0, 'amountPaid' => 325.0]],
            ],
            [
                'id' => 900000082, 'vendorID' => self::VENDOR, 'billNumber' => 70082, 'billDate' => $lawn->toDateString(),
                'dueDate' => $lawn->addDays(15)->toDateString(), 'comments' => 'Lawn care while vacant', 'amount' => 95.0,
                'lastModifiedDateTime' => $lawn->toIso8601ZuluString(),
                'billSplits' => [['portfolioID' => self::PORTFOLIO, 'buildingID' => self::HOME_VACANT, 'buildingAbbr' => '5678SAMPL', 'glAccountID' => self::GL_REPAIRS, 'amount' => 95.0, 'amountPaid' => 0.0]],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vendors(): array
    {
        return [['id' => self::VENDOR, 'name' => 'Demo Home Services']];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workOrders(): array
    {
        $order = fn (int $id, int $building, string $description, string $category, array $extra): array => [
            'id' => (string) $id, 'number' => $id - 900000000 + 9000, 'description' => $description, 'category' => $category,
            'status' => 'Open', 'priority' => 'MEDIUM', 'type' => 'Service Request', 'source' => 'Portal',
            'buildingID' => $building, 'portfolioID' => self::PORTFOLIO, 'unitID' => null,
            'publishToOwnerPortal' => true, 'publishToTenantPortal' => $building === self::HOME_LEASED,
            'approved' => true, 'approvedDate' => null, 'completedDate' => null, 'ownerCost' => null, 'costEstimate' => null,
            'requestedBy' => null, 'assignedVendors' => [['id' => self::VENDOR, 'name' => 'Demo Home Services']],
            ...$extra,
        ];
        $at = fn (int $daysAgo): string => $this->today()->subDays($daysAgo)->setTime(10, 0)->toIso8601ZuluString();

        return [
            $order(self::WORK_ORDER_DONE, self::HOME_LEASED, 'Kitchen faucet is leaking', 'Plumbing', [
                'status' => 'Closed', 'createdDateTime' => $at(62), 'lastModifiedDateTime' => $at(45), 'completedDate' => $at(45),
                'approvedDate' => $this->today()->subDays(61)->toDateString(), 'ownerCost' => 325.0, 'requestedBy' => self::TENANT_CONTACT,
            ]),
            $order(self::WORK_ORDER_AC, self::HOME_LEASED, 'AC not cooling upstairs', 'HVAC', [
                'createdDateTime' => $at(6), 'lastModifiedDateTime' => $at(2), 'approvedDate' => $this->today()->subDays(5)->toDateString(),
                'costEstimate' => 240.0, 'requestedBy' => self::TENANT_CONTACT,
            ]),
            $order(self::WORK_ORDER_APPROVAL, self::HOME_VACANT, 'Make-ready: interior paint touch-up and carpet cleaning before listing', 'General', [
                'approved' => false, 'createdDateTime' => $at(3), 'lastModifiedDateTime' => $at(1), 'costEstimate' => 1150.0, 'assignedVendors' => [],
            ]),
            $order(self::WORK_ORDER_GARAGE, self::HOME_LEASED, 'Garage door remote stopped working', 'General', [
                'createdDateTime' => $at(1), 'lastModifiedDateTime' => $at(1), 'requestedBy' => self::TENANT_CONTACT, 'assignedVendors' => [],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function documents(): array
    {
        $document = fn (int $id, string $fileName, string $entityType, int $entityId, bool $owner, bool $tenant, int $daysAgo): array => [
            'id' => $id, 'fileName' => $fileName, 'fileType' => 'application/pdf', 'description' => null,
            'entityType' => $entityType, 'entityId' => $entityId,
            'publishToOwnerPortal' => $owner, 'publishToTenantPortal' => $tenant,
            'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(9, 0)->toIso8601ZuluString(),
        ];
        $statementMonth = $this->today()->startOfMonth()->subMonth()->format('F Y');

        return [
            $document(self::DOC_LEASE, 'Lease Agreement.pdf', 'LEASE', self::LEASE, true, true, 290),
            $document(self::DOC_STATEMENT, "Owner Statement - {$statementMonth}.pdf", 'PORTFOLIO', self::PORTFOLIO, true, false, 3),
            $document(self::DOC_INSPECTION, 'Move-out Inspection Report.pdf', 'BUILDING', self::HOME_VACANT, true, false, 20),
            $document(self::DOC_INVOICE, 'Invoice - Kitchen Faucet.pdf', 'WORK_ORDER', self::WORK_ORDER_DONE, true, false, 45),
            $document(self::DOC_WELCOME, 'Welcome Letter.pdf', 'CONTACT', self::TENANT_CONTACT, false, true, 290),
        ];
    }

    /**
     * Propertyware conversations, as `contacts/{id}/conversations` and `buildings/{id}/conversations` return them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversations(): array
    {
        $conversation = fn (string $id, string $type, string $entityType, int $entityId, string $subject, string $text, int $daysAgo): array => [
            'id' => $id, 'type' => $type, 'subject' => $subject, 'entityType' => $entityType, 'entityID' => $entityId,
            'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(15, 0)->toIso8601ZuluString(),
            'comments' => [['id' => $id.'-1', 'text' => $text, 'createdBy' => self::OFFICE, 'createdDateTime' => $this->today()->subDays($daysAgo)->setTime(15, 0)->toIso8601ZuluString()]],
        ];

        return [
            $conversation('900000091', 'OWNER', 'Contact', self::OWNER_CONTACT, 'Welcome to TexasRenters', 'Welcome aboard! Both of your homes are set up in the portal.', 200),
            $conversation('900000092', 'TENANT', 'Contact', self::TENANT_CONTACT, 'Welcome home', 'Welcome to your new home! Reach us here any time.', 290),
            $conversation('900000093', 'OWNER', 'Building', self::HOME_VACANT, 'Listing the home', 'The make-ready estimate is in the app for your approval. We will list the home as soon as it is done.', 2),
            $conversation('900000094', 'TENANT', 'Building', self::HOME_LEASED, 'Annual HVAC service', 'Our technician will service the AC this month. We will text before the visit.', 30),
        ];
    }

    /**
     * Chat hub threads, as `threads` returns them, before their last message is added.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chatThreads(): array
    {
        return [
            [
                'id' => self::OWNER_THREAD, 'status' => 'open', 'subject' => 'Question about my statement', 'type' => 'accounting',
                'contact_id' => (string) self::OWNER_CONTACT, 'phone' => null, 'work_order_id' => null, 'work_order_number' => null,
                'assigned_to' => self::OFFICE, 'assigned_to_photo_url' => null,
            ],
            [
                'id' => self::TENANT_THREAD, 'status' => 'open', 'subject' => 'Garage door remote', 'type' => 'maintenance',
                'contact_id' => (string) self::TENANT_CONTACT, 'phone' => null, 'work_order_id' => (string) self::WORK_ORDER_GARAGE,
                'work_order_number' => (string) (self::WORK_ORDER_GARAGE - 900000000 + 9000), 'assigned_to' => self::OFFICE, 'assigned_to_photo_url' => null,
            ],
        ];
    }

    /**
     * Chat hub messages, each with the thread it belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chatMessages(): array
    {
        $message = fn (int $id, int $thread, bool $fromClient, string $body, int $hoursAgo): array => [
            'id' => $id, 'thread_id' => $thread, 'direction' => $fromClient ? 'inbound' : 'outbound', 'sender' => $fromClient ? 'user' : 'agent',
            'sender_name' => $fromClient ? null : self::OFFICE, 'sender_photo_url' => null, 'body' => $body, 'media_urls' => [], 'sender_number' => null,
            'created_at' => $this->today()->setTime(9, 0)->subHours($hoursAgo)->toIso8601String(),
        ];

        return [
            $message(900000101, self::OWNER_THREAD, true, 'Hi, what was the $325 charge two months ago?', 50),
            $message(900000102, self::OWNER_THREAD, false, 'That was the kitchen faucet repair at 1234 Demo Meadow Ln. The invoice is under Documents.', 48),
            $message(900000103, self::TENANT_THREAD, true, 'The garage door remote stopped working yesterday.', 20),
            $message(900000104, self::TENANT_THREAD, false, 'Thanks! We opened a work order and a technician will reach out to schedule.', 18),
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today('America/Chicago');
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DemoDataTest`
Expected: PASS — 7 tests, the first with 5 dataset dates.

- [ ] **Step 5: Commit**

```bash
composer lint
git add database/migrations/2026_10_06_100100_create_demo_records_table.php app/Models/DemoRecord.php app/Services/Demo/DemoData.php app/Services/Demo/DemoPdf.php tests/Feature/Demo/DemoDataTest.php
git commit -m "Make up the owner, tenant, homes and ledger the demo logins see"
```

---

### Task 3: Answer Propertyware for the demo

**Files:**
- Create: `app/Services/Demo/DemoPropertyware.php`
- Create: `app/Services/Demo/DemoBackend.php`
- Create: `app/Services/Demo/DemoPropertywareSoap.php`
- Create: `app/Http/Middleware/DemoMode.php` (only the static `enter()` in this task; `handle()` is wired up in Task 5)
- Test: `tests/Feature/Demo/DemoPropertywareTest.php`

**Interfaces:**
- Consumes: `DemoData` and `DemoRecord` (Task 2).
- Produces:
  - `DemoBackend` host constants `PROPERTYWARE`, `CHAT_HUB`, `LISTINGS`, `LEADS`, `EXPO`.
  - `DemoBackend::respond(Illuminate\Http\Client\Request $request): ?GuzzleHttp\Promise\PromiseInterface` — returns `null` for hosts it doesn't own.
  - `DemoPropertyware::respond(Request $request): PromiseInterface`
  - `DemoMode::enter(): void` — switches the current request to demo mode.
  - `DemoMode::cacheStore(): string` — the demo store name, `'demo'`.

- [ ] **Step 1: Write the failing test**

Each test calls `DemoMode::enter()` and then uses the real `PropertywareClient` from the container, so it covers the switching and the answers together.

```php
<?php

use App\Http\Middleware\DemoMode;
use App\Models\DemoRecord;
use App\Services\Demo\DemoData;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // As in production: a real client already resolved before the request turns demo.
    app(PropertywareClient::class);
    DemoMode::enter();
    $this->pw = app(PropertywareClient::class);
});

test('reads the demo owner and the homes in their portfolio', function () {
    expect($this->pw->get('contacts/'.DemoData::OWNER_CONTACT)['type'])->toBe('OWNER')
        ->and(collect($this->pw->listAll('buildings', ['portfolioID' => DemoData::PORTFOLIO]))->pluck('id')->all())
        ->toBe([DemoData::HOME_LEASED, DemoData::HOME_VACANT]);
});

test('only ever calls the demo host', function () {
    $this->pw->get('leases/'.DemoData::LEASE);

    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'propertyware.demo.invalid');
    Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) !== 'propertyware.demo.invalid');
});

test('filters collections the way Propertyware does', function () {
    expect($this->pw->listAll('workorders', ['buildingID' => DemoData::HOME_VACANT]))->toHaveCount(1)
        ->and($this->pw->listAll('leases/adjustments', ['leaseID' => 1]))->toBe([])
        ->and($this->pw->listAll('docs', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE]))->toHaveCount(1)
        ->and($this->pw->listAll('accounting/generalledger', [
            'portfolioID' => DemoData::PORTFOLIO,
            'postDateStart' => now('America/Chicago')->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'postDateEnd' => now('America/Chicago')->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]))->toHaveCount(2)
        ->and($this->pw->listAll('bills', ['portfolioID' => DemoData::PORTFOLIO]))->toHaveCount(2)
        ->and($this->pw->listAll('bills', ['portfolioID' => 1]))->toBe([]);
});

test('pages through a collection like Propertyware', function () {
    expect($this->pw->list('workorders', ['portfolioID' => DemoData::PORTFOLIO], limit: 2, offset: 2))->toHaveCount(2)
        ->and($this->pw->list('workorders', ['portfolioID' => DemoData::PORTFOLIO], limit: 2, offset: 4))->toBe([]);
});

test('reads several records at once', function () {
    $homes = $this->pw->getMany(['a' => 'buildings/'.DemoData::HOME_LEASED, 'b' => 'buildings/'.DemoData::HOME_VACANT]);

    expect($homes['a']['leaseID'])->toBe(DemoData::LEASE)->and($homes['b']['leaseID'])->toBeNull();
});

test('answers a path it does not know with 404, so a gap fails loudly', function () {
    $this->pw->get('contacts/123');
})->throws(PropertywareException::class, 'Propertyware returned 404');

test('keeps a created work order for the demo and lists it afterwards', function () {
    $created = $this->pw->create('workorders', ['buildingID' => DemoData::HOME_LEASED, 'description' => 'Smoke alarm chirping', 'category' => 'General', 'publishToTenantPortal' => true]);

    expect((int) $created['id'])->toBeGreaterThanOrEqual(DemoRecord::FIRST_ID)
        ->and(collect($this->pw->listAll('workorders', ['buildingID' => DemoData::HOME_LEASED]))->pluck('description'))->toContain('Smoke alarm chirping')
        ->and($this->pw->get('workorders/'.$created['id'])['status'])->toBe('Open');
});

test('keeps an uploaded document, its description and its file', function () {
    $created = $this->pw->upload('docs', '%PDF-1.4 mine', 'Renters insurance.pdf', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE, 'publishToOwnerPortal' => false, 'publishToTenantPortal' => true]);
    $this->pw->update('docs/'.$created['id'], ['fileName' => 'Renters insurance.pdf', 'description' => 'Policy for this year']);

    $listed = collect($this->pw->listAll('docs', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE]))->firstWhere('id', $created['id']);

    expect($listed)->toMatchArray(['fileName' => 'Renters insurance.pdf', 'description' => 'Policy for this year', 'publishToTenantPortal' => true])
        ->and($this->pw->download('docs/'.$created['id'].'/download')->body())->toBe('%PDF-1.4 mine');
});

test('downloads a made-up document as a pdf', function () {
    $response = $this->pw->download('docs/'.DemoData::DOC_LEASE.'/download');

    expect($response->header('Content-Type'))->toBe('application/pdf')->and($response->body())->toStartWith('%PDF-1.4');
});

test('approving a work order over SOAP does nothing', function () {
    app(PropertywareSoap::class)->approveWorkOrder(DemoData::WORK_ORDER_APPROVAL, today()->toDateString(), 'ok');

    expect(app(PropertywareSoap::class)->isConfigured())->toBeTrue();
});

test('keeps the demo cache apart from the real one', function () {
    expect(Cache::getDefaultDriver())->toBe(DemoMode::cacheStore())
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('services.propertyware.discover_accounts'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoPropertywareTest`
Expected: FAIL with "Class App\Http\Middleware\DemoMode not found".

- [ ] **Step 3: Implement**

`app/Services/Demo/DemoPropertywareSoap.php`:

```php
<?php

namespace App\Services\Demo;

use App\Services\Propertyware\PropertywareSoap;

/**
 * SOAP for the demo logins: an approval is recorded in the portal and goes no further.
 */
final class DemoPropertywareSoap extends PropertywareSoap
{
    public function __construct()
    {
        parent::__construct('https://propertyware-soap.demo.invalid', 'demo', 'demo');
    }

    public function approveWorkOrder(int $workOrderId, string $approvedDate, string $comment, bool $approved = true): void {}
}
```

`app/Services/Demo/DemoPropertyware.php`:

```php
<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Propertyware's REST API for the demo logins: the made-up records merged with
 * what the reviewer created, filtered the way Propertyware filters them.
 *
 * A path it doesn't know answers 404, which the client turns into a
 * PropertywareException, so a screen that needs something new fails its test
 * instead of reaching anywhere real.
 */
final class DemoPropertyware
{
    public function __construct(private DemoData $data) {}

    public function respond(Request $request): PromiseInterface
    {
        $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ($request->method()) {
            'GET' => $this->read($path, $query),
            'POST' => match ($path) {
                'workorders' => Http::response($this->createWorkOrder($request->data()), 201),
                'docs' => Http::response($this->uploadDocument($query, $request->data()), 201),
                default => $this->missing(),
            },
            'PUT' => preg_match('#^docs/(\d+)$#', $path, $match)
                ? Http::response($this->updateDocument((int) $match[1], $request->data()))
                : $this->missing(),
            default => $this->missing(),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function read(string $path, array $query): PromiseInterface
    {
        if (preg_match('#^docs/(\d+)/download$#', $path, $match)) {
            return $this->download((int) $match[1]);
        }

        $records = $this->find($path, $query);

        if ($records === null) {
            return $this->missing();
        }

        if (array_is_list($records) && isset($query['limit'])) {
            $records = array_slice($records, (int) ($query['offset'] ?? 0), (int) $query['limit']);
        }

        return Http::response($records);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private function find(string $path, array $query): ?array
    {
        $data = $this->data;
        $segments = explode('/', $path);
        $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;

        return match (true) {
            $path === 'contacts' => [],
            $segments[0] === 'contacts' && count($segments) === 2 => $data->contact((int) $id),
            in_array($segments[0], ['contacts', 'buildings'], true) && ($segments[2] ?? null) === 'conversations' => $this->conversations($segments[0], (int) $id, $query['type'] ?? null),
            $path === 'buildings' => $this->where($data->buildings(), 'portfolioID', $query['portfolioID'] ?? null),
            $segments[0] === 'buildings' && count($segments) === 2 => $this->byId($data->buildings(), $id),
            $path === 'units' => [],
            $path === 'leases/adjustments' => $this->where($data->leaseAdjustments(), 'leaseID', $query['leaseID'] ?? null),
            $path === 'leases' => $this->leases($query),
            $segments[0] === 'leases' && count($segments) === 2 => $this->byId($data->leases(), $id),
            $segments[0] === 'portfolios' && count($segments) === 2 => $data->portfolio((int) $id),
            $path === 'portfolios' => [$data->portfolio(DemoData::PORTFOLIO)],
            $path === 'accounting/glaccounts' => $data->glAccounts(),
            $path === 'accounting/generalledger' => $this->generalLedger($query),
            $path === 'bills' => $this->bills($query),
            $path === 'vendors' => $data->vendors(),
            $segments[0] === 'vendors' && count($segments) === 2 => $this->byId($data->vendors(), $id),
            $path === 'workorders' => $this->where($this->where($this->workOrders(), 'portfolioID', $query['portfolioID'] ?? null), 'buildingID', $query['buildingID'] ?? null),
            $segments[0] === 'workorders' && count($segments) === 2 => $this->byId($this->workOrders(), $id),
            $path === 'docs' => $this->where($this->where($this->documents(), 'entityType', $query['entityType'] ?? null), 'entityId', $query['entityId'] ?? null),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workOrders(): array
    {
        return [...$this->data->workOrders(), ...DemoRecord::payloads('workorder')];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(): array
    {
        $edits = collect(DemoRecord::payloads('document_edit'))->keyBy('id');

        return array_map(
            fn (array $document): array => [...$document, ...($edits[$document['id']] ?? [])],
            [...$this->data->documents(), ...DemoRecord::payloads('document')],
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function leases(array $query): array
    {
        return array_values(array_filter(
            $this->where($this->data->leases(), 'buildingID', $query['buildingID'] ?? null),
            fn (array $lease): bool => (! isset($query['endDateStart']) || $lease['endDate'] >= $query['endDateStart'])
                && (! isset($query['endDateEnd']) || $lease['endDate'] <= $query['endDateEnd']),
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function generalLedger(array $query): array
    {
        if (isset($query['portfolioID']) && (int) $query['portfolioID'] !== DemoData::PORTFOLIO) {
            return [];
        }

        return array_values(array_filter(
            $this->data->generalLedger(),
            fn (array $transaction): bool => (! isset($query['postDateStart']) || $transaction['postDate'] >= $query['postDateStart'])
                && (! isset($query['postDateEnd']) || $transaction['postDate'] <= $query['postDateEnd']),
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function bills(array $query): array
    {
        return array_values(array_filter(
            $this->data->bills(),
            fn (array $bill): bool => ! isset($query['portfolioID'])
                || collect($bill['billSplits'])->contains(fn (array $split): bool => (int) $split['portfolioID'] === (int) $query['portfolioID']),
        ));
    }

    /**
     * Propertyware returns a contact's or building's conversations of the asked type, plus the management team's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversations(string $entity, int $id, ?string $type): array
    {
        $entityType = $entity === 'contacts' ? 'Contact' : 'Building';

        return array_values(array_filter(
            $this->data->conversations(),
            fn (array $conversation): bool => $conversation['entityType'] === $entityType && $conversation['entityID'] === $id
                && ($type === null || $conversation['type'] === $type || $conversation['type'] === 'MANAGEMENT_TEAM'),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createWorkOrder(array $payload): array
    {
        return DemoRecord::store('workorder', fn (int $id): array => [
            'status' => 'Open', 'approved' => true, 'portfolioID' => DemoData::PORTFOLIO, 'assignedVendors' => [],
            ...$payload,
            'id' => (string) $id,
            'number' => $id - DemoRecord::FIRST_ID + 9500,
            'createdDateTime' => now()->toIso8601ZuluString(),
            'lastModifiedDateTime' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Propertyware takes the file as the only multipart field and everything else in the query string.
     *
     * @param  array<string, mixed>  $query
     * @param  array<int, array{name: string, contents: mixed, filename?: string}>  $parts
     * @return array<string, mixed>
     */
    private function uploadDocument(array $query, array $parts): array
    {
        $file = collect($parts)->firstWhere('name', 'file');
        $fileName = (string) ($file['filename'] ?? 'Document');

        return DemoRecord::store('document', function (int $id) use ($query, $file, $fileName): array {
            Storage::disk('local')->put("demo/{$id}", (string) $file['contents']);

            return [
                'id' => $id,
                'fileName' => $fileName,
                'fileType' => str_ends_with(strtolower($fileName), '.pdf') ? 'application/pdf' : 'application/octet-stream',
                'description' => null,
                'entityType' => (string) $query['entityType'],
                'entityId' => (int) $query['entityId'],
                'publishToOwnerPortal' => ($query['publishToOwnerPortal'] ?? 'false') === 'true',
                'publishToTenantPortal' => ($query['publishToTenantPortal'] ?? 'false') === 'true',
                'createdDateTime' => now()->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function updateDocument(int $id, array $changes): array
    {
        DemoRecord::store('document_edit', fn (): array => ['id' => $id, ...array_intersect_key($changes, array_flip(['fileName', 'description']))]);

        return $this->byId($this->documents(), $id) ?? [];
    }

    private function download(int $id): PromiseInterface
    {
        $document = $this->byId($this->documents(), $id);

        if ($document === null) {
            return $this->missing();
        }

        $stored = Storage::disk('local')->get("demo/{$id}");

        return Http::response(
            $stored ?? DemoPdf::make(pathinfo($document['fileName'], PATHINFO_FILENAME)),
            200,
            ['Content-Type' => $document['fileType']],
        );
    }

    /**
     * Keep the rows whose field equals the value, comparing as Propertyware's string query does.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function where(array $rows, string $field, mixed $value): array
    {
        if ($value === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => (string) ($row[$field] ?? '') === (string) $value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function byId(array $rows, ?int $id): ?array
    {
        return collect($rows)->first(fn (array $row): bool => (int) $row['id'] === $id);
    }

    private function missing(): PromiseInterface
    {
        return Http::response(['userMessage' => 'Not available in the demo.'], 404);
    }
}
```

`documents()` merges edits by id. `DemoRecord::store('document_edit', ...)` keeps one row per edit; because `keyBy` keeps the last row for each id, the newest edit wins.

`app/Services/Demo/DemoBackend.php`. This task only routes Propertyware; Task 4 adds the chat hub.

```php
<?php

namespace App\Services\Demo;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Answers every outside call a demo login's request makes, by host.
 *
 * DemoMode points each service at one of these hosts. They are under the
 * reserved .invalid domain, so a call that ever escaped the fake could only
 * fail to resolve. Any other host is left alone (null): scheduling, which holds
 * no client records, stays real.
 */
final class DemoBackend
{
    public const PROPERTYWARE = 'propertyware.demo.invalid';

    public const CHAT_HUB = 'chat-hub.demo.invalid';

    public const LISTINGS = 'tx-cma.demo.invalid';

    public const LEADS = 'leads.demo.invalid';

    public const EXPO = 'expo.demo.invalid';

    public function __construct(private DemoPropertyware $propertyware) {}

    public function respond(Request $request): ?PromiseInterface
    {
        return match (parse_url($request->url(), PHP_URL_HOST)) {
            self::PROPERTYWARE => $this->propertyware->respond($request),
            // No listing photos for made-up homes.
            self::LISTINGS => Http::response(['match_type' => 'none']),
            // A referral from a demo login is accepted and dropped.
            self::LEADS => Http::response(['ok' => true]),
            // Pushes to a demo login's phone are dropped.
            self::EXPO => Http::response(['data' => []]),
            default => null,
        };
    }
}
```

`app/Http/Middleware/DemoMode.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\ChatHub\ChatHubClient;
use App\Services\Demo\DemoBackend;
use App\Services\Demo\DemoPropertywareSoap;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\LeaseLedgerSource;
use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Push\ExpoPushClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Serves an App Review login made-up records instead of Propertyware, the
 * chat hub and the other outside systems, for the rest of its request.
 *
 * Every change here is to the container and config of this one request. That
 * is safe because the portal runs on PHP-FPM, a fresh process per request.
 * Under Octane or any long-lived worker it would leak into the next request,
 * so revisit this before adopting one.
 */
class DemoMode
{
    /**
     * The outside clients, and the services built on them, rebuilt once their config points at the demo.
     *
     * @var array<int, class-string>
     */
    private const REBUILT = [
        PropertywareClient::class, ChatHubClient::class, ExpoPushClient::class,
        PropertywareDirectory::class, Financials::class, LeaseLedgerSource::class, LeaseLedgerStore::class,
        LeaseLedger::class, DocumentLibrary::class, MaintenanceSummary::class,
    ];

    public static function cacheStore(): string
    {
        return 'demo';
    }

    /**
     * Switch the current request to the demo.
     */
    public static function enter(): void
    {
        $default = config('cache.default');

        config([
            'services.propertyware.base_url' => 'https://'.DemoBackend::PROPERTYWARE,
            'services.propertyware.client_id' => 'demo',
            'services.propertyware.client_secret' => 'demo',
            'services.propertyware.system_id' => 'demo',
            'services.propertyware.discover_accounts' => false,
            'services.chat_hub.url' => 'https://'.DemoBackend::CHAT_HUB.'/api/v1',
            'services.chat_hub.token' => 'demo',
            'services.tx_cma.url' => 'https://'.DemoBackend::LISTINGS,
            'services.tx_cma.token' => 'demo',
            'services.leads.webhook_url' => 'https://'.DemoBackend::LEADS.'/referrals',
            'services.expo.url' => 'https://'.DemoBackend::EXPO,
            // The same store under its own prefix, so a shared key such as the
            // account-wide building list never mixes demo and real records.
            'cache.stores.'.self::cacheStore() => [...config("cache.stores.{$default}"), 'prefix' => 'demo_'],
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ]);

        foreach (self::REBUILT as $service) {
            app()->forgetInstance($service);
        }

        app()->instance(PropertywareSoap::class, new DemoPropertywareSoap);
        Cache::setDefaultDriver(self::cacheStore());
        Http::fake(app(DemoBackend::class)->respond(...));
    }
}
```

Two framework details to check while implementing:
- **The `null` fall-through.** Open `vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php` (`fake()`, near line 331) and `PendingRequest::stub`. Confirm that a stub callback returning `null` falls through to the next stub or a real request. If it doesn't, return `Http::response()` only for demo hosts and pass the rest on with `allowStrayRequests` (near line 451).
- **The forgotten singletons.** The provider must rebuild them. `forgetInstance` drops the cached object but keeps the singleton binding, so the next `make` builds it again from config.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DemoPropertywareTest`
Expected: all pass. If a filter test fails, fix the filter in `DemoPropertyware`; never loosen the test.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Services/Demo app/Http/Middleware/DemoMode.php tests/Feature/Demo/DemoPropertywareTest.php
git commit -m "Answer Propertyware from the made-up records for a demo request"
```

---

### Task 4: Answer the chat hub for the demo

**Files:**
- Create: `app/Services/Demo/DemoChatHub.php`
- Modify: `app/Services/Demo/DemoBackend.php` (constructor and the `CHAT_HUB` arm)
- Test: `tests/Feature/Demo/DemoChatHubTest.php`

**Interfaces:**
- Consumes: `DemoData::chatThreads()`, `DemoData::chatMessages()`, `DemoRecord::store/payloads`, `DemoMode::enter()`.
- Produces: `DemoChatHub::respond(Request $request): PromiseInterface`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Middleware\DemoMode;
use App\Services\ChatHub\ChatHubClient;
use App\Services\ChatHub\ChatHubException;
use App\Services\Demo\DemoData;

beforeEach(function () {
    DemoMode::enter();
    $this->hub = app(ChatHubClient::class);
});

test('lists only the demo contact\'s own thread, with its last message', function () {
    $threads = $this->hub->threads((string) DemoData::TENANT_CONTACT);

    expect(collect($threads['data'])->pluck('id')->all())->toBe([DemoData::TENANT_THREAD])
        ->and($threads['data'][0]['last_message']['sender'])->toBe('agent')
        ->and($threads['meta'])->toMatchArray(['current_page' => 1, 'last_page' => 1, 'total' => 1]);
});

test('reads a thread and its messages oldest first', function () {
    expect($this->hub->thread(DemoData::OWNER_THREAD)['contact_id'])->toBe((string) DemoData::OWNER_CONTACT)
        ->and(collect($this->hub->messages(DemoData::OWNER_THREAD))->pluck('direction')->all())->toBe(['inbound', 'outbound']);
});

test('opens a thread and posts to it without leaving the portal', function () {
    $thread = $this->hub->openThread((string) DemoData::OWNER_CONTACT, null, 'leasing');
    $this->hub->postClientMessage($thread['id'], 'When will the vacant home be listed?', 'key-1');

    expect($thread['subject'])->toBe('Leasing question')
        ->and(collect($this->hub->threads((string) DemoData::OWNER_CONTACT)['data'])->pluck('id'))->toContain($thread['id'])
        ->and(collect($this->hub->messages($thread['id']))->pluck('body')->all())->toBe(['When will the vacant home be listed?']);
});

test('a thread that is not the demo\'s is not found', function () {
    $this->hub->thread(1);
})->throws(ChatHubException::class, '404');

test('remembers the sms preference', function () {
    expect($this->hub->setSmsReplies((string) DemoData::OWNER_CONTACT, true)['sms_replies'])->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoChatHubTest`
Expected: FAIL. The hub host isn't answered, so the request is a stray one, and `preventStrayRequests` in `tests/Pest.php` throws.

- [ ] **Step 3: Implement**

`app/Services/Demo/DemoChatHub.php`:

```php
<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The messaging hub for the demo logins: their made-up threads plus what the
 * reviewer wrote. Nothing reaches the hub, so staff never see demo messages.
 */
final class DemoChatHub
{
    public function __construct(private DemoData $data) {}

    public function respond(Request $request): PromiseInterface
    {
        $path = (string) preg_replace('#^api/v1/#', '', trim((string) parse_url($request->url(), PHP_URL_PATH), '/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $segments = explode('/', $path);
        $id = (int) ($segments[1] ?? 0);

        return match (true) {
            $request->method() === 'GET' && $path === 'threads' => $this->list((string) ($query['contact_id'] ?? ''), (int) ($query['page'] ?? 1)),
            $request->method() === 'GET' && $segments[0] === 'threads' && count($segments) === 2 => $this->show($id),
            $request->method() === 'GET' && $segments[0] === 'threads' && ($segments[2] ?? null) === 'messages' => Http::response(['data' => $this->messages($id)
                ->filter(fn (array $message): bool => $message['id'] > (int) ($query['after_id'] ?? 0))->values()->all()]),
            $request->method() === 'POST' && $path === 'threads' => Http::response(['data' => $this->open($this->fields($request))], 201),
            $request->method() === 'POST' && $segments[0] === 'threads' && ($segments[2] ?? null) === 'messages' => $this->post($id, $this->fields($request)),
            $segments[0] === 'contacts' && count($segments) === 2 => Http::response(['data' => [
                'contact_id' => $segments[1],
                'sms_replies' => (bool) ($this->fields($request)['sms_replies'] ?? false),
            ]]),
            default => Http::response(['message' => 'Not found.'], 404),
        };
    }

    private function list(string $contactId, int $page): PromiseInterface
    {
        $threads = $page === 1 ? $this->threads()->where('contact_id', $contactId)->values()->all() : [];

        return Http::response(['data' => $threads, 'meta' => ['current_page' => $page, 'last_page' => 1, 'total' => count($threads)]]);
    }

    private function show(int $id): PromiseInterface
    {
        $thread = $this->threads()->firstWhere('id', $id);

        return $thread === null ? Http::response(['message' => 'Not found.'], 404) : Http::response(['data' => $thread]);
    }

    /**
     * Threads with their last message and when they last changed, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function threads(): Collection
    {
        return collect([...$this->data->chatThreads(), ...DemoRecord::payloads('chat_thread')])
            ->map(function (array $thread): array {
                $last = $this->messages($thread['id'])->last();

                return [...$thread, 'last_message' => $last, 'updated_at' => $last['created_at'] ?? $thread['created_at'] ?? now()->toIso8601String()];
            })
            ->sortByDesc('updated_at')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function messages(int $threadId): Collection
    {
        return collect([...$this->data->chatMessages(), ...DemoRecord::payloads('chat_message')])
            ->where('thread_id', $threadId)
            ->sortBy('id')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function open(array $fields): array
    {
        return DemoRecord::store('chat_thread', fn (int $id): array => [
            'id' => $id, 'status' => 'open',
            'subject' => $fields['subject'] ?? ucfirst((string) ($fields['category'] ?? 'general')).' question',
            'type' => $fields['category'] ?? null, 'contact_id' => (string) ($fields['contact_id'] ?? ''), 'phone' => null,
            'work_order_id' => null, 'work_order_number' => null, 'assigned_to' => null, 'assigned_to_photo_url' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function post(int $threadId, array $fields): PromiseInterface
    {
        if ($this->threads()->firstWhere('id', $threadId) === null) {
            return Http::response(['message' => 'Not found.'], 404);
        }

        $message = DemoRecord::store('chat_message', fn (int $id): array => [
            'id' => $id, 'thread_id' => $threadId, 'direction' => 'inbound', 'sender' => 'user', 'sender_name' => null,
            'sender_photo_url' => null, 'body' => $fields['body'] ?? null, 'media_urls' => [], 'sender_number' => null,
            'created_at' => now()->toIso8601String(),
        ]);

        return Http::response(['data' => ['id' => $message['id']]], 201);
    }

    /**
     * The request's fields, whether sent as JSON or, with an attachment, as multipart.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        if (! $request->isMultipart()) {
            return $request->data();
        }

        return collect($request->data())
            ->reject(fn (array $part): bool => isset($part['filename']))
            ->mapWithKeys(fn (array $part): array => [$part['name'] => (string) $part['contents']])
            ->all();
    }
}
```

In `DemoBackend`, change the constructor to `public function __construct(private DemoPropertyware $propertyware, private DemoChatHub $chatHub) {}` and add the arm `self::CHAT_HUB => $this->chatHub->respond($request),`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='DemoChatHubTest|DemoPropertywareTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Services/Demo/DemoChatHub.php app/Services/Demo/DemoBackend.php tests/Feature/Demo/DemoChatHubTest.php
git commit -m "Answer the messaging hub from the demo threads"
```

---

### Task 5: Switch demo logins' requests over

**Files:**
- Modify: `app/Http/Middleware/DemoMode.php` (add `handle()`)
- Modify: `bootstrap/app.php` (the web and api groups)
- Test: `tests/Feature/Demo/DemoModeTest.php`

**Interfaces:**
- Consumes: `DemoMode::enter()`, `User::$is_demo`, `User::factory()->demo()`.
- Produces: `DemoMode::handle(Request $request, Closure $next): Response`, and the test helper `demoLogin(ClientRole $role): array{0: User, 1: ClientAccount}` in `tests/Pest.php`, used by Tasks 6–8.

- [ ] **Step 1: Write the failing test**

Add this helper to `tests/Pest.php`, below `partialGet`:

```php
/**
 * One of the App Review logins, linked to its made-up Propertyware contact.
 *
 * @return array{0: User, 1: ClientAccount}
 */
function demoLogin(ClientRole $role): array
{
    $user = User::factory()->demo()->create([
        'email' => $role === ClientRole::Owner ? 'appreview.owner@texasrenters.com' : 'appreview.tenant@texasrenters.com',
    ]);
    $account = ClientAccount::factory()->for($user)->create([
        'role' => $role,
        'propertyware_id' => (string) ($role === ClientRole::Owner ? DemoData::OWNER_CONTACT : DemoData::TENANT_CONTACT),
    ]);

    return [$user, $account];
}
```

Add the `use` lines it needs: `App\Enums\ClientRole`, `App\Models\ClientAccount`, `App\Models\User` and `App\Services\Demo\DemoData`. Check `ClientAccountFactory` for the attribute names; it may expose `owner()` and `resident()` states, as `AccountDataTest` uses, rather than taking `role`.

`tests/Feature/Demo/DemoModeTest.php`:

```php
<?php

use App\Enums\ClientRole;
use App\Http\Middleware\DemoMode;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Demo\DemoData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

test('a demo login\'s app request is answered by the demo', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.properties.index', $account))->assertOk()
        ->assertJsonFragment(['id' => DemoData::HOME_VACANT]);

    expect(Cache::getDefaultDriver())->toBe(DemoMode::cacheStore());
    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
});

test('a guest is left alone', function () {
    $this->get(route('resident.lease'))->assertRedirect();

    expect(Cache::getDefaultDriver())->not->toBe(DemoMode::cacheStore());
});

test('a demo login\'s web page is answered by the demo', function () {
    [$user] = demoLogin(ClientRole::Resident);

    $this->actingAs($user);
    partialGet(route('resident.lease'), 'resident/Lease', 'lease')->assertOk()
        ->assertJsonPath('props.lease.baseRent', DemoData::RENT);
});

test('a real login still reads Propertyware, with the real cache and account discovery', function () {
    config([
        'services.propertyware.base_url' => 'https://pw.test',
        'services.propertyware.client_id' => 'id', 'services.propertyware.client_secret' => 'secret', 'services.propertyware.system_id' => 'sys',
        'services.propertyware.discover_accounts' => true,
    ]);
    Http::fake([
        'pw.test/contacts?*' => Http::response([]),
        'pw.test/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'P']]]),
        'pw.test/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        'pw.test/units*' => Http::response([]),
    ]);
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.me'))->assertOk();
    $this->getJson(route('api.v1.accounts.properties.index', $account))->assertOk()->assertJsonFragment(['id' => 7]);

    expect(Cache::getDefaultDriver())->not->toBe(DemoMode::cacheStore());
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://pw.test/contacts?'));
    Http::assertNotSent(fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
});

test('a demo login never goes looking for more Propertyware accounts', function () {
    [$user] = demoLogin(ClientRole::Owner);
    config(['services.propertyware.discover_accounts' => true]);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.me'))->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'contacts?email'));
    expect($user->clientAccounts()->count())->toBe(1);
});
```

The deferred prop name `lease` and the field `baseRent` come from `tests/Feature/Resident/LeaseTest.php`; match what that test asserts.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoModeTest`
Expected: the demo tests FAIL with a stray request to `api.propertyware.com`, because the middleware isn't registered yet. The real-login test should already pass.

- [ ] **Step 3: Implement**

Add to `DemoMode`:

```php
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // The app's token is resolved by the route's auth middleware, which
        // runs after this group, so ask the Sanctum guard directly.
        $user = $request->user() ?? $request->user('sanctum');

        if ($user instanceof User && $user->is_demo) {
            self::enter();
        }

        return $next($request);
    }
```

Add these imports: `App\Models\User`, `Closure`, `Illuminate\Http\Request` and `Symfony\Component\HttpFoundation\Response`.

In `bootstrap/app.php`:
- Import `App\Http\Middleware\DemoMode`.
- Change `$middleware->appendToGroup('api', [EnsureUserIsNotSuspended::class, TrackLastSeen::class]);` to `$middleware->appendToGroup('api', [EnsureUserIsNotSuspended::class, TrackLastSeen::class, DemoMode::class]);`.
- In `$middleware->web(append: [...])`, insert `DemoMode::class,` right after `TrackLastSeen::class,`, before `SyncPropertywareAccounts::class`. Add the comment: `// Before anything reads Propertyware for the page: an App Review login sees made-up records.`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DemoModeTest && composer test`
Expected: PASS. The whole suite stays green, because non-demo users are unaffected.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Http/Middleware/DemoMode.php bootstrap/app.php tests/Pest.php tests/Feature/Demo/DemoModeTest.php
git commit -m "Serve the demo logins made-up records on every request"
```

---

### Task 6: Every screen loads for both demo logins

**Files:**
- Test: `tests/Feature/Demo/DemoScreensTest.php`
- Modify, as gaps show up: `app/Services/Demo/DemoData.php` and `app/Services/Demo/DemoPropertyware.php`

**Interfaces:**
- Consumes: `demoLogin()` and `DemoData` constants.

- [ ] **Step 1: Write the test**

```php
<?php

use App\Enums\ClientRole;
use App\Services\Demo\DemoData;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Scheduling stays real for the demo; here it is faked like any test would.
    config(['services.scheduling.url' => 'https://scheduling.test', 'services.scheduling.token' => 't']);
    Http::fake(['scheduling.test/*' => Http::response(['data' => []])]);
});

/**
 * Every call the request made to a demo host was answered: a quiet 404 on a
 * deferred prop would otherwise pass as "unavailable" with a 200 page.
 */
function expectDemoAnsweredEverything(): void
{
    $failed = Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_HOST), '.demo.invalid') && $pair[1]->status() >= 400)
        ->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())
        ->values()
        ->all();

    expect($failed)->toBe([]);
}

dataset('owner pages', [
    'dashboard' => ['owner.dashboard', []],
    'portfolio' => ['owner.portfolio', []],
    'properties' => ['owner.properties.index', []],
    'leased home' => ['owner.properties.show', ['property' => DemoData::HOME_LEASED]],
    'vacant home' => ['owner.properties.show', ['property' => DemoData::HOME_VACANT]],
    'financials' => ['owner.financials', []],
    'statements' => ['owner.statements', []],
    'maintenance' => ['owner.maintenance.index', []],
    'work order waiting' => ['owner.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_APPROVAL]],
    'work order done' => ['owner.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'documents' => ['owner.documents', []],
    'conversations' => ['owner.conversations.index', []],
    'messages' => ['owner.messages.index', []],
    'a message thread' => ['owner.messages.index', ['thread' => DemoData::OWNER_THREAD]],
    'meetings' => ['owner.meetings.index', []],
    'referrals' => ['owner.referrals.index', []],
]);

dataset('tenant pages', [
    'dashboard' => ['resident.dashboard', []],
    'lease' => ['resident.lease', []],
    'property' => ['resident.property', []],
    'maintenance' => ['resident.maintenance.index', []],
    'work order' => ['resident.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_GARAGE]],
    'documents' => ['resident.documents', []],
    'conversations' => ['resident.conversations.index', []],
    'messages' => ['resident.messages.index', []],
    'a message thread' => ['resident.messages.index', ['thread' => DemoData::TENANT_THREAD]],
    'referrals' => ['resident.referrals.index', []],
]);

test('the demo owner sees every web page with its deferred data', function (string $route, array $parameters) {
    [$user] = demoLogin(ClientRole::Owner);

    $this->actingAs($user)->get(route($route, $parameters))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload));

    expectDemoAnsweredEverything();
})->with('owner pages');

test('the demo tenant sees every web page with its deferred data', function (string $route, array $parameters) {
    [$user] = demoLogin(ClientRole::Resident);

    $this->actingAs($user)->get(route($route, $parameters))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload));

    expectDemoAnsweredEverything();
})->with('tenant pages');

test('demo documents open as pdfs on the web', function (ClientRole $role, string $route, array $parameters) {
    [$user] = demoLogin($role);

    $response = $this->actingAs($user)->get(route($route, $parameters))->assertOk();

    expect($response->streamedContent())->toStartWith('%PDF-1.4');
})->with([
    'owner statement' => [ClientRole::Owner, 'owner.documents.download', ['document' => DemoData::DOC_STATEMENT]],
    'owner invoice on a work order' => [ClientRole::Owner, 'owner.maintenance.documents.download', ['workOrder' => DemoData::WORK_ORDER_DONE, 'document' => DemoData::DOC_INVOICE]],
    'tenant lease' => [ClientRole::Resident, 'resident.documents.download', ['document' => DemoData::DOC_LEASE]],
]);

dataset('owner api', [
    'dashboard' => ['dashboard', []], 'portfolio' => ['portfolio', []], 'financials' => ['financials', []],
    'ledger' => ['ledger', []], 'properties' => ['properties.index', []],
    'leased home' => ['properties.show', ['property' => DemoData::HOME_LEASED]],
    'approvals' => ['approvals.index', []], 'meetings' => ['meetings.index', []], 'contact details' => ['contact-details', []],
    'messages' => ['messages.index', []], 'message thread' => ['messages.show', ['thread' => DemoData::OWNER_THREAD]],
    'referrals' => ['referrals.index', []], 'conversations' => ['conversations.index', []],
    'maintenance' => ['maintenance.index', []], 'request options' => ['maintenance.request-options', []],
    'work order' => ['maintenance.show', ['workOrder' => DemoData::WORK_ORDER_APPROVAL]],
    'work order documents' => ['maintenance.documents.index', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'work order conversations' => ['maintenance.conversations.index', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'documents' => ['documents.index', []], 'upload targets' => ['documents.upload-targets', []],
    'document' => ['documents.show', ['document' => DemoData::DOC_STATEMENT]],
]);

dataset('tenant api', [
    'dashboard' => ['dashboard', []], 'lease' => ['lease', []], 'payments' => ['payments', []],
    'meetings' => ['meetings.index', []], 'contact details' => ['contact-details', []],
    'messages' => ['messages.index', []], 'message thread' => ['messages.show', ['thread' => DemoData::TENANT_THREAD]],
    'referrals' => ['referrals.index', []], 'conversations' => ['conversations.index', []],
    'maintenance' => ['maintenance.index', []], 'request options' => ['maintenance.request-options', []],
    'work order' => ['maintenance.show', ['workOrder' => DemoData::WORK_ORDER_GARAGE]],
    'documents' => ['documents.index', []], 'document' => ['documents.show', ['document' => DemoData::DOC_LEASE]],
]);

test('the demo owner\'s app gets every endpoint', function (string $route, array $parameters) {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->get(route('api.v1.accounts.'.$route, ['account' => $account, ...$parameters]), ['Accept' => 'application/json'])->assertOk();

    expectDemoAnsweredEverything();
})->with('owner api');

test('the demo tenant\'s app gets every endpoint', function (string $route, array $parameters) {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->get(route('api.v1.accounts.'.$route, ['account' => $account, ...$parameters]), ['Accept' => 'application/json'])->assertOk();

    expectDemoAnsweredEverything();
})->with('tenant api');

test('the owner\'s app shows the work order waiting for approval', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.approvals.index', $account))->assertOk()
        ->assertJsonFragment(['id' => (string) DemoData::WORK_ORDER_APPROVAL]);
});

test('the tenant\'s app shows rent due and the lease ending soon', function () {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.lease', $account))->assertOk()
        ->assertJsonFragment(['endDate' => today('America/Chicago')->addDays(DemoData::LEASE_DAYS_LEFT)->toDateString()]);
});
```

- [ ] **Step 2: Run the test and work through the gaps**

Run: `php artisan test --compact --filter=DemoScreensTest`

Each failure shows one of four things. Fix each in the source, never by weakening the test:
- **`failed` lists a path that answered 404.** Add that path to `DemoPropertyware::find()`. Copy the response shape from the test that fakes it: `grep -rn "'\*/<path>" tests/Feature`.
- **A page throws on a missing field** (`Undefined array key "x"` in a `Data/*.php` class). Add the field to the matching `DemoData` record, using the value the existing tests give it.
- **A route needs a query parameter** (a 422 naming it, for example `portfolio` on `ledger`). Add that parameter to the dataset row, with the value its FormRequest expects (`DemoData::PORTFOLIO` for a portfolio).
- **An assertion path is wrong** (for example the approvals list keys work orders as `workOrderId`). Read the controller's resource and correct the path in the test.

Each round, rerun and confirm the count of failures drops.

- [ ] **Step 3: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DemoScreensTest`
Expected: every dataset row passes.

- [ ] **Step 4: Commit**

```bash
composer lint
git add tests/Feature/Demo/DemoScreensTest.php app/Services/Demo
git commit -m "Prove every owner and tenant screen loads for the demo logins"
```

---

### Task 7: Demo actions work and stay in the portal

**Files:**
- Modify: `app/Services/Propertyware/WorkOrderApprovals.php` (`pushPending`)
- Test: `tests/Feature/Demo/DemoActionsTest.php`

**Interfaces:**
- Consumes: `demoLogin()`, `DemoData`, `DemoRecord`, `MaintenanceSummary::clientCategories()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ClientRole;
use App\Models\DemoRecord;
use App\Models\WorkOrderApproval;
use App\Services\Demo\DemoData;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
});

test('the tenant\'s maintenance request is kept for the demo and listed', function () {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => MaintenanceSummary::clientCategories()[0],
        'description' => 'Smoke alarm keeps chirping',
        'urgency' => 'normal',
        'entry' => 'anytime',
    ])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.maintenance.index', [$account, 'status' => 'all']))->assertOk()
        ->assertJsonFragment(['description' => 'Smoke alarm keeps chirping']);
    expect(DemoRecord::payloads('workorder'))->toHaveCount(1);
});

test('the owner\'s document upload is kept and downloads again', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->post(route('api.v1.accounts.documents.store', $account), [
        'file' => UploadedFile::fake()->createWithContent('W-9.pdf', '%PDF-1.4 w9'),
        'description' => 'Signed W-9',
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.documents.index', $account))->assertOk()->assertJsonFragment(['description' => 'Signed W-9']);
});

test('the owner approves the waiting work order and it is never sent to Propertyware', function () {
    config(['services.propertyware.approval_push' => false]);
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.maintenance.approval', [$account, DemoData::WORK_ORDER_APPROVAL]), ['decision' => 'approved'])
        ->assertSuccessful();

    expect(WorkOrderApproval::sole()->decision)->toBe('approved');

    // Later, the scheduled push runs with the real settings, outside any demo request.
    expect(app(WorkOrderApprovals::class)->pushPending())->toBe(['sent' => 0, 'failed' => 0]);
});

test('messages open and reply inside the portal', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $thread = $this->postJson(route('api.v1.accounts.messages.store', $account), ['category' => 'leasing', 'body' => 'When will the vacant home be listed?'])
        ->assertSuccessful()->json('data.id');
    $this->postJson(route('api.v1.accounts.messages.reply', [$account, $thread]), ['body' => 'Thanks!'])->assertSuccessful();

    $this->getJson(route('api.v1.accounts.messages.show', [$account, $thread]))->assertOk()
        ->assertJsonFragment(['body' => 'When will the vacant home be listed?'])
        ->assertJsonFragment(['body' => 'Thanks!']);
});

test('a referral and a help request from a demo login reach nobody', function () {
    Mail::fake();
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.referrals.store', $account), [
        'first_name' => 'Pat', 'last_name' => 'Example', 'email' => 'pat@example.com', 'phone' => '281-555-0199', 'property_address' => '1 Example St',
    ])->assertSuccessful();
    $this->postJson(route('api.v1.help-requests.store'), ['topic' => 'other', 'message' => 'Just trying the help form out.'])->assertSuccessful();

    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
    Mail::assertNothingOutgoing();
});
```

Before running:
- Check `App\Enums\HelpTopic` for a valid case value, and replace `'other'` if it isn't one.
- `Mail::fake()` swaps the mailer before the request, so the `mail.default => 'array'` switch isn't what this test proves. What it proves is that nothing is sent from the demo request at all.
- If the help request does notify staff, the assertion fails. Then add a demo check to `HelpDesk::submit` that skips staff notification for `is_demo` users, and keep the test as written.
- If the approval decision column isn't named `decision`, use the name in `tests/Feature/Owner/WorkOrderApprovalTest.php`.
- If `messages.store` returns the thread id under a different key, use the key in `tests/Feature/MessagesTest.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoActionsTest`
Expected: the approval test FAILS. With `approval_push` off, the demo approval stays unsynced, so `pushPending` picks it up and tries to send it. Fix any other failure the way Task 6 Step 2 describes.

- [ ] **Step 3: Implement**

In `WorkOrderApprovals::pushPending()`, add this to the query, after the `sync_error` condition:

```php
            // The App Review logins' approvals are for made-up work orders.
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='DemoActionsTest|WorkOrderApproval'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Services/Propertyware/WorkOrderApprovals.php tests/Feature/Demo/DemoActionsTest.php
git commit -m "Keep what the demo logins create inside the portal, and never push their approvals"
```

---

### Task 8: Keep the review logins intact

**Files:**
- Modify: `app/Http/Requests/Settings/ProfileUpdateRequest.php`
- Modify: `app/Http/Requests/Settings/ProfileDeleteRequest.php`
- Test: `tests/Feature/Demo/DemoAccountProtectionTest.php`

**Interfaces:**
- Consumes: `demoLogin()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ClientRole;
use Laravel\Sanctum\Sanctum;

test('a demo login cannot change its email in the app or on the web', function () {
    [$user] = demoLogin(ClientRole::Owner);

    Sanctum::actingAs($user);
    $this->patchJson(route('api.v1.profile.update'), ['name' => 'Demo Owner', 'email' => 'other@example.com'])
        ->assertJsonValidationErrors(['email' => 'The demo account\'s email can\'t be changed.']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Demo Owner', 'email' => 'other@example.com'])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('appreview.owner@texasrenters.com');
});

test('a demo login can still change its name', function () {
    [$user] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => 'Apple Reviewer', 'email' => $user->email])->assertOk();
});

test('a demo login cannot delete itself', function () {
    [$user] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertForbidden();

    expect($user->fresh())->not->toBeNull();
});

test('a real login can still change its email', function () {
    $user = App\Models\User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])->assertOk();
});
```

The factory password is `password`, which is the Laravel default; confirm it in `UserFactory::definition()`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoAccountProtectionTest`
Expected: the email and delete tests FAIL.

- [ ] **Step 3: Implement**

`ProfileUpdateRequest`:

```php
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // The App Review login's email is in App Store Connect; it stays put.
        if ($this->user()->is_demo) {
            $rules['email'][] = Rule::in([$this->user()->email]);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.in' => __('The demo account\'s email can\'t be changed.')];
    }
```

Import `Illuminate\Validation\Rule`. If `emailRules()` returns a string rather than an array, wrap it first: `$rules['email'] = [...(array) $rules['email'], Rule::in(...)]`.

`ProfileDeleteRequest`:

```php
    /**
     * The App Review logins can't be deleted from inside the app.
     */
    public function authorize(): bool
    {
        return ! $this->user()->is_demo;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='DemoAccountProtectionTest|Profile'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Http/Requests/Settings/ProfileUpdateRequest.php app/Http/Requests/Settings/ProfileDeleteRequest.php tests/Feature/Demo/DemoAccountProtectionTest.php
git commit -m "Keep the demo logins' email and account from being changed or deleted"
```

---

### Task 9: Create and reset the demo logins

**Files:**
- Create: `app/Services/Demo/DemoReset.php`
- Create: `app/Console/Commands/DemoAccounts.php`
- Create: `app/Console/Commands/DemoResetCommand.php`
- Modify: `app/Http/Middleware/DemoMode.php` (cache prefix generation)
- Modify: `routes/console.php`
- Test: `tests/Feature/Demo/DemoAccountsCommandTest.php`

**Interfaces:**
- Consumes: `DemoRecord`, `DemoData`, `Setting::valueOf/put`, `WorkOrderApproval`, `HelpRequest`, `Referral`, `ConversationRead`, `LeaseLedgerEntry`, `LeaseLedgerSync`.
- Produces:
  - `DemoReset::handle(): void`.
  - Setting key `demo.cache_generation`. The demo cache prefix becomes `demo_{generation}_`, so a reset abandons every demo cache key at once on any cache driver, without flushing the shared store.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ClientRole;
use App\Models\DemoRecord;
use App\Models\User;
use App\Models\WorkOrderApproval;
use App\Services\Demo\DemoData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('creates both demo logins, verified and linked to the made-up contacts', function () {
    Notification::fake();

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertSuccessful();

    $owner = User::where('email', 'appreview.owner@texasrenters.com')->sole();
    $tenant = User::where('email', 'appreview.tenant@texasrenters.com')->sole();

    expect($owner)->is_demo->toBeTrue()->name->toBe('Demo Owner')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Owner-pass-2026!', $owner->password))->toBeTrue()
        ->and($owner->clientAccounts->map(fn ($a) => [$a->role, $a->propertyware_id])->all())->toBe([[ClientRole::Owner, (string) DemoData::OWNER_CONTACT]])
        ->and($tenant->clientAccounts->map(fn ($a) => [$a->role, $a->propertyware_id])->all())->toBe([[ClientRole::Resident, (string) DemoData::TENANT_CONTACT]]);
    Notification::assertNothingSent();
});

test('running it again resets the passwords and keeps one account each', function () {
    $this->artisan('demo:accounts', ['--owner-password' => 'First-pass-2026!', '--tenant-password' => 'First-pass-2026!'])->assertSuccessful();
    $this->artisan('demo:accounts', ['--owner-password' => 'Second-pass-2026!', '--tenant-password' => 'Second-pass-2026!'])->assertSuccessful();

    $owner = User::where('email', 'appreview.owner@texasrenters.com')->sole();

    expect(Hash::check('Second-pass-2026!', $owner->password))->toBeTrue()
        ->and($owner->clientAccounts)->toHaveCount(1);
});

test('refuses to take over an existing real login with the demo email', function () {
    User::factory()->create(['email' => 'appreview.owner@texasrenters.com']);

    $this->artisan('demo:accounts', ['--owner-password' => 'Owner-pass-2026!', '--tenant-password' => 'Tenant-pass-2026!'])->assertFailed();

    expect(User::where('email', 'appreview.owner@texasrenters.com')->sole()->is_demo)->toBeFalse();
});

test('reset wipes what the reviewer created and starts a fresh demo cache', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    DemoRecord::store('workorder', fn (int $id) => ['id' => (string) $id]);
    WorkOrderApproval::factory()->for($user)->for($account)->create();
    $real = WorkOrderApproval::factory()->create();
    $before = App\Models\Setting::valueOf('demo.cache_generation', '0');

    $this->artisan('demo:reset')->assertSuccessful();

    expect(DemoRecord::count())->toBe(0)
        ->and(WorkOrderApproval::pluck('id')->all())->toBe([$real->id])
        ->and(App\Models\Setting::valueOf('demo.cache_generation'))->not->toBe($before);
});

test('reset runs every night at 3am Chicago time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'demo:reset'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('America/Chicago');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DemoAccountsCommandTest`
Expected: FAIL with "The command "demo:accounts" does not exist."

- [ ] **Step 3: Implement**

`app/Services/Demo/DemoReset.php`:

```php
<?php

namespace App\Services\Demo;

use App\Models\ConversationRead;
use App\Models\DemoRecord;
use App\Models\HelpRequest;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkOrderApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Put the demo back to its made-up starting point, so each review starts clean.
 */
final class DemoReset
{
    public const CACHE_GENERATION = 'demo.cache_generation';

    public function handle(): void
    {
        $users = User::query()->where('is_demo', true)->pluck('id');

        DB::transaction(function () use ($users): void {
            DemoRecord::query()->delete();
            WorkOrderApproval::query()->whereIn('user_id', $users)->delete();
            HelpRequest::query()->whereIn('user_id', $users)->delete();
            Referral::query()->whereIn('user_id', $users)->delete();
            ConversationRead::query()->whereIn('user_id', $users)->delete();
            LeaseLedgerEntry::query()->where('lease_id', (string) DemoData::LEASE)->delete();
            LeaseLedgerSync::query()->where('lease_id', (string) DemoData::LEASE)->delete();
            DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $users)->delete();
        });

        Storage::disk('local')->deleteDirectory('demo');

        // A new prefix abandons every demo cache key at once, on any driver,
        // without flushing the store real users share; the old keys expire.
        Setting::put(self::CACHE_GENERATION, (string) ((int) Setting::valueOf(self::CACHE_GENERATION, '0') + 1));
    }
}
```

Check the column names against the migrations: `help_requests.user_id`, `referrals.user_id` and `conversation_reads.user_id`. For example, `referrals` may key the referrer as `referrer_id`; use whatever the table actually has.

In `DemoMode::enter()`, change the cache prefix line to:

```php
            'cache.stores.'.self::cacheStore() => [...config("cache.stores.{$default}"), 'prefix' => 'demo_'.Setting::valueOf(DemoReset::CACHE_GENERATION, '0').'_'],
```

Import `App\Models\Setting` and `App\Services\Demo\DemoReset`.

`app/Console/Commands/DemoResetCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset')]
#[Description('Wipe what the App Review logins created and start their demo afresh')]
class DemoResetCommand extends Command
{
    public function handle(DemoReset $reset): int
    {
        $reset->handle();
        $this->components->info('The demo is back to its starting point.');

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/DemoAccounts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\User;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

#[Signature('demo:accounts {--owner-password= : Password for the demo owner} {--tenant-password= : Password for the demo tenant}')]
#[Description('Create or refresh the App Review demo owner and tenant logins')]
class DemoAccounts extends Command
{
    /**
     * @var array<string, array{name: string, role: ClientRole, contact: int}>
     */
    private const LOGINS = [
        'appreview.owner@texasrenters.com' => ['name' => 'Demo Owner', 'role' => ClientRole::Owner, 'contact' => DemoData::OWNER_CONTACT],
        'appreview.tenant@texasrenters.com' => ['name' => 'Demo Tenant', 'role' => ClientRole::Resident, 'contact' => DemoData::TENANT_CONTACT],
    ];

    public function handle(DemoReset $reset): int
    {
        $passwords = [
            'appreview.owner@texasrenters.com' => $this->option('owner-password') ?: password('Password for the demo owner'),
            'appreview.tenant@texasrenters.com' => $this->option('tenant-password') ?: password('Password for the demo tenant'),
        ];

        foreach ($passwords as $email => $password) {
            $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

            if ($validator->fails()) {
                $this->components->error("{$email}: ".$validator->errors()->first('password'));

                return self::FAILURE;
            }

            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && ! $existing->is_demo) {
                $this->components->error("{$email} already belongs to a real login; it was left alone.");

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($passwords): void {
            foreach (self::LOGINS as $email => $login) {
                $user = User::query()->firstOrNew(['email' => $email]);
                $user->forceFill([
                    'name' => $login['name'],
                    'password' => Hash::make($passwords[$email]),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'is_demo' => true,
                ])->save();

                $user->clientAccounts()->delete();
                $user->clientAccounts()->create(['role' => $login['role'], 'propertyware_id' => (string) $login['contact']]);
            }
        });

        $reset->handle();

        $this->components->info('The demo owner and tenant are ready. Put the logins in App Store Connect › App Review Information.');

        return self::SUCCESS;
    }
}
```

Check that `ClientAccount`'s fillable list includes `role` and `propertyware_id`, as `InviteClient` creates them; otherwise use `forceFill`. If `User` hashes passwords through a `'password' => 'hashed'` cast, pass the plain password, because `Hash::make` on top of the cast would hash it twice. Look at `User::casts()`. If the cast is there, drop the `Hash::make`.

In `routes/console.php`, add next to the other `Schedule::` lines:

```php
// The App Review logins start each day from their made-up records.
Schedule::command('demo:reset')->dailyAt('03:00')->timezone('America/Chicago')->withoutOverlapping();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='DemoAccountsCommandTest|DemoPropertywareTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer lint
git add app/Services/Demo/DemoReset.php app/Console/Commands/DemoAccounts.php app/Console/Commands/DemoResetCommand.php app/Http/Middleware/DemoMode.php routes/console.php tests/Feature/Demo/DemoAccountsCommandTest.php
git commit -m "Add demo:accounts and a nightly demo:reset for the App Review logins"
```

---

### Task 10: Whole-branch check

- [ ] **Step 1:** Run `composer ci:check`, or `composer lint:check && composer types:check && composer test` if `ci:check` doesn't cover all three. Expected: green.
- [ ] **Step 2:** Run `npm run types:check && npm run build`. Expected: no errors. This checks the admin badge change.
- [ ] **Step 3:** Run `php artisan migrate:fresh --seed` against the local database (it has to be running), then `php artisan demo:accounts --owner-password='Local-demo-2026!' --tenant-password='Local-demo-2026!'`. Sign in at `https://portal.test` as each login, and click through the dashboard, maintenance, documents (open a PDF), messages and approve. Confirm in the browser's network tab, or `storage/logs/laravel.log`, that nothing failed.
- [ ] **Step 4:** Commit any fix, then run `graphify update .` in the repo root, as `CLAUDE.md` asks.

---

## After merging the lease and approval branches

These depend on `feature/lease-expiry-notifications` and `feature/approval-pushes`, which aren't on `main` yet. Do them in the merge that brings those branches in:

1. In `LeaseExpiryRecipients::ownersByPortfolio()` and the tenant query, skip client accounts whose user `is_demo`, so `findContact` never sees a demo ID. Add a test beside the existing recipient tests.
2. `approvals:notify` (on the approval branch) also skips `is_demo` users.
3. Have `DemoReset::handle()` write the starting bell notifications for the demo users, using those branches' notification classes: the owner's lease-ending reminder for `HOME_LEASED`, the tenant's renewal letter, and one approval notice for `WORK_ORDER_APPROVAL`. Use the classes' own `toArray`, and write through `$user->notifyNow($notification, ['database'])` so no push is sent.

## Rollout

1. Merge, deploy, then run `php artisan migrate` and `php artisan demo:accounts`. Choose the passwords when prompted.
2. In App Store Connect › App Review Information:
   - Put the owner login in the sign-in fields.
   - Write in the Notes: "Tenant demo: appreview.tenant@texasrenters.com / <password>. Both accounts use sample data; actions are kept in a sandbox and reset nightly."
3. Leave `cr@texasrenters.com` untouched until the pending review closes.
