# Lease expiry notifications — design

Date: 2026-10-03
Repos: `tx-client` (portal + API), `tx-mobile` (Expo app)

## Goal

When a lease is 90 days from its end date, tell the owner and the tenants
in the portal and on their phones, so tenants know to renew and owners
know to decide whether to renew or list the property on the market.

Success: every active lease gets one notice per recipient, 90 days out;
nobody is told twice; owners never see tenant details; the notice is
readable later from a bell on web and mobile.

## Decisions

- **Source:** Propertyware REST API (`GET /leases` with `endDateStart` /
  `endDateEnd`), not the Propertyware JSON report. The API carries IDs
  (`portfolioID`, contact `id`) that match portal accounts; the report only
  has names and emails. Probe on 2026-10-03: a 30-day window returned 21
  leases in ~0.5s, every one with `portfolioID` and `buildingID`.
- **Channels:** push (existing `ExpoChannel`) and a stored notification list
  (bell). **No email.** Tenants are also texted today by n8n; that stays as is.
- **Schedule:** daily at 09:00 America/Chicago (the app runs in UTC).
- **Owner privacy:** the owner's notice carries the property and dates only —
  never tenant names, the lease name (it is built from tenant surnames),
  or contact details.

## 1. Daily lease check (tx-client)

### Command

`leases:notify-expiring`, scheduled in `routes/console.php`:

```php
Schedule::command('leases:notify-expiring')
    ->dailyAt('09:00')->timezone('America/Chicago')->withoutOverlapping();
```

### Selecting leases

1. `listAll('leases', ['endDateStart' => today, 'endDateEnd' => today + 90 days])`.
2. Keep `status === 'Active'`. Skip `Terminated`, `Active - Notice Given`, and
   any other status — a tenant who has given notice gets no renewal prompt.
3. Skip leases already in `lease_expiry_notices` for the same end date.

The window (not an exact "day 90" match) means a missed run catches up the
next morning. No lease ended exactly 90 days out on the probe date, so an
exact-day match would also be fragile.

### Once per lease

New table `lease_expiry_notices`:

| column | type | note |
|---|---|---|
| id | bigint | |
| propertyware_lease_id | bigint | |
| end_date | date | unique with lease id |
| notified_at | timestamp | null when seeded silently |
| recipients | unsigned int | how many accounts were notified |
| timestamps | | |

Unique on `(propertyware_lease_id, end_date)`. An extended lease (new end
date) is notified again 90 days before the new date.

### First run

On the first run every lease already inside 90 days is in the window. Only
leases ending 80–90 days out are notified; the rest are recorded with
`notified_at = null` so they are never notified. Implemented as a
`--seed` option the deploy runs once:
`php artisan leases:notify-expiring --seed`.

### Recipients

- **Owners:** every owner `ClientAccount` whose Propertyware contact's
  `portfolioIds` contains the lease's `portfolioID` — the same link
  `PropertywareDirectory::scopeFor` uses to show owners their properties.
  Several owners → each gets their own copy.
- **Tenants:** every tenant `ClientAccount` whose `propertyware_id` matches a
  lease contact with `namedOnLease` and role Primary, Secondary or Other.
  Guarantors are excluded. Several tenants → each gets their own copy,
  addressed to them.
- People without a portal account are skipped (tenants still get the n8n
  text).
- One person with several leases gets one notice per lease. Someone who is
  both owner and tenant gets each version on the matching account.
- An account created after its lease was notified does not get it later; the
  Lease screen still shows the lease as ending soon.

### Notification

`App\Notifications\LeaseExpiringNotification`, sent to the `User`, carrying
`account_id`. `via()`:

- `database` — always (the bell list).
- `ExpoChannel` — when the user's new `lease_expiry_push` preference is on
  (default on), next to `message_replies_push` and
  `work_order_approvals_push` on `User`.

Payload is built by one class, `LeaseExpiryNotice`, with `forOwner()` and
`forTenant()` — the only place lease data is turned into notice text, so
the owner rule is enforced and tested in one spot.

Stored data (both roles): `type: 'lease_expiring'`, `account_id`,
`lease_id`, `building`, `unit`, `start_date`, `end_date`,
`renewal_deadline` (end date − 60 days), `title`, `body`, `url`.
Tenant only: `first_name`, `last_name` (the recipient's own name).

### Wording

Tenant push:

> **Your lease ends Dec 30, 2026**
> To renew, your documents must be submitted and approved by Oct 31, 2026.
> After that, the home goes back on the market.

Tenant full text (bell detail):

> Dear {first name} {last name},
>
> This notification is to inform you that your lease will be expiring soon.
> The details of your current lease are shown below:
>
> Building Name: {building}
> Unit Name: {unit}
> Lease Start Date: {start date}
> Lease Expiration Date: {end date}
>
> Per your lease agreement, your home will be placed back on the market 60
> days before the lease ends. If you've been offered a renewal or extension,
> all required documents must be submitted and approved at least 60 days
> prior to your lease end date.
>
> Best regards,
> The TexasRenters.com Property Management Team

Owner push:

> **Lease ending at {building}**
> The lease ends on {end date}, {days} days from now. It's time to decide
> whether to renew it or list the property on the market.

Owner full text: the same, plus building, unit, lease start and end dates.

`{days}` is counted on the day the notice is sent (90, or slightly less after a
missed run or on the first run).

Tap targets: tenant → Lease screen; owner → the property.

## 2. Notification list (tx-client)

- Lease notices live in Laravel's `notifications` table (new migration).
- **Web:** `Client\NotificationController::latest()` and `index()` gain a
  `notices` section (current account only) beside `messages` and
  `approvals`; unread notices add to `count`. `NotificationBell.vue` and
  `client/Notifications.vue` render it; opening one marks it read.
- **API** (`routes/api.php`, owner and tenant accounts):
  - `GET /api/v1/accounts/{account}/notifications` → one list, newest first:
    lease notices plus the unread messages and approvals the web bell
    shows; `meta.unread`.
  - `POST /api/v1/accounts/{account}/notifications/{id}/read`
  - `POST /api/v1/accounts/{account}/notifications/read-all`
- Notices older than 90 days are pruned by the existing daily
  `model:prune` run.

## 3. Mobile (tx-mobile)

- **Bell:** header-right on the main tab screens, with an unread badge,
  opening `src/app/(app)/notifications.tsx`. Hidden when the portal
  answers 404 (older portal).
- **Screen:** newest first, unread highlighted, pull to refresh, empty
  state. A lease notice opens its full text with building, unit and dates,
  then links to Lease (tenant) or the property (owner). Messages and
  approvals open their existing screens.
- **Push:** `PushPayload.type` gains `'lease_expiring'`;
  `src/notifications/useNotifications.ts` routes it to the notice.
- **Settings:** `account/notifications.tsx` gains "Lease ending reminders"
  (`lease_expiry_push`).
- Ships as an OTA update after the portal is deployed.

## Testing

- tx-client feature tests, with Propertyware faked:
  - window and status filter; notice-given and terminated skipped
  - once per lease; re-run sends nothing; new end date notifies again
  - `--seed` records without notifying outside 80–90 days
  - multiple owners and multiple tenants each get one copy; guarantors and
    accountless contacts skipped
  - owner payload contains no tenant name, lease name, email or phone
  - push respects `lease_expiry_push`
  - API list, read, read-all, and account scoping
- tx-mobile: `npx expo lint`, `npx tsc --noEmit`, simulator check of bell,
  list, detail and push tap.

## Out of scope

Email; SMS (n8n owns it); reminders at other intervals (60/30 days);
changing `Lease::endsSoon` (60 days).
