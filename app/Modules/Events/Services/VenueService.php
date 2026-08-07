<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class VenueService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Venue::query()->with(['country', 'region'])->orderBy('name');

    foreach (['country_id', 'region_id', 'status'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Venue
  {
    $data['slug'] ??= Str::slug($data['name']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;

    return Venue::query()->create($data);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Venue $venue, array $data, User $actor): Venue
  {
    if (isset($data['name']) && ! isset($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }
    $data['updated_by_user_id'] = $actor->id;
    $venue->fill($data);
    $venue->save();

    return $venue->fresh(['country', 'region']);
  }

  public function delete(Venue $venue, User $actor): void
  {
    $venue->updated_by_user_id = $actor->id;
    $venue->save();
    $venue->delete();
  }
}
