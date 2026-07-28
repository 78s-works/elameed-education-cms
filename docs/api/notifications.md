# Notifications Module

> The Notifications module owns the platform's per-user, in-app notification feed and the outbound SMS abstraction. Notifications are tenant-scoped rows created server-side on key events (e.g. a completed purchase — FR-M14) via `NotificationService`; each carries a `type`, a free-form JSON `payload`, and a `read_at` marker. The public API surface is small: the current user lists their own in-app items and marks one read.
>
> **SMS is per-tenant and self-service (WE Business SMS / Connekio).** There is no platform-wide aggregator account — each academy stores **its own** WE credentials (`username`, `password`, `account_id`, `sender`) via `PUT /teacher/sms-settings` and turns SMS on. Until a tenant does that, SMS is simply off for that tenant (OTP falls back to the log driver, notification SMS records a failure) with no effect on any other tenant. Credentials are stored encrypted at rest; the driver activates when the deploy sets `SMS_DRIVER=connekio`. Delivery reports (DLR), batch send, and balance are deferred.

Both endpoints run inside the `tenant` middleware group, so the tenant is resolved from the `Host` header (dev override `X-Tenant: <slug>`) before any query, and the feed is naturally scoped to the current academy via `BelongsToTenant`.

## Models

- **`Notification`** (`notifications`) — A per-user notification row. Tenant-scoped (`BelongsToTenant`: auto-fills `tenant_id`, filters every query). Fillable: `user_id`, `channel` (`in_app` is the only channel surfaced by the API), `type`, `template_id`, `payload` (JSON, cast to array), `status`, `sent_at` (datetime), `read_at` (datetime, `null` = unread). No `read` column exists — read state is derived from `read_at`.

## Services / Support

- **`NotificationService`** — Creates in-app notifications: `inApp(int $tenantId, int $userId, string $type, array $payload = [])`. Takes an explicit tenant id so it can run from webhook/queue contexts where no tenant is bound. Sets `channel=in_app`, `status=sent`, `sent_at=now()`.
- **`SmsSender`** (Contract) — `send(string $to, string $message): void`. Implementation is swapped by the `sms.driver` config so business logic never depends on a specific aggregator. The signature carries no sender/tenant — a per-tenant driver resolves those internally, so `SmsChannel` and `SendOtpJob` are agnostic.
- **`LogSmsSender`** (Sms) — Dev/default driver bound by `NotificationsServiceProvider`; writes `[SMS]` lines to the log instead of sending. Any unknown `sms.driver` value also falls back to this driver.
- **`ConnekioSmsSender`** (Sms) — Production WE Business SMS driver (`SMS_DRIVER=connekio`). Resolves the **current tenant's** active `sms` row from `notification_channel_settings`, then `POST {base_url}/sms/single` with `Authorization: Basic base64(username:password:account_id)`. Throws if the tenant has no active/complete config, on a non-2xx, or on a `status:false` body — the notification engine turns that into a `NotificationFailure`.
- **`Msisdn`** (Sms) — Normalizes a recipient to WE format (`201XXXXXXXXX`): strips `+`/`00`/spaces and rewrites an Egypt local `01XXXXXXXXX` (trunk `0`) to the `20` country code.
- **`NotificationChannelSetting`** (`notification_channel_settings`) — Per-tenant, tenant-scoped (`BelongsToTenant`). One row per `channel`; for `sms` the encrypted `config` holds `{provider, sender, username, password, account_id, base_url}` and `is_active` is the tenant's SMS on/off switch. `config` is cast `encrypted:array`, so secrets never sit in plaintext.

---

## Endpoints

### `GET /v1/me/notifications`

**Purpose:** List the authenticated user's own in-app notifications in the current tenant, newest first, paginated 30 per page. Only `channel = in_app` rows are returned.

**Auth:** 👤 Authenticated (any active member)
**Middleware:** `tenant` group → `auth:sanctum` → `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `page` | query | no | Page number (default 1). Page size fixed at 30. |

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "id": 5012,
      "type": "purchase.completed",
      "payload": {
        "order_id": 3391,
        "course_title": "الفيزياء - الصف الثالث الثانوي",
        "amount_minor": 15000,
        "currency": "EGP"
      },
      "read": false,
      "created_at": "2026-07-15T09:41:22+00:00"
    },
    {
      "id": 4980,
      "type": "exam.graded",
      "payload": {},
      "read": true,
      "created_at": "2026-07-14T18:03:10+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 30,
    "from": 1,
    "to": 30,
    "total": 112
  }
}
```

