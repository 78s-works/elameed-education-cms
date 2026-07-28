<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — template copy (doc 10 §3, §6 language resolution). One
 * row per language per template holds the actual `title` + `body`. Belongs to a
 * template (which already carries scope/tenant), so no `tenant_id` here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template_translations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Explicit short FK name: the auto-generated
            // `notification_template_translations_notification_template_id_foreign`
            // is 67 chars and exceeds MySQL's 64-char identifier limit.
            $table->foreignId('notification_template_id')
                ->constrained('notification_templates', 'id', 'notif_tpl_translation_tpl_fk')
                ->cascadeOnDelete();
            $table->string('language', 8);           // e.g. ar, en
            $table->string('title');
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamps();

            $table->unique(['notification_template_id', 'language'], 'notif_tpl_translation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_translations');
    }
};
