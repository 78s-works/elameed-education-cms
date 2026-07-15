# Media Module

Protected video for the Elameed Education platform. The module ships **two interchangeable delivery backends**, selected by the `MEDIA_PROVIDER` env var:

- **Self-hosted (`MEDIA_PROVIDER=local`, default)** — an encrypted-HLS pipeline the platform runs itself. The teacher uploads a source video to a **private disk** (never web-reachable). On first play, a **per-viewer** rendition is transcoded to **AES-128-encrypted HLS** with a **burned-in watermark** carrying the viewer's name/phone. Delivery is entirely token-gated: manifest, segments, and AES key are each served from routes that carry a short-lived token in the URL.
- **Remote OVH "Media Host" (`MEDIA_PROVIDER=remote`)** — video bytes go straight to an external Media Host (OVH). The platform holds only control-plane state (versions, upload sessions) and mints short-lived signed JWTs for playback against the host. Processing status arrives via an **HMAC-signed callback**. See `docs/MEDIA_HOST_API_v1.md`.

Both providers reuse the same enrollment/access gate (`EnrollmentService`) and the same lesson linkage (`lessons.video_asset_id`), so authorization logic is never duplicated.

### Two auth models in this module
1. **Tenant + Sanctum bearer** — the `authorize`, teacher, and teacher-remote endpoints. Tenant is resolved from the `Host` header (dev/tooling may override with `X-Tenant: <slug>`), then Sanctum bearer + active membership.
2. **Platform-host (no tenant middleware)** — the key/stream/segment, internal, callback, and signed-upload endpoints run **outside** the `tenant` group. Auth is a **token in the URL**, an **HMAC signature**, or a **shared secret** — never a bearer/tenant. These are grouped under `Route::prefix('v1')` with no `tenant` middleware because a `<video>`/hls.js request can't send bearer headers, and the OVH host can't authenticate as a tenant user.

### Conventions
- Base prefix `/api/v1`. JSON success is `{ "data": ... }`; JSON error is `{ "error": { code, message, details } }`. Timestamps are ISO-8601 UTC.
- **Streaming responses are NOT JSON** — the manifest is an m3u8 playlist, segments are binary `.ts`, the key is raw bytes. Content-Types are documented per endpoint.

---

## Models

| Model | Table role | Notes |
|---|---|---|
| `MediaAsset` | The video/PDF/file/link. Tenant-scoped, UUID-bound. | `type`, `status`, `provider` (`local`\|`remote`), `current_version_id`, `source_key`, `hls_path`, `thumbnail_url`, `duration_sec`, `downloadable`. Videos referenced by `lessons.video_asset_id`. |
| `MediaVersion` | One version of a **remote** video on the Media Host. | `version` (int, incrementing), `state`, `host_video_id`, `playback_id`, `ready_at`. Asset's `current_version_id` only advances when a version reaches `ready` (atomic replacement). |
| `MediaUploadSession` | An authorized, resumable **remote** upload intent. | Unique `idempotency_key` → a retried "start upload" returns the SAME intent. Holds `host_upload_id`, `upload_url`, `protocol` (tus), `max_bytes`, `expires_at`. |
| `MediaRendition` | A per-student, watermark-burned, **AES-128-encrypted HLS** transcode (self-hosted). | `hls_dir`, `enc_key` (encrypted at rest, released only via key endpoint), `iv`, `segment_count`, `status`. |
| `PlaybackSession` | A short-lived, hashed playback grant. | `token_hash` (sha256), `scope` (`student`\|`preview`\|`remote`), `expires_at`, `revoked_at`, `device_fingerprint`, `ip`. |
| `MediaCallbackEvent` | Replay/idempotency ledger for signed remote callbacks. | **NOT** tenant-scoped — consulted at ingest before a tenant is resolved. Dedupes by host `event_id`. |

## Media lifecycle / states

**`MediaStatus`** (asset) — attachments (`pdf`/`file`/`link`) are `ready` on creation; videos move `uploading → transcoding → ready | failed`.

**`MediaType`** — `hls_video` (self-hosted pipeline output), `pdf`, `file`, `link`.

**`MediaVersionState`** (remote versions) — a guarded state machine (`canTransitionTo` rejects out-of-order callbacks):

```
pending → uploading → uploaded → processing → ready → (replacing | quarantined)
   (any of the above) → failed → processing (retry)
   ready/any → quarantined → (ready [restore] | purged [terminal])
```

