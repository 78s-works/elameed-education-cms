# Unified `documents` Table + Single File Storage Service

Status: **approved design — pre-development, clean-slate**
Date: 2026-08-20
Repos: `elameed-education-cms` (Laravel API), `elameed-web` (Vue 3 SPA)

> **The system is in development.** There is no production data to preserve. This document
> therefore describes a **clean cut**, not a migration: legacy columns are dropped, the
> `attachments` table is deleted, and the database is rebuilt with `migrate:fresh --seed`.
> No dual-write, no backfill, no compatibility shims.

---

## 1. Why

Files are written to disk from **11 different call sites**, each with its own path convention,
disk choice, and (sometimes) no database record at all.

### 1.1 Current file-writing call sites

| # | Call site | DB record | Disk | Cleaned up? |
|---|-----------|-----------|------|-------------|
| 1 | `Engagement\...\AttachmentController::store` | `attachments` row | `public` | row cascades, **blob leaks** |
| 2 | `Catalog\...\Teacher\LessonAttachmentController::store` | `media_assets` row (`type`=pdf/file) | `public` | only on explicit `destroy` |
| 3 | `Assessment\...\AttemptController::uploadFile` | **none** — JSON in `exam_attempts.answers` | `local` | never |
| 4 | `Assessment\...\Teacher\ExamGradingController` | **none** — JSON in `exam_attempts.corrected_file` | `local` | never |
| 5 | `Tenancy\...\TeacherLandingController::media` | **none** — URL pasted into landing JSON | `public` | never |
| 6 | `Media\...\TeacherMediaController` + `HlsTranscoder` | `media_assets` / `media_versions` / `media_renditions` | `local` | yes |
| 7 | `Commerce\InvoicePdfService` | `invoices.pdf_url` | own config | no |

Plus `MediaThumbnailService`, `PlaybackController`, `Attachment` model, and
`Identity\...\StudentImportController` (which persists nothing).

### 1.2 Concrete problems

1. **No inventory.** Nobody can list a teacher's files or measure storage usage.
2. **Orphans.** Deleting a comment cascades the row and leaves the blob forever. Same for
   attempts, landing images, replaced logos.
3. **Two competing models.** `attachments` (polymorphic) and `media_assets` (which doubles as
   the HLS video handle **and** as a PDF attachment — carrying `hls_path`,
   `encryption_key_ref`, `renditions`, `watermark_policy`, all null for a PDF).
4. **Untracked blobs.** Assignment submissions and corrected files exist only as JSON inside a
   column — unlistable, uncountable, uncollectable.
5. **Public-disk misuse.** Lesson PDFs and comment attachments sit at
   `/storage/attachments/<hash>.pdf` with **no authorization check whatsoever**. Paid lesson
   material is currently world-readable. Only video is token-gated.
6. **Duplicated validation.** mime/size rules re-declared across five FormRequests with
   inconsistent limits (20 MB / 5 MB / 1 GB).

### 1.3 Complete file surface

Swept mechanically, not from memory:

```bash
grep -rn "Storage::\|->store(\|->storeAs(\|disk(" app --include=*.php
grep -rn "_url\|_key\|_path" database/migrations/*.php | grep "table->"
```

| Table | Column | Fate |
|---|---|---|
| `attachments` | whole table | **dropped** — replaced by `documents` |
| `media_assets` | `source_key`, `thumbnail_url` | **dropped** → `source_document_id`, `thumbnail_document_id` |
| `media_assets` | `hls_path`, `encryption_key_ref`, `renditions` | **untouched** (HLS pipeline internals) |
| `media_assets` | rows of `type` = pdf/file | **removed entirely** — those become `documents` |
| `media_versions` | `thumbnail_url`, `meta` | untouched |
| `invoices` | `pdf_url` | **dropped** → `pdf_document_id` |
| `teacher_profiles` | `logo_url`, `favicon_url`, `cover_url` | **dropped** → `*_document_id` |
| `teacher_profiles` | `landing_sections` (json) | **untouched** — keeps storing plain public URLs (see §2.7) |
| `packages` | `cover_url` | **dropped** → `cover_document_id` |
| `packages` | `promo_video_url` | untouched — external link, not a stored file |
| `lessons`, `lesson_sections` | `youtube_url` | untouched — external link |
| `lesson_sections` | — | **gains** `document_id` |
| `exam_attempts` | `answers`, `corrected_file` (json) | file paths removed from JSON → Pattern B rows |
| `payment_receipts` | `attachment_id` | **dropped** → `document_id` |
| `media_upload_sessions` | `upload_url` | untouched — transient |

