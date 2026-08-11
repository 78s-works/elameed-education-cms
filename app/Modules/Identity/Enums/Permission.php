<?php

namespace App\Modules\Identity\Enums;

/**
 * The catalog of grantable assistant permissions (M18, FR-M18-02) — one entry
 * per delegatable surface of the teacher panel. A teacher grants a subset to each
 * assistant; the `permission` middleware enforces them on the shared routes.
 *
 * Teachers (academy owners) implicitly hold every permission. Adding a new
 * surface = add a case here, gate its routes with `permission:<value>`, and move
 * them into the `role:teacher,assistant` group.
 */
enum Permission: string
{
    case Students = 'students';
    case Centers = 'centers';
    case Homework = 'homework';
    case Finance = 'finance';
    case Support = 'support';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /**
     * Human-readable catalog for the teacher UI (GET /teacher/permissions).
     *
     * @return list<array{key:string, label:string, description:string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'key' => self::Students->value,
                'label' => 'Students',
                'description' => 'Add, edit and remove students; manage enrollments, wallet and activity.',
            ],
            [
                'key' => self::Centers->value,
                'label' => 'Centers & codes',
                'description' => 'Manage centers, attendance, and activation/recharge codes.',
            ],
            [
                'key' => self::Homework->value,
                'label' => 'Homework grading',
                'description' => 'Review and grade (correct) student homework submissions.',
            ],
            [
                'key' => self::Finance->value,
                'label' => 'Finance',
                'description' => 'View and manage billing, invoices, wallet top-ups and financial reports.',
            ],
            [
                'key' => self::Support->value,
                'label' => 'Support tickets',
                'description' => 'Read, reply to and change the status of student support tickets.',
            ],
        ];
    }

    /** Normalise + de-duplicate a client-supplied list to known permission values. */
    public static function sanitize(array $permissions): array
    {
        return array_values(array_intersect(self::values(), array_map('strval', $permissions)));
    }
}
