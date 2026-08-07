<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('event_certificate_templates', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
      $table->string('name');
      $table->string('slug');
      $table->longText('html_body');
      $table->foreignId('background_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['event_id', 'slug']);
      $table->index(['event_id', 'is_active']);
    });

    Schema::table('events', function (Blueprint $table): void {
      $table->boolean('is_featured')->default(false)->after('capacity');
      $table->boolean('is_paid')->default(false)->after('is_featured');
      $table->boolean('payment_required')->default(false)->after('is_paid');
      $table->decimal('price', 12, 2)->nullable()->after('payment_required');
      $table->string('currency', 3)->default('USD')->after('price');
      $table->string('seo_title')->nullable()->after('currency');
      $table->text('seo_description')->nullable()->after('seo_title');
      $table->text('announcement')->nullable()->after('seo_description');
      $table->foreignId('certificate_template_id')->nullable()->after('announcement')
        ->constrained('event_certificate_templates')->nullOnDelete();

      $table->index(['is_featured', 'status']);
      $table->index(['is_paid', 'status']);
    });

    Schema::table('event_sessions', function (Blueprint $table): void {
      $table->string('track')->nullable()->after('location');
      $table->string('room')->nullable()->after('track');
      $table->foreignId('moderator_user_id')->nullable()->after('room')
        ->constrained('users')->nullOnDelete();
      $table->json('resources_json')->nullable()->after('metadata');

      $table->index(['event_id', 'room']);
      $table->index(['event_id', 'track']);
    });

    Schema::table('event_certificate_issuances', function (Blueprint $table): void {
      $table->string('verification_code')->nullable()->unique()->after('certificate_number');
      $table->foreignId('template_id')->nullable()->after('certificate_media_id')
        ->constrained('event_certificate_templates')->nullOnDelete();
      $table->unsignedInteger('download_count')->default(0)->after('template_id');
      $table->foreignId('reissued_from_id')->nullable()->after('download_count')
        ->constrained('event_certificate_issuances')->nullOnDelete();
    });

    Schema::create('event_volunteer_roles', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('name');
      $table->string('slug');
      $table->text('description')->nullable();
      $table->unsignedInteger('slots')->nullable();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['event_id', 'slug']);
      $table->index(['event_id', 'is_active']);
    });

    Schema::create('event_volunteer_assignments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->foreignId('role_id')->constrained('event_volunteer_roles')->cascadeOnDelete();
      $table->string('status', 40)->default('interested');
      $table->timestamp('shift_starts_at')->nullable();
      $table->timestamp('shift_ends_at')->nullable();
      $table->text('notes')->nullable();
      $table->unsignedTinyInteger('performance_score')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();

      $table->index(['event_id', 'status']);
      $table->index(['role_id', 'status']);
      $table->index(['member_id', 'status']);
    });

    Schema::create('event_coupons', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('code');
      $table->string('discount_type', 20);
      $table->decimal('discount_value', 12, 2);
      $table->unsignedInteger('max_uses')->nullable();
      $table->unsignedInteger('used_count')->default(0);
      $table->timestamp('starts_at')->nullable();
      $table->timestamp('ends_at')->nullable();
      $table->boolean('is_active')->default(true);
      $table->timestamps();

      $table->unique(['event_id', 'code']);
      $table->index(['event_id', 'is_active']);
    });

    Schema::create('event_registration_payments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->decimal('amount', 12, 2)->default(0);
      $table->string('currency', 3)->default('USD');
      $table->string('status', 40)->default('pending');
      $table->string('payment_method', 40)->default('offline');
      $table->foreignId('coupon_id')->nullable()->constrained('event_coupons')->nullOnDelete();
      $table->unsignedBigInteger('donation_id')->nullable();
      $table->text('notes')->nullable();
      $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('paid_at')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'status']);
      $table->index(['registration_id', 'status']);
      $table->index('donation_id');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('event_registration_payments');
    Schema::dropIfExists('event_coupons');
    Schema::dropIfExists('event_volunteer_assignments');
    Schema::dropIfExists('event_volunteer_roles');

    Schema::table('event_certificate_issuances', function (Blueprint $table): void {
      $table->dropForeign(['template_id']);
      $table->dropForeign(['reissued_from_id']);
      $table->dropColumn(['verification_code', 'template_id', 'download_count', 'reissued_from_id']);
    });

    Schema::table('event_sessions', function (Blueprint $table): void {
      $table->dropForeign(['moderator_user_id']);
      $table->dropIndex(['event_id', 'room']);
      $table->dropIndex(['event_id', 'track']);
      $table->dropColumn(['track', 'room', 'moderator_user_id', 'resources_json']);
    });

    Schema::table('events', function (Blueprint $table): void {
      $table->dropForeign(['certificate_template_id']);
      $table->dropIndex(['is_featured', 'status']);
      $table->dropIndex(['is_paid', 'status']);
      $table->dropColumn([
        'is_featured', 'is_paid', 'payment_required', 'price', 'currency',
        'seo_title', 'seo_description', 'announcement', 'certificate_template_id',
      ]);
    });

    Schema::dropIfExists('event_certificate_templates');
  }
};
