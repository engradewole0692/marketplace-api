<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\LmsSchool;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class SchoolService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = LmsSchool::query()
      ->with(['coverMedia', 'thumbnailMedia'])
      ->withCount('courses')
      ->orderBy('sort_order')
      ->orderBy('title');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('summary', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['status'])) {
      $statuses = array_filter(array_map('trim', explode(',', (string) $filters['status'])));
      $query->whereIn('status', $statuses);
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginatePublished(array $filters = []): LengthAwarePaginator
  {
    $filters['status'] = SchoolStatus::Published->value;

    return $this->paginate($filters);
  }

  public function findPublicBySlug(string $slug): ?LmsSchool
  {
    return LmsSchool::query()
      ->published()
      ->with([
        'coverMedia',
        'thumbnailMedia',
        'programModules' => fn ($q) => $q
          ->where('status', 'published')
          ->orderBy('sort_order')
          ->with(['courses' => fn ($cq) => $cq
            ->where('status', 'published')
            ->with(['coverMedia', 'thumbnailMedia', 'instructors'])
            ->orderBy('sort_order')]),
        'courses' => fn ($q) => $q
          ->where('status', 'published')
          ->whereNull('program_module_id')
          ->with(['coverMedia', 'thumbnailMedia', 'instructors'])
          ->orderBy('sort_order'),
      ])
      ->withCount([
        'courses' => fn ($q) => $q->where('status', 'published'),
        'programModules' => fn ($q) => $q->where('status', 'published'),
      ])
      ->where('slug', $slug)
      ->first();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): LmsSchool
  {
    return LmsSchool::query()->create([
      'slug' => $this->uniqueSlug($data['slug'] ?? Str::slug($data['title'])),
      'title' => $data['title'],
      'subtitle' => $data['subtitle'] ?? null,
      'summary' => $data['summary'] ?? null,
      'description' => $data['description'] ?? null,
      'status' => $data['status'] ?? SchoolStatus::Draft->value,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'member_price' => $data['member_price'] ?? 0,
      'public_price' => $data['public_price'] ?? 0,
      'currency' => $data['currency'] ?? 'USD',
      'certificate_enabled' => (bool) ($data['certificate_enabled'] ?? true),
      'sequential_progression' => (bool) ($data['sequential_progression'] ?? false),
      'cover_media_id' => ! empty($data['cover_media_id'])
        ? CmsMedia::query()->where('uuid', $data['cover_media_id'])->value('id')
        : null,
      'thumbnail_media_id' => ! empty($data['thumbnail_media_id'])
        ? CmsMedia::query()->where('uuid', $data['thumbnail_media_id'])->value('id')
        : null,
      'metadata' => $data['metadata'] ?? null,
      'published_at' => ($data['status'] ?? null) === SchoolStatus::Published->value ? now() : null,
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(LmsSchool $school, array $data, User $actor): LmsSchool
  {
    $payload = collect($data)->only([
      'title', 'subtitle', 'summary', 'description', 'sort_order',
      'member_price', 'public_price', 'currency', 'certificate_enabled',
      'sequential_progression', 'metadata',
    ])->all();

    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueSlug($data['slug'], $school->id);
    }
    if (isset($data['status'])) {
      $payload['status'] = $data['status'];
      if ($data['status'] === SchoolStatus::Published->value && ! $school->published_at) {
        $payload['published_at'] = now();
      }
    }
    if (array_key_exists('cover_media_id', $data)) {
      $payload['cover_media_id'] = $data['cover_media_id']
        ? CmsMedia::query()->where('uuid', $data['cover_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('thumbnail_media_id', $data)) {
      $payload['thumbnail_media_id'] = $data['thumbnail_media_id']
        ? CmsMedia::query()->where('uuid', $data['thumbnail_media_id'])->value('id')
        : null;
    }

    $payload['updated_by_user_id'] = $actor->id;
    $school->fill($payload)->save();

    return $school->fresh(['coverMedia', 'thumbnailMedia']);
  }

  public function publish(LmsSchool $school, User $actor): LmsSchool
  {
    $school->update([
      'status' => SchoolStatus::Published,
      'published_at' => $school->published_at ?? now(),
      'updated_by_user_id' => $actor->id,
    ]);

    return $school->fresh(['coverMedia', 'thumbnailMedia']);
  }

  public function unpublish(LmsSchool $school, User $actor): LmsSchool
  {
    $school->update([
      'status' => SchoolStatus::Draft,
      'updated_by_user_id' => $actor->id,
    ]);

    return $school->fresh(['coverMedia', 'thumbnailMedia']);
  }

  public function archive(LmsSchool $school, User $actor): LmsSchool
  {
    $school->update([
      'status' => SchoolStatus::Archived,
      'updated_by_user_id' => $actor->id,
    ]);

    return $school->fresh(['coverMedia', 'thumbnailMedia']);
  }

  public function delete(LmsSchool $school): void
  {
    $school->delete();
  }

  private function uniqueSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'school';
    $candidate = $base;
    $i = 1;
    while (
      LmsSchool::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = "{$base}-{$i}";
      $i++;
    }

    return $candidate;
  }
}
