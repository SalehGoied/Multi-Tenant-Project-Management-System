# Multi-tenant project management API

## Overview

This is a multi-tenant project management HTTP API. Users sign in with a phone one-time password and then create and manage projects for their company. The stack is Laravel 13.33, PHP 8.3, and SQLite for local development. Authentication uses Laravel Sanctum personal access tokens. There is no web UI.

## Setup / Run locally

From a clone of this repository:

```bash
composer install
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate:fresh --seed
php artisan serve
```

`.env.example` already sets `DB_CONNECTION=sqlite`. The empty `database/database.sqlite` file has to exist before migrate; it is not committed. `php artisan serve` listens on `http://127.0.0.1:8000`.

The seeder creates two companies and four users. Phones are E.164 and globally unique. There is no email and no password.

| Name        | Phone        | Company           |
| ----------- | ------------ | ----------------- |
| Maya Chen   | +15555550101 | Acme (`acme`)     |
| Luis Ortega | +15555550102 | Acme (`acme`)     |
| Priya Shah  | +15555550103 | Globex (`globex`) |
| Jonah Berg  | +15555550104 | Globex (`globex`) |

Each company also gets two projects, with assignees drawn only from that company.

There is no SMS vendor. A successful OTP request writes the plaintext code to `storage/logs/laravel.log` as `OTP code for +15555550101: 123456`. Tail that file, or run `php artisan pail` in a second terminal, then submit the code to verify. Do not send a company id or a company header. The phone is enough.

```bash
curl -s -X POST http://127.0.0.1:8000/api/otp/request \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"phone\":\"+15555550101\"}"
```

```bash
curl -s -X POST http://127.0.0.1:8000/api/otp/verify \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"phone\":\"+15555550101\",\"code\":\"123456\"}"
```

Send the returned token on later requests as `Authorization: Bearer {token}`.

## API reference

