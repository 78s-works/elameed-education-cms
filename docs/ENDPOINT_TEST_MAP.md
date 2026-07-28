# Endpoint Test Map & Fix Plan

_Generated 2026-07-20. Full-surface live test of every `api/v1` route + a prioritized bug / feature-gap map._

> **2026-07-26 — harness re-greened (S1).** The smoke/seeder fixtures had drifted from the
> consolidated `DatabaseSeeder` (tenant slugs `ahmed-physics`→`farag-physics`/`sara-chemistry`,
> admin phone `01000000009`→`01000000000`, teacher `01500000001`→`0101000001`, student
> `01281000001`→`0101000101`; package/tenant/subscription/course counts). Fixtures reconciled and
> the harness is green again. This pass also uncovered and fixed a real defect — **B4** below
> (seeding `TRUNCATE` silently committed the test transaction). **Full suite: 224 tests / 935
> assertions green.** B2 (media preview 500) remains open and is tracked separately (S1).

## 1. How this was produced

- **Route surface:** `php artisan route:list` → **153 registered `api/v1` routes** across 14 modules
  (now **168** after the 2026-07-21 packages/bundles feature added 7 routes — 2 public `/bundles` +
  5 teacher `/teacher/bundles` — the 2026-07-22 custom-landing switch added 2 teacher
  `/teacher/custom-landing` routes, and the 2026-07-26 site-metadata store added 5 teacher
  `/teacher/meta` routes + 1 public `GET /tenant/landing/meta` bundle; all covered by
  `EndpointSmokeTest`, `PackageBundleTest`, `TeacherCustomLandingTest`, `TeacherMetaTest`,
  and `TenantLandingMetaTest`).
- **Live exercise:** a new data-driven test, [`tests/Feature/EndpointSmokeTest.php`](../tests/Feature/EndpointSmokeTest.php), seeds the demo academies, builds the remaining fixtures **through the real API** (so create endpoints are exercised too), then hits **every** route with the correct actor (platform-admin / teacher / student / parent / guest), tenant header, and a valid payload — recording the real HTTP status of each call. Report is written to the scratchpad (`smoke-results.json` / `smoke-summary.txt`).
- **Isolation:** runs on the `elameed_test` DB under `RefreshDatabase` — it never touches the live `elameed` data.
- **Classification:** `PASS` (expected status) · `WARN` (unexpected non-5xx worth a look) · `WARN5xx` (5xx on a known-stub route) · `FAIL` (5xx / auth hole on a normal route — a real defect).

**Baseline:** the pre-existing suite (185 tests) + this new smoke test = **186 tests / 781 assertions green**.

## 2. Headline result

| | |
|---|---|
| Endpoint calls executed | **149** (covers all 153 routes; delivery/HMAC routes probed with bad + happy-path tokens) |
| PASS | **147** |
| Real 5xx / auth holes (FAIL) | **1** → **found & fixed** in this pass |
| Known-stub 5xx (WARN5xx) | **2** (local media transcode, see B2) |

The API surface is **healthy**: every route is reachable, tenant-scoped, and returns the documented `{data}` / `{error}` envelope. Two issues were surfaced (one fixed here), plus the roadmap feature-gaps in §5.

## 3. Coverage by module