---

## 2. Design

### 2.1 One table: `documents`

```
documents
  id                bigint  pk
  uuid              uuid    unique          -- route key, public identifier
  tenant_id         fk tenants  cascade     -- RLS via TenantRls::enableFor
  owner_id          fk users    cascade     -- uploader
  purpose           string                  -- what the file is FOR
  kind              string                  -- image | video | audio | pdf | document | archive | other
  visibility        string                  -- public | private
  disk              string                  -- resolved disk name
  storage_key       string                  -- path on that disk
  original_name     string
  mime              string   nullable
  extension         string   nullable
  size_bytes        bigint   nullable
  checksum          char(64) nullable       -- sha256
  documentable_type string   nullable       -- Pattern B owner
  documentable_id   bigint   nullable
  meta              json     nullable       -- duration_sec, width, height, pages…
  status            string                  -- ready | processing | failed | quarantined
  created_at / updated_at

  indexes: (tenant_id, owner_id), (tenant_id, purpose),
           (documentable_type, documentable_id), (tenant_id, checksum), uuid unique
```

**No `deleted_at`.** Per decision (2), deletion is permanent and immediate — see §2.6.

`purpose` values:
`lesson_attachment`, `comment_attachment`, `ticket_attachment`, `payment_receipt`,
`assignment_submission`, `assignment_corrected`, `landing_image`, `branding_logo`,
`branding_favicon`, `branding_cover`, `package_cover`, `video_source`, `video_thumbnail`,
`invoice_pdf`, `student_import`.

### 2.2 How a document links to the rest of the schema

Every file becomes exactly one `documents` row. Two linking patterns, chosen by cardinality.

#### Pattern A — the owner row holds `document_id`

For rows with **exactly one** file that is part of the row's own definition.

```php
$table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
```

| Table | Column | Replaces |
|---|---|---|
| `lesson_sections` | `document_id` | — (new) |
| `payment_receipts` | `document_id` | `attachment_id` |
| `teacher_profiles` | `logo_document_id` | `logo_url` |
| `teacher_profiles` | `favicon_document_id` | `favicon_url` |
| `teacher_profiles` | `cover_document_id` | `cover_url` |
| `packages` | `cover_document_id` | `cover_url` |
| `invoices` | `pdf_document_id` | `pdf_url` |
| `media_assets` | `source_document_id` | `source_key` |
| `media_assets` | `thumbnail_document_id` | `thumbnail_url` |

The replaced columns are **dropped in the same migration**. Nothing is kept for compatibility.

#### Pattern B — the document holds `documentable_type` + `documentable_id`

For owners with **many** files, unknown counts, or files uploaded **before** their owner exists.

```php
// Document
public function documentable(): MorphTo { return $this->morphTo(); }

// owner, via HasDocuments trait
public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
```

| Owner | `purpose` | Cardinality |
|---|---|---|
| `Comment` | `comment_attachment` | 0..N |
| `SupportTicket` | `ticket_attachment` | 0..N |
| `TicketReply` | `ticket_attachment` | 0..N |
| `ExamAttempt` | `assignment_submission` | 0..N (one per file-type question) |
| `ExamAttempt` | `assignment_corrected` | 0..N |
| `Lesson` | `lesson_attachment` | 0..N (materials list) |
| `Tenant` | `landing_image` | 0..N (page-builder images) |

`documentable_*` is nullable because of the two-phase upload: `POST /documents` returns an
unattached row, the client passes the uuids as `document_ids` on the owner-creating request, and
the service calls `attachTo()` inside the same transaction. `PruneUnattachedDocuments` deletes
rows that never gained an owner after 24 h.

`ExamAttempt` carries two purposes, so lookups always filter on both:

```php
$attempt->documents()->where('purpose', DocumentPurpose::AssignmentSubmission)->get();
```

When a Pattern-B owner is deleted, `DocumentsObserver` deletes its documents **and their blobs**
in the same transaction. This is the leak fix.

#### Video is the one exception

A video is not one file: a source MP4, plus N AES-encrypted HLS renditions, plus a per-student
watermarked rendition, plus versions, tokens, segment and key endpoints. `media_assets` is the
state machine for that; `documents` is the storage ledger. The chain:

