# Elameed Education — Full API Reference (v1)

Complete endpoint reference: **method · path · headers · request · response**. Reflects the implemented API (72 routes). For narrative/onboarding see `FRONTEND_API_GUIDE.md`.

- **Base URL (dev):** `http://192.168.100.143:8000/api/v1` · **(prod):** `https://api.elameed.app/api/v1`
- **Money:** integer minor units (piastres); `15000` = 150.00 EGP.
- **Success:** `{ "data": … }` (+ `links`/`meta` when paginated). **Error:** `{ "error": { "code", "message", "details"? } }`.
- **Codes:** 200 ok · 201 created · 202 accepted · 204 no-content · 400 · 401 · 403 · 404 · 409 · 422 · 429 · 5xx.

## Header profiles

Every request sends `Accept: application/json`; every write (POST/PUT/PATCH) also sends `Content-Type: application/json` (or `multipart/form-data` for file uploads). Beyond that:

| Profile | Headers | Meaning |
|---|---|---|
| **🌐 Public** | `X-Tenant: <slug>` *(dev; prod = host)* | Tenant resolved, no auth |
| **🔑 Auth** | Public + `Authorization: Bearer <token>` | Logged-in user in the tenant |
| **🧑‍🏫 Teacher** | Auth, and user must be an active `teacher` in the tenant | else `403 forbidden` |
| **🛡️ Admin** | `Authorization: Bearer <token>`, **no `X-Tenant`** | platform admin (`is_platform_admin`) |
| **⚙️ Machine** | signature / secret / token in the request; **no `X-Tenant`**, no user auth | gateway / media tier |

> In **production** the tenant comes from the Host (subdomain/custom domain) — omit `X-Tenant`. It's a dev/staging override only.

---

## 1. Tenant & storefront — 🌐 Public

### GET `/tenant/context`
SPA boot: resolve host → academy identity, branding, locale.
**Response 200**
```json
{ "data": {
  "uuid": "…", "slug": "demo", "name": "Demo Academy", "status": "active",
  "branding": { "logo_url": null, "cover_url": null, "primary_color": "#1D4ED8",
    "secondary_color": "#9333EA", "bio": "…", "socials": {}, "landing_sections": ["courses","about"] },
  "locale": { "default": "ar", "supported": ["ar","en"] }, "features": [] } }
```
**404** `{ "error": { "code": "tenant_not_found" } }`

### GET `/courses`
Published catalogue. Query: `?filter[category_id]=` `?filter[grade]=` `?filter[subject]=` `?q=` `?page=`.
**Response 200** (paginated)
```json
{ "data": [ { "uuid":"…","title":"…","slug":"…","description":null,"category":null,
  "price_minor":15000,"currency":"EGP","access_days":null,"visibility":"visible",
  "publish_at":null,"is_free":false,"purchase_enabled":true,"is_center":false,
  "cover_url":null,"points":0 } ],
  "links": {"first":"…","last":"…","prev":null,"next":null},
  "meta": {"current_page":1,"last_page":1,"per_page":20,"total":1} }
```

### GET `/courses/{slug}`
Course detail + published outline.
**Response 200**
```json
{ "data": { "uuid":"…","title":"…","slug":"…","description":null,"cover_url":null,
  "price_minor":15000,"currency":"EGP","is_free":false,"access_days":null,"category":null,
  "units": [ { "id":1,"title":"Chapter 1","sort_order":0,
    "lessons": [ { "id":1,"title":"Lesson 1","duration_sec":null,"is_free_preview":true,"has_video":false } ] } ] } }
```
**404** if hidden/unknown.

---

## 2. Auth & OTP — 🌐 Public (rate-limited)

### POST `/auth/register`  *(throttle: 5/min per phone+ip)*
Body `{ "name", "phone", "email"?, "password", "locale"? }` → creates a **pending** student membership + sends OTP.
**202** `{ "data": { "message":"…", "identifier":"01…", "requires_otp": true } }`
**422** `validation_error` (e.g. duplicate phone).

