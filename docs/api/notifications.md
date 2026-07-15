# Notifications Module

> The Notifications module owns the platform's per-user, in-app notification feed and the outbound SMS abstraction. Notifications are tenant-scoped rows created server-side on key events (e.g. a completed purchase — FR-M14) via `NotificationService`; each carries a `type`, a free-form JSON `payload`, and a `read_at` marker. The public API surface is small: the current user lists their own in-app items and marks one read. SMS/WhatsApp/email fan-out is stubbed for Phase 1 — the `SmsSender` contract is bound to a log-only driver so the OTP flow works before an aggregator account exists; templated multi-channel delivery is Phase 1.5.

Both endpoints run inside the `tenant` middleware group, so the tenant is resolved from the `Host` header (dev override `X-Tenant: <slug>`) before any query, and the feed is naturally scoped to the current academy via `BelongsToTenant`.

## Models

- **`Notification`** (`notifications`) — A per-user notification row. Tenant-scoped (`BelongsToTenant`: auto-fills `tenant_id`, filters every query). Fillable: `user_id`, `channel` (`in_app` is the only channel surfaced by the API), `type`, `template_id`, `payload` (JSON, cast to array), `status`, `sent_at` (datetime), `read_at` (datetime, `null` = unread). No `read` column exists — read state is derived from `read_at`.

## Services / Support

- **`NotificationService`** — Creates in-app notifications: `inApp(int $tenantId, int $userId, string $type, array $payload = [])`. Takes an explicit tenant id so it can run from webhook/queue contexts where no tenant is bound. Sets `channel=in_app`, `status=sent`, `sent_at=now()`.
- **`SmsSender`** (Contract) — `send(string $to, string $message): void`. Implementation is swapped by the `sms.driver` config so business logic never depends on a specific aggregator.
- **`LogSmsSender`** (Sms) — Dev/default driver bound by `NotificationsServiceProvider`; writes `[SMS]` lines to the log instead of sending. Any unknown `sms.driver` value also falls back to this driver in Phase 1.

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