```
lesson_sections.media_asset_id  →  media_assets  →  documents (source_document_id)
                                        │                     (thumbnail_document_id)
                                        └→ media_versions / media_renditions / playback_sessions
```

The raw MP4 and poster get `documents` rows — so they appear in the files tab and count toward
usage — but the section still points at `media_asset_id`, because playback needs the token
pipeline, not a file path. **No video playback code is modified by this change.**

#### Resulting map, per lesson-section type

| Section `type` / `delivery` | Link | Pattern | Pipeline |
|---|---|---|---|
| `lecture_video`, uploaded | `lesson_sections.media_asset_id` | A via `media_assets` | yes |
| `lecture_video`, YouTube | `lesson_sections.youtube_url` | unchanged | no |
| `pdf` | `lesson_sections.document_id` | A | no |
| `image_upload` | `lesson_sections.document_id` | A | no |
| lesson materials list | `documents.documentable = Lesson` | B | no |
| assignment submission | `documents.documentable = ExamAttempt` | B | no |
| corrected return | `documents.documentable = ExamAttempt` | B | no |

#### Example — teacher adds a PDF section

```php
$document = $documents->store(
    $request->file('file'),
    DocumentPurpose::LessonAttachment,
    new StoreOptions(visibility: Visibility::Private),
);

$section = $lesson->sections()->create([
    'type'        => 'pdf',
    'pdf_kind'    => $data['pdf_kind'],
    'title'       => $data['title'],
    'document_id' => $document->id,        // Pattern A
]);
```

#### Example — student attaches two images to a comment

```php
// 1. POST /documents ×2 → unattached rows
// 2. POST /comments { body, document_ids: [uuidA, uuidB] }
DB::transaction(function () use ($comment, $uuids, $documents) {
    foreach (Document::whereIn('uuid', $uuids)->get() as $document) {
        $documents->attachTo($document, $comment);   // Pattern B
    }
});
```

### 2.3 One service: `App\Support\Files\DocumentService`

```php
final class DocumentService
{
    public function store(UploadedFile $file, DocumentPurpose $purpose, StoreOptions $o = new StoreOptions): Document;
    public function storeContents(string $bytes, string $name, DocumentPurpose $p, StoreOptions $o): Document;
    public function attachTo(Document $d, Model $owner): Document;
    public function detach(Document $d): Document;                   // clears documentable_* / owner FK
    public function replace(Document $old, UploadedFile $new): Document;
    public function delete(Document $d): void;                       // PERMANENT — row + blob, one transaction
    public function url(Document $d): string;
    public function signedUrl(Document $d, int $ttl = null): string;
    public function download(Document $d): StreamedResponse;
    public function usageFor(int $tenantId): StorageUsage;
    public function pruneUnattached(): int;
}
```

Rules the service owns:

- **Path** `tenants/{tenant_id}/{purpose}/{Y}/{m}/{ulid}.{ext}` — tenant-prefixed, so a bucket
  listing never mixes tenants and per-tenant deletion is a prefix delete.
- **Disk** from `visibility`: `private → config('documents.private_disk')` (default `local`),
  `public → config('documents.public_disk')` (default `public`). New `config/documents.php`.
  **Default private.** Public only for `landing_image`, `branding_*`, `package_cover`,
  `video_thumbnail`.
- **Validation** via `DocumentRules` — per-purpose mime allow-list and max size from config.
  FormRequests call it; no hand-written `mimes:` strings anywhere.
- **Filename sanitisation** and extension/mime cross-check.
- **Checksum** (sha256) on write.
- **Deletion is permanent** (§2.6).

### 2.4 Authorization

Private-by-default means files are no longer reachable without a check. A `DocumentPolicy`
resolves access by `purpose`:

| Purpose | Who may read |
|---|---|
| `lesson_attachment` | existing enrollment + content-gating check for that lesson |
| `comment_attachment` | anyone who can read the thread |
| `ticket_attachment` | ticket owner, or staff with `support` |
| `payment_receipt` | uploader, or staff with `finance` |
| `assignment_submission` | the student who submitted, or staff with `homework` |
| `assignment_corrected` | the attempt's student, or staff with `homework` |
| `invoice_pdf` | the buyer, or tenant staff |
| `video_source` | teacher/assistant only — students never touch it, they get HLS |
| `branding_*`, `package_cover`, `landing_image`, `video_thumbnail` | public |

