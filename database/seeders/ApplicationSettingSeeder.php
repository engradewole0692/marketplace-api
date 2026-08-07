<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use Illuminate\Database\Seeder;

final class ApplicationSettingSeeder extends Seeder
{
  public function run(): void
  {
    $settings = [
      [
        'group' => 'general',
        'key' => 'app.display_name',
        'value' => 'Marketplace Ministers',
        'type' => 'string',
        'description' => 'Public application display name.',
      ],
      [
        'group' => 'general',
        'key' => 'auth.require_email_verification',
        'value' => 'true',
        'type' => 'boolean',
        'description' => 'Whether new users must verify email before full access.',
      ],
      [
        'group' => 'security',
        'key' => 'auth.login_throttle_per_minute',
        'value' => '5',
        'type' => 'integer',
        'description' => 'Maximum login attempts per minute per email/IP.',
      ],
    ];

    foreach ($settings as $setting) {
      ApplicationSetting::query()->updateOrCreate(
        ['key' => $setting['key']],
        $setting,
      );
    }
  }
}
