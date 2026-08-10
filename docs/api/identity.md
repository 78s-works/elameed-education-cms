# Identity Module

The **Identity** module (M11, plus M02/M13/M18 slices) owns everything about *who a user is* and *what a teacher may do with their students and assistants*. It covers public authentication (register, password + OTP login, forgot/reset password), the authenticated "current user" endpoint, the read-only **parent portal**, the teacher's full **student-management surface** (roster CRUD, per-academy registration profiles, manual enrollments, wallet control, activity timelines, direct notifications, and parent linking), and **assistant management + granular RBAC** (M18): the teacher creates assistants and grants each a subset of permissions that gate the shared teacher surface.

Identity is deliberately built around the distinction between a **global user identity** (`users` — a person, who may study at several academies) and a **tenant membership** (`tenant_user` — that person's role + status inside one academy). Teacher actions almost never touch the global identity; they act on the membership and the academy-scoped data. A user who is not a student of the current tenant is reported as `404` (invisible), not `403`.

## Conventions (apply to every endpoint)

- Base prefix `/api/v1`. Success is `{ "data": ... }` (plus `"meta"` for paginated lists). Errors are `{ "error": { "code", "message", "details" } }`.
- Money is an integer in **minor units** (piastres) plus a `currency` code. Timestamps are ISO-8601 UTC.
- **Tenancy:** every route runs through the `tenant` middleware group; the tenant is resolved from the **Host** header (or `X-Tenant: <slug>` as a dev override).
- **Auth:** Sanctum `Authorization: Bearer <token>`. Public auth routes need no token. `role:teacher` / `role:parent` require a token **and** an *active* membership carrying that role in the resolved tenant. Platform admins bypass the role check.
- **Granular permissions (M18):** the student- and center-management blocks run under `role:teacher,assistant` **and** a `permission:<key>` gate (`permission:students` / `permission:centers`). A **teacher** passes every permission check implicitly; an **assistant** passes only for the keys the teacher granted on their membership (else `403 forbidden`). Assistant *management itself* (`/teacher/assistants*`, `/teacher/permissions`) is `role:teacher` only.
- **Throttling:** `register`, `otp/request`, `password/forgot`, `password/reset` use `throttle:otp`; `login` and `otp/verify` use `throttle:auth`.

> **Gotcha — OTP is stubbed.** `OtpService::verify()` currently returns `true` unconditionally (the real verification is commented out, to be re-enabled "when we have a proper OTP system in place"). In the current build **any code passes** OTP verification. Codes are still issued, hashed, and dispatched via `SendOtpJob`; only the check is a no-op.

## Models

- **`TenantUser`** (`tenant_user`) — a user's membership + role within one tenant. **Global** mapping table (not RLS-scoped). Casts `role` → `TenantUserRole`, `status` → `MembershipStatus`, `permissions` → `array` (the M18 granular grant, only meaningful for assistants); `isActive()`, `hasPermission($key)`, `effectivePermissions()` helpers.
- **`StudentProfile`** — per-academy registration details for a student (`gender`, `governorate`, `region`, `academic_year`, `education_type`, `guardian_phone`, plus VD R6/R7 `study_mode` enum `center|online|both` default `online` + `center_id` nullable FK → `centers`). Tenant-scoped, one row per (tenant, student). Free-text profile fields; the frontend owns the dropdown options. `guardian_phone` is non-unique (siblings share). Exposes `FIELDS` (incl. `study_mode`; `center_id` is resolved from a center uuid, not plucked), `rules($prefix)`, `fields($data)`, and a `center()` relation.
- **`ParentLink`** — links a parent user to a student user within a tenant, with an optional `relation`. Tenant-scoped.
- **`ParentMagicLink`** — a permanent, revocable passwordless login token for a guardian (VD R11). Stores only the SHA-256 `token_hash` (never the raw token) + `is_active` + `last_used_at`. Tenant-scoped; consumed via `GET /parent/magic/{token}` to mint a parent session. Issued/rotated/revoked by `ParentMagicLinkService`.
- **`OtpCode`** — a one-time passcode. Stores only `code_hash` (never plaintext), plus `identifier`, `channel`, `purpose`, `attempts`, `expires_at`, `consumed_at`. `isExpired()` / `isConsumed()` helpers.
- **`LoginAttempt`** — an audit row for every login attempt (`user_id`, `tenant_id`, `identifier`, `ip`, `user_agent`, `success`). Create-only (`UPDATED_AT = null`).
- **`User`** (`app/Models/User`, shared) — the global identity Identity authenticates and edits (`name`, `phone`, `email`, `password`, `locale`, verification timestamps, `isPlatformAdmin()`, `membershipFor()`).

**Enums:** `TenantUserRole` (`teacher`, `assistant`, `student`, `parent` — platform admin is a *global* flag, not a membership role) · `MembershipStatus` (`active`, `pending`, `suspended`) · `OtpPurpose` (`register`, `login`, `reset`) · `Permission` (M18 catalog: `students`, `centers`, `homework`, `finance`; `Permission::catalog()` returns each with a `label` + `description`, `Permission::values()`/`sanitize()`).

**Actions / services / support:** `RegisterStudentAction`, `LoginAction`, `VerifyOtpAction`, `ResetPasswordAction`; `OtpService` (issue/verify); `UserLookup` (resolve phone-or-email → user); `SendOtpJob`. Middleware `EnsureTenantRole` (`role:…`, accepts a comma list e.g. `role:teacher,assistant`), `EnsureActiveMembership` (`active`), and `EnsurePermission` (`permission:<key>`, M18 — teachers pass implicitly; assistants need the granted key).

---

## Endpoints

### Auth & OTP

Registration is student self-signup into the current academy: it creates the global user + a **pending** student membership and issues a `register` OTP; the membership activates on OTP verify. Teacher/admin accounts are provisioned by the platform admin (self-signup is P1.5). Login is password-first; if `otp.login_required` is enabled it returns `otp_required` instead of a token and the client finishes via `otp/verify`. Bad credentials and unknown identifiers always return generic messages (no account enumeration).

---

#### `POST /v1/auth/register`
**Purpose:** Student self-registration into the current tenant. Creates the user + a pending membership + student profile and sends a registration OTP.
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:otp`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| X-Tenant | dev only | `academy` |

**Path / Query params:** None

**Request body**
```json
{
  "name": "Mohamed Ali Hassan Ibrahim",
  "phone": "+201112223334",
  "email": "student@example.com",
  "password": "Passw0rd!",
  "password_confirmation": "Passw0rd!",
  "locale": "ar",
  "gender": "male",
  "governorate": "Cairo",
  "region": "Nasr City",
  "academic_year": "Grade 12",
  "education_type": "general",
  "guardian_phone": "+201009998887",
  "study_mode": "center",
  "center": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f"
}
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `phone` | required, string, max 20, regex `^[0-9+]{6,20}$` (trimmed before validation) |
| `email` | nullable, email, max 255 |
| `password` | required, string, **confirmed** (send `password_confirmation`), min 8 |
| `locale` | optional, `ar` or `en` (defaults `ar`) |
| `gender` | nullable, string, max 20 |
| `governorate` | nullable, string, max 100 |
| `region` | nullable, string, max 100 |
| `academic_year` | nullable, string, max 100 |
| `education_type` | nullable, string, max 100 |
| `guardian_phone` | nullable, string, max 30, regex `^[0-9+]{6,30}$` (non-unique — siblings share) |
| `study_mode` | nullable, one of `center` \| `online` \| `both` (defaults `online`) — VD R6/R7 |
| `center` | center **uuid** (not the numeric id); **required when `study_mode` ∈ {center, both}**; must belong to the current tenant; resolved to `student_profiles.center_id` |

**Response** `202 Accepted`
```json
{
  "data": {
    "message": "A verification code has been sent.",
    "identifier": "+201112223334",
    "requires_otp": true
  }
}
```

**Errors:** `422` validation (`phone` is returned as `An account with these details already exists. Please log in.` when the phone/email already exists globally — P1 keeps phone globally unique); `422` `tenant` when not called from an academy site; `403 forbidden` (`Registration is currently closed for this academy.`) when the teacher has disabled self-registration (`registration_enabled=false`, see `PUT /teacher/access` in the Tenancy module); `429` throttled.

---

#### `POST /v1/auth/otp/request`
**Purpose:** (Re)send an OTP for register / login / reset. Response is generic so identifiers can't be enumerated.
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:otp`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{ "identifier": "+201112223334", "purpose": "register" }
```

| Field | Rules |
|---|---|
| `identifier` | required, string, max 255 (phone or email) |
| `purpose` | required, one of `register` \| `login` \| `reset` |

For `login`/`reset` a code is only actually sent if the identifier maps to a user; for `register` it is always sent to the given phone.

**Response** `200`
```json
{ "data": { "message": "If the details are valid, a verification code has been sent." } }
```

**Errors:** `422` validation; `429` throttled.

---

#### `POST /v1/auth/otp/verify`
**Purpose:** Verify a `register` or `login` code and issue an access token. For `register` it also marks the phone verified and activates the pending membership. (Reset codes are consumed by `/password/reset`, not here.)
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:auth`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{ "identifier": "+201112223334", "purpose": "register", "code": "123456" }
```

| Field | Rules |
|---|---|
| `identifier` | required, string, max 255 |
| `purpose` | required, one of `register` \| `login` |
| `code` | required, string |

**Response** `200` (token + `UserResource`)
```json
{
  "data": {
    "token": "12|f0Xk9r8Q2c...plainTextSanctumToken",
    "user": {
      "uuid": "9b2c1e4a-...",
      "name": "Mohamed Ali Hassan Ibrahim",
      "email": "student@example.com",
      "phone": "+201112223334",
      "locale": "ar",
      "email_verified": false,
      "phone_verified": true
    }
  }
}
```

**Errors:** `422` `code` (`The code is invalid or has expired.`); `422` `identifier` when no user matches; `429` throttled. (See the OTP-stub gotcha above — in the current build the code check always passes.)

---

#### `POST /v1/auth/login`
**Purpose:** Password login by phone or email. Returns a token directly, or (when login-OTP is enabled) requests an OTP step. Every attempt is recorded to `login_attempts`.
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:auth`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{ "identifier": "+201112223334", "password": "Passw0rd!" }
```

| Field | Rules |
|---|---|
| `identifier` | required, string, max 255 (phone or email) |
| `password` | required, string |

**Response** `200` — one of two shapes.

Token issued:
```json
{
  "data": {
    "token": "13|Ab9...plainTextSanctumToken",
    "user": {
      "uuid": "9b2c1e4a-...",
      "name": "Mohamed Ali Hassan Ibrahim",
      "email": "student@example.com",
      "phone": "+201112223334",
      "locale": "ar",
      "email_verified": false,
      "phone_verified": true
    }
  }
}
```

OTP required (when `otp.login_required` is on):
```json
{ "data": { "otp_required": true, "identifier": "+201112223334" } }
```

**Errors:** `401` (`These credentials do not match our records.` — generic, whether identifier or password was wrong); `403` on a tenant host when the user has no active membership there (`You are not a member of this academy.`); `403 forbidden` (`Sign-in is currently disabled for this academy.`) when the teacher has disabled login (`login_enabled=false`) and the user is **anyone other than the teacher** — assistants, students, and parents are all blocked; only the teacher can still sign in (to reach their panel and re-open access — see `PUT /teacher/access`); `403` on the platform host for non-admins; `429` throttled.

---

#### `POST /v1/auth/password/forgot`
**Purpose:** Issue a password-reset OTP. Generic response regardless of whether the account exists.
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:otp`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{ "identifier": "+201112223334" }
```

| Field | Rules |
|---|---|
| `identifier` | required, string, max 255 (phone or email) |

**Response** `200`
```json
{ "data": { "message": "If the account exists, a reset code has been sent." } }
```

**Errors:** `422` validation; `429` throttled.

---

#### `POST /v1/auth/password/reset`
**Purpose:** Verify a reset code and set a new password. Revokes all existing tokens so pre-reset sessions are invalidated.
**Auth:** 🔓 Public
**Middleware:** `tenant`, `throttle:otp`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{ "identifier": "+201112223334", "code": "123456", "password": "NewPassw0rd!" }
```

| Field | Rules |
|---|---|
| `identifier` | required, string, max 255 |
| `code` | required, string |
| `password` | required, string, min 8 |

**Response** `200`
```json
{ "data": { "message": "Your password has been reset. Please sign in." } }
```

**Errors:** `422` `code` (`The code is invalid or has expired.` — also returned generically if the identifier maps to no user); `429` throttled.

---

#### `POST /v1/auth/logout`
**Purpose:** Revoke the current access token.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer 13|Ab9...` |

**Path / Query params:** None
**Request body:** None

**Response** `200`
```json
{ "data": { "message": "Signed out." } }
```

**Errors:** `401` missing/invalid token; `403` if membership is no longer active.

---

### Current user

#### `GET /v1/me`
**Purpose:** The authenticated user, all their tenant memberships (identity spans tenants), and their role in the current tenant.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer 13|Ab9...` |

**Path / Query params:** None
**Request body:** None

**Response** `200` (`MeResource`)
```json
{
  "data": {
    "uuid": "9b2c1e4a-...",
    "name": "Mohamed Ali Hassan Ibrahim",
    "email": "student@example.com",
    "phone": "+201112223334",
    "locale": "ar",
    "email_verified": false,
    "phone_verified": true,
    "is_platform_admin": false,
    "memberships": [
      { "tenant": "academy", "tenant_name": "El Ameed Academy", "role": "student", "status": "active" }
    ],
    "current": { "tenant": "academy", "role": "teacher", "permissions": ["students", "centers"] }
  }
}
```

`current.permissions` is the caller's **effective** permission set in the current tenant (M18): a **teacher** gets the full catalog (`["students", "centers"]`), an **assistant** gets their granted subset, and any other role gets `[]`.

**Errors:** `401` missing/invalid token; `403` inactive membership.

---

### Parent portal

Read-only. A parent (`role:parent`) sees only children linked to them in this academy, plus each child's progress and results. Targeting a student who isn't the parent's linked child returns `404`.

Parents may sign in **passwordless** via a permanent magic link (VD R11) instead of a password. The link is minted by the teacher (`POST /v1/teacher/students/{student}/parents/{parent}/magic-link`, see the teacher section) and consumed at the public endpoint below.

---

#### `GET /v1/parent/magic/{token}`
**Purpose:** Passwordless parent login. Resolves the guardian from a permanent magic-link token **in the resolved tenant**, mints a normal parent Sanctum session, and returns the child list.
**Auth:** 🔓 public (no token)
**Middleware:** `tenant`, `throttle:auth`

**Path params**
| Param | In | Description |
|---|---|---|
| `token` | path | Raw magic-link token (compared by SHA-256 hash; never stored in plaintext) |

**Request body:** None

**Response** `200`
```json
{
  "data": {
    "token": "42|xxxx…",
    "user": { "uuid": "…", "name": "Abu Sara", "phone": "+20…" },
    "children": [ { "uuid": "…", "name": "Sara Kamal", "phone": "+20…", "relation": "father", "lessons_completed": 12 } ],
    "active_child": "…"
  }
}
```

**Lifecycle (VD-D5 — permanent + revocable):** the token never expires and is **reusable** (each consume stamps `last_used_at`). It is **tenant-scoped** (a token minted on another academy host resolves to nothing) and **cryptographically random**. Revocation is explicit — teacher rotate (a new link deactivates the old) or DELETE (`is_active=false`).
**Errors:** `404` on an invalid / revoked / cross-tenant token, or when the guardian has no active `parent` membership (no enumeration); `403` if the academy's sign-in is disabled; `429` if rate-limited.

---

#### `GET /v1/parent/children`
**Purpose:** List the parent's linked children in this academy, with a completed-lessons count. Only **active** students are listed (a suspended/removed child drops off — the magic link stays valid). Each child carries an `active` flag = the session's currently-selected child.
**Auth:** 👪 role:parent
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:parent`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <parent token>` |

**Path / Query params:** None
**Request body:** None

**Response** `200`
```json
{
  "data": [
    {
      "uuid": "3f7a...",
      "name": "Sara Kamal",
      "phone": "+201223334445",
      "relation": "father",
      "lessons_completed": 12,
      "active": true
    }
  ]
}
```

**Errors:** `401`; `403` if not a parent of this tenant.

---

#### `POST /v1/parent/switch`
**Purpose:** Multi-child switcher (VD R11) — set the active child of **this session only** (persisted on `personal_access_tokens.active_child_id`, so it never leaks to another device/session).
**Auth:** 👪 role:parent
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:parent`

**Request body**
| Field | Type | Required | Description |
|---|---|---|---|
| `student` | uuid | yes | A child linked to this parent and still an active student |

**Response** `200`
```json
{ "data": { "uuid": "…", "name": "Sara Kamal", "phone": "+20…", "relation": "father", "lessons_completed": 12, "active": true } }
```

**Errors:** `404` if the target isn't a linked/active child; `422` if `student` is missing/not a uuid.

---

#### `GET /v1/parent/children/{student:uuid}/progress`
**Purpose:** A linked child's per-lesson progress.
**Auth:** 👪 role:parent
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:parent`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <parent token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** (must be a child linked to this parent) |

**Request body:** None

**Response** `200`
```json
{
  "data": [
    { "lesson_id": 501, "lesson_title": "Algebra · Unit 1", "watch_percent": 100, "completed": true },
    { "lesson_id": 502, "lesson_title": "Algebra · Unit 2", "watch_percent": 40, "completed": false }
  ]
}
```

**Errors:** `401`; `403` not a parent; `404` `Child not found.` when the target isn't linked to this parent.

---

#### `GET /v1/parent/children/{student:uuid}/results`
**Purpose:** A linked child's submitted/graded exam results.
**Auth:** 👪 role:parent
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:parent`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <parent token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** (linked child) |

**Request body:** None

**Response** `200`
```json
{
  "data": [
    {
      "exam": "Midterm — Mechanics",
      "status": "graded",
      "score": 18,
      "max_score": 20,
      "submitted_at": "2026-05-10T14:03:00+00:00"
    }
  ]
}
```

Only attempts with status `submitted` or `graded` are returned.

**Errors:** `401`; `403`; `404` `Child not found.`

---

### Teacher · Students

The teacher's roster + student lifecycle. All actions are tenant-scoped and operate on the student's **membership** and academy data, never the global identity. Any student not a member of the current academy → `404 Student not found in this academy.`

> **Access (M18).** This whole block — Students, Enrollments, Finance, Activity, and Parents below — now runs under **`role:teacher,assistant`** + **`permission:students`**. A teacher reaches it implicitly; an assistant only if granted the `students` permission (else `403 forbidden`). The per-endpoint **Middleware** lines below reflect this chain.

---

#### `GET /v1/teacher/students`
**Purpose:** Paginated roster of the academy's students, with search and status filter, plus a per-student active-enrollment count and registration profile.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `q` | query | Search term matched against user `name`, `phone`, `email` |
| `filter[status]` | query | Filter by membership status (`active` \| `pending` \| `suspended`) |
| `page` | query | Page number (30 per page) |

**Request body:** None

**Response** `200` (`StudentResource` collection, paginated → includes `meta`/`links`)
```json
{
  "data": [
    {
      "uuid": "3f7a...",
      "name": "Sara Kamal",
      "phone": "+201223334445",
      "email": "sara@example.com",
      "status": "active",
      "joined_at": "2026-02-01T09:00:00+00:00",
      "enrolled_courses": 3,
      "gender": "female",
      "governorate": "Giza",
      "region": "Dokki",
      "academic_year": "Grade 11",
      "education_type": "general",
      "guardian_phone": "+201009998887"
    }
  ],
  "meta": { "current_page": 1, "per_page": 30, "total": 84, "last_page": 3 }
}
```

**Errors:** `401`; `403` not a teacher of this tenant.

---

#### `POST /v1/teacher/students`
**Purpose:** Manually add a student to this academy (offline onboarding). Reuses an existing global identity by phone if one exists (links, never modifies it); otherwise creates the user with a temporary password. Also writes the registration profile.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params:** None

**Request body**
```json
{
  "name": "Sara Kamal",
  "phone": "+201223334445",
  "email": "sara@example.com",
  "password": "TempPass123",
  "gender": "female",
  "governorate": "Giza",
  "region": "Dokki",
  "academic_year": "Grade 11",
  "education_type": "general",
  "guardian_phone": "+201009998887"
}
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `phone` | required, string, max 20, regex `^[0-9+]{6,20}$` |
| `email` | nullable, email, max 255 |
| `password` | nullable, string, min 8 (if omitted a temp password is generated and returned once) |
| profile fields | `gender`, `governorate`, `region`, `academic_year`, `education_type`, `guardian_phone`, `study_mode` (`center\|online\|both`) — all nullable (see `StudentProfile`). `center_id` is only settable via `/auth/register` (center uuid). |

**Response** `201` (nulls stripped; `temporary_password` present only when generated)
```json
{
  "data": {
    "uuid": "3f7a...",
    "name": "Sara Kamal",
    "phone": "+201223334445",
    "email": "sara@example.com",
    "status": "active",
    "gender": "female",
    "governorate": "Giza",
    "region": "Dokki",
    "academic_year": "Grade 11",
    "education_type": "general",
    "guardian_phone": "+201009998887"
  }
}
```

**Errors:** `422` `phone` (`This person is already a member of your academy.`) when the phone already belongs to a member of this tenant; `422` validation; `401`/`403`.

---

#### `GET /v1/teacher/students/{student:uuid}`
**Purpose:** 360° view of one student: identity + membership + registration profile + summary counts (enrollments, wallet balance, orders, completed lessons).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": {
    "uuid": "3f7a...",
    "name": "Sara Kamal",
    "phone": "+201223334445",
    "email": "sara@example.com",
    "status": "active",
    "joined_at": "2026-02-01T09:00:00+00:00",
    "gender": "female",
    "governorate": "Giza",
    "region": "Dokki",
    "academic_year": "Grade 11",
    "education_type": "general",
    "guardian_phone": "+201009998887",
    "summary": {
      "enrolled_courses": 3,
      "wallet_balance_minor": 15000,
      "orders": 5,
      "lessons_completed": 22
    }
  }
}
```

**Errors:** `404` `Student not found in this academy.`; `401`/`403`.

---

#### `PATCH /v1/teacher/students/{student:uuid}`
**Purpose:** Edit identity (name/phone/email), change membership status (activate/suspend), and/or update the registration profile. All fields optional — patch only what's sent. Status changes are audit-logged (`student.status_changed`).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body**
```json
{
  "name": "Sara Kamal Ahmed",
  "phone": "+201223334446",
  "email": "sara.new@example.com",
  "status": "active",
  "academic_year": "Grade 12"
}
```

| Field | Rules |
|---|---|
| `name` | sometimes, string, max 255 |
| `phone` | sometimes, string, max 30, unique in `users` (ignoring this student) |
| `email` | sometimes, nullable, email, max 190, unique in `users` (ignoring this student) |
| `status` | sometimes, `active` \| `suspended` |
| profile fields | `gender`, `governorate`, `region`, `academic_year`, `education_type`, `guardian_phone`, `study_mode` (`center\|online\|both`) — all nullable |

**Response** `200`
```json
{
  "data": {
    "uuid": "3f7a...",
    "name": "Sara Kamal Ahmed",
    "phone": "+201223334446",
    "email": "sara.new@example.com",
    "status": "active",
    "gender": "female",
    "governorate": "Giza",
    "region": "Dokki",
    "academic_year": "Grade 12",
    "education_type": "general",
    "guardian_phone": "+201009998887"
  }
}
```

**Errors:** `422` validation (unique phone/email); `404` not a student here; `401`/`403`.

---

#### `DELETE /v1/teacher/students/{student:uuid}`
**Purpose:** Remove the student from this academy: cancel active enrollments and delete the membership (the global identity is untouched). Audit-logged (`student.removed`).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `204 No Content`

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/reset-password`
**Purpose:** Force a password reset. Revokes the student's existing tokens. Returns the new password only if the server generated it. Audit-logged (`student.password_reset`).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body** (validated inline)
```json
{ "password": "NewTempPass123" }
```

| Field | Rules |
|---|---|
| `password` | nullable, string, min 8, max 72 (if omitted, a temp password is generated and returned once) |

**Response** `200` (`temporary_password` present only when generated)
```json
{ "data": { "uuid": "3f7a...", "temporary_password": "aB3xK9mQ2p" } }
```

When the caller supplied `password`, the response is just `{ "data": { "uuid": "3f7a..." } }`.

**Errors:** `422` validation; `404` not a student here; `401`/`403`.

---

#### `GET /v1/teacher/students/{student:uuid}/export`
**Purpose:** Export everything this academy holds on the student (data-portability): profile, membership, enrollments, orders, progress, and wallet balance.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": {
    "profile": {
      "uuid": "3f7a...",
      "name": "Sara Kamal",
      "phone": "+201223334445",
      "email": "sara@example.com",
      "gender": "female",
      "governorate": "Giza",
      "region": "Dokki",
      "academic_year": "Grade 11",
      "education_type": "general",
      "guardian_phone": "+201009998887"
    },
    "membership": { "status": "active", "joined_at": "2026-02-01T09:00:00+00:00" },
    "enrollments": [ { "course_id": 12, "status": "active", "expires_at": null } ],
    "orders": [ { "uuid": "a1b2...", "status": "paid", "total_minor": 20000, "created_at": "2026-03-01T10:00:00+00:00" } ],
    "progress": [ { "lesson_id": 501, "watch_percent": 100, "completed_at": "2026-03-02T12:00:00+00:00" } ],
    "wallet_balance_minor": 15000
  }
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/import`
**Purpose:** Bulk-update matched students' history/profile fields from an `.xlsx`/`.csv` upload. Rows are matched by `phone` or `email` against existing users who are students of **this** tenant; each matched student's `StudentProfile` fields are updated. Returns a per-row `applied` | `duplicate` | `failed` result plus a summary (mirrors the Centers offline-sync contract). Audit-logged as `student.history_imported`.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`, `Content-Type: multipart/form-data`.

**Request body** (`StudentImportRequest`) — one file part:
| Field | Rules |
|---|---|
| `file` | required, file, extension `xlsx`/`csv`/`txt`, ≤ `identity.import_max_kb` (10 MB) |

The **first row is the header**. Recognised columns (case-insensitive): a match key (`phone` or `email`) plus the canonical `StudentProfile` history fields — `gender`, `governorate`, `region`, `academic_year`, `education_type`, `guardian_phone`. Unknown columns are ignored; blank cells are skipped (never blank out existing data). Each row is validated with `StudentProfile::rules()`.

Per-row outcomes:
- **applied** — the student was matched and their history updated.
- **duplicate** — a row references a student already applied earlier in the same file.
- **failed** — no matching student, no history fields present, or a field failed validation (message included).

**Response** `200`
```json
{
  "data": {
    "summary": { "total": 3, "applied": 2, "duplicate": 0, "failed": 1 },
    "results": [
      { "row": 2, "status": "applied", "student_uuid": "3f7a..." },
      { "row": 3, "status": "applied", "student_uuid": "9c2b..." },
      { "row": 4, "status": "failed", "message": "No matching student for the given phone/email." }
    ]
  }
}
```
**Errors:** `422` missing/oversize file or wrong extension; `401`/`403`.

---

#### Manual content-access overrides

Grant a specific student **direct access to a locked lesson/section/unit**, bypassing unmet Content
Dependencies / progression gates (and revoke it). An active override short-circuits both
`ContentUnlockService` and `LessonProgressionService` to unlocked; a `unit` override covers every
section/lesson under it, a `lesson` override covers that lesson + its sections, a `section` override
covers just that section. Grant/revoke are audit-logged (`content_access.override_granted` /
`content_access.override_revoked`).

**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`) · **Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

