# Elameed Education — Complete Endpoint Reference (v1)

Every endpoint with **header tier**, **request JSON**, and **response JSON**. Base URL: `http://<host>:8000/api/v1`. Generated from the live route table (132 routes).

## Conventions

**Header tiers** (per-endpoint tag):

| Tag | Required headers |
|---|---|
| **P** | `X-Tenant: <slug>` · `Accept: application/json` |
| **A** | **P** + `Authorization: Bearer <token>` (must be an *active* member) |
| **T** | **A** + caller is an active `teacher` |
| **PA** | **A** + caller is an active `parent` |
| **ADM** | `Authorization: Bearer` · `Accept` — **no `X-Tenant`** |
| **URL** | none — auth is a token/signature in the URL |
| **HOOK** | provider-specific (webhook) |

JSON bodies use `Content-Type: application/json` unless marked **(multipart)**. Success `{data}` / list adds `links`+`meta` / error `{error:{code,message,details}}`. Money = minor units. **Suspended member → 403 on all A/T/PA endpoints.**

---

## 1 · Auth & identity
| Endpoint | Auth | Request | Response `data` |
|---|---|---|---|
| `POST /auth/register` | P | `{ name, phone, email?, password, password_confirmation, locale?, gender?, governorate?, region?, academic_year?, education_type?, guardian_phone? }` | `202` `{ identifier, requires_otp: true }` (pending membership + student profile + OTP sent) |
| `POST /auth/otp/request` | P | `{ identifier, purpose }` (`login\|register\|reset`) | `{ sent: true }` |
| `POST /auth/otp/verify` | P | `{ identifier, purpose, code }` | `{ token, user }` |
| `POST /auth/login` | P | `{ identifier, password }` | `{ token, otp_required: false }` |
| `POST /auth/password/forgot` | P | `{ identifier }` | `{ sent: true }` |
| `POST /auth/password/reset` | P | `{ identifier, code, password }` | `{ reset: true }` |
| `POST /auth/logout` | A | — | `204` |
| `GET /me` | A | — | `{ uuid, name, phone, email, role, tenant, is_platform_admin }` |

## 2 · Tenant context & landing (public)
| Endpoint | Auth | Response `data` |
|---|---|---|
| `GET /tenant/context` | P | `{ uuid, slug, name, status, branding:{ logo_url, cover_url, primary_color, secondary_color, bio, socials }, locale, features[] }` |
| `GET /tenant/landing` | P (optional Bearer) | `{ layout:"classic\|grid\|spotlight", nav:{links[]}, sections[] }` — dynamic sections resolved to `items`; course items get `enrolled` when authed. See LANDING_CONTRACT_V2.md |

## 3 · Public catalogue & reviews
| Endpoint | Auth | Request | Response `data` |
|---|---|---|---|
| `GET /courses` | P | `?q= &filter[category_id]= &page=` | `[ CourseResource ]` paginated |
| `GET /courses/{slug}` | P | — | course + `learning_outcomes[]/requirements[]/audience[]/parts[]/promo_video_url` + `units[].lessons[]` (`has_video`, `video`, `attachments[]`, `is_free_preview`) |
| `GET /courses/{slug}/reviews` | P | `?page=` | `[ { id, student_name, course_title, rating, comment, created_at } ]` |
| `POST /courses/{slug}/reviews` | A (has course access) | `{ rating:1-5, comment? }` | `201` review (upsert — one per student) |

## 4 · Teacher — site branding & landing (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/profile` | — | `{ logo_url, cover_url, primary_color, secondary_color, bio, contact, socials }` |
| `PUT /teacher/profile` | `{ logo_url?, cover_url?, primary_color?(#hex), secondary_color?, bio?, contact?, socials? }` | same |
| `GET /teacher/landing` | — | `{ layout, nav, sections[] }` (authoring shape; dynamic sections carry `config`) |
| `PUT /teacher/landing` | `{ layout?, sections:[{ key, type, visible, order?, content, config? }] }` (full replace) | same · `422` bad layout/type/config/non-owned refs |
| `POST /teacher/landing/media` **(multipart)** | `file` (image ≤5 MB) | `{ url }` |

