# Decisions

Settled implementation decisions. Each section is what we are building.

## Tenancy model

One shared database and one application. Every tenant-owned table has `company_id`. `Project` and `OtpCode` use a `BelongsToCompany` global scope that filters on that column and fills it on create. `User` does not use the scope. This is the only exception, and it is this one: Sanctum loads the token user before a company is bound, and OTP must find a phone across all companies. No code calls `withoutGlobalScopes()`. When a company filter is needed on users (assignee checks), the query is explicit: `User::where('company_id', ...)`.

Why: the scope isolates tenant rows, and the user row has to be readable before any company is bound.

## Tenant identification

The tenant always comes from a user record. The client never supplies a company. OTP request and verify find the user by phone with a normal query on the unscoped `User` model, then bind that user's company before any `OtpCode` query or insert. Every other endpoint binds `company_id` from the authenticated user in middleware that runs after `auth:sanctum` and before route-model binding.

Why: each user belongs to exactly one company, so the phone or the token already identifies the tenant.

## Database design

`companies` is the tenant root (`name`, unique `slug`). `users.company_id` is required. `users.phone` is unique across the whole database. `users` has no email column and no password column. `projects` and `otp_codes` each store `company_id`. Assignees are a `project_user` pivot; both ids must belong to the same company, enforced in the action. List queries are backed by a composite index led by `company_id`.

Why: one schema with `company_id` isolates companies without a database per tenant, and the phone is the global login key.

## Auth

Passwordless. A successful OTP verify issues a Laravel Sanctum personal access token. Responses are JSON through Eloquent API resources. There is no registration endpoint; users are created by seeders.

Why: the task requires an OTP login and a token, and Sanctum is already installed.

## OTP

The code is stored only as a hash, expires, and cannot be used again after a successful verify. A small cap on failed verifies invalidates the code. Plaintext is passed only to an `OtpSender` implementation, never written into the HTTP response. An unknown phone receives the same request response as a known phone, and no row is written.

Why: a response body or a database read must not be enough to log in.

## Rate limiting

OTP requests are throttled on the pair of phone number and client IP.

Why: that limits repeated sends to one number from one source before a company has been bound.

## Assumptions

One company per user, and no roles beyond membership in that company. No self-registration. Email is not part of the product. No SMS vendor in this version: `OtpSender` is bound to `LogOtpSender` in every environment. Swapping in a real vendor later is a one-line binding change, and that implementation should not log the plaintext. The framework `password_reset_tokens` and `sessions` tables stay in place and no feature reads them.

Why: the task covers company users, their projects, and phone OTP.

## Scalability

At 10,000 companies and millions of rows, keep this shared database. Tenant lists use the `company_id` composite index and cursor pagination. OTP delivery moves onto a queue. The rate limiter uses a shared cache (Redis) when more than one app server is running. Assignee relations are eager-loaded. A database per tenant is a later change only if physical isolation becomes a requirement; `company_id` is the shard key.

Why: indexes, keyset pagination, and a queue remove the first bottlenecks while the single schema still holds.