Every JSON body uses the envelope in [API response & error format](#api-response--error-format). Authenticated routes require `auth:sanctum` and then `resolve.company`. The client never supplies a company. `company_id` on a project body is rejected.

Examples below use a fresh `migrate:fresh --seed` (Maya Chen is user `1`, Acme is company `1`). Timestamps and token strings vary.

### `POST /api/otp/request`

Auth: none. Throttled at 3 requests per 10 minutes per phone and IP (`throttle:otp-request`).

Request:

```json
{ "phone": "+15555550101" }
```

`phone` is required, normalized to E.164 (`+` and 2–15 digits, first digit not zero).

Success `202`:

```json
{
    "message": "If an account exists for that phone number, a verification code has been sent.",
    "data": null
}
```

An unknown phone returns the same `202` and writes no row.

Error `422` (invalid phone):

```json
{
    "message": "Validation failed.",
    "errors": {
        "phone": ["The phone field format is invalid."]
    }
}
```

The fourth request for the same phone and IP in 10 minutes is `429` with `"message": "Too Many Attempts."` and `"errors": null`. `Retry-After` and `X-RateLimit-Limit` stay on the response.

### `POST /api/otp/verify`

Auth: none.

Request:

```json
{ "phone": "+15555550101", "code": "123456" }
```

`code` is a 6-digit string.

Success `200`:

```json
{
    "message": "Authenticated.",
    "data": {
        "token": "1|plain-text-token",
        "user": {
            "id": 1,
            "name": "Maya Chen",
            "phone": "+15555550101",
            "company": { "id": 1, "name": "Acme", "slug": "acme" }
        }
    }
}
```

Error `422` (unknown phone, wrong code, expired code, or already consumed code — same body):

```json
{
    "message": "Validation failed.",
    "errors": {
        "code": ["The verification code is invalid."]
    }
}
```

### `GET /api/user`

Auth: Bearer token.

Request body: none.

Success `200`:

```json
{
    "message": "Authenticated user retrieved.",
    "data": {
        "id": 1,
        "name": "Maya Chen",
        "phone": "+15555550101",
        "company": { "id": 1, "name": "Acme", "slug": "acme" }
    }
}
```

Error `401`:

```json
{
    "message": "Unauthenticated.",
    "errors": null
}
```

### `GET /api/projects`

Auth: Bearer token.

Request body: none. Optional query `page` (default 1). Page size is 15. Order is `created_at` descending, then `id` descending. Only the token user's company is listed.

Success `200`:

```json
{
    "message": "Projects retrieved.",
    "data": [
        {
            "id": 2,
            "name": "Mobile checkout",
            "description": "In-app checkout for Acme customers.",
            "created_at": "2026-09-29T18:00:00.000000Z",
            "assigned_users": [
                {
                    "id": 2,
                    "name": "Luis Ortega",
                    "phone": "+15555550102",
                    "company": { "id": 1, "name": "Acme", "slug": "acme" }
                }
            ]
        }
    ],
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 2,
            "last_page": 1
        }
    }
}
```

Error `401`: same `Unauthenticated.` body as `GET /api/user`.

### `POST /api/projects`

Auth: Bearer token.

Request:

```json
{
    "name": "Launch",
    "description": "Ship it",
    "user_ids": [2]
}
```

`name` is required (string, max 255). `description` is optional and nullable. `user_ids` is an optional array of integers; omit it to create the project with no assignees. Every id must be a user in the same company. `company_id` is prohibited. The project’s `company_id` is filled from the authenticated user.

Success `200`:

```json
{
    "message": "Project created.",
    "data": {
        "id": 5,
        "name": "Launch",
        "description": "Ship it",
        "created_at": "2026-09-29T18:05:00.000000Z",
        "assigned_users": []
    }
}
```

Error `422` (client sent a company):

```json
{
    "message": "Validation failed.",
    "errors": {
        "company_id": ["The company id field is prohibited."]
    }
}
```

A `user_ids` entry that is missing or belongs to another company is also `422`, with `"user_ids": ["The selected user is invalid."]`. That message does not say which case it was.

### `GET /api/projects/{project}`

Auth: Bearer token.

Request body: none. `{project}` is resolved only inside the authenticated company.

Success `200`:

```json
{
    "message": "Project retrieved.",
    "data": {
        "id": 1,
        "name": "Website redesign",
        "description": "Public marketing site for Acme.",
        "created_at": "2026-09-29T18:00:00.000000Z",
        "assigned_users": []
    }
}
```

Error `404` (unknown id, or an id owned by another company):

```json
{
    "message": "Resource not found.",
    "errors": null
}
```

### `PATCH /api/projects/{project}`

Auth: Bearer token.

Request (all fields optional; send at least the fields to change):

```json
{
    "name": "Renamed",
    "description": "Keep me",
    "user_ids": [1, 2]
}
```

`name`, when present, is a required string (max 255). `description` may be null. `user_ids`, when present, replaces the assignee list; omit the key to leave assignees unchanged. Same-company rule as create. `company_id` is prohibited.

Success `200`: `"message": "Project updated."` and `data` is the project resource (same shape as show).

Error `404`: another company’s project id, same body as show. A bad `user_ids` entry is `422` with `"The selected user is invalid."`

### `DELETE /api/projects/{project}`

Auth: Bearer token.

Request body: none.

Success `200`:

```json
{
    "message": "Project deleted.",
    "data": null
}
```

Error `404`: another company’s project id, same body as show.

### `POST /api/projects/{project}/users`

Auth: Bearer token.

Request:

```json
{ "user_id": 2 }
```

`user_id` is a required integer and must be a user in the same company. `company_id` is prohibited.

Success `200`: `"message": "User assigned to project."` and `data` is the project resource, including `assigned_users`.

Error `422` (missing user, or a user from another company):

```json
{
    "message": "Validation failed.",
    "errors": {
        "user_id": ["The selected user is invalid."]
    }
}
```

Another company’s `{project}` is `404` before the body is considered.

### `DELETE /api/projects/{project}/users/{user}`

Auth: Bearer token.

Request body: none. `{user}` must be numeric.

Success `200` (also when that user was not assigned — the call is idempotent):

```json
{
    "message": "User removed from project.",
    "data": null
}
```

Error `404`: another company’s project id, same body as show.

## Database design

One shared schema. `companies` is the tenant root (`name`, unique `slug`). Each `users` row requires `company_id`. `users.phone` is unique across the whole database and is the login key, stored in one E.164 form. `users` has no email column and no password column.

`projects` and `otp_codes` each store `company_id`. Project assignees live on `project_user` (`project_id`, `user_id`, unique pair, both foreign keys cascade on delete). The database does not enforce that both ids belong to the same company; the project actions do, by loading candidates with `User::where('company_id', ...)`. A miss and a foreign-company id fail the same way.

Project lists are ordered by `created_at` descending, then `id` descending, and are backed by a composite index on `(company_id, created_at, id)`.

`otp_codes` stores `code_hash`, `expires_at`, nullable `consumed_at`, and `attempts`. Only the hash is stored. Lookup is the latest unused, unexpired row for that user, then `Hash::check`, because a bcrypt hash cannot be queried by the plaintext code.

## Tenant isolation

One database, one application. `Project` and `OtpCode` use a `BelongsToCompany` global scope that filters on `company_id` and fills it on create. If no company is bound, the scope matches zero rows, so a query that forgets to bind a company cannot return every tenant’s projects or OTP rows. Creating either model without a `company_id` and without a bound company fails instead of inserting a null tenant.

`User` does not use the scope. That is the only exception: Sanctum loads the token’s user before any company is bound, and OTP has to find a phone across all companies. Nothing in the application or the tests calls `withoutGlobalScopes()`. When a company filter is needed on users (assignee checks), the query is explicit: `User::where('company_id', ...)`.

The tenant always comes from a user record. OTP request and verify load `User` by phone with a normal query, then bind that user’s company before any `OtpCode` read or insert. Every other API route runs `auth:sanctum`, then `resolve.company`, then route-model binding. `resolve.company` reads `company_id` from the authenticated user and binds that company. It does not read a header or a route parameter. `{project}` resolves only after the company is bound; another company’s id is hidden by the scope and returns `404`, not `403`.

## Authentication approach

Passwordless. `POST /api/otp/verify` is the only place a Sanctum personal access token is issued, and only after the code checks out. The token name is `otp`. Later requests send it as a Bearer token. Responses are JSON through Eloquent API resources (`UserResource`, `AuthTokenResource`, `ProjectResource`), wrapped in the envelope.

There is no registration endpoint. Users are created by seeders (or by an operator writing rows). The API does not create users.

## OTP implementation decisions

Codes are stored only as a hash. Plaintext is passed to `OtpSender` and is never put in an HTTP response. The lifetime comes from `config/otp.php` (`OTP_TTL`, default 5 minutes). A successful verify sets `consumed_at`; a second verify of that code fails. An expired code fails.

Each wrong code increments `attempts`. The fifth failure sets `consumed_at`, so the remaining TTL cannot be used to keep guessing. Unknown phone, wrong code, expired code, and consumed code all return the same `422` body.

`POST /api/otp/request` is limited to 3 requests per 10 minutes, keyed by the normalized phone plus the client IP, including unknown phones. An unknown phone gets the same `202` as a known phone, and no `otp_codes` row is written.

`AppServiceProvider` binds `OtpSender` to `LogOtpSender` in every environment, which writes the code to the log. A real SMS vendor later is a one-line change of that binding. That implementation should not log the plaintext. Tests replace the contract and capture the code from the fake.

## API response & error format

Success:

```json
{
    "message": "string",
    "data": {},
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 1,
            "last_page": 1
        }
    }
}
```

`data` is an object, an array, or `null`. `meta` is present only on a paginated response (`GET /api/projects`) and is omitted otherwise. Pagination links and the request path are not included. Eloquent API resources are the `data` payload; they are wrapped once, not given a second `data` key.

Error:

```json
{
    "message": "string",
    "errors": null
}
```

`errors` is `field => [messages]` for validation, and `null` for every other error. Validation’s `message` is always `Validation failed.`

These statuses all use that error shape. The body never includes a stack trace, file path, exception class, or model name, including when `APP_DEBUG` is true:

| Status | `message`                                                                                                                            |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| 401    | `Unauthenticated.`                                                                                                                   |
| 403    | The message this app threw (for example `User is not assigned to a company.`), or `This action is unauthorized.` when that message is empty |
| 404    | `Resource not found.` for a missing model and for an unknown route                                                                   |
| 422    | `Validation failed.` with `errors` filled                                                                                            |
| 429    | `Too Many Attempts.` Rate-limit headers stay on the response                                                                         |
| 500    | `Something went wrong.` The real exception is still written to the log                                                               |

Other statuses under `/api` (for example 405) use the same shape and a status phrase that does not include the route. 5xx messages stay `Something went wrong.` This rendering applies to `/api` and to any request that expects JSON. Non-API web requests keep Laravel’s HTML error pages.

Delete and unassign return `200` with `"data": null`. A `204` cannot carry the envelope.

## Assumptions

One company per user. No roles beyond membership in that company. No self-registration. Email is not part of the schema. No SMS vendor in this version. The framework `password_reset_tokens` and `sessions` tables remain and no feature reads them.

## Scalability

At 10,000 companies and millions of rows, keep this shared database. Tenant lists already use the `company_id` composite index; large listings should move to cursor pagination. OTP delivery should move onto a queue. When more than one app server is running, the rate limiter should use a shared cache (Redis). Assignee relations are eager-loaded (`users.company`). A database per tenant is a later change only if physical isolation becomes a requirement; `company_id` is the shard key.

## Testing

```bash
vendor/bin/phpunit
```

or:

```bash
php artisan test
```

Current status: 38 tests, 223 assertions, passing. Feature tests hit the HTTP API against in-memory SQLite. They cover tenant isolation (the scope returns nothing until a company is bound, and it fills `company_id` on create), the OTP lifecycle (hash at rest, no code in the response, unknown phones, single use, expiry, the fifth failed guess, the phone-and-IP throttle), cross-tenant project access (`404` on show, update, and delete, and `422` when assigning another company’s user), and the response envelope (including generic `404` and `500` bodies with debug turned on). Tests do not call `withoutGlobalScopes()` and do not send a company header.
