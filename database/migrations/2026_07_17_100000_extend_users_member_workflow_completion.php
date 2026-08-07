<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('users', function (Blueprint $table): void {
      if (! Schema::hasColumn('users', 'username')) {
        $table->string('username')->nullable()->unique()->after('email');
      }
      if (! Schema::hasColumn('users', 'must_change_password')) {
        $table->boolean('must_change_password')->default(false)->after('password');
      }
      if (! Schema::hasColumn('users', 'activation_token')) {
        $table->string('activation_token', 80)->nullable()->index()->after('remember_token');
      }
      if (! Schema::hasColumn('users', 'activated_at')) {
        $table->timestamp('activated_at')->nullable()->after('activation_token');
      }
    });

    Schema::table('member_interviews', function (Blueprint $table): void {
      if (! Schema::hasColumn('member_interviews', 'interview_type')) {
        $table->string('interview_type', 40)->default('online')->after('status');
      }
      if (! Schema::hasColumn('member_interviews', 'duration_minutes')) {
        $table->unsignedSmallInteger('duration_minutes')->nullable()->after('scheduled_time');
      }
      if (! Schema::hasColumn('member_interviews', 'external_interviewer_name')) {
        $table->string('external_interviewer_name')->nullable()->after('interviewer_id');
      }
      if (! Schema::hasColumn('member_interviews', 'venue')) {
        $table->string('venue')->nullable()->after('physical_location');
      }
    });

    Schema::table('member_notification_queue', function (Blueprint $table): void {
      if (! Schema::hasColumn('member_notification_queue', 'attempts')) {
        $table->unsignedSmallInteger('attempts')->default(0)->after('status');
      }
      if (! Schema::hasColumn('member_notification_queue', 'scheduled_at')) {
        $table->timestamp('scheduled_at')->nullable()->after('queued_at');
      }
      if (! Schema::hasColumn('member_notification_queue', 'cancelled_at')) {
        $table->timestamp('cancelled_at')->nullable()->after('sent_at');
      }
      if (! Schema::hasColumn('member_notification_queue', 'processing_at')) {
        $table->timestamp('processing_at')->nullable()->after('scheduled_at');
      }
    });

    Schema::table('member_ministry_assignments', function (Blueprint $table): void {
      if (! Schema::hasColumn('member_ministry_assignments', 'department')) {
        $table->string('department')->nullable()->after('role');
      }
      if (! Schema::hasColumn('member_ministry_assignments', 'team')) {
        $table->string('team')->nullable()->after('department');
      }
      if (! Schema::hasColumn('member_ministry_assignments', 'mentor_user_id')) {
        $table->foreignId('mentor_user_id')->nullable()->after('assigned_by')->constrained('users')->nullOnDelete();
      }
      if (! Schema::hasColumn('member_ministry_assignments', 'leader_user_id')) {
        $table->foreignId('leader_user_id')->nullable()->after('mentor_user_id')->constrained('users')->nullOnDelete();
      }
      if (! Schema::hasColumn('member_ministry_assignments', 'status')) {
        $table->string('status', 40)->default('active')->after('is_primary');
      }
    });

    if (Schema::hasTable('venues')) {
      Schema::table('venues', function (Blueprint $table): void {
        if (! Schema::hasColumn('venues', 'state')) {
          $table->string('state')->nullable()->after('city');
        }
      });
    }
  }

  public function down(): void
  {
    Schema::table('member_ministry_assignments', function (Blueprint $table): void {
      foreach (['department', 'team', 'mentor_user_id', 'leader_user_id', 'status'] as $column) {
        if (Schema::hasColumn('member_ministry_assignments', $column)) {
          if (in_array($column, ['mentor_user_id', 'leader_user_id'], true)) {
            $table->dropConstrainedForeignId($column);
          } else {
            $table->dropColumn($column);
          }
        }
      }
    });

    Schema::table('member_notification_queue', function (Blueprint $table): void {
      foreach (['attempts', 'scheduled_at', 'cancelled_at', 'processing_at'] as $column) {
        if (Schema::hasColumn('member_notification_queue', $column)) {
          $table->dropColumn($column);
        }
      }
    });

    Schema::table('member_interviews', function (Blueprint $table): void {
      foreach (['interview_type', 'duration_minutes', 'external_interviewer_name', 'venue'] as $column) {
        if (Schema::hasColumn('member_interviews', $column)) {
          $table->dropColumn($column);
        }
      }
    });

    Schema::table('users', function (Blueprint $table): void {
      foreach (['username', 'must_change_password', 'activation_token', 'activated_at'] as $column) {
        if (Schema::hasColumn('users', $column)) {
          $table->dropColumn($column);
        }
      }
    });
  }
};
