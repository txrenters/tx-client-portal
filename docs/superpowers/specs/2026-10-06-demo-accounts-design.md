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
- **Notifications**: the owner and tenant lease reminders and one approval notice, written by `demo:reset` as stored notifications. They need the notification bell and classes from the lease and approval branches, so they are added when those branches merge; `main` has no bell yet.

No real tenant, owner or address appears anywhere.

## How it switches

**`DemoMode` middleware** sits in the web group (before `SyncPropertywareAccounts` and `HandleInertiaRequests`) and in the `api` group. It resolves the user with `$request->user() ?? $request->user('sanctum')`. When that user `is_demo`, it does the following for the rest of the request (the app runs PHP-FPM, not Octane, so nothing outlives the request):

1. **Points every outside service at an unreachable `.invalid` host** (RFC 2606). This covers the Propertyware REST base URL and credentials, the chat hub, tx-cma listing photos, the leads webhook and Expo push. It then forgets the resolved client singletons and every service built on them, so they are rebuilt with the demo settings. A request that somehow escaped the fake could only fail to resolve; it could never reach a real system.
2. **Answers those hosts with `Http::fake(DemoBackend)`.** `DemoBackend` routes by host to `DemoPropertyware` (REST paths) and `DemoChatHub`, and accepts and drops the leads webhook and Expo pushes. It returns `null` for any other host so that host is untouched. The real Propertyware services, such as the ledger maths, approvals, documents and maintenance, run unchanged against these answers.
3. **Swaps `PropertywareSoap` for `DemoPropertywareSoap`**, whose `approveWorkOrder` is a no-op.
4. **Switches the default cache store to `demo`**: the same driver as the default store, with prefix `demo:`. Shared keys such as `propertyware:buildings` never mix demo and real data.
5. **Runs queued jobs inline** on the `sync` queue, inside the faked request, so the ledger warm-up jobs and referral webhook never reach a worker with real settings.
6. **Sends mail to the `array` mailer**, so a demo help request never emails staff.
7. **Turns off account discovery.**

Scheduling is left real. It holds no client records: the meeting types are the public catalogue, and upcoming meetings are looked up by email, so a demo email simply has none.

**`DemoPropertyware`** answers each Propertyware REST path the portal uses from `DemoData` merged with `demo_records`. It applies the same query filters the real API is called with (`portfolioID`, `buildingID`, `leaseID`, `entityType`/`entityId`, `postDate` ranges, `limit`/`offset`). `POST workorders`, `POST docs` and `PUT docs/{id}` write to `demo_records`. `docs/{id}/download` returns a generated PDF. Any other path returns 404, which surfaces as a `PropertywareException`, so a gap fails loudly in tests.

**`DemoChatHub`** serves one thread per demo contact. `POST threads` and `POST threads/{id}/messages` write to `demo_records`, so staff never see them.

**`demo_records` table**: `id`, `kind` (workorder, document, document_file, chat_thread, chat_message), `external_id`, `payload` (json) and timestamps. Uploaded document files are stored on the local disk under `demo/`. `demo:reset` truncates the table, deletes `demo/` files, starts a new demo cache prefix (a stored generation number, so nothing shared is flushed), deletes demo users' approvals, notifications, help requests and referrals, and writes the starting notifications.

## Keeping real systems safe

- **`approvals:push`**: `pushPending` excludes approvals whose user `is_demo`, so a demo approval is never sent over SOAP. The in-request send already goes through the demo SOAP client.
- **`leases:notify-expiring`**: `LeaseExpiryRecipients` (on the lease branch) skips client accounts whose user `is_demo`, so demo IDs never reach `findContact`. Demo leases don't exist in real Propertyware, so no real lease reaches the demo users.
- **Queued jobs** dispatched during a demo request (`WarmOwnerLedgerBalance`, `WarmPortfolioIncome`, `ImportOwnerLedgers`, `SyncLeaseLedger`, `SendReferralToLeads`) run inline on `sync`, inside the faked request, and never reach a worker.
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
