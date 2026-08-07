<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — interview invitation/confirm fields, multi-interviewers, application tracking.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('member_interviews', function (Blueprint $table): void {
      if (! Schema::hasColumn('member_interviews', 'timezone')) {
        $table->string('timezone', 64)->nullable()->after('duration_minutes');
      }
      if (! Schema::hasColumn('member_interviews', 'meeting_platform')) {
        $table->string('meeting_platform', 80)->nullable()->after('meeting_link');
      }
      if (! Schema::hasColumn('member_interviews', 'meeting_password')) {
        $table->string('meeting_password')->nullable()->after('meeting_platform');
      }
      if (! Schema::hasColumn('member_interviews', 'instructions')) {
        $table->text('instructions')->nullable()->after('remarks');
      }
      if (! Schema::hasColumn('member_interviews', 'confirmation_token')) {
        $table->string('confirmation_token', 80)->nullable()->unique()->after('result');
      }
      if (! Schema::hasColumn('member_interviews', 'invitation_sent_at')) {
        $table->timestamp('invitation_sent_at')->nullable()->after('confirmation_token');
      }
      if (! Schema::hasColumn('member_interviews', 'confirmed_at')) {
        $table->timestamp('confirmed_at')->nullable()->after('invitation_sent_at');
      }
      if (! Schema::hasColumn('member_interviews', 'awaiting_review_notified_at')) {
        $table->timestamp('awaiting_review_notified_at')->nullable()->after('confirmed_at');
      }
      if (! Schema::hasColumn('member_interviews', 'parent_interview_id')) {
        $table->foreignId('parent_interview_id')->nullable()->after('member_id')->constrained('member_interviews')->nullOnDelete();
      }
    });

    if (! Schema::hasTable('member_interview_interviewers')) {
      Schema::create('member_interview_interviewers', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('member_interview_id')->constrained('member_interviews')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->boolean('is_primary')->default(false);
        $table->timestamps();
        $table->unique(['member_interview_id', 'user_id'], 'member_interview_user_unique');
      });
    }

    Schema::table('members', function (Blueprint $table): void {
      if (! Schema::hasColumn('members', 'application_number')) {
        $table->string('application_number', 40)->nullable()->unique()->after('membership_number');
      }
      if (! Schema::hasColumn('members', 'application_tracking_token')) {
        $table->string('application_tracking_token', 80)->nullable()->unique()->after('application_number');
      }
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('member_interview_interviewers');

    Schema::table('member_interviews', function (Blueprint $table): void {
      foreach ([
        'timezone',
        'meeting_platform',
        'meeting_password',
        'instructions',
        'confirmation_token',
        'invitation_sent_at',
        'confirmed_at',
        'awaiting_review_notified_at',
      ] as $column) {
        if (Schema::hasColumn('member_interviews', $column)) {
          $table->dropColumn($column);
        }
      }
      if (Schema::hasColumn('member_interviews', 'parent_interview_id')) {
        $table->dropConstrainedForeignId('parent_interview_id');
      }
    });

    Schema::table('members', function (Blueprint $table): void {
      foreach (['application_number', 'application_tracking_token'] as $column) {
        if (Schema::hasColumn('members', $column)) {
          $table->dropColumn($column);
        }
      }
    });
  }
};
