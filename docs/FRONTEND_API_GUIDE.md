# Elameed Education — Frontend API Integration Guide

**Audience:** Frontend developer (Vue 3 SPA).
**API version:** v1 · **Status:** Phase 1 backend complete.
**Base URL (dev, LAN):** `http://192.168.100.143:8000/api/v1`
**Base URL (prod):** `https://api.elameed.app/api/v1` (also reachable per-tenant host)

This is the human-readable contract. It reflects what is actually implemented today. External integrations (Paymob live, real video transcode/delivery, SMS delivery) are stubbed server-side but their **contracts are final** — your integration code won't change when they go live.

---

## 1. The three rules you must know first

### 1.1 Every tenant request needs a tenant
The platform is multi-tenant: each teacher academy is a "tenant". The API figures out *which* tenant from the request:

- **Production:** the **Host** (e.g. `ahmed.elameed.app` or a custom domain) → resolved automatically.
- **Development:** send an **`X-Tenant: <slug>`** header (host-based resolution needs real subdomains). This override works in local/staging only, never in production.

If a tenant-scoped endpoint gets no resolvable tenant → `404 { "error": { "code": "tenant_not_found" } }`.

> **A few endpoints are NOT tenant-scoped and must NOT get `X-Tenant`:** `/admin/*`, `/webhooks/*`, `/internal/*`, `/media/key/*`.

### 1.2 Auth is a Bearer token (Laravel Sanctum)
Login/verify returns a token. Send it on every authenticated request:
```
Authorization: Bearer <token>
```
There are no cookies/CSRF to manage — it's pure token auth.

### 1.3 Money is integer minor units
All amounts are **piastres** (1 EGP = 100). `price_minor: 15000` = **150.00 EGP**. Never send/expect decimals. Format for display on the client.

---

## 2. Response conventions

**Success** — a `data` envelope:
```json
{ "data": { "...": "..." } }
```
**Paginated list** — `data` + `links` + `meta`:
```json
{
  "data": [ { "...": "..." } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 42 }
}
```
Pagination query: `?page=2`.

**Error** — an `error` envelope (all failures use this shape):
```json
{ "error": { "code": "validation_error", "message": "…", "details": { "field": ["msg"] } } }
```
`details` is present only for validation errors (422).

**Status codes:** `200` ok · `201` created · `202` accepted (OTP sent) · `204` no content · `400` bad request (e.g. bad webhook signature) · `401` unauthenticated · `403` forbidden (role/tenant/membership) · `404` not found · `422` validation · `429` rate-limited · `5xx` server.

**Common error codes:** `validation_error`, `unauthenticated`, `forbidden`, `not_found`, `too_many_requests`, `tenant_not_found`, `server_error`.

**Locale:** Arabic is default (RTL). `/tenant/context` returns the supported locales. Send `locale` on register if you want to pin it.

---

## 3. Axios setup (Vue)

```js
import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE, // http://192.168.100.143:8000/api/v1
  headers: {
    Accept: 'application/json',
    'X-Tenant': import.meta.env.VITE_TENANT ?? 'demo', // dev only; prod uses the host
  },
})

// attach the token after login
export function setToken(token) {
  if (token) api.defaults.headers.common.Authorization = `Bearer ${token}`
  else delete api.defaults.headers.common.Authorization
}

// normalise errors → error.response.data.error.{code,message,details}
api.interceptors.response.use(
  (r) => r,
  (e) => Promise.reject(e.response?.data?.error ?? { code: 'network', message: e.message }),
)
```

`.env` on the Vue side:
```
VITE_API_BASE=http://192.168.100.143:8000/api/v1
VITE_TENANT=demo
```

> CORS is already configured server-side to allow `http://192.168.100.23:5173` (+ localhost). If you add another origin, tell backend to add it to `CORS_ALLOWED_ORIGINS`.

---

## 4. Auth flows