##### `GET /v1/teacher/students/{student:uuid}/content-overrides`
List the student's **active** overrides (newest first). **Response** `200` — collection of `ContentAccessOverrideResource` (`id`, `target_type`, `target_id`, `note`, `granted_at`, `active`).

##### `POST /v1/teacher/students/{student:uuid}/content-overrides`
**Request body** (`GrantContentOverrideRequest`):
```json
{ "target_type": "lesson", "target_id": 101, "note": "catch-up" }
```
| Field | Rules |
|---|---|
| `target_type` | required, enum `lesson\|section\|unit` |
| `target_id` | required, integer; must be a lesson/section/unit **in this tenant** |
| `note` | nullable, string, max 500 |

Idempotent: re-granting the same target re-activates/refreshes the existing row. **Response** `201 Created` — `ContentAccessOverrideResource`. **Errors:** `404` not a student here; `422` target not in tenant.

##### `DELETE /v1/teacher/students/{student:uuid}/content-overrides/{override}`
Revoke an override (soft — retained for the audit trail). **Response** `204 No Content`. **Errors:** `404` not a student here / override not found for this student.

---

### Teacher · Enrollments

A teacher granting/revoking a student's course access directly (no payment) — e.g. offline/center students. Manual grants are marked `source=manual`.

---

