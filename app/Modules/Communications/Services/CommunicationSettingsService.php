<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Models\CommunicationSetting;

final class CommunicationSettingsService implements ServiceContract
{
  public function get(): CommunicationSetting
  {
    $setting = CommunicationSetting::query()->first();
    if ($setting instanceof CommunicationSetting) {
      return $setting;
    }

    return CommunicationSetting::query()->create([
      'ministry_email' => config('cms.notifications.admin_inbox_email'),
      'reply_to_email' => config('mail.from.address'),
      'reply_to_name' => config('mail.from.name'),
      'from_name' => config('mail.from.name'),
      'branding' => [
        'site_name' => 'Marketplace Ministers',
        'website_url' => config('app.frontend_url', config('app.url')),
        'header_text' => 'Marketplace Ministers',
        'footer_text' => 'Marketplace Ministers · Kingdom influence in the marketplace.',
        'logo_url' => null,
        'contact_email' => config('cms.notifications.admin_inbox_email'),
      ],
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(array $data): CommunicationSetting
  {
    $setting = $this->get();
    $setting->fill(collect($data)->only([
      'ministry_email',
      'reply_to_email',
      'reply_to_name',
      'from_name',
      'branding',
      'metadata',
    ])->all())->save();

    return $setting->fresh();
  }

  /** @return array<string, mixed> */
  public function branding(): array
  {
    return $this->get()->branding ?? [];
  }

  public function ministryEmail(): ?string
  {
    $email = $this->get()->ministry_email;

    return is_string($email) && $email !== '' ? $email : null;
  }
}
