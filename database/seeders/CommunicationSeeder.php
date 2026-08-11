<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Communications\Models\CommunicationRoute;
use App\Modules\Communications\Models\CommunicationSetting;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationSeederDefaults;
use Illuminate\Database\Seeder;

final class CommunicationSeeder extends Seeder
{
  public function run(): void
  {
    CommunicationSetting::query()->firstOrCreate([], [
      'ministry_email' => config('cms.notifications.admin_inbox_email'),
      'reply_to_email' => config('mail.from.address'),
      'reply_to_name' => config('mail.from.name'),
      'from_name' => config('mail.from.name'),
      'branding' => [
        'site_name' => 'Marketplace Ministers',
        'website_url' => config('app.frontend_url', 'https://marketplaceministers.net'),
        'header_text' => 'Marketplace Ministers',
        'footer_text' => 'Marketplace Ministers · Kingdom influence in the marketplace.',
        'logo_url' => null,
        'contact_email' => config('cms.notifications.admin_inbox_email'),
        'copyright' => '© '.date('Y').' Marketplace Ministers',
      ],
    ]);

    $defaults = app(CommunicationSeederDefaults::class)->allTemplates();
    foreach ($defaults as $eventKey => $template) {
      CommunicationTemplate::query()->updateOrCreate(
        ['event_key' => $eventKey],
        $template,
      );
    }

    $routes = [
      ['section' => 'general', 'label' => 'Ministry inbox', 'recipient_type' => 'ministry', 'recipient_role' => 'cc', 'sort_order' => 100],
      ['section' => 'contact', 'label' => 'Contact team', 'recipient_type' => 'section_email', 'email' => null, 'recipient_role' => 'to', 'sort_order' => 1, 'event_key' => null],
      ['section' => 'prayer', 'label' => 'Prayer team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
      ['section' => 'counseling', 'label' => 'Counseling team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
      ['section' => 'learning', 'label' => 'Learning team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
      ['section' => 'events', 'label' => 'Events team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
      ['section' => 'membership', 'label' => 'Membership team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
      ['section' => 'payments', 'label' => 'Payments team', 'recipient_type' => 'section_email', 'recipient_role' => 'to', 'sort_order' => 1],
    ];

    foreach ($routes as $route) {
      CommunicationRoute::query()->firstOrCreate(
        [
          'section' => $route['section'],
          'label' => $route['label'],
          'recipient_type' => $route['recipient_type'],
          'event_key' => $route['event_key'] ?? null,
        ],
        [
          'recipient_role' => $route['recipient_role'],
          'sort_order' => $route['sort_order'],
          'include_ministry_fallback' => true,
          'is_active' => true,
        ],
      );
    }
  }
}
