<?php

namespace App\Modules\Identity\Enums;

/**
 * The platform's permission catalog (M20) — one entry per ACTION, not per screen.
 *
 * This enum is the single source of truth. The `permissions` table is a
 * projection of it, refreshed by RolesAndPermissionsSeeder on every deploy, so
 * a key that exists here always exists in the database and never the reverse.
 *
 * Naming is `<domain>.<action>`, and `<domain>.<sub>.<action>` where a domain has
 * a distinct child resource (`students.parents.manage`). The domain prefix is
 * also the UI group, which is why the two never drift.
 *
 * Adding a capability = add a case + a catalog() entry + gate the route with
 * `can:<value>`. The teacher role picks it up automatically on the next seed;
 * every other role must be granted it explicitly.
 *
 * NOTE: same short name as Spatie's Permission model. Alias one of them when a
 * file needs both (convention: `use ...Enums\Permission as PermissionEnum`).
 */
enum Permission: string
{
    // ---- Content authoring -------------------------------------------------
    case ContentView = 'content.view';
    case AcademicYearsManage = 'content.academic_years.manage';
    case LessonsCreate = 'content.lessons.create';
    case LessonsUpdate = 'content.lessons.update';
    case LessonsDelete = 'content.lessons.delete';
    case LessonSectionsManage = 'content.lesson_sections.manage';
    case LessonAttachmentsManage = 'content.lesson_attachments.manage';
    case LessonAvailabilityManage = 'content.lesson_availability.manage';
    case LessonReopen = 'content.lessons.reopen';
    case ExtensionRequestsReview = 'content.extension_requests.review';
    case PackagesManage = 'content.packages.manage';
    case PackageTypesManage = 'content.package_types.manage';
    case MediaUpload = 'content.media.upload';
    case MediaManage = 'content.media.manage';

    // ---- Exams -------------------------------------------------------------
    case ExamsView = 'exams.view';
    case ExamsCreate = 'exams.create';
    case ExamsUpdate = 'exams.update';
    case ExamsDelete = 'exams.delete';
    case ExamQuestionsManage = 'exams.questions.manage';
    case ExamBubbleSheetManage = 'exams.bubble_sheet.manage';
    case ExamSubmissionsView = 'exams.submissions.view';
    case ExamGrade = 'exams.grade';
    case ExamPassOverride = 'exams.pass_override';
    case ExamExtensionsReview = 'exams.extensions.review';

    // ---- Students ----------------------------------------------------------
    case StudentsView = 'students.view';
    case StudentsCreate = 'students.create';
    case StudentsUpdate = 'students.update';
    case StudentsDelete = 'students.delete';
    case StudentsImport = 'students.import';
    case StudentsExport = 'students.export';
    case StudentsResetPassword = 'students.reset_password';
    case StudentsEnrollmentsManage = 'students.enrollments.manage';
    case StudentsOverridesManage = 'students.content_overrides.manage';
    case StudentsWalletView = 'students.wallet.view';
    case StudentsWalletAdjust = 'students.wallet.adjust';
    case StudentsActivityView = 'students.activity.view';
    case StudentsNotify = 'students.notify';
    case StudentsParentsManage = 'students.parents.manage';

    // ---- Centers & attendance ----------------------------------------------
    case CentersView = 'centers.view';
    case CentersCreate = 'centers.create';
    case CentersUpdate = 'centers.update';
    case CentersDelete = 'centers.delete';
    case CenterSessionsManage = 'centers.sessions.manage';
    case AttendanceView = 'centers.attendance.view';
    case AttendanceRecord = 'centers.attendance.record';
    case AttendanceRevoke = 'centers.attendance.revoke';
    case ActivationCodesView = 'centers.activation_codes.view';
    case ActivationCodesIssue = 'centers.activation_codes.issue';
    case ActivationCodesDisable = 'centers.activation_codes.disable';
    case CenterIdCodesManage = 'centers.id_codes.manage';
    case CenterExamGradesManage = 'centers.exam_grades.manage';

    // ---- Finance -----------------------------------------------------------
    case PaymentReceiptsReview = 'finance.receipts.review';
    case CouponsManage = 'finance.coupons.manage';
    case SubscriptionView = 'finance.subscription.view';
    case SalesView = 'finance.sales.view';
    case RefundsManage = 'finance.refunds.manage';

    // ---- Support -----------------------------------------------------------
    case SupportView = 'support.view';
    case SupportReply = 'support.reply';
    case SupportStatusChange = 'support.status.change';

    // ---- Community ---------------------------------------------------------
    case ForumView = 'community.forum.view';
    case ForumModerate = 'community.forum.moderate';
    case ReviewsManage = 'community.reviews.manage';
    case BadgesManage = 'community.badges.manage';
    case GamificationManage = 'community.gamification.manage';