### POST `/auth/otp/request`  *(throttle: 5/min)*
Body `{ "identifier", "purpose": "register|login|reset" }`.
**200** `{ "data": { "message":"If the details are valid, a code has been sent." } }` *(generic — no enumeration)*

### POST `/auth/otp/verify`  *(throttle: 10/min)*
Body `{ "identifier", "purpose": "register|login", "code" }`.
**200** `{ "data": { "token":"<sanctum>", "user": { "uuid","name","email","phone","locale","email_verified","phone_verified" } } }`
**422** invalid/expired code.

### POST `/auth/login`  *(throttle: 10/min)*
Body `{ "identifier", "password" }` (`identifier` = phone or email).
**200** `{ "data": { "token", "user" } }` — or when login-OTP is on: `{ "data": { "otp_required": true, "identifier" } }`.
**401** `unauthenticated` (bad creds — identical for wrong password vs unknown user). **403** `forbidden` (not a member of this academy).

### POST `/auth/password/forgot`  *(throttle: 5/min)*
Body `{ "identifier" }` → **200** generic message (sends reset OTP if the account exists).

### POST `/auth/password/reset`  *(throttle: 5/min)*
Body `{ "identifier", "code", "password" }` → **200** `{ "data": { "message":"…" } }`. Revokes existing tokens.

---

## 3. Session — 🔑 Auth

### POST `/auth/logout`
Revokes the current token. **200** `{ "data": { "message":"Signed out." } }`

### GET `/me`
```json
{ "data": { "uuid","name","email","phone","locale","email_verified","phone_verified",
  "is_platform_admin": false,
  "memberships": [ { "tenant":"demo","tenant_name":"Demo Academy","role":"student","status":"active" } ],
  "current": { "tenant":"demo","role":"student","permissions":[] } } }
```

---

## 4. Wallet & checkout — 🔑 Auth

### GET `/wallet`
`{ "data": { "balance_minor":5000, "currency":"EGP", "recent":[ {"account","direction","amount_minor","ref_type","ref_id","created_at"} ] } }`

### GET `/wallet/ledger`  *(paginated)*
`{ "data":[ {"account","direction","amount_minor","ref_type","ref_id","created_at"} ], "links":…, "meta":… }`

### POST `/checkout/quote`
Body `{ "items": [ {"type":"course","course":"<uuid>"} | {"type":"wallet_topup","amount_minor":20000} ] }`.
`{ "data": { "total_minor":15000, "currency":"EGP", "lines":[ {"type","title","price_minor"} ] } }`

### POST `/checkout/order`
Body = same cart → **201** `{ "data": { "uuid","status":"pending","total_minor","currency","items":[…] } }`

### POST `/checkout/pay`  *(throttle: 10/min)*
Body `{ "order":"<uuid>", "method":"wallet|paymob" }`.
- wallet → **200** `{ "data": { "status":"paid","order":"<uuid>" } }` (enrolled + invoice issued). **422** insufficient balance.
- paymob → **200** `{ "data": { "status":"pending","order":"<uuid>","redirect_url":"…" } }` (completes via webhook).

---

## 5. Media & playback

### POST `/media/lessons/{lesson}/playback` — 🔑 Auth
Body (optional) `{ "device_fingerprint" }`.
**200** `{ "data": { "token","manifest_url","key_url","expires_at" } }` · **403** not enrolled · **409** no ready video.

### GET `/media/key/{token}` — ⚙️ Machine (token in URL)
**200** `{ "data": { "key":"<base64>" } }` · **403** invalid/expired token.

### GET `/internal/media/authz` — ⚙️ Machine (nginx `auth_request`)
Query/header `token`. **204** (allow) · **403** (deny). No body.

### POST `/internal/transcode/callback` — ⚙️ Machine
Header `X-Transcode-Secret`. Body `{ "media_uuid","status":"ready|failed","hls_path"?,"renditions"? }`.
**200** `{ "data": { "status" } }` · **403** bad secret · **404** unknown asset.