- `pending` record created, no upload · `uploading` bytes in flight · `uploaded` host verified bytes · `processing` host transcoding · `ready` servable · `failed` retryable · `replacing` superseded by a newer ready version · `quarantined` access frozen · `purged` permanently removed (terminal).
- **Atomic replacement:** a replace/new upload creates a NEW version; the old version keeps serving until the new one reaches `ready`, at which point `makeCurrent` promotes it and demotes the previous to `replacing`.

---

## Endpoints

### Platform-host (token / HMAC / shared secret)

All endpoints below live in the **non-tenant** `Route::prefix('v1')` group — no `Host`-based tenant resolution, no Sanctum bearer.

---

#### `GET /v1/media/stream/{token}`
**Purpose:** Return the encrypted HLS playlist (`index.m3u8`) with the `#EXT-X-KEY` URI and each `seg_N.ts` URI rewritten to carry this token, so the player fetches the key and segments from the token-gated endpoints. Raw MP4 is never referenced.
**Auth:** 🔓 token-in-URL
**Middleware:** none (outside `tenant`)

**Request headers**
| Header | Required | Example |
|---|---|---|
| Accept | no | `application/vnd.apple.mpegurl` |

(Token is in the URL, not a header.)

**Path params**
| Param | Type | Notes |
|---|---|---|
| `token` | string | The 64-char playback token from `authorize`/`preview`. |

**Request body:** None
**Response:** `200` — **m3u8 manifest** (`Content-Type: application/vnd.apple.mpegurl`, `Cache-Control: no-store`). NOT JSON.
**Errors:** `403` invalid/expired token; `409` rendition not ready.

---

#### `GET /v1/media/segment/{token}/{segment}`
**Purpose:** Serve one AES-128-encrypted `.ts` segment (range-enabled). Useless without the key.
**Auth:** 🔓 token-in-URL
**Middleware:** none. Route constraint: `segment` must match `seg_[0-9]+\.ts`.

**Path params**
| Param | Type | Notes |
|---|---|---|
| `token` | string | Playback token. |
| `segment` | string | Must match `seg_\d+\.ts` (e.g. `seg_003.ts`); anything else → `404`. |

**Request body:** None
**Response:** `200` — **binary `.ts`** (`Content-Type: video/mp2t`). NOT JSON.
**Errors:** `403` invalid/expired token; `404` segment name doesn't match pattern or file missing; `409` rendition not ready.

---

#### `GET /v1/media/key/{token}`
**Purpose:** Release the raw 16-byte AES-128 content key — **only after re-checking access** (enrollment for `student`, staff membership for `preview`). A leaked token stops working the moment access lapses.
**Auth:** 🔓 token-in-URL
**Middleware:** none

**Path params**
| Param | Type | Notes |
|---|---|---|
| `token` | string | Playback token. |

**Request body:** None
**Response:** `200` — **raw AES key bytes** (`Content-Type: application/octet-stream`, `Cache-Control: no-store`). NOT JSON.
**Errors:** `403` token invalid/expired **or access re-check failed**; `409` rendition not ready.

---

#### `GET /v1/internal/media/authz`
**Purpose:** Internal media-tier authorization — the nginx `auth_request` target that validates a playback token per manifest/segment request at the edge. Not client-facing.
**Auth:** 🔓 token (query param or header)
**Middleware:** none

**Query / header params**
| Source | Name | Notes |
|---|---|---|
| query | `token` | Playback token; OR |
| header | `X-Playback-Token` | Same token if not in query. |

**Response:** `204 No Content` if the token is currently valid; `403` otherwise. Empty body.

---

#### `POST /v1/internal/transcode/callback`
**Purpose:** The self-hosted FFmpeg transcode worker reports `ready`/`failed` for a self-hosted asset and (optionally) writes the `hls_path` + `renditions`.
**Auth:** 🔑 shared secret header
**Middleware:** none

**Request headers**
| Header | Required | Example |
|---|---|---|
| X-Transcode-Secret | **yes** | must `hash_equals` `config('media.transcode_secret')` |
| Content-Type | yes | `application/json` |

**Request body**
```json
{
  "media_uuid": "9f1c...-uuid",
  "status": "ready",
  "hls_path": "media/hls/9f1c.../index.m3u8",
  "renditions": [
    { "height": 720, "bandwidth": 2500000, "dir": "720p" },
    { "height": 480, "bandwidth": 1200000, "dir": "480p" }
  ]
}
```
Fields: `media_uuid` (required), `status` (required, `in:ready,failed`), `hls_path` (nullable), `renditions` (nullable array).

