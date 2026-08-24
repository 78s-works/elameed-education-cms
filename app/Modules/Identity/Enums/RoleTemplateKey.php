<?php

namespace App\Modules\Identity\Enums;

/**
 * The platform-level role templates (M20). Every tenant gets an independent COPY
 * of each one at creation time; editing a copy never touches the template, and
 * editing a template never touches copies that already exist — only tenants
 * created afterwards see the change.
 *
 * Two kinds:
 *   - SYSTEM (isSystem() === true): teacher, assistant, student, parent. One per
 *     membership kind, always present, never editable from the academy panel.
 *   - FREE: starter bundles the teacher may rename, re-scope or delete outright.
 *
 * The permission SETS live in RoleTemplateSeeder, not here, so a template's
 * contents can change without touching this enum.
 */
enum RoleTemplateKey: string
{
    // System roles — one per TenantUserRole, locked in every tenant.
    case Teacher = 'teacher';
    case Assistant = 'assistant';
    case Student = 'student';
    case ParentGuardian = 'parent';

    // Free starter roles — the teacher owns these copies outright.
    case StudentsManager = 'students_manager';
    case Finance = 'finance';
    case HomeworkGrader = 'homework_grader';
    case SupportAgent = 'support_agent';
    case ContentEditor = 'content_editor';

    /** @return list<self> */
    public static function systemKeys(): array
    {
        return [self::Teacher, self::Assistant, self::Student, self::ParentGuardian];
    }

    public function isSystem(): bool
    {
        return in_array($this, self::systemKeys(), true);
    }

    /**
     * The membership kind this system role belongs to. Assigning a role is driven
     * off this: a student membership gets the `student` role, and so on. Free
     * roles map to nothing — they are add-ons, granted by hand.
     */
    public function membershipRole(): ?TenantUserRole
    {
        return match ($this) {
            self::Teacher => TenantUserRole::Teacher,
            self::Assistant => TenantUserRole::Assistant,
            self::Student => TenantUserRole::Student,
            self::ParentGuardian => TenantUserRole::Parent,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'Academy owner',
            self::Assistant => 'Assistant',
            self::Student => 'Student',
            self::ParentGuardian => 'Parent',
            self::StudentsManager => 'Students manager',
            self::Finance => 'Finance',
            self::HomeworkGrader => 'Homework grader',
            self::SupportAgent => 'Support agent',
            self::ContentEditor => 'Content editor',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Teacher => 'Holds every permission in the academy. Kept in sync with the catalog automatically.',
            self::Assistant => 'Baseline staff membership. Carries no permission on its own — stack roles on top.',
            self::Student => 'Baseline student membership. Carries no permission in the academy panel.',
            self::ParentGuardian => 'Baseline parent membership. Carries no permission in the academy panel.',
            self::StudentsManager => 'Students, enrollments, centers and attendance.',
            self::Finance => 'Receipts, coupons and financial reports.',
            self::HomeworkGrader => 'Review and grade student submissions.',
            self::SupportAgent => 'Answer and resolve student support tickets.',
            self::ContentEditor => 'Author lessons, parts, packages and media.',
        };
    }
}