#### `GET /v1/teacher/students/{student:uuid}/enrollments`
**Purpose:** List the student's enrollments in this academy.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": [
    {
      "id": 88,
      "course": "c0a8...",
      "course_title": "Physics — Grade 12",
      "source": "manual",
      "status": "active",
      "starts_at": null,
      "expires_at": null
    }
  ]
}
```

`course` is the course **uuid**.

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/enrollments`
**Purpose:** Grant the student access to a course directly (`source=manual`, no payment).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body**
```json
{ "course": "c0a8f2e1-..." }
```

| Field | Rules |
|---|---|
| `course` | required, string — the course **uuid** |

**Response** `201`
```json
{ "data": { "id": 88, "course": "c0a8f2e1-...", "status": "active", "expires_at": null } }
```

**Errors:** `404` `Course not found in this academy.`; `404` not a student here; `422` validation; `401`/`403`.

---

#### `DELETE /v1/teacher/students/{student:uuid}/enrollments/{enrollment}`
**Purpose:** Revoke (cancel) an enrollment. Sets its status to `cancelled` — not a hard delete.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |
| `enrollment` | path | Enrollment **id** (integer; not UUID-bound) |

**Request body:** None

**Response** `204 No Content`

**Errors:** `404` `Enrollment not found.`; `404` not a student here; `401`/`403`.

