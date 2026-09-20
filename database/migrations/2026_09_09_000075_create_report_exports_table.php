<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `report_exports` — one row per requested report file (EDU-021).
 *
 * Report generation moved off the request: a ledger PDF over a year of orders
 * takes minutes, and an inline download times out at the web server long before
 * the query finishes. The row is created immediately, a job on the `exports`
 * queue fills it in, and the client polls until `status` is ready and then
 * downloads.
 *
 * `tenant_id` is nullable on purpose: the platform-wide report belongs to the
 * admin console, which sits above any tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            // Null = a platform-level (admin console) export, which no tenant owns.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('report', 32);            // sales | students | overview | platform
            $table->string('format', 8);             // csv | xlsx | pdf
            $table->string('locale', 8)->default('ar');
            // The filters the report was run with, so a stale file can still say
            // what it covers and a re-run is reproducible.
            $table->json('filters')->nullable();
            $table->string('status', 16)->default('queued'); // queued|processing|ready|failed|expired
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The list endpoint reads "my exports, newest first".
            $table->index(['tenant_id', 'requested_by', 'created_at']);
            // The purge command sweeps by expiry.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