### 4.1 Student registration (OTP)
```
POST /auth/register            → 202  (sends OTP to phone)
POST /auth/otp/verify          → 200  { data: { token, user } }
```
```js
// 1) register (creates a PENDING student membership + sends OTP)
await api.post('/auth/register', { name: 'Sara', phone: '01000000001', password: 'secret123' })
// → 202 { data: { message, identifier: '01000000001', requires_otp: true } }

// 2) user enters the code they received → verify → token
const { data } = await api.post('/auth/otp/verify', {
  identifier: '01000000001', purpose: 'register', code: '123456',
})
setToken(data.data.token) // membership is now ACTIVE, phone verified
```
Resend a code: `POST /auth/otp/request { identifier, purpose: 'register' }`.

### 4.2 Login
```js
const { data } = await api.post('/auth/login', { identifier: '01000000000', password: 'password' })
if (data.data.otp_required) {
  // login-OTP is enabled → verify with purpose 'login'
} else {
  setToken(data.data.token)
}
```
`identifier` = phone **or** email. Wrong credentials → `401 unauthenticated` (identical whether the user exists or not — no enumeration).
Not a member of this tenant → `403 forbidden`.

### 4.3 Password reset (OTP)
```
POST /auth/password/forgot { identifier }              → 200 (generic)
POST /auth/password/reset  { identifier, code, password } → 200
```

### 4.4 Session
```
GET  /me            → current user + memberships + role in this tenant
POST /auth/logout   → revokes the current token
```

`GET /me` shape:
```json
{ "data": {
  "uuid": "…", "name": "…", "email": null, "phone": "01…", "locale": "ar",
  "email_verified": false, "phone_verified": true, "is_platform_admin": false,
  "memberships": [ { "tenant": "demo", "tenant_name": "Demo Academy", "role": "student", "status": "active" } ],
  "current": { "tenant": "demo", "role": "student", "permissions": [] }
} }
```

### 4.5 Rate limits (handle `429`)
- OTP send / register / forgot / reset: **5/min per identifier** (+15/min per IP).
- Login / OTP verify: **10/min per IP**.
Show a "try again shortly" message on `429 too_many_requests`.

---

## 5. Public storefront (no auth)

| Method | Path | Purpose |
|---|---|---|
| GET | `/tenant/context` | Branding/theme/locale to boot the SPA. Call on app start. |
| GET | `/courses` | Published course catalogue (paginated). |
| GET | `/courses/{slug}` | Course detail + units/lessons outline. |

`GET /tenant/context`:
```json
{ "data": {
  "uuid": "…", "slug": "demo", "name": "Demo Academy", "status": "active",
  "branding": {
    "logo_url": null, "cover_url": null,
    "primary_color": "#1D4ED8", "secondary_color": "#9333EA",
    "bio": "…", "socials": { "youtube": "…" },
    "landing_sections": ["courses", "about"]   // visible sections, in order
  },
  "locale": { "default": "ar", "supported": ["ar", "en"] },
  "features": []
} }
```
Use `branding.primary_color`/`logo_url` to theme; render `landing_sections` in the given order.

`GET /courses` filters: `?filter[category_id]=`, `?filter[grade]=`, `?filter[subject]=`, `?q=<search>`, `?page=`.

`GET /courses/{slug}` returns the course + its published units → lessons (title, duration, `is_free_preview`, `has_video`). Locked lessons are shown but playback requires enrollment (see §7).

---

## 6. Buying a course (student)

Flow: **quote → order → pay**. Two payment methods: `wallet` (instant) and `paymob` (card/kiosk, redirect).

```js
const cart = { items: [{ type: 'course', course: courseUuid }] }

// 1) price it (server-side pricing; never trust the client)
const quote = (await api.post('/checkout/quote', cart)).data.data
// → { total_minor: 15000, currency: 'EGP', lines: [...] }

// 2) create the order
const order = (await api.post('/checkout/order', cart)).data.data
// → 201 { uuid, status: 'pending', total_minor, currency, items:[...] }

// 3a) pay from wallet (instant)
const paid = (await api.post('/checkout/pay', { order: order.uuid, method: 'wallet' })).data.data
// → { status: 'paid', order } → student is now enrolled + invoice issued

// 3b) pay by card (Paymob)
const pending = (await api.post('/checkout/pay', { order: order.uuid, method: 'paymob' })).data.data
// → { status: 'pending', order, redirect_url }
// redirect the user to redirect_url; the gateway calls our webhook on success.
// Poll GET /me/courses (or your order view) to detect enrollment.
```

