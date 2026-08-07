<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CounsellingCatalogService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateCategories(array $filters = []): LengthAwarePaginator
  {
    $query = CounsellingCategory::query()
      ->withCount('services')
      ->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
    }

    if (array_key_exists('is_visible', $filters)) {
      $query->where('is_visible', (bool) $filters['is_visible']);
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateServices(array $filters = []): LengthAwarePaginator
  {
    $query = CounsellingService::query()
      ->with(['category', 'bannerMedia'])
      ->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
    }

    if (! empty($filters['category_id'])) {
      $categoryId = CounsellingCategory::query()->where('uuid', $filters['category_id'])->value('id');
      if ($categoryId) {
        $query->where('category_id', $categoryId);
      }
    }

    if (array_key_exists('is_visible', $filters)) {
      $query->where('is_visible', (bool) $filters['is_visible']);
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createCategory(array $data): CounsellingCategory
  {
    return CounsellingCategory::query()->create([
      'name' => $data['name'],
      'slug' => $this->uniqueCategorySlug($data['slug'] ?? Str::slug($data['name'])),
      'description' => $data['description'] ?? null,
      'icon' => $data['icon'] ?? null,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'is_visible' => (bool) ($data['is_visible'] ?? true),
      'status' => $data['status'] ?? 'active',
      'seo_title' => $data['seo_title'] ?? null,
      'seo_description' => $data['seo_description'] ?? null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateCategory(CounsellingCategory $category, array $data): CounsellingCategory
  {
    $payload = collect($data)->only([
      'name', 'description', 'icon', 'sort_order', 'is_visible', 'status', 'seo_title', 'seo_description',
    ])->all();

    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueCategorySlug($data['slug'], $category->id);
    }

    $category->fill($payload)->save();

    return $category->fresh();
  }

  public function deleteCategory(CounsellingCategory $category): void
  {
    $category->delete();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createService(array $data, User $actor): CounsellingService
  {
    return CounsellingService::query()->create([
      'title' => $data['title'],
      'slug' => $this->uniqueServiceSlug($data['slug'] ?? Str::slug($data['title'])),
      'category_id' => ! empty($data['category_id'])
        ? CounsellingCategory::query()->where('uuid', $data['category_id'])->value('id')
        : null,
      'description' => $data['description'] ?? null,
      'short_description' => $data['short_description'] ?? null,
      'icon' => $data['icon'] ?? null,
      'banner_media_id' => ! empty($data['banner_media_id'])
        ? CmsMedia::query()->where('uuid', $data['banner_media_id'])->value('id')
        : null,
      'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
      'format' => $data['format'] ?? 'hybrid',
      'google_meet_link' => $data['google_meet_link'] ?? null,
      'zoom_link' => $data['zoom_link'] ?? null,
      'teams_link' => $data['teams_link'] ?? null,
      'office_address' => $data['office_address'] ?? null,
      'maximum_sessions' => (int) ($data['maximum_sessions'] ?? 1),
      'requires_approval' => (bool) ($data['requires_approval'] ?? true),
      'requires_payment' => (bool) ($data['requires_payment'] ?? false),
      'is_free' => (bool) ($data['is_free'] ?? true),
      'visitor_price' => $data['visitor_price'] ?? null,
      'member_price' => $data['member_price'] ?? null,
      'currency' => $data['currency'] ?? 'USD',
      'is_visible' => (bool) ($data['is_visible'] ?? true),
      'is_featured' => (bool) ($data['is_featured'] ?? false),
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'seo_title' => $data['seo_title'] ?? null,
      'seo_description' => $data['seo_description'] ?? null,
      'status' => $data['status'] ?? 'published',
      'metadata' => $data['metadata'] ?? null,
      'created_by_user_id' => $actor->id,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateService(CounsellingService $service, array $data, User $actor): CounsellingService
  {
    $payload = collect($data)->only([
      'title', 'description', 'short_description', 'icon', 'duration_minutes', 'format',
      'google_meet_link', 'zoom_link', 'teams_link', 'office_address', 'maximum_sessions',
      'requires_approval', 'requires_payment', 'is_free', 'visitor_price', 'member_price',
      'currency', 'is_visible', 'is_featured', 'sort_order', 'seo_title', 'seo_description',
      'status', 'metadata',
    ])->all();

    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueServiceSlug($data['slug'], $service->id);
    }

    if (array_key_exists('category_id', $data)) {
      $payload['category_id'] = $data['category_id']
        ? CounsellingCategory::query()->where('uuid', $data['category_id'])->value('id')
        : null;
    }

    if (array_key_exists('banner_media_id', $data)) {
      $payload['banner_media_id'] = $data['banner_media_id']
        ? CmsMedia::query()->where('uuid', $data['banner_media_id'])->value('id')
        : null;
    }

    $service->fill($payload)->save();

    return $service->fresh(['category', 'bannerMedia']);
  }

  public function deleteService(CounsellingService $service): void
  {
    $service->delete();
  }

  private function uniqueCategorySlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'category';
    $candidate = $base;
    $i = 1;

    while (
      CounsellingCategory::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }

  private function uniqueServiceSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'service';
    $candidate = $base;
    $i = 1;

    while (
      CounsellingService::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }
}
