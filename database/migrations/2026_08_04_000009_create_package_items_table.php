<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `package_items` — the ordered contents of a {@see App\Modules\Catalog\Models\Package}:
 * each row references EITHER a lesson OR a sub-package (`item_type`), by that
 * model's internal id (`item_id`). A lesson may sit in many packages (LP-3
 * reuse); a package may nest sub-packages (LP-2 recursion, cycle-guarded in
 * PackageItemService).
 *
 * `item_id` is intentionally NOT a hard FK — it is polymorphic across two tables
 * (`lessons` / `packages`), so referential integrity is kept in the app:
 *   - deleting the parent package cascades these rows (`package_id` FK);
 *   - deleting a member lesson auto-detaches its rows (VD-D1c, Lesson `deleting`
 *     hook); deleting a nested package auto-detaches its rows (Package hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->enum('item_type', ['lesson', 'package']);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['package_id', 'sort_order']);
            // A given lesson/sub-package can appear at most once in a package.
            $table->unique(['package_id', 'item_type', 'item_id']);
        });

        TenantRls::enableFor('package_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
    }
};