| Module | Calls | PASS | Notes |
|---|--:|--:|---|
| Tenancy (`/tenant/*` incl. `landing/meta`, `/teacher/profile|landing|access|custom-landing|meta`) | 17 | 17 | landing PUT + media upload OK; custom-landing toggle; site-metadata CRUD + public branding/meta bundle (`TeacherMetaTest`, `TenantLandingMetaTest`) |
| Auth / Identity (`/auth/*`, `/me`) | 8 | 8 | register→otp, login, forgot/reset, logout |
| Catalog (courses, categories, units, lessons, attachments, **packages**) | 31 | 31 | full CRUD, public catalogue, reviews, package CRUD + public browse |
| Media (playback, stream/segment/key, uploads, remote-videos, callbacks) | 20 | 20 | **B2** fixed — unready source now maps to a clean 409/503/422 (no 5xx) |
| Commerce / Wallet (quote/order/pay, webhook, **invoices**) | 9 | 9 | wallet purchase balances ledger; paid order renders an invoice PDF, downloadable via `/invoices` (list/detail/download) — `InvoicePdfTest` |
| Engagement (progress, favorites, points, badges, leaderboard, notifications, gamification) | 18 | 18 | |
| Assessment (exams, questions, attempts, grading) | 11 | 11 | author → attempt → submit → grade |
| Centers (centers, codes, attendance, sync, redeem) | 11 | 11 | batch-generate, disable, sync |
| Students (teacher control: CRUD, wallet, enrollments, activity, parents) | 18 | 18 | **B1** was here (unlink parent) — fixed |
| Parents portal (`/parent/*`) | 6 | 6 | children, progress, results |
| Reporting (`/teacher/reports/*`, audit-logs) | 3 | 3 | |
| Billing (`/teacher/subscription|packages`, `/admin/packages`, subscription) | 9 | 9 | admin CRUD + assign |
| Platform Admin (`/admin/tenants`, overview) | 6 | 6 | central-host gated |

## 4. Bugs found

### B1 — Unlinking a parent returns 500 on every call  ✅ FIXED
- **Route:** `DELETE /api/v1/teacher/students/{student:uuid}/parents/{parent:uuid}`
- **Severity:** High (feature completely broken; teacher can never remove a linked guardian).
- **Root cause:** the custom-key **child** binding (`{parent:uuid}`) makes Laravel auto-enable *scoped* implicit binding, so it tries to resolve `parent` through a `$student->parents()` relationship. `App\Models\User` has no `parents()` method → `BadMethodCallException` → 500. `StudentParentController@destroy` already filters the `ParentLink` delete by `(student, parent)`, so scoping is unnecessary.
- **Fix applied:** added `->withoutScopedBindings()` to the route in [`routes/api.php`](../routes/api.php). Smoke test now returns 200; full suite green.

### B2 — Playback / preview 500 when a lesson's video source is missing/not-ready  ✅ FIXED (2026-07-26)
- **Routes:** `POST /api/v1/media/lessons/{lesson}/playback`, `POST /api/v1/teacher/media/{media:uuid}/preview`
- **Severity:** Medium.
- **Root cause:** `PlaybackService::issue()` → [`HlsTranscoder::ensureRendition()`](../app/Modules/Media/Services/HlsTranscoder.php) transcodes **synchronously in the request** and threw a bare `RuntimeException` (`'Source file for this media is missing.'` / `'FFmpeg is not configured…'`). `ApiExceptionRenderer` didn't map `RuntimeException`, so the caller got an unstyled **HTTP 500** instead of a clean error envelope.
- **When it bit:** any lesson whose upload never completed, whose transcode failed, or (dev) has no FFmpeg. In the smoke test this fired because the fixture marks an asset `ready` with no file on disk.
- **Fix applied:** introduced typed [`MediaException`](../app/Modules/Media/Exceptions/MediaException.php) with stable machine codes + user-safe (translatable) messages, thrown from the transcoder's readiness guard **before** the transcode runs:
  - source missing / not transcodable → **409 `media_not_ready`**
  - transcode backend (FFmpeg) unavailable → **503 `media_processing_unavailable`**
  - transcode ran but failed → **422 `media_processing_failed`** (FFmpeg output persisted on the rendition for operators, never surfaced to the client)

  `ApiExceptionRenderer` now maps `MediaException` to the `{error:{code,message}}` envelope. Regression: [`tests/Feature/Media/MediaNotReadyTest.php`](../tests/Feature/Media/MediaNotReadyTest.php) (student 409, no-FFmpeg 503, teacher-preview 409, and asserts no `Storage`/`.mp4` leak). The smoke sweep is now **164/164 PASS** (was 1 WARN5xx here). Longer term this path still becomes a queued/pre-generated transcode (see §6).