---

### Teacher · Finance

Full control of a student's money. Every change is posted to the double-entry ledger as a balanced `adjustment` against `teacher_earnings` (never a raw balance edit) and is audit-logged (`wallet.adjust` / `wallet.set`). Crediting the student debits `teacher_earnings`; debiting the student credits it.

---

#### `GET /v1/teacher/students/{student:uuid}/wallet`
**Purpose:** Current balance + currency + the 15 most recent ledger entries.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": {
    "balance_minor": 15000,
    "currency": "EGP",
    "recent": [
      {
        "account": "student_wallet",
        "direction": "credit",
        "amount_minor": 5000,
        "ref_type": "adjustment",
        "ref_id": 41,
        "created_at": "2026-06-01T11:00:00+00:00"
      }
    ]
  }
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `GET /v1/teacher/students/{student:uuid}/wallet/ledger`
**Purpose:** Full, paginated wallet history (30 per page).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |
| `page` | query | Page number (30 per page) |

**Request body:** None

**Response** `200` (`LedgerEntryResource` collection, paginated → includes `meta`/`links`)
```json
{
  "data": [
    {
      "account": "student_wallet",
      "direction": "credit",
      "amount_minor": 5000,
      "ref_type": "adjustment",
      "ref_id": 41,
      "created_at": "2026-06-01T11:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 30, "total": 12, "last_page": 1 }
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/wallet/adjust`
**Purpose:** Credit or debit the wallet by an amount (a gift/top-up or a correction).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |
| Idempotency-Key | optional | `a1b2c3d4-...` (money-mutating endpoint) |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body**
```json
{ "amount_minor": 5000, "direction": "credit", "reason": "Loyalty bonus" }
```