---

## 6. Progress & notifications — 🔑 Auth

### POST `/lessons/{lesson}/progress`
Body `{ "watch_percent":0-100, "watch_seconds"?, "last_position_sec"? }`.
**200** `{ "data": { "watch_percent","last_position_sec","completed" } }` · **403** no access.

### GET `/me/activity`
`{ "data": [ {"lesson_id","lesson_title","watch_percent","last_position_sec","completed"} ] }`

### GET `/me/courses`
`{ "data": [ {"uuid","title","slug","cover_url","lessons_total","lessons_completed","progress_percent"} ] }`

### GET `/me/notifications`  *(paginated)*
`{ "data": [ {"id","type","payload","read","created_at"} ], "links":…, "meta":… }`

### POST `/me/notifications/{id}/read`
**200** `{ "data": { "read": true } }` · **404** not yours.

---

## 7. Teacher — site & branding — 🧑‍🏫 Teacher

### GET·PUT `/teacher/profile`
PUT body `{ "logo_url"?,"cover_url"?,"primary_color"?(#hex),"secondary_color"?,"bio"?,"contact"?:{phone,email,whatsapp,address},"socials"?:{...} }`.
`{ "data": { "logo_url","cover_url","primary_color","secondary_color","bio","contact","socials" } }`

### GET·PUT `/teacher/landing`
PUT body `{ "landing_sections":[ {"key":"courses|offers|about|testimonials","visible":true} ] }`.
`{ "data": { "landing_sections":[…] } }`

---

## 8. Teacher — catalog — 🧑‍🏫 Teacher

### Categories
```
GET    /teacher/categories            → { "data":[ {id,name,grade,subject,level,section,sort_order} ] }
POST   /teacher/categories            body {name*,grade?,subject?,level?,section?,sort_order?}  → 201 {data:{…}}
PUT    /teacher/categories/{id}        → 200 {data:{…}}
DELETE /teacher/categories/{id}        → 204
```

### Courses  (addressed by **uuid**)
```
GET    /teacher/courses                → paginated (CourseResource, all visibilities)
POST   /teacher/courses                body {title*,description?,category_id?,price_minor?,currency?,access_days?,
                                             visibility?,publish_at?,is_free?,purchase_enabled?,is_center?,cover_url?,points?} → 201
GET    /teacher/courses/{uuid}          → 200 {data:{…CourseResource}}
PUT    /teacher/courses/{uuid}          → 200
DELETE /teacher/courses/{uuid}          → 204 (soft delete)
```

### Units  (nested under a course)
```
GET    /teacher/courses/{uuid}/units             → {data:[ {id,course_id,title,sort_order,visibility,publish_at} ]}
POST   /teacher/courses/{uuid}/units             body {title*,sort_order?,visibility?,publish_at?} → 201
PUT    /teacher/courses/{uuid}/units/{unit}       → 200
DELETE /teacher/courses/{uuid}/units/{unit}       → 204
```

### Lessons  (nested under a unit)
```
GET    /teacher/units/{unit}/lessons             → {data:[ {id,unit_id,course_id,title,description,sort_order,
                                                    duration_sec,max_views,is_free_preview,has_video,visibility,publish_at} ]}
POST   /teacher/units/{unit}/lessons             body {title*,description?,sort_order?,duration_sec?,max_views?,
                                                    is_free_preview?,visibility?,publish_at?} → 201
PUT    /teacher/units/{unit}/lessons/{lesson}     → 200
DELETE /teacher/units/{unit}/lessons/{lesson}     → 204
```

### Lesson attachments
```
GET    /teacher/lessons/{lesson}/attachments               → {data:[ {uuid,type,status,title,url,downloadable,duration_sec} ]}
POST   /teacher/lessons/{lesson}/attachments               link:  {type:"link",title?,url}    → 201
                                                           file:  multipart {type:"pdf|file",file,title?,downloadable?}
DELETE /teacher/lessons/{lesson}/attachments/{uuid}         → 204
```

