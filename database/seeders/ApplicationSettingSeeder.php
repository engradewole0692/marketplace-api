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
      [
        'group' => 'counselling',
        'key' => 'counselling.default_timezone',
        'value' => 'UTC',
        'type' => 'string',
        'description' => 'Default timezone for counselling scheduling.',
      ],
      [
        'group' => 'counselling',
        'key' => 'counselling.allow_client_cancel',
        'value' => 'true',
        'type' => 'boolean',
        'description' => 'Whether clients may cancel counselling appointments.',
      ],
      [
        'group' => 'counselling',
        'key' => 'counselling.allow_client_reschedule',
        'value' => 'true',
        'type' => 'boolean',
        'description' => 'Whether clients may reschedule counselling appointments.',
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