| Field | Rules |
|---|---|
| `amount_minor` | required, integer, min 1 |
| `direction` | required, `credit` \| `debit` |
| `reason` | nullable, string, max 255 |

**Response** `200`
```json
{ "data": { "balance_minor": 20000 } }
```

**Errors:** `422` `amount_minor` (`Balance is too low for this deduction.`) on a debit exceeding the balance; `422` validation; `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/wallet/set`
**Purpose:** Set the wallet to an exact balance; posts the difference (positive or negative) as an adjustment. No-op if already at that balance.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |
| Idempotency-Key | optional | `a1b2c3d4-...` (money-mutating endpoint) |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body** (validated inline)
```json
{ "balance_minor": 25000, "reason": "Manual correction" }
```

| Field | Rules |
|---|---|
| `balance_minor` | required, integer, min 0 |
| `reason` | nullable, string, max 255 |

**Response** `200`
```json
{ "data": { "balance_minor": 25000 } }
```

**Errors:** `422` validation; `404` not a student here; `401`/`403`.

---

#### `GET /v1/teacher/students/{student:uuid}/orders`
**Purpose:** The student's orders in this academy, with line items.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200` (`OrderResource`)
```json
{
  "data": [
    {
      "uuid": "a1b2...",
      "status": "paid",
      "total_minor": 20000,
      "currency": "EGP",
      "items": [
        { "type": "course", "title": "Physics — Grade 12", "price_minor": 20000 }
      ]
    }
  ]
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

### Teacher · Activity

Teacher view of a student's learning activity + direct messaging.

---

#### `GET /v1/teacher/students/{student:uuid}/progress`
**Purpose:** Per-lesson progress for the student (includes last playback position).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": [
    {
      "lesson_id": 501,
      "lesson_title": "Algebra · Unit 1",
      "watch_percent": 100,
      "last_position_sec": 640,
      "completed": true
    }
  ]
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `GET /v1/teacher/students/{student:uuid}/activity`
**Purpose:** A merged, most-recent-first activity timeline (up to 100 events): logins, playback sessions, orders, exam attempts.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200` (each event has `type`, `at`, and a `type`-specific `meta`)
```json
{
  "data": [
    { "type": "login", "at": "2026-06-10T08:00:00+00:00", "meta": { "success": true, "ip": "197.0.0.1" } },
    { "type": "playback", "at": "2026-06-10T08:05:00+00:00", "meta": { "lesson_id": 501, "ip": "197.0.0.1", "device": "fp_ab12" } },
    { "type": "order", "at": "2026-06-09T12:00:00+00:00", "meta": { "uuid": "a1b2...", "status": "paid", "total_minor": 20000 } },
    { "type": "exam_attempt", "at": "2026-06-08T15:00:00+00:00", "meta": { "exam_id": 7, "status": "graded", "score": 18 } }
  ]
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/notify`
**Purpose:** Send the student an in-app notification (`teacher.message`).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body**
```json
{ "title": "Reminder", "message": "Your next live session is tomorrow at 6 PM." }
```

