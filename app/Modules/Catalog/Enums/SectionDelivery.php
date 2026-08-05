<?php

namespace App\Modules\Catalog\Enums;

/**
 * How a lesson part's work is delivered / collected (VD change set §7). Set on
 * every authored part:
 *
 *   video_upload  — a lecture video part (the uploaded MediaAsset).
 *   image_upload  — homework/quiz answered by uploading photo(s).
 *   pdf_upload    — homework/quiz answered by uploading a PDF.
 *   bubble_sheet  — on-site digital MCQ sheet; the only delivery that may be
 *                   auto-graded (LP-12).
 */
enum SectionDelivery: string
{
    case VideoUpload = 'video_upload';
    case ImageUpload = 'image_upload';
    case PdfUpload = 'pdf_upload';
    case BubbleSheet = 'bubble_sheet';

    /** File/photo uploads are always human-graded; only a bubble sheet may auto-grade. */
    public function supportsAutoGrading(): bool
    {
        return $this === self::BubbleSheet;
    }
}