## 5 · Teacher — catalogue (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/categories` | — | `[ { id, name, grade, subject, level, section, sort_order } ]` |
| `POST /teacher/categories` | `{ name, grade?, subject?, level?, section?, sort_order? }` | `201` category |
| `PUT /teacher/categories/{id}` · `DELETE …` | same · — | category · `204` |
| `GET /teacher/courses` | `?page=` | `[ CourseResource ]` |
| `POST /teacher/courses` | `{ title, subtitle?, description?, learning_outcomes?[], requirements?[], audience?[], parts?[], category_id?, price_minor?, currency?, access_days?, visibility?, publish_at?, is_free?, purchase_enabled?, is_center?, cover_url?, promo_video_url?, points? }` | `201` CourseResource |
| `GET/PUT/DELETE /teacher/courses/{uuid}` | — / same / — | CourseResource · `204` (soft delete) |
| `GET /teacher/courses/{uuid}/units` · `POST` | — · `{ title, sort_order?, visibility?, publish_at? }` | `[unit]` · `201` unit |
| `PUT/DELETE /teacher/courses/{uuid}/units/{unit}` | same · — | unit · `204` |
| `GET /teacher/units/{unit}/lessons` · `POST` | — · `{ title, description?, sort_order?, duration_sec?, max_views?, is_free_preview?, visibility?, publish_at? }` | `[LessonResource]` · `201` — each lesson has `video` (one) + `attachments` (many) |
| `PUT/DELETE /teacher/units/{unit}/lessons/{lesson}` | same · — | LessonResource · `204` |
| `GET /teacher/lessons/{lesson}/attachments` | — | `[ { uuid, type, title, url, downloadable } ]` (pdf/file/link — never the video) |
| `POST /teacher/lessons/{lesson}/attachments` **(multipart for file)** | link → `{type:"link", title?, url}`; file → `type:"pdf\|file"` + `file`, `title?`, `downloadable?` | `201` attachment |
| `DELETE /teacher/lessons/{lesson}/attachments/{uuid}` | — | `204` (refuses to delete the video) |

## 6 · Teacher — video pipeline (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `POST /teacher/media/uploads` **(multipart)** | direct: `file`=video (mp4/mov/webm/mkv ≤1 GB), `lesson_id?`, `title?` | `201` `{ media:{uuid,status:"ready",url:null}, upload:null }` |
| `POST /teacher/media/uploads` | async: `{ filename, lesson_id?, title? }` | `201` `{ media, upload:{upload_url, method:"PUT"} }` |
| `POST /teacher/media/uploads/{uuid}/complete` | — | `{ …MediaAsset }` |
| `POST /teacher/media/{uuid}/preview` | — | `{ token, manifest_url, key_url, expires_at }` (encrypted-HLS preview) |
| `GET /teacher/media/{uuid}` | — | `{ uuid, type, status, title, url, downloadable, duration_sec }` |

## 7 · Teacher — exams & grading (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/courses/{uuid}/exams` · `POST` | — · `{ title, lesson_id?, type, pass_percent?, duration_min?, attempts_allowed?, question_order?, scoring?, starts_at?, ends_at?, result_visibility?, show_answers?, depends_on_exam_id?, mode?, is_published? }` | `[Exam]` · `201` Exam |
| `GET/PUT/DELETE /teacher/exams/{uuid}` | — / same / — | Exam (with questions incl. `correct`) · `204` |
| `GET /teacher/exams/{uuid}/questions` · `POST` | — · `{ type, body, points?, sort_order?, options?[], correct?, book_ref? }` | `[Question]` · `201` |
| `PUT/DELETE /teacher/exams/{uuid}/questions/{question}` | same · — | Question · `204` |
| `GET /teacher/exams/{uuid}/submissions` | — | `[ { attempt_id, student, status, score, max_score, submitted_at } ]` |
| `POST /teacher/exams/{uuid}/attempts/{attempt}/grade` | `{ grades:[{ question_id, score, feedback? }] }` | `{ score, max_score, status }` |