This closes the currently-open hole on lesson PDFs and comment attachments.

### 2.5 Delivery

Three mechanisms exist today; only the video one survives as-is.

| Today | Mechanism | Safe? |
|---|---|---|
| lesson PDFs, attachments, receipts, landing images | raw `/storage/...` URL in the payload | **no** |
| assignment files, corrected files, invoice PDFs | `Storage::download()` behind Sanctum + ownership check | yes |
| video | playback token → gated manifest/segment/key | yes |

**Mode 1 — authenticated stream.** `GET /documents/{uuid}/download`, Sanctum-guarded,
`DocumentPolicy`, then `Storage::disk($d->disk)->download(...)`. For explicit downloads.

**Mode 2 — short-lived signed URL.** `GET /documents/{uuid}` returns metadata including a
`download_url` from `URL::temporarySignedRoute(...)`, TTL `config('documents.signed_url_ttl')`
(default 300 s). Same policy, but bound to the signature instead of a bearer token.

Mode 2 exists because of a hard browser constraint: **`<img src>`, `<iframe src>`,
`<video src>`, `<audio src>` and `<a href download>` cannot carry an Authorization header.**
Fetching as a blob works for a download click but is wrong for inline previews — it pulls the
whole file into JS memory, breaks range requests, and defeats caching. So:

- inline render (thumbnails, PDF preview, audio) → Mode 2
- explicit download click → Mode 2 (for consistency)
- large media → always Mode 2, bytes stream browser↔disk

**Payload shape.** Every resource that exposed a bare `url` string now exposes:

```json
"document": {
  "uuid": "…",
  "name": "chapter-3.pdf",
  "kind": "pdf",
  "mime": "application/pdf",
  "size_bytes": 184320,
  "download_url": "https://…/documents/…/download?expires=…&signature=…",
  "expires_at": "2026-08-20T12:05:00Z"
}
```

Touched resources: `MediaAssetResource`, `AttachmentResource` (renamed `DocumentResource`), the
student lesson-section payload, `PaymentReceiptResource`, `InvoiceResource`, branding/tenant
resources.

Signed URLs expire, so the SPA re-fetches metadata once on 403 — the one genuinely new client
behaviour, encapsulated in a `useDocumentUrl(document)` composable.

### 2.6 Deletion semantics (decisions 1 and 2)

**Unlink before delete.** `DELETE /teacher/documents/{uuid}` on a document that is still linked
returns **409** with the linking resource identified:

```json
{
  "message": "This file is still attached.",
  "linked_to": { "type": "lesson_section", "uuid": "…", "label": "Chapter 3 — worksheet" }
}
```

The teacher unlinks first — `DELETE /teacher/documents/{uuid}/link`, which nulls the owner's
`document_id` (Pattern A) or clears `documentable_*` (Pattern B) — and then deletes. The UI
offers this as a two-step confirm, not as a hidden force flag.

**Deletion is permanent.** There is no soft delete, no trash, no retention window. `delete()`
removes the row and the blob in one transaction; a failed disk delete rolls the row back. The
confirmation dialog states this explicitly:

> **Delete `chapter-3.pdf` permanently?**
> This file will be erased from the server immediately. It cannot be recovered.
> [ Cancel ]  [ Delete permanently ]

The word "permanently" appears on the button, and the dialog requires an explicit click — no
click-through default, no undo toast promising recovery we cannot deliver.

### 2.7 Landing-page images stay URL-based (decided)

`teacher_profiles.landing_sections` is a free-form page-builder JSON whose nodes carry
`image_url` string fields ([LandingSchema.php:445](../../app/Modules/Tenancy/Support/LandingSchema.php)).
Those fields are **not** converted to `document_uuid` nodes.

What changes: the upload goes through `DocumentService` like everything else, so the image gets
a `documents` row (`purpose = landing_image`, `visibility = public`). The service returns the
public URL, and that URL is stored into the JSON exactly as today.

What does not change: `LandingSchema`, its validation rules, the landing editor, the public site
renderer, and the seeder all keep working untouched.

Rationale: landing images are public in both designs — a visitor must see the teacher's public
page without logging in — so converting the JSON buys no security and no capability. The image
still appears in the files tab, still counts toward storage, and is still deletable, because the
`documents` row exists. The only gain would be a tidier JSON shape, paid for with coordinated
edits across the riskiest surface in the system (teacher public pages). Not worth it.

