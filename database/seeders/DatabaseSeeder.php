<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
  public function run(): void
  {
    $this->call([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CmsSeeder::class,
      DonationsSeeder::class,
      EventsSeeder::class,
      CounsellingSeeder::class,
      ApplicationSettingSeeder::class,
      SuperAdminSeeder::class,
      MemberPortalDemoSeeder::class,
      VisitorWorkspaceDemoSeeder::class,
    ]);
  }
}
