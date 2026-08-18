<?php

namespace App\Modules\Catalog\Enums;

/**
 * The kind of target a manual content-access override grants access to. Maps to
 * the matching nullable FK column on `content_access_overrides`.
 */
enum ContentAccessTarget: string
{
    case Lesson = 'lesson';
    case Section = 'section';

    /** The override column that stores this target's id. */
    public function column(): string
    {
        return match ($this) {
            self::Lesson => 'lesson_id',
            self::Section => 'section_id',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
