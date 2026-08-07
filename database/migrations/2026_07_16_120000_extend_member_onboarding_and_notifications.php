<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('member_onboarding_checklist_items', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('step_key', 80);
      $table->string('label');
      $table->boolean('is_completed')->default(false)->index();
      $table->timestamp('completed_at')->nullable();
      $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
      $table->text('notes')->nullable();
      $table->unsignedSmallInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['member_id', 'step_key']);
    });

    Schema::table('member_notification_queue', function (Blueprint $table): void {
      if (! Schema::hasColumn('member_notification_queue', 'scheduled_at')) {
        $table->timestamp('scheduled_at')->nullable()->after('queued_at');
      }
      if (! Schema::hasColumn('member_notification_queue', 'cancelled_at')) {
        $table->timestamp('cancelled_at')->nullable()->after('sent_at');
      }
      if (! Schema::hasColumn('member_notification_queue', 'attempts')) {
        $table->unsignedSmallInteger('attempts')->default(0)->after('status');
      }
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('member_onboarding_checklist_items');
    Schema::table('member_notification_queue', function (Blueprint $table): void {
      $cols = [];
      foreach (['scheduled_at', 'cancelled_at', 'attempts'] as $col) {
        if (Schema::hasColumn('member_notification_queue', $col)) {
          $cols[] = $col;
        }
      }
      if ($cols !== []) {
        $table->dropColumn($cols);
      }
    });
  }
};
