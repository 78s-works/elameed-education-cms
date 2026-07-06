<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `enrollments` — the single source of truth for content access (03_Data_Model.md
 * §3, §5). The playback-authz endpoint checks an active, in-window enrollment
 * before issuing a token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
            $table->unsignedBigInteger('bundle_id')->nullable(); // P1.5
            $table->string('source')->default('purchase');       // purchase|wallet|code|manual|center
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();          // from access_days
            $table->string('status')->default('active');          // active|expired|cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'course_id']);
        });

        TenantRls::enableFor('enrollments');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
