---
paths:
    - 'resources/js/pages/**'
---

# Pages

## New portal pages need a mobile API counterpart

Any page or feature built for the client portal must also be usable from the iOS and Android apps. Add or update the matching `/api/v1` endpoint (app/Http/Controllers/Api/V1) and its tests in the same change. Put logic such as filtering or pagination on the server, not only in Vue, so the apps get the same behavior.
