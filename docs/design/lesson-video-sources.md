# Design Note — Lesson Video Sources (Upload **or** YouTube, teacher-toggled)

**Status:** ✅ Implemented (2026-07-20).
**Scope:** Catalog (`lessons`) + Media modules.
**Author context:** Requested capability — a lesson may hold **both** a protected uploaded video and a YouTube link; the teacher toggles which one is visible to students.

> **As built** — the two open decisions in §7 were resolved with the recommended
> defaults: (1) storage = a plain `lessons.youtube_url` column (no new MediaAsset
> type); (2) YouTube gating = API-gate the URL behind enrollment/free-preview,
> and embed via `youtube-nocookie.com`. New columns: `lessons.youtube_url`,
> `lessons.active_video_source` (`upload|youtube`, default `upload`). Enum
> `App\Modules\Catalog\Enums\VideoSource`; URL parsing in `App\Support\Youtube`.
> Playback branches in both `PlaybackController` and `RemotePlaybackController`
> via `PlaybackService::issueYoutube()`. Covered by `tests/Feature/Media/YoutubeLessonTest.php`.

> The "today" baseline in §1 describes behaviour *before* this change.

---

## 1. Today (baseline)

- A lesson references a **single** video via `lessons.video_asset_id` → a `MediaAsset` of type `hls_video`.
- That asset is produced only by the protected pipeline (self-hosted encrypted HLS with per-student burned-in watermark, or the remote OVH host) — selected by `MEDIA_PROVIDER`.
- **YouTube is not a lesson-video source.** A YouTube URL appears only as `courses.promo_video_url`, a public marketing teaser — never as lesson content.
- The `link` `MediaType` exists but is used for lesson **attachments** (external references), explicitly excluded from the lesson video.

**Conclusion:** "upload a lesson video" works; "use a YouTube link as the lesson video" is unbuilt. This note adds the second source and a selector.

---

## 2. Target behaviour

A lesson can carry **two independent video sources**, either/both populated:

| Source | Storage | Protection |
|---|---|---|
| **A — Upload** | existing `video_asset_id` → protected `hls_video` asset | Encrypted HLS + per-student watermark + access-gated AES key |
| **B — YouTube** | `youtube_url` on the lesson | None (see §6) |

A selector, **`active_video_source`** (`upload` \| `youtube`), decides which one students receive. The inactive source stays stored but is **never exposed** to students.

Teachers see and manage both slots; students see only the active one.

---

## 3. Data model change (`lessons` table)

Recommended lean version — a plain URL column (mirrors the existing `courses.promo_video_url` precedent):

| Field | Type | Notes |
|---|---|---|
| `video_asset_id` | *(existing)* nullable FK | The uploaded protected video. Unchanged. |
| `youtube_url` | **new** nullable string ≤2048, validated | The YouTube alternative. |
| `active_video_source` | **new** enum `upload\|youtube`, default `upload` | The teacher toggle. |

Derived semantics:

- `has_video` = **the active source is populated** — i.e.
  `(active_video_source = upload AND video_asset_id present AND its asset is ready)`
  **OR** `(active_video_source = youtube AND youtube_url present)`.
  (Today `has_video` is just `video_asset_id !== null`; this changes it to be source-aware.)

> **Alternative considered:** model the YouTube link as a `MediaAsset` (new
> `youtube` `MediaType`) plus a second FK on the lesson. Pros: consistency with
> the media abstraction (title/thumbnail/status, appears in media listings/reports).
> Cons: heavier — new enum value, migration, resource branch, and a status
> concept that doesn't really apply (a link is always "ready"). Deferred unless
> YouTube lessons need to surface in media tooling. **Open decision — see §7.**

---

## 4. Authoring API (teacher)

Two viable shapes; pick one:

**(a) Fold into the existing lesson update** — `PUT /v1/teacher/units/{unit}/lessons/{lesson}` additionally accepts:

| Field | Rules |
|---|---|
| `youtube_url` | nullable, url ≤2048, host must be a YouTube domain (`youtube.com` watch/embed or `youtu.be`) |
| `active_video_source` | nullable, enum `upload\|youtube` |

**(b) Dedicated routes** (clearer intent, keeps the toggle atomic):

- `PUT /v1/teacher/lessons/{lesson}/youtube` `{ "url": "..." }` — set/replace the link.
- `DELETE /v1/teacher/lessons/{lesson}/youtube` — clear it.
- `PUT /v1/teacher/lessons/{lesson}/video-source` `{ "source": "upload" | "youtube" }` — toggle.

**Validation rules (either shape):**

