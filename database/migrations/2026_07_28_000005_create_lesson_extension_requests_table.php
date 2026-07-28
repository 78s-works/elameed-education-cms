<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_extension_requests` ("Lesson Availability & Extension Requests"). A
 * student's request for more time after (or near) window expiry. Staff grant or
 * deny; a grant pushes the window's `expires_at` out by the lesson's
 * `extension_hours` and increments `extensions_used`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_extension_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('access_window_id')->constrained('lesson_access_windows')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default('pending'); // ExtensionStatus
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable(); // staff user id

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        TenantRls::enableFor('lesson_extension_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_extension_requests');
    }
};
