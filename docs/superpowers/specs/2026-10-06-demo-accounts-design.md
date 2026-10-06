# Demo owner and tenant accounts for App Review

## Goal

Apple's reviewer signs in to the app as a demo **owner** and as a demo **tenant** and finds every screen filled with realistic data, and every action working. No real Propertyware record is needed, real accounts behave exactly as today, and nothing the reviewer does reaches Propertyware, the chat hub, the leads webhook or staff.

The accounts are for App Review and our own demos only.

## Decisions

- Two separate logins, one per role (Apple asked for both).
- Actions are **kept for the demo only** and appear in the lists afterwards; they never leave the portal. The demo data resets every night.
- Demo data comes from a **stand-in for the external clients**, switched in per request for demo logins only, so the real services (ledger maths, approvals, documents, maintenance) run unchanged against canned responses. Rejected: a demo version of each service (about ten classes that drift as features change).

## Accounts

`php artisan demo:accounts {--owner-password=} {--tenant-password=}` creates or refreshes:

| Login | Name | Role | Propertyware ID |
| --- | --- | --- | --- |
| `appreview.owner@texasrenters.com` | Demo Owner | owner | `900000001` |
| `appreview.tenant@texasrenters.com` | Demo Tenant | tenant | `900000002` |

- Passwords are set directly (prompted when the options are missing); the email is marked verified; no invitation is sent and no mailbox is needed.
- A new `users.is_demo` boolean (default false) marks them. Only this command sets it; it is not mass-assignable.
- Demo IDs are 9 digits starting at 900000000, far outside the real range. All demo entity IDs (portfolios, buildings, units, leases, work orders, documents, threads) live in that range too.
- Re-running the command resets both passwords and the demo data. `demo:reset` (data only) is scheduled daily at 03:00 America/Chicago.

## Demo data

One fixture set, `resources/demo/propertyware.php` (plus small PDFs under `resources/demo/files/`), shaped exactly like Propertyware's REST responses, built from the samples already in the tests:

- **Owner** "Demo Owner", one portfolio with two made-up Houston-area homes:
  - **Home A** is leased to Demo Tenant, with the lease ending about 75 days from today (dates are computed relative to today so the 90-day reminder always applies).
  - **Home B** is vacant.
- **Ledger**: six months of general-ledger rows (rent in, management fee, a repair bill), plus bills and GL accounts.
- **Work orders**: four on Home A/B. One is completed, one is in progress, one is new, and one is waiting for the owner's approval.
- **Documents**: the lease, a monthly statement and an inspection report (real small PDFs that open).
- **Conversations**: Propertyware conversations on the contact and building.
- **Chat hub**: one thread per login with a short office exchange.
- **Scheduling**: event types plus one upcoming meeting.
- **Notifications**: the owner and tenant lease reminders and one approval notice, written by `demo:reset` as stored notifications. These use the existing classes once the lease and approval branches are merged; until then, whatever notification classes `main` has.

No real tenant, owner or address appears anywhere.

## How it switches

**`DemoMode` middleware**, in the web client and `api` v1 groups, runs after authentication and before `SyncPropertywareAccounts`. When `$request->user()?->is_demo` is true it:

1. Rebinds `PropertywareClient`, `PropertywareSoap`, `ChatHubClient` and `SchedulingClient` in the container to demo subclasses for the rest of the request. The app runs on PHP-FPM, without Octane, so a rebind lasts only for that one request.
2. Switches the default cache store to a `demo` store. This is the same driver with prefix `demo:`, so shared keys such as `propertyware:buildings` and `propertyware:units` never mix demo and real data.
3. Disables account discovery for the request.

**Demo clients** override every public method of their parents:

