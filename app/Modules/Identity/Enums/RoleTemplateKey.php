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

    /**
     * Display name. Arabic: these strings are shown as-is in the admin console
     * and stamped onto every academy's copy of the role, and the panel they
     * appear in is Arabic-only.
     */
    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'مالك الأكاديمية',
            self::Assistant => 'مساعد',
            self::Student => 'طالب',
            self::ParentGuardian => 'ولي أمر',
            self::StudentsManager => 'مسؤول الطلاب',
            self::Finance => 'المالية',
            self::HomeworkGrader => 'مصحّح الواجبات',
            self::SupportAgent => 'موظف الدعم',
            self::ContentEditor => 'محرّر المحتوى',
        };
    }

    /**
     * What the role can do AND what it cannot — a teacher picks an assistant's
     * role from this sentence alone, so the limit matters as much as the grant.
     */
    public function description(): string
    {
        return match ($this) {
            self::Teacher => 'يملك كل الصلاحيات في الأكاديمية. يُحدَّث تلقائيًا مع كتالوج الصلاحيات.',
            self::Assistant => 'عضوية أساسية لفريق العمل. لا تمنح أي صلاحية بمفردها — تُضاف فوقها الأدوار الأخرى.',
            self::Student => 'عضوية أساسية للطالب. لا تمنح أي صلاحية في لوحة الأكاديمية.',
            self::ParentGuardian => 'عضوية أساسية لولي الأمر. لا تمنح أي صلاحية في لوحة الأكاديمية.',
            self::StudentsManager => 'يدير الطلاب والاشتراكات والسناتر والحضور. لا يرى الإيرادات ولا يعدّل المحتوى.',
            self::Finance => 'يراجع الإيصالات والكوبونات والتقارير المالية. لا يعدّل المحتوى ولا يدير الطلاب.',
            self::HomeworkGrader => 'يراجع ويصحّح تسليمات الطلاب. لا يعدّل الاختبارات ولا يرى الإيرادات.',
            self::SupportAgent => 'يرد على تذاكر الدعم ويغلقها. لا يعدّل المحتوى ولا يرى الإيرادات.',
            self::ContentEditor => 'ينشئ الدروس والأجزاء والباقات والوسائط. لا يرى الطلاب ولا الإيرادات.',
        };
    }
}
