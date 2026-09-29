# Implementation plan

Implements [DECISIONS.md](DECISIONS.md). No company is ever taken from the client. Tests are PHPUnit feature tests, matching the installed `phpunit/phpunit` suite and `tests/Feature/ApiUserTest.php`. Pest is named in `.cursor/rules/project.mdc` but is not installed; this plan does not add it.

Do not call `withoutGlobalScopes()` in application code or in tests.

## Phase 1 — Migrations and models

Create:

- `database/migrations/…_create_companies_table.php`
- `database/migrations/…_add_company_and_phone_to_users_table.php`
- `database/migrations/…_create_projects_table.php`
- `database/migrations/…_create_project_user_table.php`
- `database/migrations/…_create_otp_codes_table.php`
- `app/Models/Company.php`
- `app/Models/Project.php`
- `app/Models/OtpCode.php`
- `database/factories/CompanyFactory.php`
- `database/factories/ProjectFactory.php`

Update:

- `app/Models/User.php`
- `database/factories/UserFactory.php`

Leave the original users migration as Laravel shipped it. Change `users` in the new migration.

**Design decisions**

- `companies`: `name`, unique `slug`. Unscoped. This row is the tenant root, loaded by id from a user, not by a request header.
- `users`: required `company_id` foreign key (`cascadeOnDelete`), `name`, and `phone`. `phone` has a single-column unique index so the same number cannot exist in two companies. Drop `email`, `email_verified_at`, and `password`. Store `phone` in one normalized form (E.164) so lookup, the unique index, and the rate-limit key use the same value.
- `User` does not use `BelongsToCompany`. Fillable attributes are `name`, `phone`, and `company_id`. Factories and seeders set `company_id` themselves. No API creates users.
- `projects`: `company_id`, `name`, nullable `description`. Composite index `(company_id, created_at, id)` for tenant lists ordered by `created_at` desc, `id` desc.
- `project_user`: `project_id`, `user_id`, unique pair, both foreign keys `cascadeOnDelete`. The same-company rule is enforced later in the project action, with an explicit `User::where('company_id', ...)` query.
- `otp_codes`: `company_id`, `user_id`, `code_hash`, `expires_at`, nullable `consumed_at`, `attempts` (unsigned integer, default 0). Store only the hash. Lookup is the latest unused, unexpired row for that user, then `Hash::check`, because a bcrypt hash cannot be queried by the plaintext code.
- `Company` has many `User` and `Project`. `User` belongs to `Company` and belongs to many `Project`. `Project` belongs to `Company` and belongs to many `User`. `OtpCode` belongs to `Company` and `User`.
- The trait is applied in phase 2. Phase 1 only defines tables, relationships, factories, and the unscoped `User`.

## Phase 2 — Tenant layer

Create:

- `app/Models/Concerns/BelongsToCompany.php`
- `app/Models/Scopes/CompanyScope.php`
- `app/Http/Middleware/ResolveCompany.php`
- `app/Support/CurrentCompany.php`

Update:

- `bootstrap/app.php`
- `app/Models/Project.php`
- `app/Models/OtpCode.php`

Do not add the trait to `User`.

**Design decisions**

- The trait adds the global scope and, on creating, sets `company_id` from `CurrentCompany` when the attribute is empty. If neither the attribute nor the current company is set, creating fails rather than inserting a null tenant.
- When no company is bound, the scope matches zero rows. A query that forgets to bind the company cannot return every tenant's projects or OTP rows.
- `ResolveCompany` runs only on routes that already passed `auth:sanctum`. It reads `company_id` from `$request->user()`, loads that `Company`, and binds `CurrentCompany`. It does not read a header or a route parameter.
- Middleware priority: `Authenticate`, then `ResolveCompany`, then `SubstituteBindings`. `{project}` must resolve only after the company is bound, or the scope is still fail-closed and every id 404s.
- OTP routes do not use `ResolveCompany`. Those actions bind `CurrentCompany` themselves after the phone lookup and before the first `OtpCode` query or insert (phase 3).
- `Company` stays unscoped.

## Phase 3 — OTP auth

Create:

- `config/otp.php`
- `app/Contracts/OtpSender.php`
- `app/Services/Otp/LogOtpSender.php`
- `app/Actions/Auth/RequestOtp.php`
- `app/Actions/Auth/VerifyOtp.php`
- `app/Http/Requests/Auth/RequestOtpRequest.php`
- `app/Http/Requests/Auth/VerifyOtpRequest.php`
- `app/Http/Controllers/Auth/OtpController.php`
- `app/Http/Resources/AuthTokenResource.php`
- `app/Http/Resources/UserResource.php`

Update:

- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

**Endpoints**

- `POST /api/otp/request` — middleware: `throttle:otp-request` only
- `POST /api/otp/verify` — no auth middleware
- `GET /api/user` — middleware: `auth:sanctum`, then `ResolveCompany`; body is `UserResource`

**Design decisions**

