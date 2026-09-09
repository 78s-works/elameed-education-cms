<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `otp_codes` — one-time passcodes for registration, password reset, and
 * (optional) login (FR-M11-02). Supporting table (not in the data model, but
 * required to issue/verify OTPs and rate-limit abuse). The code is stored as a
 * HASH, never in plaintext; `attempts` caps brute-force on verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('identifier');            // phone or email the code was sent to
            $table->string('channel')->default('sms'); // sms | email
            $table->string('purpose');               // register | login | reset
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['identifier', 'purpose', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
