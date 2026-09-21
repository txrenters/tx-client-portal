---
paths:
    - 'app/Http/Controllers/**'
---

# Controllers

## Every client feature must also ship in the mobile API

The iOS (SwiftUI) and Android apps use `/api/v1` (routes/api.php, app/Http/Controllers/Api/V1), so any feature added to or changed in the web portal (Owner/Resident controllers) needs a matching API endpoint in the same change.
Keep the data logic in shared services (app/Services/Propertyware/*) and form requests so web and API don't drift. Web controllers only wrap them in Inertia props; API controllers return JSON as `{data, meta?}`.
API endpoints: Sanctum token auth, account-scoped under `accounts/{account}` with `verified` + `can:view,account` (another client's account returns 404), paginate lists, and add tests in tests/Feature/Api/V1.
Don't change an existing v1 response shape in a breaking way, because shipped app versions depend on it. Add fields, or create /api/v2.