    // ---- Reports -----------------------------------------------------------
    case ReportsView = 'reports.view';
    case AuditLogView = 'reports.audit_log.view';

    // ---- Academy settings --------------------------------------------------
    case AccessManage = 'settings.access.manage';
    case LandingManage = 'settings.landing.manage';
    case MetaManage = 'settings.meta.manage';
    case DomainsManage = 'settings.domains.manage';
    case SmsSettingsManage = 'settings.sms.manage';
    case NotificationsManage = 'settings.notifications.manage';
    // Writing and sending a custom message to students/assistants. Deliberately
    // its own permission: an assistant gets it only when the teacher grants it,
    // because it spends the academy's SMS credit and reaches muted students.
    case NotificationsSend = 'settings.notifications.send';

    // ---- Team --------------------------------------------------------------
    // Grantable by the academy owner ONLY — see TeamAuthority. Delegating team
    // management must not let the delegate delegate it onward.
    case TeamView = 'team.view';
    case TeamAssistantsManage = 'team.assistants.manage';
    case TeamRolesManage = 'team.roles.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::ContentView, self::AcademicYearsManage, self::LessonsCreate,
            self::LessonsUpdate, self::LessonsDelete, self::LessonSectionsManage,
            self::LessonAttachmentsManage, self::LessonAvailabilityManage,
            self::LessonReopen, self::ExtensionRequestsReview, self::PackagesManage,
            self::PackageTypesManage, self::MediaUpload, self::MediaManage => PermissionGroup::Content,

            self::ExamsView, self::ExamsCreate, self::ExamsUpdate, self::ExamsDelete,
            self::ExamQuestionsManage, self::ExamBubbleSheetManage,
            self::ExamSubmissionsView, self::ExamGrade, self::ExamPassOverride,
            self::ExamExtensionsReview => PermissionGroup::Exams,

            self::StudentsView, self::StudentsCreate, self::StudentsUpdate,
            self::StudentsDelete, self::StudentsImport, self::StudentsExport,
            self::StudentsResetPassword, self::StudentsEnrollmentsManage,
            self::StudentsOverridesManage, self::StudentsWalletView,
            self::StudentsWalletAdjust, self::StudentsActivityView,
            self::StudentsNotify, self::StudentsParentsManage => PermissionGroup::Students,

            self::CentersView, self::CentersCreate, self::CentersUpdate,
            self::CentersDelete, self::CenterSessionsManage, self::AttendanceView,
            self::AttendanceRecord, self::AttendanceRevoke, self::ActivationCodesView,
            self::ActivationCodesIssue, self::ActivationCodesDisable,
            self::CenterIdCodesManage, self::CenterExamGradesManage => PermissionGroup::Centers,

            self::PaymentReceiptsReview, self::CouponsManage,
            self::SubscriptionView, self::SalesView,
            self::RefundsManage => PermissionGroup::Finance,

            self::SupportView, self::SupportReply, self::SupportStatusChange => PermissionGroup::Support,

            self::ForumView, self::ForumModerate, self::ReviewsManage,
            self::BadgesManage, self::GamificationManage => PermissionGroup::Community,

            self::ReportsView, self::AuditLogView => PermissionGroup::Reports,

            self::AccessManage, self::LandingManage, self::MetaManage, self::DomainsManage,
            self::SmsSettingsManage, self::NotificationsManage,
            self::NotificationsSend => PermissionGroup::Settings,

            self::TeamView, self::TeamAssistantsManage, self::TeamRolesManage => PermissionGroup::Team,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ContentView => 'View content',
            self::AcademicYearsManage => 'Manage academic years',
            self::LessonsCreate => 'Create lessons',
            self::LessonsUpdate => 'Edit lessons',
            self::LessonsDelete => 'Delete lessons',
            self::LessonSectionsManage => 'Manage lesson parts',
            self::LessonAttachmentsManage => 'Manage lesson attachments',
            self::LessonAvailabilityManage => 'Manage lesson availability',
            self::LessonReopen => 'Reopen a lesson for a student',
            self::ExtensionRequestsReview => 'Review lesson extension requests',
            self::PackagesManage => 'Manage content packages',
            self::PackageTypesManage => 'Manage package types',
            self::MediaUpload => 'Upload video',
            self::MediaManage => 'Manage uploaded video',

            self::ExamsView => 'View exams',
            self::ExamsCreate => 'Create exams',
            self::ExamsUpdate => 'Edit exams',
            self::ExamsDelete => 'Delete exams',
            self::ExamQuestionsManage => 'Manage exam questions',
            self::ExamBubbleSheetManage => 'Manage bubble sheets',
            self::ExamSubmissionsView => 'View submissions',
            self::ExamGrade => 'Grade submissions',
            self::ExamPassOverride => 'Override a part pass',
            self::ExamExtensionsReview => 'Review exam extension requests',