Notes: `payload` is an arbitrary JSON object whose shape depends on `type`; it is `{}` (empty object) when the notification was created without a payload. `read` is a derived boolean (`read_at !== null`) — the timestamp itself is not exposed. `created_at` is ISO-8601 UTC.

**Errors:**
- `401 unauthenticated` — missing/invalid bearer token.
- `403` — token holder is not an active member of the resolved tenant (`active` middleware).
- `403 / 404` — unregistered or non-active host (domain gate on the `tenant` group).

---

### `POST /v1/me/notifications/{notification}/read`

**Purpose:** Mark a single notification as read (idempotent). Sets `read_at` to now on first call; a no-op if already read. Returns a tiny confirmation body, not the notification.

**Auth:** 👤 Authenticated (any active member)
**Middleware:** `tenant` group → `auth:sanctum` → `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `notification` | path | yes | Notification primary key (`id`) — the numeric id from the list. |

**Request body:** None

**Response 200**

```json
{
  "data": {
    "read": true
  }
}
```

**Errors:**
- `404` — the notification does not exist, belongs to another user, or belongs to another tenant (ownership is asserted with `abort_unless($notification->user_id === $userId, 404)`; cross-tenant rows are already invisible via `BelongsToTenant`).
- `401 unauthenticated` — missing/invalid bearer token.
- `403` — not an active member of the resolved tenant.

---

### `GET /teacher/sms-settings`

**Purpose:** Read the current academy's WE Business SMS (Connekio) configuration. The password is **never** returned — a `has_password` boolean tells the UI whether one is stored. Safe to call on a never-configured tenant (returns defaults, `enabled: false`).

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Request body:** None

**Response 200**

```json
{
  "data": {
    "enabled": false,
    "sender": null,
    "username": null,
    "account_id": null,
    "base_url": "https://weapi.connekio.com",
    "has_password": false
  }
}
```

Notes: `base_url` falls back to the platform default (`config('sms.connekio.base_url')`) when the tenant has not overridden it. `password` is intentionally absent from every response.

**Errors:**
- `401 unauthenticated` — missing/invalid bearer token.
- `403 forbidden` — token holder is not a teacher in the resolved tenant.

---

### `PUT /teacher/sms-settings`

**Purpose:** Create or update the academy's own WE Business SMS credentials and toggle SMS on/off. `password` is write-only: send it to set/replace, **omit it to keep** the stored one (lets the teacher edit the sender or flip `enabled` without re-typing the secret). When `enabled` is `true`, the merged credential set (`sender` + `username` + `password` + `account_id`) must be complete, otherwise the request is rejected `422`.

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Request body**

| Field | Type | Required | Description |
|---|---|---|---|
| `enabled` | boolean | yes | Turn SMS on/off for this tenant. `true` requires a complete credential set. |
| `sender` | string (≤20) | conditional | Registered/approved WE sender name. Required (in-store or in-body) to enable. |
| `username` | string (≤255) | conditional | WE account username. Required to enable. |
| `password` | string (≤255) | conditional | WE account password. Write-only; omit to keep the stored one. Required the first time you enable. |
| `account_id` | string (≤64) | conditional | WE numeric account id. Required to enable. |
| `base_url` | url (≤255) | no | Override the gateway base URL. Defaults to `https://weapi.connekio.com`. |

**Example request**

```json
{
  "enabled": true,
  "sender": "Tammam",
  "username": "we-user",
  "password": "we-secret",
  "account_id": "987654321"
}
```

**Response 200:** Same shape as `GET /teacher/sms-settings` (reflecting the saved state; `has_password: true`).

**Errors:**
- `422 validation_error` — a field failed its rule (e.g. `enabled` missing, `base_url` not a URL, `sender` > 20 chars).
- `422` — `enabled: true` with an incomplete credential set: `{ "message": "Provide sender, username, password and account_id before enabling SMS." }`.
- `401 unauthenticated` — missing/invalid bearer token.
- `403 forbidden` — not a teacher in the resolved tenant.

**Frontend notes**
- Render `has_password` as a "password set" indicator; leave the password field empty on edit and only submit it when the user types a new one.
- The recipient's phone is normalized server-side to `201XXXXXXXXX`, so the UI may accept local `01XXXXXXXXX` numbers as-is.
- After enabling, no test-send endpoint exists yet — a failed real send surfaces via the notification engine's failure records, not this endpoint.