**Response:** `200` — `{ "data": { "status": "ready" } }`
**Errors:** `403` bad `X-Transcode-Secret`; `404` unknown asset; `422` validation.

---

#### `POST /v1/media/callbacks/processing`
**Purpose:** Ingest a signed processing callback from the **remote** OVH Media Host (state change → `ready`/`failed`). Verifies HMAC signature + timestamp skew, dedupes by event id, then applies the guarded state transition. Never logs the raw body or signature.
**Auth:** 🔐 HMAC signature (no tenant/bearer)
**Middleware:** `throttle:120,1` (outside `tenant`)

**Request headers**
| Header | Required | Example / Notes |
|---|---|---|
| X-Media-Signature | **yes** | `base64(hmac_sha256(timestamp + "." + rawBody, callback_secret))` |
| X-Media-Timestamp | **yes** | unix seconds; rejected if skew > 300s |
| X-Media-Event-Id | **yes** | unique per event; replay-protection key |
| Content-Type | yes | `application/json` |

**Request body** (host-defined; consumed by `RemoteVideoService::applyCallback`)
```json
{
  "type": "video.processing.completed",
  "video_ref": "asset-uuid",
  "version": 2,
  "state": "ready",
  "host_video_id": "vid_abc",
  "playback_id": "pb_xyz",
  "thumbnail_url": "https://host/.../thumb.jpg",
  "duration_sec": 634
}
```
On `state: "failed"` the payload carries `error.message`.

**Response:** `200` — `{ "received": true }`, or `{ "received": true, "duplicate": true }` for a replayed `X-Media-Event-Id`.
**Errors:** `403` (`AccessDeniedHttpException`) missing/stale timestamp or invalid signature; `404` unknown `video_ref`/version; `409` unsupported callback state.

---

#### `PUT | POST /v1/media/upload/{uuid}`
**Purpose:** Local-dev receiver for the async self-hosted pipeline. The client PUTs the raw file (or multipart `file`) to the **signed** `upload_url` returned by `startUpload`. Stores the bytes on the private disk, marks the asset `ready`, links the lesson. In production a real object-storage presigned target replaces this route.
**Auth:** 🔓 signed URL (the signature is the auth; no tenant/bearer)
**Middleware:** `signed` (outside `tenant`). Route name: `media.upload.receive`.

**Path params**
| Param | Type | Notes |
|---|---|---|
| `uuid` | string | `MediaAsset` uuid (resolved with global scopes off). |

**Request body:** raw file bytes, or multipart field `file`. Empty body → `422`.
**Response:** `200` — `{ "data": <MediaAssetResource> }` (see resource shape below).
**Errors:** `403` bad/expired signature; `404` unknown uuid; `422` empty upload body.

---

### Authenticated playback

Tenant-resolved (`Host` / `X-Tenant`) + `auth:sanctum` + `active` membership.

---

#### `POST /v1/media/lessons/{lesson}/playback`
**Purpose:** Self-hosted playback authorization. Re-checks access (enrollment or free-preview), ensures the caller's per-student encrypted+watermarked rendition exists (transcoded on first play), opens a short-lived `PlaybackSession`, and returns the token + delivery URLs.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | tenant domain (or `X-Tenant: <slug>` in dev) |
| Authorization | yes | `Bearer <sanctum-token>` |
| Accept | yes | `application/json` |
| Content-Type | if body | `application/json` |

**Path params:** `lesson` — Lesson id (tenant-scoped by route binding; cross-tenant → `404`).

**Request body** (optional)
```json
{ "device_fingerprint": "opaque-client-id" }
```

**Response:** `200`
```json
{
  "data": {
    "token": "<64-char token>",
    "manifest_url": "https://.../api/v1/media/stream/<token>",
    "key_url": "https://.../api/v1/media/key/<token>",
    "expires_at": "2026-07-15T12:00:00+00:00"
  }
}
```
`expires_at` is `now + media.playback_ttl` (default 120s).
**Errors:** `403` no access to the lesson; `409` lesson has no ready video / rendition not ready.

---

#### `POST /v1/media/remote/lessons/{lesson}/playback`
**Purpose:** Remote (OVH) playback authorization. Active when `MEDIA_PROVIDER=remote`. Re-checks tenant + enrollment, confirms the asset's current version is `ready`, binds a `PlaybackSession`, and mints a short-lived signed JWT bound to tenant/user/video/version/session. Returns the host playback URL.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers:** same as above (`Host`/`X-Tenant`, `Bearer`, `Accept`).

