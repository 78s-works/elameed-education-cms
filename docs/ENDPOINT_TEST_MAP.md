# Endpoint Test Map & Fix Plan

_Generated 2026-07-20. Full-surface live test of every `api/v1` route + a prioritized bug / feature-gap map._

## 1. How this was produced

- **Route surface:** `php artisan route:list` → **153 registered `api/v1` routes** across 14 modules
  (now **160** after the 2026-07-21 packages/bundles feature added 7 routes — 2 public `/bundles` +
  5 teacher `/teacher/bundles`; all covered by `EndpointSmokeTest` and `PackageBundleTest`).
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
| Tenancy (`/tenant/*`, `/teacher/profile|landing|access`) | 9 | 9 | landing PUT + media upload OK |
| Auth / Identity (`/auth/*`, `/me`) | 8 | 8 | register→otp, login, forgot/reset, logout |
| Catalog (courses, categories, units, lessons, attachments, **packages**) | 31 | 31 | full CRUD, public catalogue, reviews, package CRUD + public browse |
| Media (playback, stream/segment/key, uploads, remote-videos, callbacks) | 20 | 18 | 2× **B2** stub 500 (source not transcoded) |
| Commerce / Wallet (quote/order/pay, webhook) | 6 | 6 | wallet purchase balances ledger |
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

### B2 — Playback / preview 500 when a lesson's video source is missing/not-ready  ⚠️ OPEN
- **Routes:** `POST /api/v1/media/lessons/{lesson}/playback`, `POST /api/v1/teacher/media/{media:uuid}/preview`
- **Severity:** Medium.
- **Root cause:** `PlaybackService::issue()` → [`HlsTranscoder::ensureRendition()`](../app/Modules/Media/Services/HlsTranscoder.php#L50-L55) transcodes **synchronously in the request** and throws a bare `RuntimeException` (`'Source file for this media is missing.'` / `'FFmpeg is not configured…'`). `ApiExceptionRenderer` doesn't map `RuntimeException`, so the student gets an unstyled **HTTP 500** instead of a clean error envelope.
- **When it bites:** any lesson whose upload never completed, whose transcode failed, or (dev) has no FFmpeg. In the smoke test this fires because the fixture marks an asset `ready` with no file on disk.
- **Recommended fix:** before invoking the transcoder, guard on asset readiness/source existence and return a mapped error (e.g. `409 media_not_ready` / `503 media_unavailable`); or map `RuntimeException` from the media namespace in `ApiExceptionRenderer`. Longer term this path becomes a queued/pre-generated transcode (see §6) — the request should only ever read an already-ready rendition.

### B3 — (historical, already resolved) `Unknown column 'locales'` on `PUT /teacher/landing`
- The dev log shows a 07-19 `SQLSTATE 42S22 … 'locales'` crash saving landing sections. Migration [`2026_07_19_000001_add_locales_to_teacher_profiles`](../database/migrations/2026_07_19_000001_add_locales_to_teacher_profiles.php) adds `locales` + `primary_locale`; the endpoint now passes. **No action needed** — listed only so the log entry isn't re-investigated.

## 5. Feature-requirement gaps (evidence-based)

Confirmed **absent from the route surface** (no controller/route exists) and tracked as remaining scope in `PROJECT_STATUS.md` §4. These are not bugs — they're unbuilt requirements:

| Requirement | Phase | Evidence |
|---|---|---|
| Coupons / discount codes | P1.5 | no `/coupons` routes |
| ~~Course **bundles** (packages)~~ | ✅ Done (2026-07-21) | `/teacher/bundles` CRUD + public `/bundles` + `bundle` checkout item; see [catalog.md](api/catalog.md#public--packages) & [commerce.md](api/commerce.md#packages-bundles) |
| **Fawry** payments; **real Paymob** go-live | P1.5 | only `PaymobGateway` stub + `/webhooks/paymob`; no `/webhooks/fawry` |
| Q&A / comments + teacher **forum** | P2 | no `/comments`, `/questions` (non-exam), `/forum` routes |
| **WhatsApp + email** channels, templates, bulk broadcast | P1.5/P2 | `LogSmsSender` only; no `/broadcast` route |
| Teacher subscription **billing automation** (self-serve pay) | P1.5 | Billing (M03) is read-only + admin-assign; no teacher checkout for plans |
| **Excel / PDF** report export; import tooling | P2 | only `/teacher/students/{uuid}/export`; no `/reports/*/export` |
| **Support tickets** + help center | P2 | no `/tickets`, `/support` routes |
| **Custom domains** (Cloudflare for SaaS) | P1.5 | subdomain / `X-Tenant` only |
| **Bubble-sheet** exam mode (scan) | — | data fields only; no scan/ingest route |
| Content-protection **hardening** (device/session limits, abnormal-use alerts, **PDF watermark**) | P2 | video watermark done; the rest not enforced in playback authz |

**Partial / needs-verification (from project notes — worth a focused check):**
- **Timed-exam enforcement** — `duration_min` is stored/returned but server-side auto-submit on expiry is reportedly not enforced (`AttemptController@submit`).
- **Question-bank reuse** across exams — schema supports `exam_id = null` banks but no reuse endpoint.
- **Membership re-check on student routes** — a token stays valid until expiry after a student is removed/suspended mid-session.

## 6. Production-readiness stubs (from `PROJECT_STATUS.md` §5 — not defects, launch blockers)

| Area | Current | Needed for prod |
|---|---|---|
| Payments | `PaymobGateway` stub | real Paymob merchant go-live + reconciliation, then Fawry |
| Media transcode | **lazy synchronous FFmpeg** in-request, local disk, single rendition | queued/pre-generated workers, **object storage (S3/MinIO)**, **nginx edge**, multi-bitrate (also fixes B2's blast radius) |
| Messaging | `LogSmsSender` (log only) | real SMS/WhatsApp/email providers + templates |
| Infra | local MySQL, `CORS *`, `queue=database/sync` | managed cloud, tight CORS, real queue workers |

## 7. Recommended deeper reviews (not covered by a smoke test)

A smoke test proves endpoints don't crash and respect auth; it does **not** prove business-logic correctness. These already have targeted tests (noted) but are the highest-value places for an adversarial review:
- **Money integrity** — ledger double-entry balance, idempotency keys, concurrent-checkout races, no negative balances. _(Covered by `CheckoutTest`; extend with a concurrency test.)_
- **Answer-key exposure** — student resources must never leak `correct`. _(Covered by `ExamsTest::answer-key-not-leaked`.)_
- **Tenant isolation on every route-model-bound model** — the sole guard on MySQL (no RLS). _(Covered per-module by cross-tenant 404 tests; the B1 scoped-binding class of bug is worth auditing on all nested routes.)_
- **Rate-limit coverage** on sensitive mutating routes.

## 8. Re-running

```bash
php artisan test --filter=EndpointSmokeTest     # full-surface sweep, writes smoke-summary.txt
php artisan test                                 # whole suite (186 tests)
```

The smoke test is a permanent regression asset: add a line per new route and it will flag any future endpoint that starts returning 5xx or an auth hole.