## 8 · Teacher — student control (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/students` | `?q= &filter[status]= &page=` | `[ { uuid, name, phone, email, status, joined_at, enrolled_courses, gender, governorate, region, academic_year, education_type, guardian_phone } ]` |
| `POST /teacher/students` | `{ name, phone, email?, password?, gender?, governorate?, region?, academic_year?, education_type?, guardian_phone? }` | `201` student (+`temporary_password?`) |
| `GET /teacher/students/{uuid}` | — | full student + `summary:{ enrolled_courses, wallet_balance_minor, orders, lessons_completed }` |
| `PATCH /teacher/students/{uuid}` | `{ name?, phone?, email?, status?, gender?, governorate?, region?, academic_year?, education_type?, guardian_phone? }` | updated student |
| `POST /teacher/students/{uuid}/reset-password` | `{ password? }` | `{ uuid, temporary_password? }` (revokes sessions) |
| `GET /teacher/students/{uuid}/export` | — | `{ profile, membership, enrollments[], orders[], progress[], wallet_balance_minor }` |
| `DELETE /teacher/students/{uuid}` | — | `204` |
| `GET/POST /teacher/students/{uuid}/enrollments` · `DELETE …/{id}` | — · `{ course:"<uuid>" }` · — | list · `201` enrollment · `204` |
| `GET /teacher/students/{uuid}/wallet` | — | `{ balance_minor, currency, recent[] }` |
| `GET /teacher/students/{uuid}/wallet/ledger` | `?page=` | full ledger (paginated) |
| `POST /teacher/students/{uuid}/wallet/adjust` | `{ amount_minor, direction:"credit\|debit", reason? }` | `{ balance_minor }` (`422` if debit > balance) |
| `POST /teacher/students/{uuid}/wallet/set` | `{ balance_minor(≥0), reason? }` | `{ balance_minor }` (set exact; 0 clears) |
| `GET /teacher/students/{uuid}/orders` | — | `[ order ]` |
| `GET /teacher/students/{uuid}/progress` | — | `[ { lesson_id, lesson_title, watch_percent, last_position_sec, completed } ]` |
| `GET /teacher/students/{uuid}/activity` | — | `[ { type:"login\|playback\|order\|exam_attempt", at, meta } ]` |
| `POST /teacher/students/{uuid}/notify` | `{ message, title? }` | `201` `{ message }` |
| `GET/POST /teacher/students/{uuid}/parents` · `DELETE …/{parent:uuid}` | — · `{ name, phone, email?, relation?, password? }` · — | list · `201` · `204` |

## 9 · Teacher — reports, gamification, audit (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/reports/sales` | — | `{ earnings_minor, gross_minor, orders_paid }` |
| `GET /teacher/reports/students` | — | `{ students, courses }` |
| `GET/PUT /teacher/gamification` | — · `{ hide_ranking? }` | gamification settings |
| `GET /teacher/badges` · `POST` · `DELETE /{badge}` | — · `{ name, description?, threshold, icon? }` · — | `[badge]` · `201` · `204` |
| `GET /teacher/audit-logs` | `?page=` | `[ { action, actor, target_type, target_id, meta, created_at } ]` |

## 10 · Teacher — Centers (M12) (T)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /teacher/centers` | — | `[ { uuid, name, address, phone, is_active } ]` |
| `POST /teacher/centers` | `{ name, address?, phone?, is_active? }` | `201` center |
| `PUT /teacher/centers/{center:uuid}` · `DELETE …` | `{ name?, … }` · — | center · `204` |
| `GET /teacher/codes` | `?filter[status]= &filter[type]= &filter[batch]= &page=` | `[ { uuid, code, type, amount_minor, course_id, batch, status, redeemed_by, redeemed_at, expires_at } ]` |
| `POST /teacher/codes/batch` | `{ type:"wallet\|course", count:1-1000, amount_minor(if wallet), course_id(if course, owned), center_id?, batch?, expires_at? }` | `201` `[ code, … ]` |
| `POST /teacher/codes/{code:uuid}/disable` | — | code (status→disabled) |
| `GET /teacher/centers/{center:uuid}/attendance` | `?page=` | `[ { id, center_id, student:{uuid,name,phone}, course_id, attended_on, status, source } ]` |
| `POST /teacher/centers/{center:uuid}/attendance` | `{ students:[uuid,…], status?:"present\|absent", course_id?, attended_on? }` | `{ marked, skipped:[uuid,…] }` |
| `POST /teacher/centers/sync` | `{ events:[{ kind:"attendance\|redeem", external_ref, center_uuid?, student_uuid?/student_phone?, code?, attended_on?, status? }] }` | `[ { external_ref, kind, status:"applied\|duplicate\|failed", message?, grant? } ]` (idempotent) |

