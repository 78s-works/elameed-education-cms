# Frontend Integration Guide — start here

A practical, journey-based guide to consuming the Elameed Education API from the
Vue 3 SPA. For the **exhaustive endpoint list** see [API_ENDPOINTS.md](API_ENDPOINTS.md);
landing details are in [LANDING_CONTRACT_V2.md](LANDING_CONTRACT_V2.md).

---

## 1. Basics

- **Base URL:** `http://<host>:8000/api/v1` (dev). All paths below are relative to it.
- **Every request:** `Accept: application/json`.
- **Tenant:** in dev send **`X-Tenant: <slug>`** on every call. In prod the academy resolves from the Host (`<slug>.elameed.app` / custom domain), so the header isn't needed there.
- **Auth:** `Authorization: Bearer <token>` once logged in.
- **JSON** bodies use `Content-Type: application/json`; uploads use `multipart/form-data`.
- **Money:** integers in **minor units** (piastres) — `amount_minor: 30000` = 300.00 EGP.
- **Dates:** ISO-8601. **Language:** Arabic-first, **RTL**.

### Envelopes
```jsonc
{ "data": … }                                              // success
{ "data": [ … ], "links": {…}, "meta": {…} }               // paginated list
{ "error": { "code": "validation_error", "message": "…", "details": { "field": ["…"] } } }  // error
```
Errors: `401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conflict`, `422 validation_error`, `429 too_many_requests`.

---

## 2. API client (axios)

```js
import axios from 'axios'
const api = axios.create({ baseURL: import.meta.env.VITE_API_BASE, headers: { Accept: 'application/json' } })

api.interceptors.request.use((c) => {
  c.headers['X-Tenant'] = currentTenantSlug()          // dev only; omit in prod
  const t = localStorage.getItem('token')
  if (t) c.headers.Authorization = `Bearer ${t}`
  return c
})
api.interceptors.response.use(r => r, (err) => {
  const { status, data } = err.response ?? {}
  if (status === 401) redirectToLogin()                // token missing/expired
  if (status === 403) showAccessDenied()               // not allowed / account suspended
  return Promise.reject(data?.error ?? err)            // reject with { code, message, details }
})
export default api
```
> **Suspend rule:** a student the teacher suspends gets **403 on every authenticated call** (including video). Treat that as "access revoked".

---

## 3. Auth & tokens

**Student registration (OTP):**
```js
await api.post('/auth/register', {
  name: 'سارة أحمد محمود علي',                         // الاسم رباعي
  phone: '01000000001', password: 'secret123', password_confirmation: 'secret123',
  gender: 'أنثى', governorate: 'القاهرة', region: 'المعادي',
  academic_year: 'الثالث الثانوي', education_type: 'عام', guardian_phone: '01099999999',
}) // → 202 { data: { identifier, requires_otp: true } } ; extra fields are free-text (frontend owns dropdown lists)

const { data } = await api.post('/auth/otp/verify', { identifier: '01000000001', purpose: 'register', code: '123456' })
localStorage.setItem('token', data.data.token)
```
**Login** (teacher/student/parent): `POST /auth/login { identifier, password }` → `{ token, otp_required }`.
**Who am I / logout:** `GET /me` → `{ uuid, name, role, tenant }`; `POST /auth/logout`. Use `me.role` to pick the dashboard.
**Password reset:** `POST /auth/password/forgot { identifier }` → `POST /auth/password/reset { identifier, code, password }`.

---

## 4. Boot the SPA (public)
```js
const ctx = (await api.get('/tenant/context')).data.data       // identity + branding + locale → theme the app
const landing = (await api.get('/tenant/landing')).data.data   // { layout, nav, sections[] } — render by layout
```
Render the template chosen by `landing.layout` (`classic|grid|spotlight`); dynamic `courses`/`testimonials` sections arrive with resolved `items`. Send a Bearer token and course items include `enrolled`. See [LANDING_CONTRACT_V2.md](LANDING_CONTRACT_V2.md).

---

## 5. Visitor → catalogue
```js
await api.get('/courses', { params: { q, 'filter[category_id]': 3, page: 1 } })
const course  = (await api.get(`/courses/${slug}`)).data.data     // detail + units→lessons; each lesson: { has_video, video, attachments[] }
const reviews = await api.get(`/courses/${slug}/reviews`)          // paginated
```
Course detail carries marketing fields (`subtitle, learning_outcomes[], requirements[], audience[], parts[], promo_video_url`) and the `units[].lessons[]` outline. **A lesson has one `video` + many `attachments`** (pdf/file/link).

---

## 6. Student journey

**Enroll & pay** — `POST /checkout/quote` → `POST /checkout/order` → `POST /checkout/pay { order, method: wallet|paymob|fawry }`. Wallet: `GET /wallet`, `GET /wallet/ledger`.

