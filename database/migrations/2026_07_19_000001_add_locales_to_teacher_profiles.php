<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-language landing (EDU enhancement). A teacher enables a subset of the
 * platform locales and picks a primary; each landing section's `content` becomes
 * a per-locale map ({ ar: {...}, en: {...} }). See LandingSchema + docs.
 *
 *  - locales:        the academy's enabled UI languages, e.g. ["ar","en"].
 *  - primary_locale: the fallback language for untranslated sections.
 *
 * Backfill wraps every existing (flat, single-language) section content under
 * the primary locale, so the pre-i18n data keeps rendering unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $primary = (string) config('tenancy.default_locale', 'ar');

        Schema::table('teacher_profiles', function (Blueprint $table) use ($primary): void {
            $table->json('locales')->nullable()->after('landing_sections');
            $table->string('primary_locale', 8)->default($primary)->after('locales');
        });

        $this->backfill($primary);
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn(['locales', 'primary_locale']);
        });
    }

    /** Wrap existing flat section content under the primary locale + seed locales. */
    private function backfill(string $primary): void
    {
        $pg = DB::getDriverName() === 'pgsql';

        // RLS on teacher_profiles is FORCED (applies to the table owner too), so a
        // migration with no app.tenant_id set would see zero rows. Lift FORCE for
        // the duration of the backfill, then restore it.
        if ($pg) {
            DB::statement('ALTER TABLE teacher_profiles NO FORCE ROW LEVEL SECURITY');
        }

        try {
            DB::table('teacher_profiles')->orderBy('id')->chunkById(200, function ($rows) use ($primary): void {
                foreach ($rows as $row) {
                    $update = [
                        'locales' => json_encode([$primary]),
                        'primary_locale' => $primary,
                    ];

                    $sections = $row->landing_sections ? json_decode($row->landing_sections, true) : null;
                    if (is_array($sections)) {
                        $update['landing_sections'] = json_encode(
                            self::wrapSections($sections, $primary),
                            JSON_UNESCAPED_UNICODE
                        );
                    }

                    DB::table('teacher_profiles')->where('id', $row->id)->update($update);
                }
            });
        } finally {
            if ($pg) {
                DB::statement('ALTER TABLE teacher_profiles FORCE ROW LEVEL SECURITY');
            }
        }
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return array<int, array<string, mixed>>
     */
    private static function wrapSections(array $sections, string $primary): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $content = $section['content'] ?? [];

            // Idempotency guard: skip if already locale-keyed under the primary.
            $alreadyWrapped = is_array($content)
                && array_key_exists($primary, $content)
                && is_array($content[$primary]);

            $section['content'] = $alreadyWrapped ? $content : [$primary => (array) $content];
            $out[] = $section;
        }

        return $out;
    }
};