**Wallet top-up** is a cart item too: `{ items: [{ type: 'wallet_topup', amount_minor: 20000 }] }` then `pay` with `method: 'paymob'`.

Wallet:
```
GET /wallet         → { data: { balance_minor, currency, recent: [ ledger entries ] } }
GET /wallet/ledger  → paginated ledger (credits/debits)
```
Insufficient balance on a wallet purchase → `422` with `error.details.wallet`.

---

## 7. Protected video playback (student)

Videos are self-hosted + encrypted. You never get a plain video URL — you get a **short-lived token + manifest URL + key URL**. Flow:

```js
// 1) authorize playback for a lesson (must be enrolled OR the lesson is a free preview)
const pb = (await api.post(`/media/lessons/${lessonId}/playback`, {
  device_fingerprint: myFingerprint, // optional
})).data.data
// → { token, manifest_url, key_url, expires_at }

// 2) feed manifest_url to hls.js; it will fetch the AES key from key_url.
//    Tokens are short-lived (≈2 min) and NOT shareable.
```
- Not enrolled / access expired → `403`.
- Lesson has no ready video yet → `409`.
- `key_url` (`GET /media/key/{token}`) is called by the player, not you; it re-checks access and returns `{ data: { key } }`.

> Playback authorization is the security-critical path: a token only works while the enrollment is active and in-window, and the AES key is re-validated at fetch time.

---

## 8. Student dashboard

| Method | Path | Returns |
|---|---|---|
| GET | `/me/courses` | Enrolled courses + progress: `{ uuid, title, slug, cover_url, lessons_total, lessons_completed, progress_percent }` |
| GET | `/me/activity` | Recent watched lessons: `{ lesson_id, lesson_title, watch_percent, last_position_sec, completed }` |
| POST | `/lessons/{lesson}/progress` | Report watch progress (see below) |
| GET | `/me/notifications` | Paginated in-app notifications |
| POST | `/me/notifications/{id}/read` | Mark one read |

Report progress (call periodically while watching):
```js
await api.post(`/lessons/${lessonId}/progress`, {
  watch_percent: 42,        // 0..100 (server keeps the max)
  watch_seconds: 310,       // optional
  last_position_sec: 305,   // optional, for resume
})
// → { data: { watch_percent, last_position_sec, completed } }  (completed at ≥95%)
```
Requires access to the lesson (enrollment or free preview), else `403`.

Notification shape: `{ id, type, payload, read, created_at }` (e.g. `type: "purchase.completed"`, `"account.welcome"`).

---

## 9. Teacher endpoints (role: teacher)

All require `Authorization: Bearer` **and** the user must be an active `teacher` in the current tenant (else `403 forbidden`). Courses are addressed by **uuid**; units/lessons by numeric id.

### Branding & landing
| Method | Path | Body |
|---|---|---|
| GET/PUT | `/teacher/profile` | `{ logo_url, cover_url, primary_color(#hex), secondary_color, bio, contact:{phone,email,whatsapp,address}, socials:{...} }` |
| GET/PUT | `/teacher/landing` | `{ landing_sections: [ { key: 'courses'|'offers'|'about'|'testimonials', visible: bool } ] }` |

### Categories
```
GET    /teacher/categories
POST   /teacher/categories            { name, grade?, subject?, level?, section?, sort_order? }
PUT    /teacher/categories/{id}
DELETE /teacher/categories/{id}
```