**Path params:** `lesson` — Lesson id (tenant-scoped).

**Request body** (optional)
```json
{ "device_fingerprint": "opaque-client-id" }
```

**Response:** `200`
```json
{
  "data": {
    "status": "ready",
    "playback_url": "https://media-host/v1/playback/<playback_id>/index.m3u8?token=<jwt>",
    "thumbnail_url": "https://media-host/.../thumb.jpg",
    "token": "<jwt>",
    "expires_at": "2026-07-15T12:15:00+00:00",
    "session": "1234"
  }
}
```
JWT TTL is `media.host.playback_token_ttl` (default 900s).
**Errors:** `403` no access; `409` remote provider not enabled / lesson has no remote video / version not ready; `503` Media Host not configured.

---

### Teacher · Self-hosted media

`role:teacher` inside the tenant + Sanctum + active. Models bind by `{media:uuid}` (tenant-scoped; cross-tenant → `404`).

**`MediaAssetResource` shape** (returned by these endpoints):
```json
{
  "uuid": "…",
  "type": "hls_video",
  "status": "ready",
  "title": "Lesson 1 Video",
  "url": null,
  "thumbnail_url": "https://.../thumb.jpg",
  "downloadable": false,
  "duration_sec": 634
}
```

---

#### `POST /v1/teacher/media/uploads`
**Purpose:** Start a self-hosted video upload. **Direct** path (multipart `file`) stores the source privately and marks it `ready`. **Async** path (no file, `filename` required) returns a signed `upload` target for a direct-to-storage PUT, then `.../complete`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** `Host`/`X-Tenant`, `Authorization: Bearer`, `Accept`; `Content-Type: application/json` (async) or `multipart/form-data` (direct).

**Request body**
| Field | Rules |
|---|---|
| `lesson_id` | nullable integer (must resolve in this tenant, else `422`) |
| `title` | nullable string ≤255 |
| `filename` | required **without** `file`; string ≤255 |
| `file` | sometimes; mimetypes `video/mp4,video/quicktime,video/webm,video/x-matroska`; max 1048576 KB (~1 GB) |

Async example: `{ "lesson_id": 42, "title": "Lesson 1 Video", "filename": "lesson1.mp4" }`

**Response:** `201`
```json
{
  "data": {
    "media": { "...MediaAssetResource..." },
    "upload": { "upload_url": "https://.../api/v1/media/upload/<uuid>?signature=…", "method": "PUT" }
  }
}
```
Direct upload returns `"upload": null` and `media.status: "ready"`. Client always reads the id at `data.media.uuid`.
**Errors:** `422` validation / lesson not in tenant.

---

#### `POST /v1/teacher/media/uploads/{media:uuid}/complete`
**Purpose:** Mark an async-uploaded asset `ready` and link its lesson (idempotent — no-op if already `ready`).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `media` — MediaAsset uuid.
**Request body:** None
**Response:** `200` — `{ "data": <MediaAssetResource> }`
**Errors:** `404` unknown uuid (or cross-tenant).

---

#### `GET /v1/teacher/media/{media:uuid}`
**Purpose:** Status snapshot of a self-hosted asset.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `media` — MediaAsset uuid.
**Response:** `200` — `{ "data": <MediaAssetResource> }` (returned directly; the resource wraps in `data`).
**Errors:** `404` unknown/cross-tenant.

---

#### `POST /v1/teacher/media/{media:uuid}/preview`
**Purpose:** Teacher self-preview through the **same** encrypted-HLS flow as students, watermarked with the teacher's own name (preview copies stay traceable). Requires an active teacher/assistant membership.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `media` — MediaAsset uuid.
**Request body:** None
**Response:** `200`
```json
{
  "data": {
    "token": "<64-char token>",
    "manifest_url": "https://.../api/v1/media/stream/<token>",
    "key_url": "https://.../api/v1/media/key/<token>",
    "expires_at": "2026-07-15T12:00:00+00:00"
  }
}
```
**Errors:** `403` not an active teacher/assistant; `409` media has no source to preview.

---

### Teacher · Remote videos

`role:teacher` + Sanctum + active, **and** active only when `MEDIA_PROVIDER=remote` (otherwise the service throws `409`; `503` if the host is unconfigured). Bound models (`{media:uuid}`, `{session}`, `{version}`) are tenant-scoped → cross-tenant ids resolve to `404`.

---

