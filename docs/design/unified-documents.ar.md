# جدول `documents` الموحد + خدمة واحدة لحفظ الملفات

الحالة: **تصميم معتمد — قبل بدء التنفيذ، بداية نظيفة**
التاريخ: 2026-08-20
المستودعات: `elameed-education-cms` (Laravel API)، `elameed-web` (Vue 3 SPA)

> **النظام في مرحلة التطوير.** مفيش داتا حقيقية محتاجة حفاظ عليها. عشان كده الورقة دي بتوصف
> **قطع نظيف** مش هجرة تدريجية: الأعمدة القديمة بتتشال، جدول `attachments` بيتمسح، وقاعدة
> البيانات بتتبني من الأول بـ `migrate:fresh --seed`. من غير كتابة مزدوجة، من غير backfill،
> من غير أي طبقة توافق مؤقتة.

---

## 1. ليه بنعمل ده

الملفات دلوقتي بتتكتب على الهارد من **11 مكان مختلف**، كل واحد بمساره الخاص، وبالـ disk اللي
هو مختاره، وأحياناً من غير أي تسجيل في قاعدة البيانات أصلاً.

### 1.1 أماكن كتابة الملفات الحالية

| # | المكان | سطر في الداتابيز؟ | الـ disk | بيتنضف؟ |
|---|--------|-------------------|----------|---------|
| 1 | `Engagement\...\AttachmentController::store` | سطر في `attachments` | `public` | السطر بيتمسح و**الملف بيفضل** |
| 2 | `Catalog\...\Teacher\LessonAttachmentController::store` | سطر في `media_assets` (`type`=pdf/file) | `public` | بس عند المسح الصريح |
| 3 | `Assessment\...\AttemptController::uploadFile` | **لا** — JSON جوه `exam_attempts.answers` | `local` | أبداً |
| 4 | `Assessment\...\Teacher\ExamGradingController` | **لا** — JSON جوه `exam_attempts.corrected_file` | `local` | أبداً |
| 5 | `Tenancy\...\TeacherLandingController::media` | **لا** — الرابط بيتحط جوه JSON الصفحة | `public` | أبداً |
| 6 | `Media\...\TeacherMediaController` + `HlsTranscoder` | `media_assets` / `media_versions` / `media_renditions` | `local` | أيوه |
| 7 | `Commerce\InvoicePdfService` | `invoices.pdf_url` | إعداد خاص | لا |

وكمان `MediaThumbnailService` و `PlaybackController` وموديل `Attachment` و
`Identity\...\StudentImportController` (اللي مش بيحفظ أي حاجة أصلاً).

### 1.2 المشاكل الحقيقية

1. **مفيش جرد للملفات.** محدش يقدر يعرض ملفات مدرس معين، ولا يقيس المساحة المستهلكة.
2. **ملفات يتيمة.** لما يتمسح كومنت، السطر بيتمسح والملف بيفضل على الهارد للأبد. نفس الكلام مع
   المحاولات، وصور الصفحة الرئيسية، واللوجوهات المستبدلة.
3. **نموذجين متنافسين.** `attachments` (polymorphic) و `media_assets` (اللي بيلعب دور مقبض
   الفيديو **و** دور مرفق الـ PDF في نفس الوقت — وشايل `hls_path` و `encryption_key_ref` و
   `renditions` و `watermark_policy`، وكلها فاضية في حالة الـ PDF).
4. **ملفات مش متتبعة.** تسليمات الواجبات والملفات المصححة موجودة بس كـ JSON جوه عمود — مينفعش
   تتعرض ولا تتعد ولا تتنضف.
5. **سوء استخدام الـ public disk.** ملفات الدروس ومرفقات الكومنتات موجودة على
   `/storage/attachments/<hash>.pdf` **من غير أي تحقق صلاحيات خالص**. يعني المحتوى المدفوع
   مقروء للكل حالياً. الفيديو بس هو المحمي بـ token.
6. **تكرار في التحقق.** قواعد الـ mime والحجم متكتوبة في 5 FormRequests بحدود مختلفة
   (20 ميجا / 5 ميجا / 1 جيجا).

### 1.3 الجرد الكامل لسطح الملفات

