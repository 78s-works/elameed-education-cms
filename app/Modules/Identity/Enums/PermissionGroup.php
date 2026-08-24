<?php

namespace App\Modules\Identity\Enums;

/**
 * Display grouping for the permission catalog (M20).
 *
 * With ~70 fine-grained keys, an ungrouped checkbox list is unusable — the
 * teacher panel renders one collapsible section per group, in `order()`, with a
 * select-all toggle per section. Groups carry NO authority of their own: they
 * are presentation only, and granting a group means granting its keys.
 */
enum PermissionGroup: string
{
    case Content = 'content';
    case Exams = 'exams';
    case Students = 'students';
    case Centers = 'centers';
    case Finance = 'finance';
    case Support = 'support';
    case Community = 'community';
    case Settings = 'settings';
    case Reports = 'reports';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Content => 'Content',
            self::Exams => 'Exams',
            self::Students => 'Students',
            self::Centers => 'Centers & attendance',
            self::Finance => 'Finance',
            self::Support => 'Support',
            self::Community => 'Community',
            self::Settings => 'Academy settings',
            self::Reports => 'Reports',
            self::Team => 'Team',
        };
    }

    /** Position in the picker; lower comes first. */
    public function order(): int
    {
        return match ($this) {
            self::Content => 1,
            self::Exams => 2,
            self::Students => 3,
            self::Centers => 4,
            self::Finance => 5,
            self::Support => 6,
            self::Community => 7,
            self::Reports => 8,
            self::Settings => 9,
            self::Team => 10,
        };
    }
}
