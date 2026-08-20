<?php

use App\Support\Files\Enums\DocumentPurpose;

return [

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    | Every stored file lands on one of these two, chosen from the purpose's
    | visibility — never hard-coded at the call site. The private disk MUST NOT
    | be reachable over HTTP: private documents are only ever served through the
    | policy-checked download route.
    */

    'private_disk' => env('DOCUMENTS_PRIVATE_DISK', 'local'),
    'public_disk' => env('DOCUMENTS_PUBLIC_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime (seconds)
    |--------------------------------------------------------------------------
    | `<img src>` / `<iframe src>` / `<a download>` cannot send an Authorization
    | header, so private documents are handed to the browser as a short-lived
    | signed URL. Short enough that a leaked link is worthless; long enough that
    | a page can render without re-fetching.
    */

    'signed_url_ttl' => (int) env('DOCUMENTS_SIGNED_URL_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Unattached upload TTL (hours)
    |--------------------------------------------------------------------------
    | Two-phase uploads (upload first, link when the owner is created) leave a
    | row with a null `documentable_*`. If the owner is never created, the row
    | and its blob are pruned after this window.
    */

    'unattached_ttl_hours' => (int) env('DOCUMENTS_UNATTACHED_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Per-purpose rules
    |--------------------------------------------------------------------------
    | The single source of truth for what may be uploaded where. FormRequests
    | read these through DocumentRules instead of hand-writing `mimes:` strings,
    | so a limit is changed in one place.
    |
    |   visibility — public files get a stable URL; private files are gated.
    |   max_kb     — upload ceiling, enforced by validation.
    |   mimes      — allowed client extensions (Laravel `mimes:` rule).
    */

    'purposes' => [

        DocumentPurpose::LessonAttachment->value => [
            'visibility' => 'private',
            'max_kb' => 20480,
            'mimes' => 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,webp,zip',
        ],

        DocumentPurpose::CommentAttachment->value => [
            'visibility' => 'private',
            'max_kb' => 20480,
            'mimes' => 'jpg,jpeg,png,gif,webp,mp3,m4a,ogg,wav,webm,pdf,doc,docx',
        ],

        DocumentPurpose::TicketAttachment->value => [
            'visibility' => 'private',
            'max_kb' => 20480,
            'mimes' => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,txt,zip',
        ],

        DocumentPurpose::PaymentReceipt->value => [
            'visibility' => 'private',
            'max_kb' => 10240,
            'mimes' => 'jpg,jpeg,png,webp,pdf',
        ],

        DocumentPurpose::AssignmentSubmission->value => [
            'visibility' => 'private',
            'max_kb' => 20480,
            'mimes' => 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,zip',
        ],

        DocumentPurpose::AssignmentCorrected->value => [
            'visibility' => 'private',
            'max_kb' => 20480,
            'mimes' => 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,zip',
        ],

        DocumentPurpose::LandingImage->value => [
            'visibility' => 'public',
            'max_kb' => 5120,
            'mimes' => 'jpg,jpeg,png,webp,gif,svg',
        ],

        DocumentPurpose::BrandingLogo->value => [
            'visibility' => 'public',
            'max_kb' => 2048,
            'mimes' => 'jpg,jpeg,png,webp,svg',
        ],

        DocumentPurpose::BrandingFavicon->value => [
            'visibility' => 'public',
            'max_kb' => 512,
            'mimes' => 'png,webp,svg,ico',
        ],

        DocumentPurpose::BrandingCover->value => [
            'visibility' => 'public',
            'max_kb' => 5120,
            'mimes' => 'jpg,jpeg,png,webp',
        ],

        DocumentPurpose::PackageCover->value => [
            'visibility' => 'public',
            'max_kb' => 5120,
            'mimes' => 'jpg,jpeg,png,webp',
        ],

        DocumentPurpose::VideoSource->value => [
            'visibility' => 'private',
            'max_kb' => 1048576, // 1 GB
            'mimes' => 'mp4,mov,webm,mkv',
        ],

        DocumentPurpose::VideoThumbnail->value => [
            'visibility' => 'public',
            'max_kb' => 2048,
            'mimes' => 'jpg,jpeg,png,webp',
        ],

        DocumentPurpose::InvoicePdf->value => [
            'visibility' => 'private',
            'max_kb' => 10240,
            'mimes' => 'pdf',
        ],

        DocumentPurpose::StudentImport->value => [
            'visibility' => 'private',
            'max_kb' => 10240,
            'mimes' => 'xlsx,xls,csv,txt',
        ],

    ],

];