The same reasoning applies to `packages.promo_video_url` and `lessons.youtube_url`, which are
external links and were never stored files.

---

## 3. API surface

### Shared

```
POST   /documents                          → upload; returns an unattached document
GET    /documents/{uuid}                   → metadata + signed download_url
GET    /documents/{uuid}/download          → policy-checked stream
```

### Teacher (`teacher` role, or assistant with the new `files` permission)

```
GET    /teacher/documents                  ?kind=&purpose=&q=&from=&to=&linked=(0|1)&sort=&page=
GET    /teacher/documents/summary          → { total_bytes, count, by_kind[], by_purpose[] }
GET    /teacher/documents/{uuid}           → metadata + linked-resource label
DELETE /teacher/documents/{uuid}/link      → unlink only
DELETE /teacher/documents/{uuid}           → permanent delete; 409 while linked
```

### Student (decision 3)

```
GET    /student/documents                  ?kind=&purpose=&q=&page=   -- own uploads only
GET    /student/documents/summary          → { total_bytes, count, by_purpose[] }
DELETE /student/documents/{uuid}           → permanent delete, unlinked own files only; 409 while linked
```

The student list is scoped by `owner_id = auth()->id()` **in addition to** tenant RLS, and is
restricted to student-generated purposes: `assignment_submission`, `payment_receipt`,
`comment_attachment`, `ticket_attachment`. A student never sees `assignment_corrected` in the
list as a deletable item — corrected files are visible read-only, since the teacher owns them.

A new `Permission::Files = 'files'` case is added to
`app/Modules/Identity/Enums/Permission.php`, gated with `permission:files`, matching the
existing `students` / `finance` / `support` pattern.

---

## 4. Frontend plan (`elameed-web`)

### 4.1 Teacher tab

`teacher-documents` → `src/modules/teacher/views/DocumentsPage.vue`, registered under the
`/teacher` parent in `src/app/router/index.js`, added to
`src/components/layout/TeacherLayout.vue` in the **System** group
(`icon: 'fa-folder-open'`, label `t('nav.files')`), shown to a teacher or to an assistant
holding `files` — same conditional pattern as `teacher-receipts` and `teacher-support`.

Contents:

- Summary strip: storage used, file count, breakdown by kind.
- Filters: name search, kind chips (image / video / pdf / audio / other), purpose select, date
  range, "unlinked only" toggle.
- Table ⇄ grid toggle. Columns: preview thumb, name, kind, purpose, linked-to (click through to
  the lesson / comment / attempt), size, uploaded by, date, actions.
- Row actions: preview, download, copy link, **unlink**, **delete permanently**.
- Preview modal: images inline, PDFs via `<iframe>`, audio via `<audio>`, `video_source` through
  the existing player component.
- Delete flow per §2.6: a linked file shows the linked-to resource and an *Unlink* button; only
  an unlinked file shows *Delete permanently*, behind the explicit warning dialog.
- Server-side pagination reusing the existing `src/components/ui` table and paginator.

### 4.2 Student tab

`student-documents` → `src/modules/student/views/MyFilesPage.vue`, in the student dashboard nav.
Simpler: summary strip, kind filter, list of own uploads grouped by purpose (submissions,
receipts, comment attachments), preview + download, and permanent-delete on unlinked own files
with the same warning dialog. Corrected files from teachers appear read-only.

### 4.3 Shared work

- New `src/api/endpoints/documents.js` exporting `documentsApi`
  (`upload`, `list`, `summary`, `show`, `unlink`, `remove`) plus `studentDocumentsApi`.
- `useDocumentUrl(document)` composable: signed-URL refresh on 403.
- `DocumentPreviewModal.vue` and `DeleteDocumentDialog.vue` shared by both tabs.
- Migrate the 12 lines that read a bare `.url` on a now-private file:

```
src/modules/student/views/LessonPlayerPage.vue:673-674   lesson PDF link
src/modules/student/views/SupportPage.vue:162-163,180-181  ticket attachments
src/modules/teacher/views/SupportInboxPage.vue:198-199,216-217  ticket attachments
src/modules/teacher/views/ReceiptsPage.vue:197-198,203    receipt link + inline <img>
```

