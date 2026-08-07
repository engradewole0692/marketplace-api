<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Models\CourseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CategoryService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CourseCategory::query()->with(['coverMedia', 'parent'])->withCount('courses')->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): CourseCategory
  {
    return CourseCategory::query()->create([
      'name' => $data['name'],
      'slug' => $this->uniqueSlug($data['slug'] ?? Str::slug($data['name'])),
      'description' => $data['description'] ?? null,
      'seo_title' => $data['seo_title'] ?? null,
      'seo_description' => $data['seo_description'] ?? null,
      'parent_id' => ! empty($data['parent_id'])
        ? CourseCategory::query()->where('uuid', $data['parent_id'])->value('id')
        : null,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'status' => $data['status'] ?? CatalogStatus::Active->value,
      'is_visible' => (bool) ($data['is_visible'] ?? true),
      'icon' => $data['icon'] ?? null,
      'cover_media_id' => ! empty($data['cover_media_id'])
        ? CmsMedia::query()->where('uuid', $data['cover_media_id'])->value('id')
        : null,
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(CourseCategory $category, array $data, User $actor): CourseCategory
  {
    $payload = collect($data)->only([
      'name', 'description', 'seo_title', 'seo_description', 'sort_order', 'status', 'icon', 'is_visible',
    ])->all();
    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueSlug($data['slug'], $category->id);
    }
    if (array_key_exists('parent_id', $data)) {
      $payload['parent_id'] = $data['parent_id']
        ? CourseCategory::query()->where('uuid', $data['parent_id'])->value('id')
        : null;
    }
    if (array_key_exists('cover_media_id', $data)) {
      $payload['cover_media_id'] = $data['cover_media_id']
        ? CmsMedia::query()->where('uuid', $data['cover_media_id'])->value('id')
        : null;
    }
    $payload['updated_by_user_id'] = $actor->id;
    $category->fill($payload)->save();

    return $category->fresh(['coverMedia', 'parent']);
  }

  public function delete(CourseCategory $category): void
  {
    $category->delete();
  }

  private function uniqueSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'category';
    $candidate = $base;
    $i = 1;
    while (
      CourseCategory::query()
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
