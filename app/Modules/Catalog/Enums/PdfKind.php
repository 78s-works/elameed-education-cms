<?php

namespace App\Modules\Catalog\Enums;

/**
 * Role of a PDF resource inside a lesson (FR-M04-01). Lets a lesson carry
 * several PDFs, each with a distinct purpose — and lets dependency rules gate
 * the answer sheets until an assignment/exam is submitted.
 */
enum PdfKind: string
{
    case LectureNotes = 'lecture_notes';
    case AssignmentAnswerSheet = 'assignment_answer_sheet';
    case ExamAnswerSheet = 'exam_answer_sheet';
}
