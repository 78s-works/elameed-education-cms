<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds each access token to the device it was minted on. `device_id` holds a
 * SHA-256 hash of the client's `X-Device-Id` header (never the raw value). A
 * token whose stored hash does not match the header on a later request is
 * rejected, so a token copied out of one browser's storage does not
 * authenticate in another browser/device. Nullable: tokens issued before this
 * migration stay unbound (legacy grace) until the user signs in again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->index()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
