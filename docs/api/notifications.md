# Notifications Module

> The Notifications module owns the platform's notification system: the **engine** (M10 / doc 10 — a type/template/translation/dispatch model), the **custom notifications** a human writes and sends, and the outbound SMS abstraction.
>
> **Two kinds of notification.** *Automatic* ones fire from a business event, render admin-authored copy a teacher can reword per academy, and are catalogued as `NotificationType`s. *Custom* ones carry their own bilingual copy, are aimed at an audience the sender picks (everyone, a lesson, a package, a grade, a center, hand-picked students, the assistants — or, for the platform admin, every teacher), and are stored as `NotificationBroadcast`s. Both land in the SAME inbox and the same audit trail.
>
> **One inbox.** `/me/inbox` (`new_notifications`) is THE student inbox, with an unread counter. The old simple feed (`notifications`, `/me/notifications`) still answers for rows written before the change, but **nothing writes to it any more** — every producer dispatches through `NotificationEngineService` (or, for a human-written message, `BroadcastService`).
>
> **Channels.** `database` (in-app), `sms` and `email` have real dispatchers; `push` is still a stub. A channel being *implemented* is separate from it being *available to an academy* — see `ChannelAvailability`.
>
> **SMS is per-tenant and self-service (WE Business SMS / Connekio).** There is no platform-wide aggregator account — each academy stores **its own** WE credentials via `PUT /teacher/sms-settings` and turns SMS on. Until it does, SMS is simply **off** for that academy: the engine skips the channel entirely rather than attempting a send and recording one failure per recipient. Credentials are encrypted at rest; the driver activates when the deploy sets `SMS_DRIVER=connekio`.

