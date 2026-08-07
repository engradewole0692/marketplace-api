<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CmsPartnerAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsPartner::query()->with(['logoMedia', 'country'])->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $query->where('name', 'like', '%'.(string) $filters['search'].'%');
    }

    if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
      $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor): CmsPartner
  {
    $partner = CmsPartner::query()->create([
      ...$this->normalize($data),
      'slug' => $data['slug'] ?? Str::slug($data['name']),
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);
    $this->auditService->record(CmsAuditEventType::Created, 'partner', $partner->id, $actor, null, ['name' => $partner->name]);
    $this->cacheManager->flushPublic();

    return $partner->load(['logoMedia', 'country']);
  }

  public function update(CmsPartner $partner, array $data, User $actor): CmsPartner
  {
    $old = $partner->only(['name', 'is_active', 'sort_order']);
    $partner->fill([...$this->normalize($data), 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'partner', $partner->id, $actor, $old, $partner->only(['name', 'is_active', 'sort_order']));
    $this->cacheManager->flushPublic();

    return $partner->fresh(['logoMedia', 'country']);
  }

  public function delete(CmsPartner $partner, User $actor): void
  {
    $partner->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'partner', $partner->id, $actor, null, null);
    $this->cacheManager->flushPublic();
  }

  /**
   * @param  list<string>  $ids
   */
  public function reorder(array $ids, User $actor): void
  {
    foreach ($ids as $index => $uuid) {
      CmsPartner::query()->where('uuid', $uuid)->update(['sort_order' => $index, 'updated_by' => $actor->id]);
    }

    $this->auditService->record(CmsAuditEventType::Updated, 'partner', 0, $actor, null, ['reordered' => count($ids)]);
    $this->cacheManager->flushPublic();
  }

  /**
   * @param  list<string>  $ids
   */
  public function bulkUpdate(array $ids, array $data, User $actor): int
  {
    $count = 0;
    foreach (CmsPartner::query()->whereIn('uuid', $ids)->get() as $partner) {
      $this->update($partner, $data, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  list<string>  $ids
   */
  public function bulkDelete(array $ids, User $actor): int
  {
    $count = 0;
    foreach (CmsPartner::query()->whereIn('uuid', $ids)->get() as $partner) {
      $this->delete($partner, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalize(array $data): array
  {
    $normalized = $data;

    if (! empty($data['logo_media_id']) && ! is_numeric($data['logo_media_id'])) {
      $normalized['logo_media_id'] = CmsMedia::query()->where('uuid', $data['logo_media_id'])->value('id');
    }

    if (! empty($data['country_id']) && ! is_numeric($data['country_id'])) {
      $normalized['country_id'] = CmsCountry::query()->where('uuid', $data['country_id'])->value('id');
    }

    return $normalized;
  }
}