- `active_video_source = youtube` **requires** a non-empty `youtube_url` → else `422`.
- `active_video_source = upload` **requires** a `video_asset_id` whose asset is `ready` → else `422`.
- `youtube_url` must parse to a recognised YouTube video id (so playback can build a clean embed).

**Teacher `LessonResource`** (authoring view) exposes **both** slots + the toggle:

```json
{
  "id": 101,
  "title": "Displacement & Velocity",
  "active_video_source": "youtube",
  "has_video": true,
  "video": {                       // upload slot (may be present but inactive)
    "uuid": "af23…", "type": "hls_video", "status": "ready",
    "url": null, "thumbnail_url": "https://…", "duration_sec": 720
  },
  "youtube_url": "https://youtu.be/abc123",
  "attachments": [ … ]
}
```

---

## 5. Student-facing / playback

The existing authorization endpoint branches on the active source and returns a
**discriminated** payload, so the SPA makes one call and gets whatever is live.

`POST /v1/media/lessons/{lesson}/playback`

- **active = `upload`** → unchanged. Existing HLS/remote flow: re-checks access,
  ensures the per-student encrypted+watermarked rendition, returns
  `{ token, manifest_url, key_url, expires_at }`.
- **active = `youtube`** → new branch, no token/key/watermark:

  ```json
  {
    "data": {
      "source": "youtube",
      "video_id": "abc123",
      "embed_url": "https://www.youtube.com/embed/abc123"
    }
  }
  ```

**Access gate still applies to the YouTube branch:** the URL is returned **only**
if the caller is enrolled (`EnrollmentService`) or the lesson `is_free_preview` —
same gate as the upload branch. A `403` otherwise.

**Non-leak rule (critical):** the inactive source is never present in any
student-facing resource. If `active_video_source = youtube`, student responses
must not include the `hls_video` asset, and vice-versa. Public course detail
(`GET /v1/courses/{slug}`) keeps returning only `has_video` — never the raw URL.

---

## 6. Protection trade-off (the real product decision)

The platform's content-protection thesis — AES-encrypted HLS + per-student
burned-in watermark + access-gated key (M04/M22) — **does not extend to a
YouTube-sourced lesson**. Concretely, when `active_video_source = youtube`:

- **No watermark, no encryption, no per-key re-check.**
- API gating only hides the URL until enrolled; once returned it is a normal,
  **shareable** YouTube URL. Recommend teachers use **unlisted** videos at minimum.
- **`max_views` cannot be server-enforced** (no playback session / rendition to
  count against).
- **Progress %** for YouTube depends on the frontend reporting YouTube IFrame
  Player events to `POST /v1/lessons/{lesson}/progress` — client-trusted, unlike
  the HLS path.

Framing: **upload = protected tier; YouTube = fast/cheap unprotected tier.** The
toggle lets a teacher choose per lesson. Whether to allow YouTube for *paid*
lessons is an open decision (§7).

---

## 7. Open decisions (need sign-off)

1. **Storage model** — plain `youtube_url` column (recommended) vs. YouTube as a
   `MediaAsset` (new `youtube` type + second FK).
2. **YouTube gating strictness** — API-gate the URL only (recommended, pair with
   unlisted) vs. formally require unlisted + document limits vs. disallow YouTube
   for paid lessons (protected upload mandatory for paid content).

---

## 8. Edge cases to nail down

- **Delete the active uploaded video** → block, unless the YouTube slot is
  populated (then optionally auto-fallback `active_video_source → youtube`).
  Recommend: block + explicit teacher action, no silent switch.
- **Neither source set** → `has_video = false`; lesson has no playable video.
- **Both set, toggle flipped** → students immediately see the newly-active source
  on their next `playback` call (short-TTL tokens mean no stale HLS access lingers
  beyond `media.playback_ttl`).
- **Free preview + YouTube** → still gate the URL behind the preview/enrollment check.
- **Invalid/removed YouTube video** → the platform can't detect YouTube-side
  deletion; the embed will simply fail client-side. Acceptable for the unprotected tier.

---

## 9. Impact checklist (for when this is greenlit)

- **Migration:** add `youtube_url`, `active_video_source` to `lessons`.
- **Catalog:** `Lesson` model (fillable/cast), `LessonRequest` (or new requests),
  teacher `LessonResource` (both slots + toggle), `has_video` recomputation.
- **Media:** playback controller branch for `youtube`; ensure student resources
  never leak the inactive source.
- **Docs:** update [`../api/catalog.md`](../api/catalog.md) (lesson fields +
  authoring) and [`../api/media.md`](../api/media.md) (playback discriminator).
- **Tests:** toggle validation (`422` when target source empty), non-leak of
  inactive source, YouTube playback gated by enrollment/preview, `has_video`
  source-awareness.
