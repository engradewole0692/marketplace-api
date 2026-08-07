<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Models\Instructor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class InstructorService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Instructor::query()->with(['photoMedia', 'user'])->withCount('courses')->orderBy('name');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Instructor
  {
    return Instructor::query()->create([
      'user_id' => ! empty($data['user_id']) ? User::query()->where('uuid', $data['user_id'])->value('id') : null,
      'name' => $data['name'],
      'slug' => $this->uniqueSlug($data['slug'] ?? Str::slug($data['name'])),
      'title' => $data['title'] ?? null,
      'bio' => $data['bio'] ?? null,
      'photo_media_id' => ! empty($data['photo_media_id'])
        ? CmsMedia::query()->where('uuid', $data['photo_media_id'])->value('id')
        : null,
      'email' => $data['email'] ?? null,
      'website_url' => $data['website_url'] ?? null,
      'status' => $data['status'] ?? CatalogStatus::Active->value,
      'metadata' => $data['metadata'] ?? null,
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Instructor $instructor, array $data, User $actor): Instructor
  {
    $payload = collect($data)->only([
      'name', 'title', 'bio', 'email', 'website_url', 'status', 'metadata',
    ])->all();
    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueSlug($data['slug'], $instructor->id);
    }
    if (array_key_exists('photo_media_id', $data)) {
      $payload['photo_media_id'] = $data['photo_media_id']
        ? CmsMedia::query()->where('uuid', $data['photo_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('user_id', $data)) {
      $payload['user_id'] = $data['user_id']
        ? User::query()->where('uuid', $data['user_id'])->value('id')
        : null;
    }
    $payload['updated_by_user_id'] = $actor->id;
    $instructor->fill($payload)->save();

    return $instructor->fresh(['photoMedia', 'user']);
  }

  public function delete(Instructor $instructor): void
  {
    $instructor->delete();
  }

  private function uniqueSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'instructor';
    $candidate = $base;
    $i = 1;
    while (
      Instructor::query()
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
