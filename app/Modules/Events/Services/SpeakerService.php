<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Models\Speaker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class SpeakerService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Speaker::query()->orderBy('name');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('organization', 'like', "%{$search}%"));
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Speaker
  {
    $data['slug'] ??= Str::slug($data['name']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;

    return Speaker::query()->create($data);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Speaker $speaker, array $data, User $actor): Speaker
  {
    if (isset($data['name']) && ! isset($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }
    $data['updated_by_user_id'] = $actor->id;
    $speaker->fill($data);
    $speaker->save();

    return $speaker->fresh();
  }

  public function delete(Speaker $speaker, User $actor): void
  {
    $speaker->updated_by_user_id = $actor->id;
    $speaker->save();
    $speaker->delete();
  }
}