| Field | Rules |
|---|---|
| `message` | required, string, max 1000 |
| `title` | nullable, string, max 255 |

**Response** `201`
```json
{ "data": { "message": "Notification sent." } }
```

**Errors:** `422` validation; `404` not a student here; `401`/`403`.

---

### Teacher · Parents

Manage the parents linked to one of the teacher's students (M13). Linking provisions a `parent` membership (via `firstOrCreate`) so the guardian can log in and follow their child. Operates on the membership + link, never the global identity.

---

#### `GET /v1/teacher/students/{student:uuid}/parents`
**Purpose:** List the parents linked to the student.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body:** None

**Response** `200`
```json
{
  "data": [
    {
      "uuid": "7c3d...",
      "name": "Kamal Sami",
      "phone": "+201004445556",
      "email": "parent@example.com",
      "relation": "father"
    }
  ]
}
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/parents`
**Purpose:** Link a parent to the student. Reuses an existing user by phone if present; otherwise creates one with the **password the teacher supplies** (no random password is generated). Provisions a `parent` membership and the link.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Content-Type | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |

**Request body**
```json
{
  "name": "Kamal Sami",
  "phone": "+201004445556",
  "email": "parent@example.com",
  "relation": "father",
  "password": "ParentPass1"
}
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `phone` | required, string, max 20, regex `^[0-9+]{6,20}$` |
| `email` | nullable, email, max 255 |
| `relation` | nullable, `father` \| `mother` \| `guardian` |
| `password` | **required** when creating a new parent (min 8); optional/ignored when the `phone` already belongs to a user (that account is just linked) |

**Response** `201` (nulls stripped; no `temporary_password` is ever returned — the teacher already knows the password they set)
```json
{
  "data": {
    "uuid": "7c3d...",
    "name": "Kamal Sami",
    "phone": "+201004445556",
    "relation": "father"
  }
}
```

**Errors:** `422` `phone` (`This parent is already linked to the student.`); `422` validation; `404` not a student here; `401`/`403`.

---

#### `DELETE /v1/teacher/students/{student:uuid}/parents/{parent:uuid}`
**Purpose:** Unlink a parent from the student (removes the `ParentLink` rows; the parent's membership/identity is left intact).
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.io` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <teacher token>` |

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |
| `parent` | path | Parent **uuid** |

**Request body:** None

**Response** `200` (note: JSON body, not `204`)
```json
{ "data": { "unlinked": true } }
```

**Errors:** `404` not a student here; `401`/`403`.

---

#### `POST /v1/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link`
**Purpose:** Issue (rotating) a permanent passwordless magic link for a linked guardian (VD R11). Any prior link for that guardian is deactivated. The **raw** token is returned **once** — only its SHA-256 hash is stored. Scoped to the `(student, parent)` link. Audit: `parent.magic_link_issued`.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Path params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |
| `parent` | path | Parent **uuid** (must be linked to the student) |

**Request body:** None

**Response** `201`
```json
{ "data": { "uuid": "<parent uuid>", "magic_token": "a1b2…(48 hex)", "magic_path": "/parent/magic/a1b2…" } }
```

**Errors:** `404` if the parent isn't linked to this student (or the student isn't here); `401`/`403`.

---

#### `DELETE /v1/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link`
**Purpose:** Revoke **every** magic link for a linked guardian (`is_active=false`). Audit: `parent.magic_link_revoked`.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `students`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:students`

