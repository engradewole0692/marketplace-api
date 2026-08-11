<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Models\CommunicationRoute;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CommunicationRouteService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CommunicationRoute::query()->with('user')->orderBy('section')->orderBy('sort_order');

    if (! empty($filters['section'])) {
      $query->where('section', $filters['section']);
    }
    if (! empty($filters['event_key'])) {
      $query->where('event_key', $filters['event_key']);
    }
    if (isset($filters['active'])) {
      $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): CommunicationRoute
  {
    return CommunicationRoute::query()->create([
      'section' => $data['section'],
      'event_key' => $data['event_key'] ?? null,
      'label' => $data['label'],
      'recipient_role' => $data['recipient_role'] ?? 'to',
      'recipient_type' => $data['recipient_type'],
      'email' => $data['email'] ?? null,
      'user_id' => ! empty($data['user_id']) ? User::query()->where('uuid', $data['user_id'])->value('id') : null,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'include_section_fallback' => (bool) ($data['include_section_fallback'] ?? false),
      'include_ministry_fallback' => (bool) ($data['include_ministry_fallback'] ?? false),
      'is_active' => (bool) ($data['is_active'] ?? true),
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(CommunicationRoute $route, array $data, User $actor): CommunicationRoute
  {
    if (array_key_exists('user_id', $data)) {
      $data['user_id'] = $data['user_id']
        ? User::query()->where('uuid', $data['user_id'])->value('id')
        : null;
    }

    $route->fill(collect($data)->only([
      'section', 'event_key', 'label', 'recipient_role', 'recipient_type', 'email', 'user_id',
      'sort_order', 'include_section_fallback', 'include_ministry_fallback', 'is_active', 'metadata',
    ])->all())->save();

    return $route->fresh(['user']);
  }

  public function delete(CommunicationRoute $route): void
  {
    $route->delete();
  }
}