اتعمل بالمسح الآلي، مش بالذاكرة:

```bash
grep -rn "Storage::\|->store(\|->storeAs(\|disk(" app --include=*.php
grep -rn "_url\|_key\|_path" database/migrations/*.php | grep "table->"
```

| الجدول | العمود | المصير |
|---|---|---|
| `attachments` | الجدول كله | **بيتمسح** — `documents` بياخد مكانه |
| `media_assets` | `source_key`, `thumbnail_url` | **بيتشال** ← `source_document_id`, `thumbnail_document_id` |
| `media_assets` | `hls_path`, `encryption_key_ref`, `renditions` | **مش هنلمسه** (داخلية الـ HLS) |
| `media_assets` | السطور اللي `type` = pdf/file | **بتتشال بالكامل** — بتبقى `documents` |
| `media_versions` | `thumbnail_url`, `meta` | مش هنلمسه |
| `invoices` | `pdf_url` | **بيتشال** ← `pdf_document_id` |
| `teacher_profiles` | `logo_url`, `favicon_url`, `cover_url` | **بتتشال** ← `*_document_id` |
| `teacher_profiles` | `landing_sections` (json) | **مش هنلمسه** — هيفضل يخزن روابط عامة (§2.7) |
| `packages` | `cover_url` | **بيتشال** ← `cover_document_id` |
| `packages` | `promo_video_url` | مش هنلمسه — رابط خارجي مش ملف محفوظ |
| `lessons`, `lesson_sections` | `youtube_url` | مش هنلمسه — رابط خارجي |
| `lesson_sections` | — | **بياخد** `document_id` |
| `exam_attempts` | `answers`, `corrected_file` (json) | المسارات بتتشال من الـ JSON ← سطور Pattern B |
| `payment_receipts` | `attachment_id` | **بيتشال** ← `document_id` |
| `media_upload_sessions` | `upload_url` | مش هنلمسه — مؤقت |

---

## 2. التصميم

### 2.1 جدول واحد: `documents`

```
documents
  id                bigint  pk
  uuid              uuid    unique          -- مفتاح الراوت، المعرّف العام
  tenant_id         fk tenants  cascade     -- RLS عن طريق TenantRls::enableFor
  owner_id          fk users    cascade     -- اللي رفع الملف
  purpose           string                  -- الملف ده لإيه
  kind              string                  -- image | video | audio | pdf | document | archive | other
  visibility        string                  -- public | private
  disk              string                  -- اسم الـ disk المحسوب
  storage_key       string                  -- المسار على الـ disk ده
  original_name     string
  mime              string   nullable
  extension         string   nullable
  size_bytes        bigint   nullable
  checksum          char(64) nullable       -- sha256
  documentable_type string   nullable       -- مالك Pattern B
  documentable_id   bigint   nullable
  meta              json     nullable       -- duration_sec, width, height, pages…
  status            string                  -- ready | processing | failed | quarantined
  created_at / updated_at

  الفهارس: (tenant_id, owner_id), (tenant_id, purpose),
           (documentable_type, documentable_id), (tenant_id, checksum), uuid unique
```

**مفيش `deleted_at`.** حسب القرار (2)، المسح نهائي وفوري — بص على §2.6.

قيم الـ `purpose`:
`lesson_attachment`, `comment_attachment`, `ticket_attachment`, `payment_receipt`,
`assignment_submission`, `assignment_corrected`, `landing_image`, `branding_logo`,
`branding_favicon`, `branding_cover`, `package_cover`, `video_source`, `video_thumbnail`,
`invoice_pdf`, `student_import`.

### 2.2 إزاي الملف بيترتبط ببقية الجداول

كل ملف بيبقى سطر واحد في `documents`. وفيه طريقتين للربط، الاختيار بينهم حسب العدد.

#### الطريقة A — الصف المالك هو اللي شايل `document_id`

للصفوف اللي ليها **ملف واحد بالظبط**، والملف ده جزء من تعريف الصف نفسه.

```php
$table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
```