### Courses → units → lessons
```
GET    /teacher/courses                          (paginated, all visibilities)
POST   /teacher/courses                          { title*, description?, category_id?, price_minor?, currency?,
                                                   access_days?, visibility(visible|hidden|scheduled), publish_at?,
                                                   is_free?, purchase_enabled?, is_center?, cover_url?, points? }
GET    /teacher/courses/{uuid}
PUT    /teacher/courses/{uuid}
DELETE /teacher/courses/{uuid}                    (soft delete)

GET    /teacher/courses/{uuid}/units
POST   /teacher/courses/{uuid}/units             { title*, sort_order?, visibility?, publish_at? }
PUT    /teacher/courses/{uuid}/units/{unit}
DELETE /teacher/courses/{uuid}/units/{unit}

GET    /teacher/units/{unit}/lessons
POST   /teacher/units/{unit}/lessons             { title*, description?, sort_order?, duration_sec?,
                                                   max_views?, is_free_preview?, visibility?, publish_at? }
PUT    /teacher/units/{unit}/lessons/{lesson}
DELETE /teacher/units/{unit}/lessons/{lesson}
```
- `slug` is auto-generated from the title (stable across updates). Arabic titles get a safe fallback slug.
- A course is `hidden` by default — set `visibility: 'visible'` (and optional `publish_at`) to list it publicly.

### Lesson attachments (PDF / file / link)
```
GET    /teacher/lessons/{lesson}/attachments
POST   /teacher/lessons/{lesson}/attachments     (multipart)
DELETE /teacher/lessons/{lesson}/attachments/{uuid}
```
POST body: `type` = `link` → `{ type, title?, url }`; `type` = `pdf|file` → multipart with a `file` field (`title?`, `downloadable?`).

### Video upload (self-hosted pipeline)
Two paths — both respond `{ data: { media, upload } }`, so **always read the id at `data.media.uuid`**.

**Direct upload (use this in dev — one request):** send the file as multipart.
```
POST /teacher/media/uploads   (multipart)  { file: <video>, lesson_id?, title? }
  → 201 { data: { media: { uuid, status: "ready", ... }, upload: null } }
```
The file is stored and transcoded (stub) in this one call → the asset comes back **ready** and (if `lesson_id` given) is linked as the lesson's video. No follow-up call.
Accepted: `video/mp4|quicktime|webm|x-matroska`, ≤ 1 GB.

**Async upload (also works in dev):** send no file → you get a **signed `upload_url`** (already under `/api/*`, so CORS covers it). `PUT` the raw file bytes to that exact URL, then confirm.
```
POST /teacher/media/uploads                 { filename, lesson_id?, title? }
  → 201 { data: { media: {...}, upload: { upload_url, method: "PUT" } } }

PUT  <upload.upload_url>   body = raw file bytes (no X-Tenant / Authorization needed — the URL signature is the auth)
  → 200 { data: { uuid, status: "ready", ... } }        // stored + transcoded + lesson linked

POST /teacher/media/uploads/{uuid}/complete → 200 (idempotent confirm; asset already ready in dev)
GET  /teacher/media/{uuid}                  → poll { status: uploading|transcoding|ready|failed }
```
Send `upload_url` **exactly as returned** — don't strip or reorder its `?signature=…&expires=…` query, or you'll get `403`. It expires in 6 hours.

### Playing a video
A ready asset now carries a **playable `url`** — in dev it's a range-enabled stream of the file, so you can drop it straight into a `<video>` element. The URL carries its own auth in the query, so **do not** attach `X-Tenant`/`Authorization` (a `<video>` tag can't send headers anyway).

- **Teacher preview** (right after upload): use `data.media.url` — a URL-signed link.
  ```html
  <video controls :src="media.url"></video>
  ```