- **`DemoPropertywareClient`**
  - `get`, `list`, `listAll`, `listAllForFilters`, `listAllForQueries`, `listAllForPaths` and `getMany` answer from the fixture set merged with `demo_records`, applying the same query filters the real API is called with (`portfolioID`, `buildingID`, `leaseID`, `entityType`/`entityId`, date ranges).
  - `download` returns the fixture PDF as a `Response`.
  - `create('workorders')`, `upload('docs')` and `update('docs/{id}')` write to `demo_records` and return the shape Propertyware returns.
  - Any path the fixtures don't cover throws `PropertywareException` ("not available in the demo"), so a gap fails loudly in tests instead of calling out.
- **`DemoPropertywareSoap::approveWorkOrder`** is a no-op.
- **`DemoChatHubClient`** serves fixture threads and messages; `openThread` and `postClientMessage` write to `demo_records`. Staff never see them.
- **`DemoSchedulingClient`** returns fixture event types and an upcoming meeting.

**`demo_records` table**: `id`, `kind` (workorder, document, document_file, chat_thread, chat_message), `external_id`, `payload` (json) and timestamps. Uploaded document files are stored on the local disk under `demo/`. `demo:reset` truncates the table, deletes `demo/` files, clears the `demo:` cache, deletes demo users' approvals, notifications, help requests and referrals, and writes the starting notifications.

## Keeping real systems safe

- **`approvals:push`**: `pushPending` excludes approvals whose user `is_demo`, so a demo approval is never sent over SOAP. The in-request send already goes through the demo SOAP client.
- **`leases:notify-expiring`**: `LeaseExpiryRecipients` (on the lease branch) skips client accounts whose user `is_demo`, so demo IDs never reach `findContact`. Demo leases don't exist in real Propertyware, so no real lease reaches the demo users.
- **Queued jobs**, which run without the request's bindings:
  - Jobs dispatched for a demo login (`WarmOwnerLedgerBalance`, `WarmPortfolioIncome`, `ImportOwnerLedgers`, `SyncLeaseLedger`) are not dispatched; the services compute inline for demo requests.
  - `SendReferralToLeads` is not dispatched for demo users.
- **Account discovery** (`SyncPropertywareAccounts`, `MeController`) is skipped for demo users.
- **Pushes**: lease and approval pushes only go to demo devices through demo notifications written by `demo:reset`; the push jobs exclude demo users. There are no staff pushes.
- **Admin**: the users list and user page show a "Demo" badge. Admin "view as" and suspend still work.
- **Account changes**: demo users can't change their email or delete their account (403 with a short message), so the review login can't be lost mid-review. Changing the password is allowed, and `demo:accounts` resets it.

## Errors

- A fixture gap raises `PropertywareException`, which every controller already handles as "couldn't load from Propertyware". Tests catch gaps before release.
- If `demo_records` or the demo cache is empty, the fixtures alone are served, so the demo always renders.

## Testing (Pest)

- `demo:accounts` creates both users with `is_demo`, verified email and the right account links; re-running resets the passwords and data.
- **Every owner and tenant screen** (Inertia) and **every API v1 account endpoint** returns 200 with demo data for its demo login. `Http::preventStrayRequests()` is already global, so any real outbound call fails the test.
- Each demo action round-trips: create a work order, upload a document, approve and decline an approval, open a thread and post a message. Each shows up in the following list response. No SOAP, chat hub or leads call is made.
- A real (non-demo) login on the same route still calls Propertyware (`Http::fake` asserts the request) and does not see demo data. The demo cache prefix is not used.
- `approvals:push`, `leases:notify-expiring` and account discovery ignore demo users.
- `demo:reset` restores the starting state.

## Out of scope

- Demo data for the staff/admin area.
- Changes to the mobile app. It already signs in with email and password; the reviewer uses the two logins above.
- A public "try the demo" button.

## Rollout

1. Merge and deploy, then run `php artisan migrate` and `php artisan demo:accounts`. Choose the passwords at that point.
2. In App Store Connect, App Review Information: put the owner login in the sign-in fields and the tenant login in the Notes.
3. Keep `cr@texasrenters.com` untouched until the pending review closes.
