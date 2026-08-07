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
      $table->uuid('uuid')->unique()->after('id');
      $table->string('first_name')->nullable()->after('name');
      $table->string('last_name')->nullable()->after('first_name');
      $table->string('display_name')->nullable()->after('last_name');
      $table->string('phone', 30)->nullable()->after('email');
      $table->string('avatar')->nullable()->after('phone');
      $table->string('status', 30)->default('active')->after('avatar');
      $table->timestamp('last_login_at')->nullable()->after('remember_token');
      $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
      $table->text('last_login_user_agent')->nullable()->after('last_login_ip');
      $table->string('timezone', 64)->default('UTC')->after('last_login_user_agent');
      $table->string('locale', 10)->default('en')->after('timezone');
      $table->softDeletes();
    });
  }

  public function down(): void
  {
    Schema::table('users', function (Blueprint $table): void {
      $table->dropSoftDeletes();
      $table->dropColumn([
        'uuid',
        'first_name',
        'last_name',
        'display_name',
        'phone',
        'avatar',
        'status',
        'last_login_at',
        'last_login_ip',
        'last_login_user_agent',
        'timezone',
        'locale',
      ]);
    });
  }
};