**Path params**
| Param | In | Description |
|---|---|---|
| `student` | path | Student **uuid** |
| `parent` | path | Parent **uuid** (must be linked to the student) |

**Request body:** None

**Response** `200`
```json
{ "data": { "revoked": true } }
```

**Errors:** `404` if the parent isn't linked to this student; `401`/`403`.

---

### Teacher · Assistants

Assistant management + granular RBAC (M18). The teacher creates assistants and grants each a subset of the permission catalog; those permissions gate the shared teacher surface (see the **Granular permissions** convention and the `permission:students` / `permission:centers` gates). Like students, assistants are **global users** with an `assistant` **membership** in this academy — the teacher acts on the membership + identity link, never rewriting a foreign global user. **Assistant management itself is `role:teacher` only** (an assistant cannot manage assistants). All actions are audit-logged (`assistant.created` / `assistant.updated` / `assistant.removed`).

---

#### `GET /v1/teacher/permissions`
**Purpose:** The grantable-permission catalog for the assistant editor UI.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request body:** None

**Response** `200`
```json
{
  "data": [
    { "key": "students", "label": "Students", "description": "Add, edit and remove students; manage enrollments, wallet and activity." },
    { "key": "centers", "label": "Centers & codes", "description": "Manage centers, attendance, and activation/recharge codes." }
  ]
}
```

