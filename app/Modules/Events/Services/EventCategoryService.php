<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Models\EventCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EventCategoryService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = EventCategory::query()->with('ministry')->orderBy('sort_order')->orderBy('name');

    if (! empty($filters['ministry_id'])) {
      $query->where('ministry_id', $filters['ministry_id']);
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): EventCategory
  {
    $data['slug'] ??= Str::slug($data['name']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;

    return EventCategory::query()->create($data);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(EventCategory $category, array $data, User $actor): EventCategory
  {
    if (isset($data['name']) && ! isset($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }
    $data['updated_by_user_id'] = $actor->id;
    $category->fill($data);
    $category->save();

    return $category->fresh('ministry');
  }

  public function delete(EventCategory $category, User $actor): void
  {
    $category->updated_by_user_id = $actor->id;
    $category->save();
    $category->delete();
  }
}
