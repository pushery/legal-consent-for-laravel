<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Support\IndexName;

/**
 * Record each change notice that is on its way, or failed, per subject and version.
 *
 * The notice sweep used to know one thing per subject: whether a delivery proof exists. A subject
 * whose notice was still in the queue therefore looked exactly like one who had never been sent
 * anything, and a mail failure re-opened the whole version. Three things followed. An address the
 * mail transport refuses on every attempt re-opened the version on every run, and each run sent that
 * subject a fresh in-app notice. A run that started while an earlier one's jobs were still queued
 * queued every one of those subjects a second time. And a failure that arrived while the sweep of
 * the same version was still running was lost, because the version was stamped afterwards.
 *
 * A row here says: this subject's notice for this version was queued at `queued_at`, and failed
 * `failures` times, the last at `failed_at`. The sweep skips a subject whose notice is still on its
 * way and one that has failed too often, and retries the others; a delivery deletes the row, because
 * from then on the proof row answers the question. It is operational state and not evidence, so it
 * carries no append-only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_notice_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('subject_type');
            // As wide as the key on `legal_consents` it is compared with, see migration 000018.
            $table->string('subject_id', 64);
            $table->timestamp('queued_at');
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('failed_at')->nullable();

            $table->unique(['document_id', 'subject_type', 'subject_id'], IndexName::of('legal_notice_attempts_subject_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_notice_attempts');
    }
};