| الجدول | العمود | بديل عن |
|---|---|---|
| `lesson_sections` | `document_id` | — (جديد) |
| `payment_receipts` | `document_id` | `attachment_id` |
| `teacher_profiles` | `logo_document_id` | `logo_url` |
| `teacher_profiles` | `favicon_document_id` | `favicon_url` |
| `teacher_profiles` | `cover_document_id` | `cover_url` |
| `packages` | `cover_document_id` | `cover_url` |
| `invoices` | `pdf_document_id` | `pdf_url` |
| `media_assets` | `source_document_id` | `source_key` |
| `media_assets` | `thumbnail_document_id` | `thumbnail_url` |

الأعمدة القديمة دي **بتتشال في نفس الـ migration**. مفيش حاجة بتفضل للتوافق.

#### الطريقة B — الملف هو اللي شايل `documentable_type` + `documentable_id`

للمالكين اللي ليهم **أكتر من ملف**، أو العدد مش معروف، أو الملف بيترفع **قبل** ما المالك يتخلق.

```php
// Document
public function documentable(): MorphTo { return $this->morphTo(); }

// المالك، عن طريق trait اسمه HasDocuments
public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
```

| المالك | الـ `purpose` | العدد |
|---|---|---|
| `Comment` | `comment_attachment` | 0..N |
| `SupportTicket` | `ticket_attachment` | 0..N |
| `TicketReply` | `ticket_attachment` | 0..N |
| `ExamAttempt` | `assignment_submission` | 0..N (ملف لكل سؤال من نوع file) |
| `ExamAttempt` | `assignment_corrected` | 0..N |
| `Lesson` | `lesson_attachment` | 0..N (قائمة المواد) |
| `Tenant` | `landing_image` | 0..N (صور محرر الصفحة) |

الـ `documentable_*` بيقبل NULL عشان الرفع على مرحلتين: `POST /documents` بترجع سطر غير مرتبط،
وبعدين العميل بيبعت الـ uuids في `document_ids` مع الطلب اللي بيخلق المالك، والخدمة بتنادي
`attachTo()` جوه نفس الـ transaction. و `PruneUnattachedDocuments` بيمسح السطور اللي فضلت من غير
مالك بعد 24 ساعة.

الـ `ExamAttempt` شايل نوعين، فالاستعلام دايماً بيفلتر بالاتنين:

```php
$attempt->documents()->where('purpose', DocumentPurpose::AssignmentSubmission)->get();
```

لما مالك من Pattern B يتمسح، الـ `DocumentsObserver` بيمسح ملفاته **والـ blobs بتاعتها** في نفس
الـ transaction. ده هو حل مشكلة التسريب.

#### الفيديو هو الاستثناء الوحيد

الفيديو مش ملف واحد: ده ملف MP4 أصلي، زائد عدة نسخ HLS مشفرة بـ AES، زائد نسخة بعلامة مائية لكل
طالب، زائد إصدارات و tokens و endpoints للـ segments والمفاتيح. الـ `media_assets` هو آلة الحالة
بتاعة ده كله؛ و `documents` هو سجل التخزين. السلسلة:

```
lesson_sections.media_asset_id  →  media_assets  →  documents (source_document_id)
                                        │                     (thumbnail_document_id)
                                        └→ media_versions / media_renditions / playback_sessions
```

الـ MP4 الأصلي والبوستر بياخدوا سطور في `documents` — عشان يظهروا في تبويب الملفات ويتحسبوا في
المساحة — لكن الـ section بيفضل مشاور على `media_asset_id`، لأن التشغيل محتاج نظام الـ tokens مش
مسار ملف. **مفيش أي كود خاص بتشغيل الفيديو هيتعدل في التغيير ده.**

#### الخريطة النهائية حسب نوع الـ section

| نوع الـ section | الربط | الطريقة | فيه pipeline؟ |
|---|---|---|---|
| `lecture_video` مرفوع | `lesson_sections.media_asset_id` | A عبر `media_assets` | أيوه |
| `lecture_video` يوتيوب | `lesson_sections.youtube_url` | زي ما هو | لا |
| `pdf` | `lesson_sections.document_id` | A | لا |
| `image_upload` | `lesson_sections.document_id` | A | لا |
| قائمة مواد الدرس | `documents.documentable = Lesson` | B | لا |
| تسليم الواجب | `documents.documentable = ExamAttempt` | B | لا |
| الملف المصحح | `documents.documentable = ExamAttempt` | B | لا |