### Video pipeline  (both paths → `{data:{media,upload}}`; read id at `data.media.uuid`)
```
POST   /teacher/media/uploads   (multipart)  {file:<video>,lesson_id?,title?}     ← DEV: one-step
                                             → 201 {data:{media:{uuid,status:"ready"},upload:null}} (stored+transcoded, links lesson)
                                             file: video/mp4|quicktime|webm|x-matroska, ≤1GB
POST   /teacher/media/uploads                {filename*,lesson_id?,title?}        ← ASYNC (prod)
                                             → 201 {data:{media:{…},upload:{upload_url,method}}}
POST   /teacher/media/uploads/{uuid}/complete → 200 {data:{…MediaAsset}} (enqueues transcode, links to lesson)
GET    /teacher/media/{uuid}                  → 200 {data:{uuid,type,status,title,url,downloadable,duration_sec}}
```

---

## 9. Teacher — students & reports — 🧑‍🏫 Teacher

### Reports
```
GET /teacher/reports/sales     → { "data": { "earnings_minor","gross_minor","orders_paid" } }
GET /teacher/reports/students  → { "data": { "students","courses" } }   (counts)
```

### Student roster + full control  (students by **uuid**; cross-tenant → 404)
```
GET    /teacher/students                  paginated. ?q= ?filter[status]=active|pending|suspended ?page=
                                          → {data:[ {uuid,name,phone,email,status,joined_at,enrolled_courses} ]}
POST   /teacher/students                  body {name*,phone*,email?,password?}
                                          → 201 {data:{uuid,name,phone,email,status,temporary_password?}}
GET    /teacher/students/{uuid}            → {data:{uuid,name,phone,email,status,joined_at,
                                                 summary:{enrolled_courses,wallet_balance_minor,orders,lessons_completed}}}
PATCH  /teacher/students/{uuid}            body {status:"active|suspended"} → {data:{uuid,status}}
DELETE /teacher/students/{uuid}            → 204 (removes membership + cancels active enrollments)

GET    /teacher/students/{uuid}/enrollments             → {data:[ {id,course,course_title,source,status,starts_at,expires_at} ]}
POST   /teacher/students/{uuid}/enrollments             body {course:"<uuid>"} → 201 {data:{id,course,status,expires_at}}
DELETE /teacher/students/{uuid}/enrollments/{id}        → 204 (cancel)

GET    /teacher/students/{uuid}/wallet                  → {data:{balance_minor,currency,recent:[…]}}
POST   /teacher/students/{uuid}/wallet/adjust           body {amount_minor*,direction:"credit|debit",reason?}
                                                        → {data:{balance_minor}} · 422 if debit > balance
GET    /teacher/students/{uuid}/orders                  → {data:[ {uuid,status,total_minor,currency,items:[…]} ]}

GET    /teacher/students/{uuid}/progress                → {data:[ {lesson_id,lesson_title,watch_percent,last_position_sec,completed} ]}
POST   /teacher/students/{uuid}/notify                  body {message*,title?} → 201 {data:{message}}
```

---

## 9b. Exams & assignments (M08)

