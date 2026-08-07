<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('members', function (Blueprint $table): void {
      $table->string('profession')->nullable()->after('occupation');
      $table->string('city')->nullable()->after('country_id');
      $table->string('state')->nullable()->after('city');
      $table->string('church_name')->nullable()->after('biography');
      $table->string('church_address')->nullable()->after('church_name');
      $table->unsignedSmallInteger('years_of_experience')->nullable()->after('church_address');
      $table->unsignedSmallInteger('years_in_faith')->nullable()->after('years_of_experience');
      $table->json('ministry_interests')->nullable()->after('years_in_faith');
      $table->json('gifts')->nullable()->after('ministry_interests');
      $table->json('references')->nullable()->after('gifts');
      $table->string('education')->nullable()->after('references');
      $table->string('availability')->nullable()->after('education');
      $table->unsignedBigInteger('preferred_ministry_id')->nullable()->index()->after('ministry_id');
      $table->text('interview_notes')->nullable()->after('availability');
      $table->text('onboarding_notes')->nullable()->after('interview_notes');
      $table->timestamp('activated_at')->nullable()->after('joined_at');
      $table->timestamp('orientation_completed_at')->nullable()->after('activated_at');
    });

    Schema::create('member_interviews', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('status', 40)->default('pending')->index();
      $table->date('scheduled_date')->nullable()->index();
      $table->time('scheduled_time')->nullable();
      $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('meeting_link')->nullable();
      $table->string('physical_location')->nullable();
      $table->text('remarks')->nullable();
      $table->string('result', 40)->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['member_id', 'status']);
    });

    Schema::create('member_ministry_assignments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->foreignId('ministry_id')->constrained('cms_ministries')->cascadeOnDelete();
      $table->string('role', 40)->default('member');
      $table->boolean('is_primary')->default(false)->index();
      $table->timestamp('assigned_at')->nullable();
      $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();

      $table->unique(['member_id', 'ministry_id']);
    });

    Schema::create('member_notification_queue', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('channel', 40);
      $table->string('template', 80);
      $table->json('payload')->nullable();
      $table->string('status', 40)->default('pending')->index();
      $table->timestamp('queued_at')->nullable();
      $table->timestamp('sent_at')->nullable();
      $table->text('error')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('member_notification_queue');
    Schema::dropIfExists('member_ministry_assignments');
    Schema::dropIfExists('member_interviews');
    Schema::table('members', function (Blueprint $table): void {
      $table->dropColumn([
        'profession', 'city', 'state', 'church_name', 'church_address',
        'years_of_experience', 'years_in_faith', 'ministry_interests', 'gifts',
        'references', 'education', 'availability', 'preferred_ministry_id',
        'interview_notes', 'onboarding_notes', 'activated_at', 'orientation_completed_at',
      ]);
    });
  }
};
