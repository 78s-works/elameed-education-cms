<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retire Course-grouping tables in favour of recursive Packages (VD change set
 * §7.4, doc 13 Phase 5, decision D13-2 = hard drop). Runs AFTER the packages +
 * package_items tables exist (000008/000009) and after Phase 3 made lessons
 * standalone (000003).
 *
 * Documented data mapping (per tenant, into that tenant's earliest / "Default"
 * academic year):
 *   • each `unit`         → a `package` (name = unit.title). Every lesson whose
 *                           `lesson.unit_id` = that unit becomes a `lesson`
 *                           package_item (preserving `sort_order`).
 *   • each `bundle`       → a `package` (name/title, price, currency, purchasable).
 *   • each `bundle_item`  → a package_item on the bundle's package:
 *        - item_type=unit   → a `package` item pointing at the unit's new package;
 *        - item_type=lesson → a `lesson` item;
 *        - item_type=course → SKIPPED (no course grouping in the lesson/package
 *          model; courses stay independently sellable). Loss is documented here.
 *   • `unit_dependencies` → no mapping (unit prerequisites are not part of the new
 *                           model); the table is dropped.
 *
 * Then the FKs that point at `units`/`bundles` from kept tables are dropped and the
 * four old tables are dropped. The scalar columns `lessons.unit_id`,
 * `exams.unit_id`, `enrollments.unit_id`, `enrollments.bundle_id` are LEFT in place
 * as dormant nullable columns (the progression/reporting engine still reads them;
 * they are simply always null for standalone content). `down()` recreates the
 * tables + FKs structurally — reversible in shape, not in data (a drop loses rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrateData();

        // Unwind FKs from kept tables so the old tables can be dropped. Columns stay
        // as dormant nullable scalars (progression/reporting/overrides still read them).
        Schema::table('lessons', fn (Blueprint $t) => $t->dropForeign(['unit_id']));
        Schema::table('exams', fn (Blueprint $t) => $t->dropForeign(['unit_id']));
        Schema::table('content_access_overrides', fn (Blueprint $t) => $t->dropForeign(['unit_id']));
        Schema::table('enrollments', function (Blueprint $t): void {
            $t->dropForeign(['unit_id']);
            $t->dropForeign(['bundle_id']);
        });

        // Drop the retired tables (children first — each drop takes its own FKs).
        Schema::dropIfExists('unit_dependencies');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('units');
        Schema::dropIfExists('bundles');
    }

    /** Copy units/bundles/bundle_items into packages/package_items. Idempotent-safe. */
    private function migrateData(): void
    {
        if (! Schema::hasTable('units') && ! Schema::hasTable('bundles')) {
            return;
        }

        $tenantIds = DB::table('academic_years')->distinct()->pluck('tenant_id')
            ->merge(Schema::hasTable('units') ? DB::table('units')->distinct()->pluck('tenant_id') : collect())
            ->merge(Schema::hasTable('bundles') ? DB::table('bundles')->distinct()->pluck('tenant_id') : collect())
            ->unique();

        foreach ($tenantIds as $tenantId) {
            $yearId = $this->defaultYearId((int) $tenantId);
            $unitPackageMap = [];

            // Units → packages (+ their lessons as lesson items).
            if (Schema::hasTable('units')) {
                foreach (DB::table('units')->where('tenant_id', $tenantId)->orderBy('id')->get() as $unit) {
                    $packageId = $this->insertPackage($tenantId, $yearId, $unit->title, null, null, true);
                    $unitPackageMap[$unit->id] = $packageId;

                    $lessons = DB::table('lessons')
                        ->where('tenant_id', $tenantId)->where('unit_id', $unit->id)
                        ->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

                    foreach ($lessons as $lesson) {
                        $this->insertItem($tenantId, $packageId, 'lesson', (int) $lesson->id, (int) $lesson->sort_order);
                    }
                }
            }

            // Bundles → packages (+ their bundle_items as items).
            if (Schema::hasTable('bundles')) {
                foreach (DB::table('bundles')->where('tenant_id', $tenantId)->orderBy('id')->get() as $bundle) {
                    $packageId = $this->insertPackage(
                        $tenantId, $yearId, $bundle->title,
                        $bundle->price_minor ?? null, $bundle->currency ?? null,
                        (bool) ($bundle->purchase_enabled ?? true),
                    );

                    if (! Schema::hasTable('bundle_items')) {
                        continue;
                    }

                    $items = DB::table('bundle_items')
                        ->where('bundle_id', $bundle->id)->orderBy('sort_order')->orderBy('id')->get();

                    foreach ($items as $item) {
                        if ($item->item_type === 'lesson' && $item->lesson_id !== null) {
                            $this->insertItem($tenantId, $packageId, 'lesson', (int) $item->lesson_id, (int) $item->sort_order);
                        } elseif ($item->item_type === 'unit' && $item->unit_id !== null && isset($unitPackageMap[$item->unit_id])) {
                            $this->insertItem($tenantId, $packageId, 'package', $unitPackageMap[$item->unit_id], (int) $item->sort_order);
                        }
                        // item_type=course → intentionally skipped (see class docblock).
                    }
                }
            }
        }
    }

    private function defaultYearId(int $tenantId): int
    {
        $yearId = DB::table('academic_years')->where('tenant_id', $tenantId)
            ->orderBy('sort_order')->orderBy('id')->value('id');

        return $yearId ?? DB::table('academic_years')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => 'Default',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPackage(int $tenantId, int $yearId, string $name, $priceMinor, $currency, bool $purchasable): int
    {
        return DB::table('packages')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'academic_year_id' => $yearId,
            'name' => $name,
            'access_mode' => 'both',
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'is_purchasable' => $purchasable,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertItem(int $tenantId, int $packageId, string $type, int $itemId, int $sortOrder): void
    {
        DB::table('package_items')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'item_type' => $type,
            'item_id' => $itemId,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Recreate the retired tables + FKs structurally (shape only — dropped rows are
     * not restored). Lets migrate:rollback/refresh round-trip.
     */
    public function down(): void
    {
        if (! Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('title');
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('visibility')->default('visible');
                $table->timestamp('publish_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'course_id']);
            });
        }

        if (! Schema::hasTable('bundles')) {
            Schema::create('bundles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->string('slug');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('price_minor')->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->unsignedInteger('access_days')->nullable();
                $table->string('visibility')->default('hidden');
                $table->timestamp('publish_at')->nullable();
                $table->boolean('is_free')->default(false);
                $table->boolean('purchase_enabled')->default(true);
                $table->string('cover_url')->nullable();
                $table->string('thumbnail_url')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'slug']);
                $table->index(['tenant_id', 'visibility']);
            });
        }

        if (! Schema::hasTable('bundle_items')) {
            Schema::create('bundle_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
                $table->string('item_type');
                $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();
                $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'bundle_id']);
            });
        }

        if (! Schema::hasTable('unit_dependencies')) {
            Schema::create('unit_dependencies', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
                $table->foreignId('depends_on_unit_id')->nullable()->constrained('units')->cascadeOnDelete();
                $table->foreignId('depends_on_section_id')->nullable()->constrained('lesson_sections')->cascadeOnDelete();
                $table->string('trigger');
                $table->string('enforcement');
                $table->timestamps();
                $table->index(['tenant_id', 'unit_id']);
                $table->unique(['unit_id', 'depends_on_unit_id', 'depends_on_section_id'], 'unit_dep_pair_unique');
            });
        }

        // Re-add the dormant-column FKs to the recreated tables.
        Schema::table('lessons', fn (Blueprint $t) => $t->foreign('unit_id')->references('id')->on('units')->nullOnDelete());
        Schema::table('exams', fn (Blueprint $t) => $t->foreign('unit_id')->references('id')->on('units')->nullOnDelete());
        Schema::table('content_access_overrides', fn (Blueprint $t) => $t->foreign('unit_id')->references('id')->on('units')->cascadeOnDelete());
        Schema::table('enrollments', function (Blueprint $t): void {
            $t->foreign('unit_id')->references('id')->on('units')->cascadeOnDelete();
            $t->foreign('bundle_id')->references('id')->on('bundles')->nullOnDelete();
        });
    }
};