#### مثال — المدرس بيضيف section نوعه PDF

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
    'document_id' => $document->id,        // الطريقة A
]);
```

#### مثال — طالب بيرفق صورتين على كومنت

```php
// 1. POST /documents مرتين → سطور غير مرتبطة
// 2. POST /comments { body, document_ids: [uuidA, uuidB] }
DB::transaction(function () use ($comment, $uuids, $documents) {
    foreach (Document::whereIn('uuid', $uuids)->get() as $document) {
        $documents->attachTo($document, $comment);   // الطريقة B
    }
});
```

### 2.3 خدمة واحدة: `App\Support\Files\DocumentService`

```php
final class DocumentService
{
    public function store(UploadedFile $file, DocumentPurpose $purpose, StoreOptions $o = new StoreOptions): Document;
    public function storeContents(string $bytes, string $name, DocumentPurpose $p, StoreOptions $o): Document;
    public function attachTo(Document $d, Model $owner): Document;
    public function detach(Document $d): Document;                   // بيفك الربط
    public function replace(Document $old, UploadedFile $new): Document;
    public function delete(Document $d): void;                       // نهائي — السطر والملف، transaction واحد
    public function url(Document $d): string;
    public function signedUrl(Document $d, int $ttl = null): string;
    public function download(Document $d): StreamedResponse;
    public function usageFor(int $tenantId): StorageUsage;
    public function pruneUnattached(): int;
}
```

القواعد اللي الخدمة مسؤولة عنها:

- **المسار** `tenants/{tenant_id}/{purpose}/{Y}/{m}/{ulid}.{ext}` — مبدوء بالـ tenant، فأي عرض
  للـ bucket عمره ما هيخلط بين المدرسين، ومسح tenant بالكامل بيبقى مسح prefix.
- **اختيار الـ disk** من الـ `visibility`: `private ← config('documents.private_disk')`
  (افتراضي `local`)، و `public ← config('documents.public_disk')` (افتراضي `public`). ملف إعدادات
  جديد `config/documents.php`. **الافتراضي private.** الـ public بس لـ `landing_image` و
  `branding_*` و `package_cover` و `video_thumbnail`.
- **التحقق** عن طريق `DocumentRules` — قائمة mime وحد أقصى للحجم لكل purpose من الإعدادات.
  الـ FormRequests بتناديها؛ مفيش `mimes:` مكتوبة بالإيد في أي مكان.
- **تنظيف اسم الملف** ومطابقة الامتداد مع الـ mime.
- **checksum** (sha256) عند الكتابة.
- **المسح نهائي** (§2.6).

### 2.4 الصلاحيات

الافتراضي private معناه إن الملفات مبقتش متاحة من غير تحقق. `DocumentPolicy` بيحدد الصلاحية حسب
الـ `purpose`:

| الـ purpose | مين يقدر يقرأ |
|---|---|
| `lesson_attachment` | نفس تحقق الاشتراك + الـ gating الموجود للدرس ده |
| `comment_attachment` | أي حد يقدر يقرأ الـ thread |
| `ticket_attachment` | صاحب التذكرة، أو موظف عنده `support` |
| `payment_receipt` | اللي رفعه، أو موظف عنده `finance` |
| `assignment_submission` | الطالب اللي سلّم، أو موظف عنده `homework` |
| `assignment_corrected` | طالب المحاولة، أو موظف عنده `homework` |
| `invoice_pdf` | المشتري، أو موظفي الأكاديمية |
| `video_source` | المدرس/المساعد بس — الطلبة عمرهم ما بيلمسوه، بياخدوا HLS |
| `branding_*`, `package_cover`, `landing_image`, `video_thumbnail` | عام |

ده بيقفل الثغرة المفتوحة حالياً على ملفات الدروس ومرفقات الكومنتات.

### 2.5 طريقة تقديم الملفات

فيه 3 آليات دلوقتي؛ واحدة بس هي اللي هتفضل زي ما هي.

| الوضع الحالي | الآلية | آمن؟ |
|---|---|---|
| ملفات الدروس والمرفقات والإيصالات وصور الصفحة | رابط `/storage/...` مباشر في الـ payload | **لا** |
| ملفات الواجبات والمصححة وفواتير PDF | `Storage::download()` ورا Sanctum + تحقق ملكية | أيوه |
| الفيديو | playback token ← manifest/segment/key محمية | أيوه |

**الوضع 1 — بث مُوثّق.** `GET /documents/{uuid}/download`، محمي بـ Sanctum، بيعدي على
`DocumentPolicy`، وبعدين `Storage::disk($d->disk)->download(...)`. للتحميل الصريح.

**الوضع 2 — رابط موقّع قصير العمر.** `GET /documents/{uuid}` بترجع بيانات الملف ومعاها
`download_url` من `URL::temporarySignedRoute(...)`، ومدته من `config('documents.signed_url_ttl')`
(افتراضي 300 ثانية). نفس الـ policy، بس مربوطة بالتوقيع بدل الـ bearer token.

الوضع 2 موجود بسبب قيد حقيقي في المتصفح: **`<img src>` و `<iframe src>` و `<video src>` و
`<audio src>` و `<a href download>` مش بيقدروا يبعتوا Authorization header.**
التحميل كـ blob بيشتغل مع زرار التحميل، لكنه غلط في المعاينة المدمجة — بيحمّل الملف كله في ذاكرة
الـ JS، وبيكسر الـ range requests، وبيلغي الـ caching. يبقى:

- المعاينة المدمجة (صور مصغرة، معاينة PDF، صوت) ← الوضع 2
- ضغطة التحميل الصريحة ← الوضع 2 (للاتساق)
- الملفات الكبيرة ← الوضع 2 دايماً، البيانات بتعدي من المتصفح للهارد مباشرة

**شكل الـ payload.** أي resource كان بيرجع `url` كنص، دلوقتي بيرجع:

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

الـ resources المتأثرة: `MediaAssetResource`، و `AttachmentResource` (هيتغير اسمه لـ
`DocumentResource`)، و payload الـ section عند الطالب، و `PaymentReceiptResource`، و
`InvoiceResource`، و resources الـ branding/tenant.

الروابط الموقّعة بتنتهي، فالواجهة بتعيد جلب البيانات مرة واحدة عند 403 — ده السلوك الجديد الوحيد
في الواجهة، ومتغلّف كله في composable اسمه `useDocumentUrl(document)`.

### 2.6 قواعد المسح (القرارات 1 و 2)

**فك الربط قبل المسح.** `DELETE /teacher/documents/{uuid}` على ملف لسه مرتبط بيرجع **409** ومعاه
تحديد للمكان المرتبط بيه:

```json
{
  "message": "الملف ده لسه مرتبط.",
  "linked_to": { "type": "lesson_section", "uuid": "…", "label": "الفصل 3 — ورقة عمل" }
}
```

المدرس بيفك الربط الأول — `DELETE /teacher/documents/{uuid}/link`، اللي بيفضّي `document_id` عند
المالك (الطريقة A) أو بيمسح `documentable_*` (الطريقة B) — وبعدين يمسح. الواجهة بتعرض ده كخطوتين
واضحتين، مش كـ flag مخفي.

**المسح نهائي.** مفيش soft delete، مفيش سلة مهملات، مفيش فترة استرجاع. `delete()` بيشيل السطر
والملف في transaction واحد؛ ولو مسح الملف فشل، السطر بيرجع زي ما كان. ورسالة التأكيد بتقول ده
بالنص:

> **مسح `chapter-3.pdf` نهائياً؟**
> الملف هيتشال من السيرفر فوراً. مش هينفع استرجاعه.
> [ إلغاء ]  [ مسح نهائي ]

كلمة "نهائي" مكتوبة على الزرار نفسه، والرسالة محتاجة ضغطة صريحة — مفيش زرار افتراضي، ومفيش
إشعار "تراجع" بيوعد باسترجاع إحنا مش قادرين نعمله.

### 2.7 صور الصفحة الرئيسية هتفضل روابط (قرار متاخد)

`teacher_profiles.landing_sections` عمود JSON حر بتاع محرر الصفحة، وعقده شايلة حقول `image_url`
كنصوص ([LandingSchema.php:445](../../app/Modules/Tenancy/Support/LandingSchema.php)). الحقول دي
**مش** هتتحول لعقد `document_uuid`.

**اللي هيتغير:** الرفع هيعدي على `DocumentService` زي أي حاجة تانية، فالصورة هتاخد سطر في
`documents` (`purpose = landing_image`، `visibility = public`). الخدمة بترجع الرابط العام،
والرابط ده بيتخزن في الـ JSON بالظبط زي دلوقتي.

**اللي مش هيتغير:** `LandingSchema` وقواعد التحقق بتاعته، ومحرر الصفحة، وعارض الموقع العام،
والـ seeder — كلهم هيفضلوا شغالين من غير أي تعديل.

**السبب:** صور الصفحة عامة في التصميمين — الزائر لازم يشوف صفحة المدرس من غير تسجيل دخول — يعني
تحويل الـ JSON مش هيجيب أي مكسب أمني ولا قدرة جديدة. الصورة هتظهر في تبويب الملفات، وهتتحسب في
المساحة، وهتتمسح عادي، لأن سطر الـ `documents` موجود. المكسب الوحيد كان شكل JSON أنضف، وتمنه
تعديلات متزامنة في أخطر سطح في النظام (صفحات المدرسين العامة). ماتستاهلش.

نفس المنطق بينطبق على `packages.promo_video_url` و `lessons.youtube_url`، وهما روابط خارجية
أصلاً مش ملفات محفوظة.

---

## 3. الـ API

### مشترك

```
POST   /documents                          → رفع؛ بترجع ملف غير مرتبط
GET    /documents/{uuid}                   → البيانات + رابط موقّع
GET    /documents/{uuid}/download          → بث بعد تحقق الصلاحية
```

### المدرس (أو مساعد عنده صلاحية `files` الجديدة)

```
GET    /teacher/documents                  ?kind=&purpose=&q=&from=&to=&linked=(0|1)&sort=&page=
GET    /teacher/documents/summary          → { total_bytes, count, by_kind[], by_purpose[] }
GET    /teacher/documents/{uuid}           → البيانات + اسم المكان المرتبط
DELETE /teacher/documents/{uuid}/link      → فك الربط بس
DELETE /teacher/documents/{uuid}           → مسح نهائي؛ 409 لو لسه مرتبط
```

### الطالب (القرار 3)

```
GET    /student/documents                  ?kind=&purpose=&q=&page=   -- ملفاته هو بس
GET    /student/documents/summary          → { total_bytes, count, by_purpose[] }
DELETE /student/documents/{uuid}           → مسح نهائي، لملفاته غير المرتبطة بس؛ 409 لو مرتبط
```

قائمة الطالب مقيّدة بـ `owner_id = auth()->id()` **بالإضافة لـ** الـ RLS بتاع الـ tenant، ومحصورة
في الأنواع اللي الطالب بينتجها: `assignment_submission` و `payment_receipt` و
`comment_attachment` و `ticket_attachment`. الطالب عمره ما هيشوف `assignment_corrected` كعنصر
قابل للمسح — الملفات المصححة بتظهر للقراءة بس، لأن المدرس هو مالكها.

وبيتضاف `Permission::Files = 'files'` في `app/Modules/Identity/Enums/Permission.php`، ويتحط على
الراوتات بـ `permission:files`، بنفس نمط `students` و `finance` و `support` الموجود.

---

## 4. خطة الواجهة (`elameed-web`)

### 4.1 تبويب المدرس

`teacher-documents` ← `src/modules/teacher/views/DocumentsPage.vue`، متسجل تحت الأب `/teacher`
في `src/app/router/index.js`، ومضاف في `src/components/layout/TeacherLayout.vue` في مجموعة
**النظام** (`icon: 'fa-folder-open'`، النص `t('nav.files')`)، ظاهر للمدرس أو للمساعد اللي عنده
`files` — بنفس النمط المستخدم في `teacher-receipts` و `teacher-support`.

المحتوى:

- شريط ملخص: المساحة المستخدمة، عدد الملفات، التقسيم حسب النوع.
- فلاتر: بحث بالاسم، شرائح الأنواع (صورة / فيديو / pdf / صوت / أخرى)، اختيار الـ purpose، نطاق
  تاريخ، ومفتاح "غير المرتبطة بس".
- تبديل بين جدول وشبكة. الأعمدة: صورة مصغرة، الاسم، النوع، الـ purpose، مرتبط بإيه (بالضغط
  بتروح للدرس/الكومنت/المحاولة)، الحجم، اللي رفعه، التاريخ، الإجراءات.
- إجراءات الصف: معاينة، تحميل، نسخ الرابط، **فك الربط**، **مسح نهائي**.
- نافذة المعاينة: الصور مدمجة، الـ PDF في `<iframe>`، الصوت في `<audio>`، و `video_source` عن
  طريق مشغل الفيديو الموجود.
- خطوات المسح حسب §2.6: الملف المرتبط بيعرض المكان المرتبط بيه وزرار *فك الربط*؛ وزرار
  *مسح نهائي* بيظهر بس للملف غير المرتبط، ووراه رسالة التحذير.
- الترقيم من السيرفر، بإعادة استخدام مكونات الجدول والترقيم الموجودة في `src/components/ui`.

### 4.2 تبويب الطالب

`student-documents` ← `src/modules/student/views/MyFilesPage.vue`، في قائمة لوحة الطالب. أبسط:
شريط ملخص، فلتر نوع، قائمة ملفاته مجمّعة حسب الـ purpose (تسليمات، إيصالات، مرفقات كومنتات)،
معاينة وتحميل، ومسح نهائي لملفاته غير المرتبطة بنفس رسالة التحذير. الملفات المصححة من المدرس
بتظهر للقراءة بس.

### 4.3 شغل مشترك

- ملف جديد `src/api/endpoints/documents.js` بيصدّر `documentsApi`
  (`upload`, `list`, `summary`, `show`, `unlink`, `remove`) و `studentDocumentsApi`.
- composable اسمه `useDocumentUrl(document)`: بيجدد الرابط الموقّع عند 403.
- `DocumentPreviewModal.vue` و `DeleteDocumentDialog.vue` مشتركين بين التبويبين.
- تعديل الـ 12 سطر اللي بيقروا `.url` مباشرة على ملف بقى private:

```
src/modules/student/views/LessonPlayerPage.vue:673-674        رابط PDF الدرس
src/modules/student/views/SupportPage.vue:162-163,180-181     مرفقات التذاكر
src/modules/teacher/views/SupportInboxPage.vue:198-199,216-217 مرفقات التذاكر
src/modules/teacher/views/ReceiptsPage.vue:197-198,203         رابط الإيصال + الصورة المدمجة
```

باقي الـ 74 استخدام (`logo_url` و `favicon_url` و `cover_url` و `thumbnail_url` و
`promo_video_url`) كلها صور branding وأغلفة. هتفضل بروابط عامة — بس اسم الحقل هيتغير عشان يجي من
الـ document، فالتعديل بيعدي على `adapters.js` و `types.js` و `stores/tenant.js` ومكونات الـ
branding/landing في مرور ميكانيكي واحد.

- الترجمة: `nav.files` و `teacher.documents.*` و `student.documents.*` في `en` و `ar`، ومعاهم نص
  تحذير المسح.

---

## 5. الفروع

| المستودع | الفرع |
|------|--------|
| `elameed-education-cms` | `feat/unified-documents` |
| `elameed-web` | `feat/files-tabs` |

الاتنين متفرعين من `main`.

---

## 6. تقسيم الشغل

عشان مفيش داتا إنتاج، الـ backend بينزل **تغيير واحد متماسك** مش مراحل.

**الـ Backend — `feat/unified-documents`**

1. `config/documents.php`؛ و enums: `DocumentPurpose` و `DocumentKind` و `DocumentVisibility`.
2. Migration: إنشاء `documents` + RLS؛ إضافة أعمدة الطريقة A التسعة؛ و**مسح** جدول
   `attachments`، و `payment_receipts.attachment_id`، و
   `teacher_profiles.{logo,favicon,cover}_url`، و `packages.cover_url`، و `invoices.pdf_url`،
   و `media_assets.{source_key,thumbnail_url}`.
3. موديل `Document` (`BelongsToTenant`، `HasUuids`، `documentable()`) + trait `HasDocuments` +
   factory.
4. `DocumentService` و `DocumentRules` و `DocumentsObserver` و `PruneUnattachedDocuments`.
5. `DocumentPolicy` + كنترولر التقديم (الوضعين 1 و 2) + `DocumentResource`.
6. إعادة كتابة الـ 11 مكان عشان يعدوا على الخدمة؛ ومسح موديل/كنترولر/resource الـ `Attachment`
   وكود التخزين المرتجل في كنترولرات الـ assessment؛ وشيل فرع pdf/file من `media_assets` ومن
   `LessonAttachmentController`.
7. راوتات المدرس والطالب؛ و `Permission::Files`؛ والحماية على الراوتات.
8. **إعادة كتابة الـ seeders** عشان تخلق سطور `documents` — دي بديل الـ backfill بالكامل. خطوة
   النشر هي `php artisan migrate:fresh --seed`.
9. أمر `documents:audit` (ملفات من غير سطور، وسطور من غير ملفات) — بيفضل كفحص صحة مستمر، ولازم
   يطلع صفر على seed جديد.
10. اختبار معماري: `Storage::` و `->store(` مسموح بيهم **بس** في `App\Support\Files\*` وفي
    الـ Media pipeline المسموح بيه صراحة. أي مسار رفع جديد في مكان غلط بيوقّع الـ CI. ده اللي
    هيمنع المشكلة إنها ترجع تاني.
11. اختبارات لكل purpose: رفع ← سطر بـ disk/visibility/purpose صح ← الربط بيتحل (A أو B) ←
    وصول مصرّح 200 ← غير مصرّح 403 ← من tenant تاني 404 ← مسح وهو مرتبط 409 ← فك الربط ← المسح
    بيشيل السطر **والملف**. وكمان إصلاح اختبارات Media و Engagement و Assessment الموجودة اللي
    بتفحص الشكل القديم.

**الواجهة — `feat/files-tabs`**

1. `documentsApi` و `studentDocumentsApi`؛ و composable `useDocumentUrl`.
2. راوت المدرس، عنصر القائمة، حارس الصلاحية؛ و `DocumentsPage.vue`.
3. راوت الطالب، عنصر القائمة؛ و `MyFilesPage.vue`.
4. `DocumentPreviewModal.vue` و `DeleteDocumentDialog.vue`.
5. تعديل الـ 12 سطر الخاصين بالملفات الخاصة؛ ومرور ميكانيكي على الـ 74 استخدام بتاع الـ branding.
6. الترجمة en/ar.
7. اختبارات Vitest: وحدة الـ api، ومنطق الفلترة والترقيم، وحماية زرار المسح (مايظهرش على ملف
   مرتبط).

---

## 7. المخاطر

1. **`php artisan migrate:fresh --seed` بيمسح قاعدة بيانات التطوير.** دي خطوة النشر المقصودة
   ومقبولة في المرحلة الحالية، بس كل مطور لازم يعرف إن الداتا المحلية بتاعته هتروح.
2. **الملفات المرفوعة حالياً في التطوير هتبقى يتيمة على الهارد.** المجلدات
   `storage/app/public/attachments/` و `landing/` و `assignments/` هيبقى فيها ملفات من غير سطور.
   `documents:audit` بيعرضها، وسطر واحد `storage:clear-orphans` (أو `rm -rf` بالإيد) بيشيلها.
   مفيش داتا تستاهل الاحتفاظ.
3. ~~شكل `landing_sections` JSON هيتغير~~ — **الخطر ده اتشال.** حسب §2.7 الـ JSON هيتساب زي ما
   هو، ومحرر الصفحة والعارض العام مش هيتلمسوا.
4. **فرض حصة تخزين خارج النطاق.** الجدول بيخلي ده ممكن؛ لكن الحدود وسلوك النظام عند الوصول للحد
   قرار منتج.
5. **فحص الفيروسات مش مشمول.** القيمة `status = quarantined` موجودة في الـ schema عشان تتضاف
   بعدين من غير migration.
6. **المسح النهائي مفيش له تراجع بالتصميم** (القرار 2). الحماية كلها في رسالة التأكيد الموصوفة
   في §2.6.
