<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Communications\Models\CommunicationSetting;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Lms\Models\LmsSchool;
use Database\Seeders\CommunicationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Simulates migrate:fresh --seed in the isolated test SQLite environment.
 * Does not touch the developer's local database file.
 */
final class FreshDatabaseSeederTest extends TestCase
{
  use RefreshDatabase;

  public function test_core_seeders_run_and_produce_required_records(): void
  {
    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CommunicationSeeder::class,
    ]);

    $this->assertTrue(
      Permission::query()->where('slug', 'communications.manage')->exists(),
      'communications.manage permission must exist after RolePermissionSeeder.',
    );

    $adminRole = Role::query()->where('slug', 'super_administrator')->first();
    $this->assertNotNull($adminRole);
    $this->assertTrue(
      $adminRole->permissions()->where('slug', 'communications.manage')->exists(),
    );

    $this->assertTrue(CommunicationSetting::query()->exists());
    $this->assertTrue(CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->exists());
  }

  public function test_database_seeder_guards_demo_seeders_in_production(): void
  {
    $source = File::get(database_path('seeders/DatabaseSeeder.php'));

    $this->assertStringContainsString("app()->environment('production')", $source);
    $this->assertStringContainsString('MemberPortalDemoSeeder::class', $source);
    $this->assertStringContainsString('VisitorWorkspaceDemoSeeder::class', $source);
  }

  public function test_lms_entities_can_be_created_after_seed(): void
  {
    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CommunicationSeeder::class,
    ]);

    $school = LmsSchool::query()->create([
      'slug' => 'fresh-db-school',
      'title' => 'Fresh DB School',
      'status' => 'published',
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'published_at' => now(),
    ]);

    $this->assertDatabaseHas('lms_schools', ['slug' => 'fresh-db-school']);
    $this->assertNotNull($school->uuid);

    foreach ([
      'communication_settings',
      'communication_routes',
      'communication_templates',
      'communication_email_logs',
      'communication_idempotency_keys',
      'lms_program_modules',
    ] as $table) {
      $this->assertTrue(
        \Illuminate\Support\Facades\Schema::hasTable($table),
        "Table {$table} must exist after migrate + seed.",
      );
    }
  }
}
