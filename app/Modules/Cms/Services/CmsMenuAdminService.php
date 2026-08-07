<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CmsMenuAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  /**
   * @return Collection<int, CmsMenu>
   */
  public function all(): Collection
  {
    return CmsMenu::query()
      ->with(['items' => fn ($q) => $q->whereNull('parent_id')->with('children')->orderBy('sort_order')])
      ->orderBy('name')
      ->get();
  }

  public function show(CmsMenu $menu): CmsMenu
  {
    return $menu->load(['items' => fn ($q) => $q->whereNull('parent_id')->with('children')->orderBy('sort_order')]);
  }

  public function update(CmsMenu $menu, array $data, User $actor): CmsMenu
  {
    $old = $menu->only(['name', 'is_active']);
    $menu->fill([...$data, 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'menu', $menu->id, $actor, $old, $menu->only(['name', 'is_active']));
    $this->cacheManager->flushPublic();

    return $menu->fresh();
  }

  /**
   * @param  array<int, array<string, mixed>>  $items
   */
  public function syncItems(CmsMenu $menu, array $items, User $actor): CmsMenu
  {
    DB::transaction(function () use ($menu, $items, $actor): void {
      $menu->items()->delete();
      $this->createItems($menu, $items, null);

      $this->auditService->record(
        CmsAuditEventType::Updated,
        'menu',
        $menu->id,
        $actor,
        null,
        ['slug' => $menu->slug, 'item_count' => $this->countItems($items)],
      );
    });

    $this->cacheManager->flushPublic();

    return $this->show($menu);
  }

  /**
   * @param  array<int, array<string, mixed>>  $items
   */
  private function createItems(CmsMenu $menu, array $items, ?int $parentId): void
  {
    foreach ($items as $index => $item) {
      $created = CmsMenuItem::query()->create([
        'menu_id' => $menu->id,
        'parent_id' => $parentId,
        'label' => $item['label'],
        'url' => $item['url'] ?? null,
        'route_name' => $item['route_name'] ?? null,
        'icon' => $item['icon'] ?? null,
        'open_in_new_tab' => $item['open_in_new_tab'] ?? false,
        'is_active' => $item['is_active'] ?? true,
        'sort_order' => $item['sort_order'] ?? $index,
      ]);

      if (! empty($item['children']) && is_array($item['children'])) {
        $this->createItems($menu, $item['children'], $created->id);
      }
    }
  }

  /**
   * @param  array<int, array<string, mixed>>  $items
   */
  private function countItems(array $items): int
  {
    $count = count($items);
    foreach ($items as $item) {
      if (! empty($item['children']) && is_array($item['children'])) {
        $count += $this->countItems($item['children']);
      }
    }

    return $count;
  }
}