**Errors:** `401`; `403` not a teacher of this tenant.

---

#### `GET /v1/teacher/assistants`
**Purpose:** Paginated list of the academy's assistants, with search and status filter, plus each one's effective permissions.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `q` | query | Search matched against user `name`, `phone`, `email` |
| `filter[status]` | query | Filter by membership status (`active` \| `suspended`) |
| `page` | query | Page number (30 per page) |

**Response** `200` (`AssistantResource` collection, paginated → includes `meta`/`links`)
```json
{
  "data": [
    {
      "uuid": "b7d2...",
      "name": "Omar Adel",
      "phone": "+201115556677",
      "email": "omar@example.com",
      "status": "active",
      "permissions": ["students"],
      "joined_at": "2026-07-01T09:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 30, "total": 3, "last_page": 1 }
}
```

`uuid` is the assistant's global-user uuid; `permissions` is the effective granted subset.

**Errors:** `401`; `403` not a teacher.

---

#### `POST /v1/teacher/assistants`
**Purpose:** Add an assistant to this academy. Reuses an existing global identity by phone if one exists (links, never modifies it); otherwise creates the user with a temporary password. Enforces the plan's `max_assistants` limit (M03).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request body**
```json
{
  "name": "Omar Adel",
  "phone": "+201115556677",
  "email": "omar@example.com",
  "password": "TempPass123",
  "permissions": ["students", "centers"]
}
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `phone` | required, string, max 20, regex `^[0-9+]{6,20}$` |
| `email` | nullable, email, max 255 |
| `password` | nullable, string, min 8 (if omitted a temp password is generated and returned once) |
| `permissions` | optional array; each value must be a catalog key (`students`, `centers`) — unknown values rejected |

**Response** `201` (nulls stripped; `temporary_password` present only when the server generated it)
```json
{
  "data": {
    "uuid": "b7d2...",
    "name": "Omar Adel",
    "phone": "+201115556677",
    "email": "omar@example.com",
    "status": "active",
    "permissions": ["students", "centers"],
    "temporary_password": "aB3xK9mQ2p"
  }
}
```

**Errors:** `422` `phone` (`This person is already a member of your academy.`) when the phone already belongs to a member of this tenant; `403 plan_limit_reached` (`details: { key: "max_assistants", limit, used }`) when the plan's assistant quota is hit; `422` validation; `401`/`403`.

---

#### `GET /v1/teacher/assistants/{assistant:uuid}`
**Purpose:** One assistant's membership + identity + effective permissions.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `assistant` | path | Assistant (user) **uuid** |

**Response** `200` — a single `AssistantResource` (shape as in the list).

**Errors:** `404` not an assistant of this academy; `401`/`403`.

---

#### `PATCH /v1/teacher/assistants/{assistant:uuid}`
**Purpose:** Edit identity (name/email), re-scope permissions, and/or change membership status (activate/suspend). All fields optional — patch only what's sent. Suspending revokes the assistant's tokens (immediate sign-out).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `assistant` | path | Assistant (user) **uuid** |

**Request body**
```json
{
  "name": "Omar Adel Hassan",
  "status": "active",
  "permissions": ["students"]
}
```

| Field | Rules |
|---|---|
| `name` | sometimes, string, max 255 |
| `email` | sometimes, nullable, email, max 255 |
| `status` | sometimes, `active` \| `suspended` |
| `permissions` | sometimes, array; each value a catalog key (`students`, `centers`) |

**Response** `200` — the updated `AssistantResource`.

**Errors:** `422` validation; `404` not an assistant here; `401`/`403`.

---

#### `DELETE /v1/teacher/assistants/{assistant:uuid}`
**Purpose:** Remove the assistant from this academy: delete the membership and revoke their tokens (the global identity is untouched).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path / Query params**
| Param | In | Description |
|---|---|---|
| `assistant` | path | Assistant (user) **uuid** |

**Request body:** None

**Response** `204 No Content`

**Errors:** `404` not an assistant here; `401`/`403`.
