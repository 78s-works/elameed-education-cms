<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_callback_events` — replay/idempotency ledger for signed processing
 * callbacks from the Media Host (docs/MEDIA_HOST_API_v1.md §1.2/§6). A callback
 * whose `event_id` is already present is acknowledged but NOT re-applied. Not
 * tenant-scoped: it is consulted at ingest time before any tenant is resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_callback_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id')->unique();              // X-Media-Event-Id
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('media_version_id')->nullable();
            $table->string('type')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_callback_events');
    }
};