            self::StudentsView => 'View students',
            self::StudentsCreate => 'Add students',
            self::StudentsUpdate => 'Edit students',
            self::StudentsDelete => 'Delete students',
            self::StudentsImport => 'Import students',
            self::StudentsExport => 'Export a student file',
            self::StudentsResetPassword => 'Reset a student password',
            self::StudentsEnrollmentsManage => 'Manage enrollments',
            self::StudentsOverridesManage => 'Manage content overrides',
            self::StudentsWalletView => 'View a student wallet',
            self::StudentsWalletAdjust => 'Adjust a student wallet',
            self::StudentsActivityView => 'View student activity',
            self::StudentsNotify => 'Notify a student',
            self::StudentsParentsManage => 'Manage linked parents',

            self::CentersView => 'View centers',
            self::CentersCreate => 'Create centers',
            self::CentersUpdate => 'Edit centers',
            self::CentersDelete => 'Delete centers',
            self::CenterSessionsManage => 'Manage center sessions',
            self::AttendanceView => 'View attendance',
            self::AttendanceRecord => 'Record attendance',
            self::AttendanceRevoke => 'Revoke a check-in',
            self::ActivationCodesView => 'View activation codes',
            self::ActivationCodesIssue => 'Issue activation codes',
            self::ActivationCodesDisable => 'Disable activation codes',
            self::CenterIdCodesManage => 'Manage center ID codes',
            self::CenterExamGradesManage => 'Manage paper-exam grades',

            self::PaymentReceiptsReview => 'Review payment receipts',
            self::CouponsManage => 'Manage coupons',
            self::SalesView => 'View the sales ledger',
            self::RefundsManage => 'Refund a paid order',
            self::SubscriptionView => 'View the subscription',

            self::SupportView => 'View support tickets',
            self::SupportReply => 'Reply to tickets',
            self::SupportStatusChange => 'Change ticket status',

            self::ForumView => 'View the forum',
            self::ForumModerate => 'Moderate the forum',
            self::ReviewsManage => 'Manage reviews',
            self::BadgesManage => 'Manage badges',
            self::GamificationManage => 'Manage gamification settings',

            self::ReportsView => 'View reports',
            self::AuditLogView => 'View the activity log',

            self::AccessManage => 'Manage sign-in and registration switches',
            self::LandingManage => 'Manage the landing page',
            self::MetaManage => 'Manage site metadata',
            self::DomainsManage => 'Manage domains',
            self::SmsSettingsManage => 'Manage SMS settings',
            self::NotificationsManage => 'Manage notification templates',
            self::NotificationsSend => 'Send custom notifications',

            self::TeamView => 'View the team',
            self::TeamAssistantsManage => 'Manage assistants',
            self::TeamRolesManage => 'Manage roles',
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::ContentView => 'Read-only access to years, lessons and packages. Every other content permission implies it.',
            self::LessonsDelete => 'Deleting a lesson removes its parts, attachments and student progress.',
            self::StudentsWalletAdjust => 'Credit or debit a balance directly, and overwrite it outright.',
            self::StudentsDelete => 'Removes the student from the academy along with their enrollments.',
            self::ExamPassOverride => 'Mark a must-pass part as passed without a qualifying attempt.',
            self::ActivationCodesIssue => 'Mint recharge/activation code batches worth real money.',
            self::SalesView => 'Read every transaction in the sales ledger, with student and amount.',
            self::RefundsManage => 'Reverse a paid order: refunds the money to the wallet and revokes the access it bought.',
            self::AccessManage => 'Close sign-in or self-registration for the whole academy.',
            self::NotificationsSend => 'Write and send a message to students or assistants. Spends SMS credit when the SMS channel is picked.',
            self::TeamRolesManage => 'Create roles and assign them. Only the academy owner can grant this.',
            self::TeamAssistantsManage => 'Invite, edit and remove assistants. Only the academy owner can grant this.',
            default => null,
        };
    }

    /**
     * The catalog the teacher UI renders, ordered by group then by declaration.
     *
     * @return list<array{key:string,label:string,description:?string,group:string,group_label:string,sort_order:int}>
     */
    public static function catalog(): array
    {
        $rows = [];

        foreach (self::cases() as $index => $case) {
            $rows[] = [
                'key' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'group' => $case->group()->value,
                'group_label' => $case->group()->label(),
                'sort_order' => $case->group()->order() * 1000 + $index,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $rows;
    }

    /** Normalise + de-duplicate a client-supplied list to known permission values. */
    public static function sanitize(array $permissions): array
    {
        return array_values(array_intersect(self::values(), array_map('strval', $permissions)));
    }
}