The legacy-feed and SMS-settings endpoints run inside the `tenant` middleware group, so the tenant is resolved from the `Host` header (dev override `X-Tenant: <slug>`) before any query, and the feed is naturally scoped to the current academy via `BelongsToTenant`. The engine surface (admin catalog + teacher overrides + student inbox) is enumerated in [Notification engine surface](#notification-engine-surface) below.

## Models

- **`NotificationBroadcast`** (`notification_broadcasts`) — One custom (human-written) notification: its audience (`audience_type` + `audience_ids`), chosen `channels`, bilingual copy (`title_ar`/`body_ar`/`title_en`/`body_en`), `status` (`scheduled` → `sending` → `sent`/`failed`/`canceled`), optional `scheduled_at`, the estimate the sender CONFIRMED (`recipient_count`, `sms_recipient_count`, `sms_segments`, `sms_cost_minor`), and the engine's `stats` afterwards. `tenant_id` is NULL for a platform-admin message to every teacher; deliberately NOT `BelongsToTenant` (a forced tenant scope would hide those rows), so callers scope with `forTenant()`.
- **`Notification`** (`notifications`) — DEPRECATED, read-only. A per-user notification row. Tenant-scoped (`BelongsToTenant`: auto-fills `tenant_id`, filters every query). Fillable: `user_id`, `channel` (`in_app` is the only channel surfaced by the API), `type`, `template_id`, `payload` (JSON, cast to array), `status`, `sent_at` (datetime), `read_at` (datetime, `null` = unread). No `read` column exists — read state is derived from `read_at`.

## Services / Support

- **`NotificationEngineService`** — The one entry point for an AUTOMATIC notification: `dispatch(notificationKey:, tenantId:, recipientUserIds:, renderVariables:, …)`. Resolves the type, gates on `status = ready`, records the audit event, resolves the effective template per channel, skips channels the academy cannot deliver on, renders **per recipient language** (`users.locale`, tenant locale as fallback), honours opt-outs, and records a `NotificationFailure` per failed recipient. `options` takes `channels` (restrict), `channel_var_blacklist` (e.g. keep `{otp}` out of the in-app copy) and `ignore_preferences`.
- **`ChannelAvailability`** — Answers "can this academy deliver on this channel right now?". `database`/`email` are on unless switched off; `sms` is off until complete credentials are stored AND enabled; `push` is always off. This is what makes an unconfigured academy silently SMS-free instead of failure-ridden.
- **`BroadcastService`** — Custom notifications: `preview()` (reach + SMS cost, pure read), `create()` (persist with the confirmed estimate) and `send()` (claim `scheduled` → `sending` so a retry cannot double-send, then deliver). Skips the opt-out check by design, and fans a platform message out **per academy** so every delivery row has the tenant whose inbox it belongs to.
- **`AudienceResolver`** — Turns an audience into user ids, filtering by academy so a foreign id resolves to nobody. Accepts numeric ids or uuids.
- **`SmsCostEstimator`** — GSM-7 (160/153) vs UCS-2 (70/67) segmentation and the per-segment price (academy override, else `config('sms.price_per_segment_minor')`).
- **`TemplatedSmsNotifier`** — Renders a catalog notification to a bare PHONE NUMBER, for the two recipients the engine cannot address: a guardian recorded only as `guardian_phone`, and an OTP sent before any session exists.
- **`NotificationService`** — DEPRECATED (nothing writes through it). Created legacy in-app notifications: `inApp(int $tenantId, int $userId, string $type, array $payload = [])`. Takes an explicit tenant id so it can run from webhook/queue contexts where no tenant is bound. Sets `channel=in_app`, `status=sent`, `sent_at=now()`.
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
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
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
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
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

## Notification engine surface

The engine's routes (doc 10). This section **enumerates** the surface — the full
per-endpoint request/response contract lives in `docs (1)/10_Notification_Engine_Mapping.md`
and `docs (1)/NotificationEngine.md`. Types bind by **`key`** (dotted `module.entity.event`,
e.g. `support.ticket.created`); templates are addressed by `{type}/{channel}`.

### Admin · type & template catalog (system scope)
Served on the **central/admin host** (`central` + `auth:sanctum` + `admin`) — *not* tenant-scoped. Authors the system catalog + audits dispatched events.

| Method | Path | Purpose |
|---|---|---|
| `GET` / `POST` | `/admin/notifications/types` | List / create notification types |
| `GET` / `PUT` / `DELETE` | `/admin/notifications/types/{type:key}` | Show / update / delete a type |
| `GET` / `POST` | `/admin/notifications/types/{type:key}/templates` | List / create a type's channel templates |
| `PUT` | `/admin/notifications/types/{type:key}/templates/{channel}/translations` | Upsert a template's per-language copy |
| `DELETE` | `/admin/notifications/types/{type:key}/templates/{channel}/translations/{language}` | Remove one language |
| `GET` | `/admin/notifications/events` · `/admin/notifications/events/{event}` | Audit dispatched events |

### Teacher · tenant overrides (copy-on-write)
`tenant` + `auth:sanctum` + `active` + `role:teacher`. A teacher overrides a `ready` **system** template for their academy; the first edit materialises a copy-on-write tenant template. Teachers can't author types from scratch.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/teacher/notifications` | List overridable types + this tenant's override state |
| `GET` | `/teacher/notifications/{type:key}` | Show one type's effective (system/override) config |
| `PUT` | `/teacher/notifications/{type:key}/channels` | Toggle/override a channel for this tenant |
| `PUT` | `/teacher/notifications/{type:key}/channels/{channel}/translations` | Upsert the tenant's channel copy |
| `DELETE` | `/teacher/notifications/{type:key}/channels/{channel}` | Reset a channel back to the system default |

### Teacher · custom notifications
`tenant` + `auth:sanctum` + `active` + `can:settings.notifications.send`. A separate permission from `settings.notifications.manage`, and one an assistant holds only if the teacher granted it — sending spends the academy's SMS credit and reaches muted students.

Path is `custom-notifications`, **not** `notifications/custom`: `/teacher/notifications/{type:key}` above would otherwise swallow the segment.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/teacher/custom-notifications` | This academy's sent / scheduled / canceled messages |
| `GET` | `/teacher/custom-notifications/channels` | Channels the academy can deliver on right now |
| `POST` | `/teacher/custom-notifications/preview` | **The cost gate:** recipients, per-channel reach, SMS segments + price |
| `POST` | `/teacher/custom-notifications` | Send now (queued) or schedule for `scheduled_at` |
| `GET` | `/teacher/custom-notifications/{broadcast}` | One message with its estimate and delivery stats |
| `POST` | `/teacher/custom-notifications/{broadcast}/cancel` | Call off a message that has not gone out |

**Audiences** (`audience_type`, with `audience_ids` where noted): `all_students`, `academic_year` (ids), `package` (ids), `lesson` (ids), `center` (ids), `students` (user ids), `assistants`. Ids may be numeric or uuids. A past `scheduled_at` is rejected (422) rather than fired immediately, and at least one language needs BOTH a title and a body.

### Admin · custom notifications (platform scope)
`central` + `auth:sanctum` + `admin`. Admin → every academy owner, on **in-app + email only**: the platform has no SMS account of its own.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/admin/notifications/custom` | Platform messages (`tenant_id` NULL) |
| `POST` | `/admin/notifications/custom/preview` | Reach before sending |
| `POST` | `/admin/notifications/custom` | Send / schedule |
| `GET` | `/admin/notifications/custom/{broadcast}` | One platform message |

### Student · engine inbox (`database` channel)
`tenant` + `auth:sanctum` + `active`. THE inbox — every automatic and custom in-app message (`new_notifications`). The legacy `/me/notifications` feed above is read-only history.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/me/inbox` | List engine in-app messages |
| `GET` | `/me/inbox/unread-count` | Unread badge count |
| `POST` | `/me/inbox/read-all` | Mark all read |
| `POST` | `/me/inbox/{message}/read` | Mark one read |

---

### `GET /teacher/sms-settings`

**Purpose:** Read the current academy's WE Business SMS (Connekio) configuration. The password is **never** returned — a `has_password` boolean tells the UI whether one is stored. Safe to call on a never-configured tenant (returns defaults, `enabled: false`).

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
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
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
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

---

## Automatic notification catalog — and what fires each one

Seeded by `NotificationCatalogSeeder` (global reference data, safe in production,
idempotent on the type `key`). A type only dispatches while its `status` is
`ready`; parking one is a status change, not a code change.

| Key | To | Channels | Fired by |
|---|---|---|---|
| `lessons.lesson.available` | grade | in-app | `LessonAnnouncer` — lesson created/edited visible, or `notifications:announce-lessons` when a scheduled `publish_at` passes. Announced **once** per lesson. |
| `lessons.extension.requested` | staff with `content.extension_requests.review` | in-app | `LessonAvailabilityService::requestExtension` |
| `lessons.extension.approved` | student | in-app | `LessonAvailabilityService::decide` (grant only) |
| `exams.exam.published` | lesson holders, else the grade | in-app | `ExamController::update` when `is_published` flips false → true |
| `exams.attempt.graded` | student | in-app | `ExamGradingController::grade` once the attempt is fully graded (auto-graded attempts show their result on the submit screen, so they are not announced) |
| `exams.extension.requested` / `.approved` | staff with `exams.extensions.review` / student | in-app | `ExamTimeExtensionService` |
| `payments.order.completed` | student | in-app | `FulfillOrderService` |
| `payments.order.refunded` | student | in-app | `RefundService` |
| `payments.receipt.uploaded` | staff with `finance.receipts.review` | in-app | `PaymentReceiptService::submit` |
| `payments.receipt.approved` / `.rejected` | student | in-app + SMS | `PaymentReceiptService` (dispatched AFTER the money transaction commits) |
| `account.welcome` | student | in-app | `VerifyOtpAction` on registration |
| `account.otp.requested` | the identifier | SMS | `SendOtpJob` via `TemplatedSmsNotifier` — the academy can reword the code message |
| `center.attendance.absent` | student + parent | in-app + SMS | `AbsenceNotifier` (linked parent users are recipients; a bare `guardian_phone` is texted) |
| `center.exam_grade.published` | student | in-app + SMS | `CenterExamGradeController::store` |
| `center.activation_code.redeemed` | student | in-app | `CodeRedemptionService` (after the transaction commits) |
| `billing.subscription.expiring` / `.expired` | academy owners | in-app + SMS | `notifications:subscription-reminders`, daily, once per subscription per day |
| `billing.plan_limit.reached` | academy owners | in-app | `PlanLimitGuard::ensure`, rate-limited to once per limit per day |
| `domains.custom_domain.verified` | academy owners | in-app | `TenantDomainObserver` — the save that first fills `verified_at` |
| `support.ticket.created` / `.replied` | staff with `support.view` / ticket owner | in-app | `Engagement` ticket controllers |
| `qa.answer.posted` | the asker | in-app | `CommentController::reply` (staff replies only, never the asker's own follow-up) |
| `custom.message` | — | — | Not an automatic notification: the audit type every **custom** message is filed under. Hidden from the teacher/admin override screens. |
| `packages.package.purchased` | — | — | Parked at `planning`: a purchase is announced once as `payments.order.completed`. |

### Scheduled work

| Command | Cadence | Why it cannot be request-driven |
|---|---|---|
| `notifications:send-scheduled` | every minute | Releases custom messages whose time has come |
| `notifications:announce-lessons` | every 15 minutes | Nothing writes a row when a scheduled `publish_at` passes |
| `notifications:subscription-reminders` | daily 09:00 | Expiry is the passage of time, not an action |

### Deploying this

`php artisan migrate` (adds `notification_broadcasts`), then
`php artisan db:seed --class=NotificationCatalogSeeder` for the new types, then
**`php artisan rbac:sync`** — `settings.notifications.send` is a new permission,
and each academy's owner role is re-derived from the catalog by that command.
The scheduler (`schedule:run`) and a queue worker must both be running: custom
messages are delivered by `SendBroadcastJob`, never inside the request.