#### `POST /v1/teacher/remote-videos/uploads`
**Purpose:** Create a new remote video (version 1) plus an authorized, resumable upload intent against the Media Host. **Idempotent** on `idempotency_key` (retry returns the same intent).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`
**Validation:** `StartRemoteUploadRequest`

**Request body**
| Field | Rules |
|---|---|
| `lesson_id` | nullable integer (must resolve in tenant) |
| `title` | nullable string ≤255 |
| `filename` | **required** string ≤255 |
| `size_bytes` | **required** integer ≥1 (must be ≤ `media.host.max_upload_bytes` if set) |
| `content_type` | **required**, `in:video/mp4,video/quicktime,video/webm,video/x-matroska` |
| `checksum_sha256` | nullable string, exactly 64 chars |
| `idempotency_key` | nullable string ≤64 (server generates a UUID if omitted) |

**Response:** `201`
```json
{
  "data": {
    "video": "<asset-uuid>",
    "version": 1,
    "state": "uploading",
    "upload": {
      "upload_session": "42",
      "upload_id": "<host-upload-id>",
      "protocol": "tus",
      "upload_url": "https://media-host/.../upload",
      "max_bytes": 2147483648,
      "expires_at": "2026-07-15T13:00:00+00:00"
    }
  }
}
```
**Errors:** `409` provider not enabled / `422` validation (incl. size over max); `503` host not configured.

---

#### `POST /v1/teacher/remote-videos/uploads/{session}/complete`
**Purpose:** Client reports the direct upload finished. Verifies received bytes with the host, sets the version `uploaded`, and dispatches `StartRemoteProcessingJob` to begin async transcoding.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `session` — MediaUploadSession id (tenant-scoped).
**Request body:** None
**Response:** `200`
```json
{ "data": { "upload_id": "<host-upload-id>", "state": "uploaded", "video": "<asset-uuid>" } }
```
**Errors:** `404` unknown session; `409` provider not enabled; `503` host down.

---

#### `POST /v1/teacher/remote-videos/{media:uuid}/replace`
**Purpose:** Upload a **new version** of an existing remote video. The current version keeps serving until the new one reaches `ready` (atomic swap). Idempotent on `idempotency_key`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`
**Validation:** `StartRemoteUploadRequest` (same fields as create).

**Path params:** `media` — MediaAsset uuid.
**Response:** `201` — same shape as create, with the incremented `version`.
**Errors:** `409` provider not enabled / asset is not a remote video; `422` validation; `503` host down.

---

#### `POST /v1/teacher/remote-videos/versions/{version}/retry`
**Purpose:** Deliberately retry a **failed** transcode — a new processing attempt.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `version` — MediaVersion id.
**Request body:** None
**Response:** `200` — `{ "data": { "version": 2, "state": "processing" } }`
**Errors:** `409` version is not in `failed` state / provider not enabled.

---

#### `POST /v1/teacher/remote-videos/versions/{version}/quarantine`
**Purpose:** Freeze access to a version on the host (transition → `quarantined`).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `version` — MediaVersion id.
**Response:** `200` — `{ "data": { "version": 2, "state": "quarantined" } }`
**Errors:** `409` invalid state transition / provider not enabled; `503` host down.

---

#### `POST /v1/teacher/remote-videos/versions/{version}/restore`
**Purpose:** Restore a quarantined version back to `ready`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `version` — MediaVersion id.
**Response:** `200` — `{ "data": { "version": 2, "state": "ready" } }`
**Errors:** `409` invalid transition (restore is only valid from `quarantined`); `503` host down.

---

#### `DELETE /v1/teacher/remote-videos/versions/{version}`
**Purpose:** Permanently purge a version on the host (terminal). Only valid from `quarantined`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `version` — MediaVersion id.
**Response:** `200` — `{ "data": { "version": 2, "state": "purged" } }`
**Errors:** `409` not `quarantined` (invalid transition); `503` host down.

---

#### `GET /v1/teacher/remote-videos/{media:uuid}`
**Purpose:** Status snapshot of a remote video and all its versions.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path params:** `media` — MediaAsset uuid.
**Response:** `200`
```json
{
  "data": {
    "video": "<asset-uuid>",
    "provider": "remote",
    "current_version_id": 7,
    "thumbnail_url": "https://media-host/.../thumb.jpg",
    "versions": [
      {
        "version": 1,
        "state": "replacing",
        "host_video_id": "vid_abc",
        "playback_id": "pb_xyz",
        "thumbnail_url": "https://…",
        "duration_sec": 634,
        "ready_at": "2026-07-14T09:00:00+00:00"
      }
    ]
  }
}
```
**Errors:** `404` unknown/cross-tenant uuid.