### B3 — (historical, already resolved) `Unknown column 'locales'` on `PUT /teacher/landing`
- The dev log shows a 07-19 `SQLSTATE 42S22 … 'locales'` crash saving landing sections. Migration [`2026_07_19_000001_add_locales_to_teacher_profiles`](../database/migrations/2026_07_19_000001_add_locales_to_teacher_profiles.php) adds `locales` + `primary_locale`; the endpoint now passes. **No action needed** — listed only so the log entry isn't re-investigated.

### B4 — Seeding inside a transaction silently commits it → `codes/redeem` 500  ✅ FIXED (2026-07-26)
- **Surfaced at:** `POST /api/v1/codes/redeem` returned **500** in the full sweep (only), with `SQLSTATE[42000] 1305 SAVEPOINT trans2 does not exist`. Redeeming a disabled/expired/invalid code in isolation correctly returns **422** — the 500 only appeared after the seeder had run.
- **Root cause:** [`DatabaseSeeder::truncateAll()`](../database/seeders/DatabaseSeeder.php) cleared tables with `TRUNCATE`. `TRUNCATE` is DDL and **implicitly COMMITs** on MySQL, so running the seeder inside the `RefreshDatabase` test transaction ended that transaction. The connection was then in autocommit while the framework still believed it held a transaction (level 1). The first code path to `throw` inside a `DB::transaction` — `CodeRedemptionService::redeem` rejecting a non-redeemable code — issued `ROLLBACK TO SAVEPOINT trans2` against a savepoint that no longer existed, turning a clean 422 into a 500 (and leaking every write to the real DB, breaking test isolation).
- **Fix applied:** made `truncateAll()` transaction-aware — it uses `DELETE` (DML, transaction-safe) when `DB::transactionLevel() > 0`, and keeps the faster id-resetting `TRUNCATE` only for plain CLI seeding. Regression guards: `SeederSmokeTest::test_seeding_does_not_break_the_surrounding_transaction` (savepoint round-trip survives a seed) and `tests/Feature/Centers/RedeemCodeTest.php` (disabled/invalid → 422, active → 200).

## 5. Feature-requirement gaps (evidence-based)

Confirmed **absent from the route surface** (no controller/route exists) and tracked as remaining scope in `PROJECT_STATUS.md` §4. These are not bugs — they're unbuilt requirements:

