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
      RegionSeeder::class,
      LmsReferenceSeeder::class,
      LmsSchoolSeeder::class,
      DonationsSeeder::class,
      EventsSeeder::class,
      CounsellingSeeder::class,
      ApplicationSettingSeeder::class,
      CommunicationSeeder::class,
      SuperAdminSeeder::class,
    ]);

    // Demo members/learners use password "password" — never seed on production.
    if (! app()->environment('production')) {
      $this->call([
        MemberPortalDemoSeeder::class,
        VisitorWorkspaceDemoSeeder::class,
      ]);
    }
  }
}