### Teacher — authoring & grading — 🧑‍🏫 Teacher
```
GET    /teacher/courses/{course:uuid}/exams        → [ ExamResource ]
POST   /teacher/courses/{course:uuid}/exams        body {title*,type?,pass_percent?,duration_min?,attempts_allowed?,
                                                        question_order?,scoring?,starts_at?,ends_at?,result_visibility?,
                                                        show_answers?,depends_on_exam_id?,mode?,is_published?} → 201
GET    /teacher/exams/{uuid}                         → ExamResource (+ questions_count)
PUT    /teacher/exams/{uuid}                         → 200 (also used to publish: is_published=true)
DELETE /teacher/exams/{uuid}                         → 204 (soft)

GET    /teacher/exams/{uuid}/questions               → [ {id,type,body,options,correct,points,book_ref,sort_order} ]  (answer key included)
POST   /teacher/exams/{uuid}/questions               body {type*,body?,options?,correct?,points?,book_ref?,sort_order?} → 201
PUT    /teacher/exams/{uuid}/questions/{id}           → 200
DELETE /teacher/exams/{uuid}/questions/{id}           → 204

GET    /teacher/exams/{uuid}/submissions             ?filter[needs_grading]=1
                                                     → [ {attempt_id,student:{uuid,name,phone},status,score,max_score,needs_manual_grade,submitted_at} ]
POST   /teacher/exams/{uuid}/attempts/{id}/grade     body {grades:{ "<question_id>": points }} → {attempt_id,status,score,max_score,needs_manual_grade}
```
Question types: `mcq` (options + correct indices), `true_false`, `short`, `essay`, `file`. `mcq`/`true_false` auto-grade on submit; the rest set `needs_manual_grade`. Bubble-sheet = an `mcq` with a `book_ref` (`{book,page,qno}`) and no `body`.

### Student — take exams — 🔑 Auth
```
GET  /exams                                → published, in-window exams for the student's enrolled courses [ ExamResource ]
POST /exams/{uuid}/attempts                → start/resume; 201-ish 200 {data:{attempt_id,attempt_number,duration_min,
                                              questions:[ {id,type,body,options,points,book_ref} ]}}  ← NO answer key
                                              · 403 not enrolled / prerequisite unmet · 409 not open / no attempts left
POST /exams/{uuid}/attempts/{id}/submit    body {answers:{ "<question_id>": <answer> }}
                                              → {attempt_id,status,needs_manual_grade,score?,max_score?,passed?,review?}
GET  /exams/{uuid}/attempts/{id}           → same result shape (score shown per result_visibility; review only if show_answers)
```
Answer shapes: mcq → array of option indices `[1]`; true_false → `true`/`false`; short/essay → text; file → url. Score/answers visibility honours the exam's `result_visibility` (immediate | after_close | manual) and `show_answers`.

---

## 10. Platform admin — 🛡️ Admin (no `X-Tenant`)

```
GET  /admin/tenants                paginated → {data:[ {uuid,slug,name,status,owner_user_id,primary_host,created_at} ]}
POST /admin/tenants                body {name*,slug*,status?,owner?:{name,phone,password,email?}} → 201 {data:{…}}
GET  /admin/tenants/{uuid}          → 200 {data:{…}}
PUT  /admin/tenants/{uuid}          body {name?,status?:"active|suspended|under_review|expired"} → 200 {data:{…}}
GET  /admin/reports/overview        → {data:{teachers,students,courses,gross_earnings_minor,tenants_by_status}}
```
Non-admin token → **403 forbidden**.

---

## 11. Webhooks — ⚙️ Machine

### POST `/webhooks/paymob`  *(throttle: 120/min)*
Header `X-Paymob-Hmac`. Body (Paymob payload) `{ transaction_id, order_uuid, amount_cents, success }`.
**200** `{ "data": { "status":"paid|failed|already_processed" } }` · **400** `invalid_signature` · **404** `order_not_found`. Idempotent (dedupes on `transaction_id`).

---

## Dev accounts (`php artisan migrate:fresh --seed` + `db:seed --class=DemoAcademiesSeeder`)

| Role | identifier | password | `X-Tenant` |
|---|---|---|---|
| Platform admin | `01000000009` | `password` | *(none)* |
| Teacher (demo) | `01000000000` | `password` | `demo` |
| Student (demo) | `01000000001` | `password` | `demo` |
| Teacher / Student (ahmed) | `01111100001` / `01111100002` | `password` | `ahmed` |
| Teacher / Student (mona) | `01222200001` / `01222200002` | `password` | `mona` |

*Reference reflects the implemented API. Some backends are stubbed (Paymob, video transcode/delivery, SMS) but their request/response contracts are final.*