- **Student playback** (enrolled / free-preview): call the playback gate, then play `manifest_url`.
  ```
  POST /media/lessons/{lesson}/playback   → 200 { data: { token, manifest_url, key_url, expires_at } }
  ```
  ```html
  <video controls :src="data.manifest_url"></video>   <!-- token-gated stream; expires in ~2 min, re-request as needed -->
  ```
  `403` = not enrolled (and lesson isn't a free preview); `409` = the lesson has no ready video yet.

> Dev only: these serve the raw uploaded file (progressive MP4). Production swaps `LocalMediaProvider` for the real self-hosted pipeline (encrypted multi-bitrate HLS + edge delivery) — the response shape (`url`, `manifest_url`) stays the same, so your player code won't change.

> The earlier CORS / `"No query results … undefined"` errors came from the old `upload_url` pointing at a route that didn't exist and wasn't under `/api/*`. That's fixed — the async flow now works end-to-end locally. (Or use the **direct** path above for a single request.)

### Reports
```
GET /teacher/reports/sales     → { earnings_minor, gross_minor, orders_paid }
GET /teacher/reports/students  → { students, courses }   (counts only)
```

### Students — full control (M17)
A teacher fully manages their own students. All tenant-scoped; a student from
another academy → `404`. Students are addressed by **uuid**.
```
GET    /teacher/students                          list (?q= ?filter[status]= ?page=)
POST   /teacher/students                          add a student { name, phone, email?, password? }
                                                  → 201; if password omitted, returns temporary_password once
GET    /teacher/students/{uuid}                   360° view + summary counts
PATCH  /teacher/students/{uuid}                   { status: active|suspended }  (membership only)
DELETE /teacher/students/{uuid}                   remove from academy (+ cancels active enrollments)

GET    /teacher/students/{uuid}/enrollments       their course access
POST   /teacher/students/{uuid}/enrollments       manual enroll { course: <uuid> } (free grant)
DELETE /teacher/students/{uuid}/enrollments/{id}  revoke access

GET    /teacher/students/{uuid}/wallet            balance + recent ledger
POST   /teacher/students/{uuid}/wallet/adjust     { amount_minor, direction: credit|debit, reason? }
GET    /teacher/students/{uuid}/orders            their orders

GET    /teacher/students/{uuid}/progress          watch %, completed lessons
POST   /teacher/students/{uuid}/notify            in-app message { message, title? }
```
List row: `{ uuid, name, phone, email, status, joined_at, enrolled_courses }`.

> Notes: a teacher edits the **membership + academy data**, never the global user identity — so profile-field edits and password resets of shared accounts are intentionally not exposed here. Wallet changes post to the double-entry **ledger** as balanced adjustments (a `debit` can't exceed the balance).

---

## 10. Platform admin (role: platform admin) — NO `X-Tenant`

Admins log in on the platform host (no tenant). These are cross-tenant and require `is_platform_admin`.
```
GET  /admin/tenants                 (paginated)
POST /admin/tenants                 { name*, slug*, status?, owner?:{name,phone,password,email?} }
GET  /admin/tenants/{uuid}
PUT  /admin/tenants/{uuid}          { name?, status? }   // status: active|suspended|under_review|expired
GET  /admin/reports/overview        → { teachers, students, courses, gross_earnings_minor, tenants_by_status }
```

---

## 11. Not for the frontend (reference only)

These exist but are called by the gateway / media tier, not your app:
- `POST /webhooks/paymob` — Paymob → us (payment confirmation).
- `GET /internal/media/authz` — nginx edge validates a playback token.
- `POST /internal/transcode/callback` — transcode worker reports readiness.

---

## 12. Dev seed accounts (local `migrate:fresh --seed`)

| Role | Login (`identifier`) | Password | Tenant (`X-Tenant`) |
|---|---|---|---|
| Teacher (owns demo academy) | `01000000000` | `password` | `demo` |
| Platform admin | `01000000009` | `password` | *(none — platform host)* |

Demo tenant slug: **`demo`** (reachable via `X-Tenant: demo`). It has a branded profile and sample landing sections.

Quick sanity check from your machine:
```bash
curl -H "X-Tenant: demo" -H "Accept: application/json" \
  http://192.168.100.143:8000/api/v1/tenant/context
```

---

## 13. Gotchas checklist

- [ ] Send `X-Tenant` on every tenant request in dev (not on `/admin`, `/webhooks`, `/internal`, `/media/key`).
- [ ] Send `Accept: application/json` always (otherwise errors may render as HTML).
- [ ] Attach `Authorization: Bearer` after login; clear it on logout/401.
- [ ] Treat all money as integer minor units; divide by 100 for display.
- [ ] Read errors from `error.code` / `error.message` / `error.details`.
- [ ] Handle `429` (rate limits) on auth/OTP.
- [ ] Playback tokens expire fast — request a fresh one per play, don't cache.
- [ ] Course create defaults to `hidden`; set `visibility: 'visible'` to publish.

*Questions or a missing field? Ping backend (Mazen). This doc tracks the implemented API; the machine-readable `openapi.yaml` is the future source of truth.*
