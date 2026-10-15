<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // The messaging hub in tx-chatbot: every conversation with clients, by text or in this app.
    // The website's owner-leads form handler (n8n). Referrals from the portal and
    // the apps are sent there too, so they reach the same pipeline as web leads.
    // Unset, referrals are only kept in the portal's admin list.
    'leads' => [
        'webhook_url' => env('LEADS_WEBHOOK_URL'),
    ],

    'chat_hub' => [
        'url' => env('CHAT_HUB_URL'),
        'token' => env('CHAT_HUB_TOKEN'),
        'webhook_secret' => env('CHAT_HUB_WEBHOOK_SECRET'),
        // TLS check: true by default, or a CA bundle path for local .test sites.
        'verify' => env('CHAT_HUB_VERIFY', true),
    ],

    // tx-maintenance: the texts a client exchanged about their work orders
    // before the messaging hub took over, shown as closed history.
    'tx_maintenance' => [
        'url' => env('TX_MAINTENANCE_URL'),
        'token' => env('TX_MAINTENANCE_TOKEN'),
        // TLS check: true by default, or a CA bundle path for local .test sites.
        'verify' => env('TX_MAINTENANCE_VERIFY', true),
    ],

    // tx-cma: our MLS listing data, used for property photos.
    'tx_cma' => [
        'url' => env('TX_CMA_URL'),
        'token' => env('TX_CMA_TOKEN'),
        'photo_cache_hours' => (int) env('TX_CMA_PHOTO_CACHE_HOURS', 24),
        // TLS check: true by default, or a CA bundle path for local .test sites.
        'verify' => env('TX_CMA_VERIFY', true),
    ],

    /*
     * Who may open the admin dashboard, as a comma separated list of email
     * addresses. Admin is deliberately not a column or a role: nothing in the
     * portal can grant it, so a compromised account cannot escalate into it.
     */
    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

    /*
     * tx-scheduling: staff availability and bookings. The portal shows what a
     * page offers and books against it; availability, reminders and calendar
     * sync all stay in that app rather than being rebuilt here.
     */
    'scheduling' => [
        'url' => env('SCHEDULING_URL'),
        'token' => env('SCHEDULING_TOKEN'),
        // Whose booking page owners are offered.
        'page' => env('SCHEDULING_PAGE'),
        // Where owners pick a meeting: the departments page lists each team
        // and its ways to meet, so the portal links there instead of listing them.
        'booking_url' => env('SCHEDULING_BOOKING_URL', 'https://scheduling.texasrenters.com/book/texasrenterscom-llc/departments/rzs7sj1p'),
        // Signs the booking webhooks scheduling sends to /api/v1/scheduling/events;
        // issued on the API key's settings in scheduling.
        'webhook_secret' => env('SCHEDULING_WEBHOOK_SECRET'),
        // TLS check: true by default, or a CA bundle path for local .test sites.
        'verify' => env('SCHEDULING_VERIFY', true),
    ],

    'propertyware' => [
        // Propertyware's own owner and tenant portals, for anything the portal
        // and the apps do not do themselves. Linked from the app's Account screen.
        'portal_urls' => [
            'owner' => env('PROPERTYWARE_OWNER_PORTAL_URL', 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/owner.action'),
            'resident' => env('PROPERTYWARE_TENANT_PORTAL_URL', 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/tenant.action'),
        ],
        'base_url' => env('PROPERTYWARE_BASE_URL', 'https://api.propertyware.com/pw/api/rest/v1'),
        'client_id' => env('PROPERTYWARE_CLIENT_ID'),
        'client_secret' => env('PROPERTYWARE_CLIENT_SECRET'),
        'system_id' => env('PROPERTYWARE_SYSTEM_ID'),
        'cache_seconds' => (int) env('PROPERTYWARE_CACHE_SECONDS', 300),
        // Portfolios, buildings, leases and contacts only change when the office
        // edits them in Propertyware, never through a client, and resolving a
        // client's scope touches them on every request. Nothing busts these keys
        // on write, so the short default above is kept for the datasets clients
        // do change: work orders and documents.
        'directory_cache_seconds' => (int) env('PROPERTYWARE_DIRECTORY_CACHE_SECONDS', 1800),
        'discover_accounts' => env('PROPERTYWARE_DISCOVER_ACCOUNTS', true),
        // Residents whose every lease is terminated are signed out and kept
        // out; see App\Services\Propertyware\TerminatedLeaseGate.
        'block_terminated_leases' => env('PROPERTYWARE_BLOCK_TERMINATED_LEASES', true),
        // Only emails on a Propertyware contact may sign up on their own;
        // see App\Rules\RegisteredClientEmail.
        'require_client_email' => env('PROPERTYWARE_REQUIRE_CLIENT_EMAIL', true),
        // Propertyware's SOAP API, for what its REST API can't do (approving work orders).
        'soap' => [
            'url' => env('PROPERTYWARE_SOAP_URL'),
            'username' => env('PROPERTYWARE_SOAP_USERNAME'),
            'password' => env('PROPERTYWARE_SOAP_PASSWORD'),
        ],
        // Push owner approvals from the portal into Propertyware. Off by default: it
        // writes to the live record and makes Propertyware send its own "Work Order
        // Approved" alert. Until then approvals are kept in the portal and sent later.
        'approval_push' => (bool) env('PROPERTYWARE_APPROVAL_PUSH', false),
        // Propertyware logins of TexasRenters.com staff, comma separated. Conversation
        // messages from these (or a property's listed managers) show as staff; any
        // other author is the client replying through their Propertyware portal.
        'staff_logins' => array_values(array_filter(array_map(
            fn (string $login): string => strtolower(trim($login)),
            explode(',', (string) env('PROPERTYWARE_STAFF_LOGINS', '')),
        ))),
        // Testing only: shows the approve button on every owner work order. These
        // approvals stay in the portal and are never sent to Propertyware.
        'approval_test_mode' => (bool) env('PROPERTYWARE_APPROVAL_TEST_MODE', false),
    ],

    // Expo's push service, which holds our APNs key and FCM credentials and
    // forwards notifications to Apple and Google for the mobile app.
    'expo' => [
        'url' => env('EXPO_PUSH_URL', 'https://exp.host'),
        // Only needed once the Expo project has enhanced security turned on.
        'token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