- Request and verify bodies carry the phone number. There is no company field.
- `RequestOtp` normalizes the phone and loads `User` with an ordinary `where('phone', ...)` query. The model has no global scope, so this searches every company. If a user is found, the action binds `CurrentCompany` from `user.company_id`, marks older unconsumed codes consumed, hashes a new 6-digit code, and hands the plaintext only to `OtpSender`. If no user is found, it writes nothing and still returns the same 202 message.
- Default TTL is 5 minutes, from `config/otp.php`.
- `VerifyOtp` loads the user the same way, binds that company, then locks the latest unused, unexpired `OtpCode` in a transaction. `Hash::check` must succeed. Success sets `consumed_at` and returns a Sanctum personal access token plus `UserResource`. A second verify of that code fails. An expired code fails. Each wrong code increments `attempts`; at 5 failures the code is consumed so it cannot be guessed for the rest of the TTL. Unknown phone, wrong code, expired code, and consumed code all return the same error shape.
- `UserResource` includes `id`, `name`, `phone`, and the company `id`, `name`, and `slug`.
- Rate limiter `otp-request` is defined in `AppServiceProvider`, keyed by normalized phone and IP, 3 requests per 10 minutes. It runs for unknown phones as well as known ones.
- `AppServiceProvider` binds `OtpSender` to `LogOtpSender` in every environment. Swapping in a real vendor later is a one-line binding change, and that implementation should not log the plaintext. Tests replace the contract and capture the plaintext.

## Phase 4 — Projects API

Create:

- `app/Actions/Projects/CreateProject.php`
- `app/Actions/Projects/UpdateProject.php`
- `app/Actions/Projects/DeleteProject.php`
- `app/Http/Requests/Projects/StoreProjectRequest.php`
- `app/Http/Requests/Projects/UpdateProjectRequest.php`
- `app/Http/Controllers/ProjectController.php`
- `app/Http/Resources/ProjectResource.php`

Update:

- `routes/api.php`

**Endpoints**

All of these use `auth:sanctum` then `ResolveCompany`:

- `GET /api/projects`
- `POST /api/projects`
- `GET /api/projects/{project}`
- `PATCH /api/projects/{project}`
- `DELETE /api/projects/{project}`

**Design decisions**

- Controllers validate through form requests, call one action, and return `ProjectResource` (204 on delete).
- Create and update accept `name`, `description`, and `user_ids`. They do not accept `company_id`. The trait fills `company_id` from the company the middleware bound.
- The action resolves assignees with `User::where('company_id', $companyId)` and the given ids. `User` is unscoped, so this `where` is required. Any missing id is a 422. The message does not reveal whether that id exists in another company.
- `{project}` is resolved under the scope, so another company's id is 404.
- `ProjectResource` includes the project fields and assigned users through `UserResource`.
- Index is paginated, ordered by `created_at` desc, then `id` desc.
- The same-company pivot rule is this assignee query. Do not rely on the foreign keys alone.

## Phase 5 — Seeders

Create:

- `database/seeders/CompanySeeder.php`

Update:

- `database/seeders/DatabaseSeeder.php`

**Design decisions**

- Seed two companies (`acme`, `globex`), two users each, and two projects each. Assign users only inside their own company.
- Phones are globally unique, fixed, and documented in the README so OTP can be tried locally. Users have no email and no password.
- The seeder sets `company_id` on each user itself. It binds `CurrentCompany` while creating that company's projects so the trait can fill `company_id`. It does not seed `otp_codes`.

## Phase 6 — Tests

Create:

- `tests/Feature/TenantResolutionTest.php`
- `tests/Feature/OtpAuthenticationTest.php`
- `tests/Feature/ProjectTest.php`
- `tests/Feature/CrossTenantProjectAccessTest.php`

Update:

- `tests/Feature/ApiUserTest.php` — unauthenticated `GET /api/user` stays 401. Do not require a company header.

**Design decisions**

- Feature tests hit the HTTP API. Use `RefreshDatabase` and the existing in-memory SQLite setup. Fake `OtpSender` and read the plaintext from the fake.
- Cover missing auth (401), validation (422), the happy path, and the persisted row.
- OTP: the response body has no code; the database stores a hash; an unknown phone returns the same 202 and writes no row; a second verify fails; an expired code fails; the fifth wrong guess consumes the code; the phone+IP throttle returns 429.
- A phone that belongs to company B is found while no company is bound. After verify, the token's user is that company, and `GET /api/projects` returns only that company's projects.
- Cross-tenant show, update, and delete return 404. The owning company's token can still read the same project, which shows the row was hidden rather than deleted.
- Assigning a user from the other company returns 422.
- Tests never call `withoutGlobalScopes()`, and they never send a company header.

## Phase 7 — README

Update:

- `README.md`

`docs/DECISIONS.md` is already settled. The README restates it for the submission. Do not reopen those choices.

**README sections**

- Database design: shared schema, global unique phone, no email or password, `company_id` on tenant-owned tables, pivot, hashed OTP rows, the composite list index.
- Tenant isolation: company comes from the phone-matched user or from the Sanctum user; `ResolveCompany` runs after auth and before binding; `Project` and `OtpCode` are scoped; `User` is the documented exception; foreign project ids return 404.
- Authentication: Sanctum personal access token issued only after OTP verify. No registration.
- OTP: hash, TTL, single use, failed-attempt cap, `OtpSender` bound to `LogOtpSender` in every environment, generic responses. A later real vendor is a one-line binding change and must not log the plaintext.
- Rate limiting: phone plus IP.
- Assumptions: one company per user, no roles, no SMS vendor, unused framework password-reset and session tables.
- Local run: copy `.env`, migrate, seed, serve, then request and verify OTP with a seeded phone. No company header.
- Scale: the scalability section of `docs/DECISIONS.md` (cursor pagination, queued OTP delivery, shared cache for the limiter, eager loads, shard by `company_id` only if physical isolation is required later).