## 11 · Student — wallet, checkout, learning (A)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /wallet` · `GET /wallet/ledger` | — · `?page=` | `{ balance_minor, currency }` · ledger list |
| `POST /checkout/quote` · `POST /checkout/order` | `{ items:[{ course:"<uuid>" }] }` | totals · `201` `{ order }` |
| `POST /checkout/pay` | `{ order:"<uuid>", method:"wallet\|paymob\|fawry" }` | `{ status, payment, redirect_url? }` |
| `POST /codes/redeem` | `{ code }` | `{ code, type:"wallet\|course", amount_minor?/course_id? }` (`422` invalid/used) |
| `POST /media/lessons/{lesson}/playback` | `{ device_fingerprint? }` | `{ token, manifest_url, key_url, expires_at }` (`403` not enrolled · `409` no video) |
| `POST /lessons/{lesson}/progress` | `{ watch_percent, watch_seconds?, last_position_sec? }` | `{ watch_percent, completed }` |
| `GET /exams` | — | `[ Exam ]` (no `correct`) |
| `POST /exams/{uuid}/attempts` | — | `201` `{ attempt_id, questions[] }` |
| `POST /exams/{uuid}/attempts/{attempt}/submit` | `{ answers:{ "<qid>": <ans> } }` | `{ status, score?, max_score? }` |
| `GET /exams/{uuid}/attempts/{attempt}` | — | `{ status, score, max_score, answers[] }` |

## 12 · Student — dashboard `me/*` (A)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /me/courses` | — | `[ { course, progress_percent, … } ]` |
| `GET /me/resume` | — | `[ { lesson_id, lesson_title, course_id, watch_percent, last_position_sec } ]` |
| `GET /me/favorites` · `POST` · `DELETE /{course:uuid}` | — · `{ course:"<uuid>" }` · — | `[course]` · `201` · `204` |
| `GET /me/points` · `GET /me/badges` · `GET /leaderboard` | — | points · badges · ranked list (or `{hidden:true}`) |
| `GET /me/notifications` · `POST /me/notifications/{id}/read` | `?page=` · — | list · `{ read:true }` |
| `GET /me/activity` | — | `[ { lesson_id, lesson_title, watch_percent, last_position_sec, completed } ]` |

## 13 · Parent portal (PA)
| Endpoint | Response `data` |
|---|---|
| `GET /parent/children` | `[ { uuid, name, relation } ]` |
| `GET /parent/children/{student:uuid}/progress` | `[ progress ]` |
| `GET /parent/children/{student:uuid}/results` | `[ { exam, score, max_score, status, submitted_at } ]` |

## 14 · Media delivery, internal & webhooks (URL / HOOK)
| Endpoint | Auth | Response |
|---|---|---|
| `GET /media/stream/{token}` | URL | `.m3u8` playlist (key + segment URIs token-bound) |
| `GET /media/segment/{token}/{seg}` | URL | encrypted `.ts` (`video/mp2t`, range) |
| `GET /media/key/{token}` | URL | raw 16-byte AES key (after access re-check) |
| `PUT /media/upload/{uuid}` | URL (signed) | dev async-upload receiver (raw body / multipart `file`) |
| `GET /internal/media/authz` | URL | `?token=` → `204`/`403` (nginx auth_request) |
| `POST /internal/transcode/callback` | HOOK | header `X-Transcode-Secret` |
| `POST /webhooks/paymob` | HOOK | Paymob HMAC payload; idempotent on gateway txn id |

## 15 · Platform admin (ADM — no `X-Tenant`)
| Endpoint | Request | Response `data` |
|---|---|---|
| `GET /admin/tenants` · `POST` | `?page=` · `{ name, slug, status?, owner:{name,phone,email?,password?} }` | `[tenant]` · `201` |
| `GET/PUT /admin/tenants/{uuid}` | — · `{ name?, status? }` | tenant |
| `GET /admin/reports/overview` | — | `{ tenants, teachers, students, gmv_minor, … }` |
| `GET /admin/audit-logs` | `?page=` | cross-tenant audit entries |

---
> Response field lists show the significant fields. Deeper detail: the matching Resource class. Landing: LANDING_CONTRACT_V2.md.
