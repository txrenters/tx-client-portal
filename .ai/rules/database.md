---
paths:
    - 'database/migrations/**'
    - 'app/Models/**'
---

# Database

## The app runs on PostgreSQL

Local and production use PostgreSQL; `.env.example` is the reference (`DB_CONNECTION=pgsql`, port 5432).
The test suite still runs on SQLite in memory (`phpunit.xml`), so a Postgres-only problem will not
show up in a green suite — check anything schema-related against a real database before shipping it.

Keep queries portable, which today means avoiding what SQLite and Postgres disagree on:

- `like` is case-sensitive on Postgres. Use `ilike` (or lower both sides) for anything a person types.
- Every selected column must appear in `GROUP BY`; group collections in PHP where that gets awkward.
- No raw MySQL functions or `enum` columns. The app has none today; keep it that way.
- `->after('column')` in a migration is ignored on Postgres. It is harmless, but column order is not
  something to rely on.
