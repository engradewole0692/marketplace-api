<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class CmsSettingAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
  ) {}

  /**
   * @return Collection<int, CmsSetting>
   */
  public function all(?string $group = null): Collection
  {
    $query = CmsSetting::query()->orderBy('group')->orderBy('key');

    if ($group !== null) {
      $query->where('group', $group);
    }

    return $query->get();
  }

  /**
   * @param  array<int, array{key: string, value: mixed, group?: string, is_public?: bool}>  $settings
   */
  public function bulkUpdate(array $settings, User $actor): Collection
  {
    $updated = collect();

    foreach ($settings as $item) {
      $setting = CmsSetting::query()->firstOrCreate(
        ['key' => $item['key']],
        [
          'group' => $item['group'] ?? 'general',
          'value' => $item['value'],
          'is_public' => $item['is_public'] ?? false,
        ],
      );

      $old = $setting->only(['key', 'value']);
      $setting->fill([
        'value' => $item['value'],
        'group' => $item['group'] ?? $setting->group,
        'is_public' => $item['is_public'] ?? $setting->is_public,
        'updated_by' => $actor->id,
      ]);
      $setting->save();

      $this->auditService->record(
        CmsAuditEventType::Updated,
        'setting',
        $setting->id,
        $actor,
        $old,
        $setting->only(['key', 'value']),
      );

      $updated->push($setting);
    }

    Cache::forget('cms:public:site-bootstrap');

    return $updated;
  }
}