**Redeem a center code** — `POST /codes/redeem { code }` → wallet credit **or** course enrollment.

**Watch a video (encrypted HLS — hls.js):**
```js
import Hls from 'hls.js'
const { data } = await api.post(`/media/lessons/${lessonId}/playback`)   // { token, manifest_url, key_url, expires_at }
const hls = new Hls(); hls.loadSource(data.data.manifest_url); hls.attachMedia(videoEl)  // Safari: videoEl.src = manifest_url
```
Never attach `X-Tenant`/`Authorization` to `manifest/segment/key` URLs — the token is in the URL. Token ≈ 2 min; re-request to resume. `403` = not enrolled; `409` = video not ready.

**Progress / exams / reviews:**
```js
await api.post(`/lessons/${lessonId}/progress`, { watch_percent: 80, last_position_sec: 240 }) // throttle ~10s
const a = await api.post(`/exams/${examUuid}/attempts`)                                          // start
await api.post(`/exams/${examUuid}/attempts/${a.data.data.attempt_id}/submit`, { answers: { 12:'B' } })
await api.post(`/courses/${slug}/reviews`, { rating: 5, comment: 'ممتاز' })                       // must be enrolled; upsert
```
**Dashboard:** `GET /me/courses` · `/me/resume` · `/me/favorites` (+POST, DELETE) · `/me/points` · `/me/badges` · `/leaderboard` · `/me/notifications` (+POST read) · `/me/activity`.

---

## 7. Teacher dashboard  (`role: teacher`)
- **Branding/landing:** `GET·PUT /teacher/profile`; `GET·PUT /teacher/landing`; `POST /teacher/landing/media` (image → `{url}`).
- **Catalogue:** categories, courses (rich fields), units, lessons (one video + many attachments), attachments CRUD.
- **Video:** `POST /teacher/media/uploads` (multipart `file`); `POST /teacher/media/{uuid}/preview` (encrypted-HLS preview).
- **Students — full control:** list/add/show/`PATCH`(identity+status+registration profile)/reset-password/export/activity; enrollments; **wallet** (`/wallet`, `/wallet/ledger`, `/wallet/adjust`, `/wallet/set`); orders; progress; notify; parents.
- **Exams & grading:** exams + questions CRUD, `submissions`, `attempts/{a}/grade`.
- **Reports/gamification:** `/teacher/reports/sales|students`, `/teacher/badges`, `/teacher/gamification`, `/teacher/audit-logs`.
- **Centers (M12):**
  ```js
  api.get('/teacher/centers'); api.post('/teacher/centers', { name })          // branches
  api.post('/teacher/codes/batch', { type:'wallet', count:50, amount_minor:5000 })   // or { type:'course', course_id }
  api.get('/teacher/codes'); api.post(`/teacher/codes/${uuid}/disable`)
  api.post(`/teacher/centers/${uuid}/attendance`, { students:[uuid,…], status:'present' })
  api.get(`/teacher/centers/${uuid}/attendance`)
  api.post('/teacher/centers/sync', { events:[{ kind:'attendance'|'redeem', external_ref, … }] })  // offline app flush
  ```

## 8. Parent portal (`role: parent`)
`GET /parent/children` · `/parent/children/{uuid}/progress` · `/parent/children/{uuid}/results`.

## 9. Platform admin (`role: platform admin`, **no `X-Tenant`**)
`GET/POST /admin/tenants`, `GET/PUT /admin/tenants/{uuid}`, `GET /admin/reports/overview`, `GET /admin/audit-logs`.

---

## 10. Test accounts (seeded)
Password for all: **`password`**. Send the academy's `X-Tenant` slug.

| Academy | `X-Tenant` | Teacher (phone) | Layout |
|---|---|---|---|
| أحمد للفيزياء | `ahmed-physics` | `01500000001` | classic |
| منى للرياضيات | `mona-math` | `01500000002` | grid |
| خالد للكيمياء | `khaled-chem` | `01500000003` | spotlight |
| سارة للغة الإنجليزية | `sara-english` | `01500000004` | classic |

Students: `01200000001`–`01200000032` (a student's `X-Tenant` must match their academy).

## 11. Gotchas
- Always send `X-Tenant` (dev). No headers on `media/stream|segment|key` — token is in the URL; use hls.js.
- Money is minor units → format `amount_minor / 100`.
- Suspended student → 403 everywhere.
- Dropdown option lists (governorate, grade, …) are **frontend-owned**; the API stores free text.
- A lesson = **one `video` + many `attachments`**; playback still via the token flow.
- Full reference: [API_ENDPOINTS.md](API_ENDPOINTS.md).
