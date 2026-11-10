# Signing in through the TR Staff Portal

When `PORTAL_ENABLED=true`, staff sign in to app.texasrenters.com through the TR Staff Portal (OpenID Connect). **Staff** means the people the admin area lets in: the owners listed in `ADMIN_EMAILS`, plus anyone given **Admin** or **Staff** on the Staff page (`User::isStaff()`). Residents and owners are not affected: their password, passkey, sign-up and phone-app sign-in work exactly as before.

## Settings (`.env.docker` on the server)

```
PORTAL_ENABLED=true
PORTAL_ISSUER=https://login.texasrenters.com    # no trailing slash
PORTAL_CLIENT_ID=<from the portal: Apps → this app → Keys>
PORTAL_CLIENT_SECRET=<shown once when the app was registered>
PORTAL_APP_SLUG=<the app's Short ID in the portal>
PORTAL_RECHECK_MINUTES=5
PORTAL_ALLOW_PASSWORD_LOGIN=true                # keep on: staff can still use their password
```

In the portal, register the app with:

- **App address:** `https://app.texasrenters.com`
- **Callback:** `https://app.texasrenters.com/auth/portal/callback`
- **Logout address:** `https://app.texasrenters.com/auth/portal/backchannel-logout`

## What happens

- **The login page** keeps the email and password form as it is, with a small "TexasRenters staff? Sign in with TR Staff Portal" link underneath.
- **Linking accounts:** the first time, your portal account is linked to your existing staff account by email. It must be a verified company address (`@texasrenters.com` / `@texra.net`), and exactly one account here may have it. Your staff role, any client accounts and your details stay as they were.
- **No automatic accounts or roles** (owner decision): if you have no staff access here yet, you're sent back to the login page with "Ask an admin to add you on the Staff page". That includes a company address that only has a resident or owner account. An admin adds you on the Staff page as today.
- **Suspended logins** stay suspended: the portal sign-in is refused with the usual message.
- **The sign-in log** (Insights) shows these sign-ins as "TR Staff Portal".
- **Every 5 minutes** the app checks with the portal that you still have access.
  - Deactivated, removed from this app, or past the portal's 12-hour sign-in window: you're signed out.
  - If the portal is briefly unreachable: you are **not** signed out.
- **Signing out:**
  - Deactivating someone in the portal, or "Sign out everywhere", ends their web sessions immediately (back-channel logout).
  - That includes a visit where they are signed in as a client (impersonation). The session then belongs to the client, so the app also remembers the sign-out and ends any portal session of that person on its next request.
  - The phone apps' sign-ins are left alone.
  - Staff who came in through the portal go back to the portal's app list when they sign out.
- **Password login** stays on for staff while `PORTAL_ALLOW_PASSWORD_LOGIN=true` (the setting we run with), so nobody is locked out. If it is ever set to `false`, staff password and passkey sign-in on the website is refused with the usual message, and staff web sessions that didn't come from the portal are ended. Residents and owners always keep their sign-in.

## Testing locally

1. Run the portal at `http://localhost:8000`.
2. Run this app at `http://127.0.0.1:8001` with `php artisan serve --port=8001`. Use 127.0.0.1, not localhost, so the two apps don't overwrite each other's cookies.
3. In the portal, register an app with:
   - **App address:** `http://127.0.0.1:8001`
   - **Callback:** `http://127.0.0.1:8001/auth/portal/callback`
   - **Logout address:** `http://127.0.0.1:8001/auth/portal/backchannel-logout`
4. Assign it to yourself.
5. In this app's `.env`, set `APP_URL=http://127.0.0.1:8001`, `PORTAL_ISSUER=http://localhost:8000` and the keys.