The other ~74 references (`logo_url`, `favicon_url`, `cover_url`, `thumbnail_url`,
`promo_video_url`) are branding and cover images. They keep a public URL — but the field is
renamed to come from the document, so `adapters.js`, `types.js`, `stores/tenant.js` and the
branding/landing components are updated in one mechanical pass.

- i18n: `nav.files`, `teacher.documents.*`, `student.documents.*` in both `en` and `ar`,
  including the delete-warning copy.

---

## 5. Branches

| Repo | Branch |
|------|--------|
| `elameed-education-cms` | `feat/unified-documents` |
| `elameed-web` | `feat/files-tabs` |

Both cut from `main`.

---

## 6. Work breakdown

Because there is no production data, the backend lands as **one coherent change** rather than
staged rollouts.

**Backend — `feat/unified-documents`**

1. `config/documents.php`; `DocumentPurpose`, `DocumentKind`, `DocumentVisibility` enums.
2. Migration: create `documents` + RLS; add all nine Pattern-A FK columns; **drop**
   `attachments`, `payment_receipts.attachment_id`, `teacher_profiles.{logo,favicon,cover}_url`,
   `packages.cover_url`, `invoices.pdf_url`, `media_assets.{source_key,thumbnail_url}`.
3. `Document` model (`BelongsToTenant`, `HasUuids`, `documentable()`) + `HasDocuments` trait +
   factory.
4. `DocumentService`, `DocumentRules`, `DocumentsObserver`, `PruneUnattachedDocuments`.
5. `DocumentPolicy` + the delivery controller (Modes 1 and 2) + `DocumentResource`.
6. Rewrite all 11 call sites onto the service; delete `Attachment` model/controller/resource and
   the ad-hoc storage code in the assessment controllers; strip the pdf/file branch out of
   `media_assets` and `LessonAttachmentController`.
7. Teacher + student endpoints; `Permission::Files`; route gating.
8. **Seeders rewritten** to create `documents` rows — this replaces the backfill entirely.
   `php artisan migrate:fresh --seed` is the deployment step.
9. `documents:audit` command (blobs without rows, rows without blobs) — kept as an ongoing
   health check, and it should report zero on a fresh seed.
10. Architecture test: `Storage::` and `->store(` may appear **only** in `App\Support\Files\*`
    and the explicitly allow-listed Media pipeline. Any new rogue upload path fails CI. This is
    what stops the problem from re-growing.
11. Feature tests per purpose: upload → row with correct disk/visibility/purpose → link resolves
    (A or B) → authorized fetch 200 → unauthorized 403 → cross-tenant 404 → delete-while-linked
    409 → unlink → delete removes row **and** blob. Plus repair of the existing Media,
    Engagement and Assessment suites that assert the old shapes.

**Frontend — `feat/files-tabs`**

1. `documentsApi` + `studentDocumentsApi`; `useDocumentUrl` composable.
2. Teacher route, nav entry, permission guard; `DocumentsPage.vue`.
3. Student route, nav entry; `MyFilesPage.vue`.
4. `DocumentPreviewModal.vue`, `DeleteDocumentDialog.vue`.
5. Migrate the 12 private-file lines; mechanical rename pass over the ~74 branding references.
6. i18n en/ar.
7. Vitest specs: api module, filter/pagination logic, delete-dialog gating (no delete button on a
   linked file).

---

## 7. Risks

1. **`php artisan migrate:fresh --seed` wipes the development database.** That is the intended
   deployment step and is acceptable per the project's current stage, but every developer needs
   to know their local data goes away.
2. **Existing uploaded dev files become orphans on disk.** `storage/app/public/attachments/`,
   `landing/`, and `assignments/` will hold blobs with no rows. `documents:audit` lists them; a
   one-line `storage:clear-orphans` (or manual `rm -rf`) removes them. No data worth keeping.
3. ~~`landing_sections` JSON changes shape~~ — **removed as a risk.** Per §2.7 the JSON is left
   alone; the landing editor and public renderer are not touched.
4. **Quota enforcement is out of scope.** The table makes it possible; limits and at-cap
   behaviour are a product decision.
5. **Virus scanning is not included.** `status = quarantined` exists in the schema so it can be
   added later without a migration.
6. **Permanent delete has no undo by design** (decision 2). The mitigation is entirely in the
   confirmation UX described in §2.6.