| Requirement | Phase | Evidence |
|---|---|---|
| Coupons / discount codes | P1.5 | no `/coupons` routes |
| ~~Course **bundles** (packages)~~ | ✅ Done (2026-07-21) | `/teacher/bundles` CRUD + public `/bundles` + `bundle` checkout item; see [catalog.md](api/catalog.md#public--packages) & [commerce.md](api/commerce.md#packages-bundles) |
| **Fawry** payments; **real Paymob** go-live | P1.5 | only `PaymobGateway` stub + `/webhooks/paymob`; no `/webhooks/fawry` |
| Q&A / comments + teacher **forum** | P2 | no `/comments`, `/questions` (non-exam), `/forum` routes |
| **WhatsApp + email** channels, templates, bulk broadcast | P1.5/P2 | SMS live via `ConnekioSmsSender` (per-tenant, send-only) + `/teacher/sms-settings`; WhatsApp/email/DLR/broadcast not built |
| Teacher subscription **billing automation** (self-serve pay) | P1.5 | Billing (M03) is read-only + admin-assign; no teacher checkout for plans |
| **Excel / PDF** report export; import tooling | P2 | only `/teacher/students/{uuid}/export`; no `/reports/*/export` |
| **Support tickets** + help center | P2 | no `/tickets`, `/support` routes |
| **Custom domains** (Cloudflare for SaaS) | P1.5 | subdomain / `X-Tenant` only |
| **Bubble-sheet** exam mode (scan) | — | data fields only; no scan/ingest route |
| Content-protection **hardening** (device/session limits, abnormal-use alerts, **PDF watermark**) | P2 | video watermark done; the rest not enforced in playback authz |

**Partial / needs-verification (from project notes — worth a focused check):**
- **Timed-exam enforcement** — `duration_min` is stored/returned but server-side auto-submit on expiry is reportedly not enforced (`AttemptController@submit`).
- **Question-bank reuse** across exams — schema supports `exam_id = null` banks but no reuse endpoint.
- ~~**Membership re-check on student routes**~~ — ✅ **verified enforced (2026-07-26).** The `active`
  middleware ([`EnsureActiveMembership`](../app/Modules/Identity/Http/Middleware/EnsureActiveMembership.php))
  re-checks membership on **every** authenticated tenant request, so a removed/suspended student's
  token is denied immediately (tenant-scoped — the same person keeps access to other academies).
  Guarded by `HardeningTest` + `TeacherStudentManagementTest::test_suspended_student_is_blocked_from_tenant_endpoints`.

## 6. Production-readiness stubs (from `PROJECT_STATUS.md` §5 — not defects, launch blockers)

| Area | Current | Needed for prod |
|---|---|---|
| Payments | `PaymobGateway` stub | real Paymob merchant go-live + reconciliation, then Fawry |
| Media transcode | **lazy synchronous FFmpeg** in-request, local disk, single rendition | queued/pre-generated workers, **object storage (S3/MinIO)**, **nginx edge**, multi-bitrate (also fixes B2's blast radius) |
| Messaging | `LogSmsSender` (dev) + `ConnekioSmsSender` (per-tenant WE SMS, send-only) | delivery reports (DLR), WhatsApp/email providers + templates |
| Infra | local MySQL, `CORS *`, `queue=database/sync` | managed cloud, tight CORS, real queue workers |

## 7. Recommended deeper reviews (not covered by a smoke test)

A smoke test proves endpoints don't crash and respect auth; it does **not** prove business-logic correctness. These already have targeted tests (noted) but are the highest-value places for an adversarial review:
- **Money integrity** — ledger double-entry balance, idempotency keys, concurrent-checkout races, no negative balances. _(Covered by `CheckoutTest` + `HardeningTest`: replay of an op key posts once, balance is derived credits−debits, unbalanced posts rejected. `idempotency_key` is DB-UNIQUE.)_
- **Answer-key exposure** — student resources must never leak `correct`. _(Covered by `ExamsTest` + `HardeningTest`: list, in-attempt questions, and a graded result with `show_answers=off` all carry no key; the `review` key only appears when the teacher enables `show_answers` and the score is visible.)_
- **Tenant isolation on every route-model-bound model** — the sole guard on MySQL (no RLS). _(Covered per-module by cross-tenant 404 tests + `HardeningTest`, which asserts `SubstituteBindings` runs AFTER `ResolveTenant` — see [`bootstrap/app.php`](../bootstrap/app.php) priority list — so a valid uuid from another tenant 404s across course/exam/center bindings.)_
- **Wallet-adjustment audit** — every teacher-initiated balance mutation (`wallet.adjust`, `wallet.set`) writes an `audit_logs` row. _(Covered by `HardeningTest`; student-initiated posts are captured by the immutable double-entry ledger via `ref_type`/`ref_id`.)_
- **Rate-limit coverage** on sensitive mutating routes.

> **2026-07-26 — S1 security & correctness hardening pass.** The five flagged follow-ups
> (membership re-check, ledger idempotency/balance, answer-key non-exposure, cross-tenant IDOR via
> route-model binding, wallet-adjustment audit) were audited against the code and found already
> enforced; each is now locked in by [`tests/Feature/Security/HardeningTest.php`](../tests/Feature/Security/HardeningTest.php)
> (7 tests) so a regression in any of them fails CI. No new production defects were found in this pass.

## 8. Re-running

```bash
php artisan test --filter=EndpointSmokeTest     # full-surface sweep, writes smoke-summary.txt (to the system temp dir)
php artisan test                                 # whole suite (224 tests / 935 assertions green as of 2026-07-26)
```

The smoke test is a permanent regression asset: add a line per new route and it will flag any future endpoint that starts returning 5xx or an auth hole.
