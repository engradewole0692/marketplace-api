<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsPageVersion;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CmsPageAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsPage::query()->orderBy('title');

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor, ?string $changeSummary = null): CmsPage
  {
    $page = CmsPage::query()->create([
      ...$data,
      'slug' => $data['slug'] ?? Str::slug($data['title']),
      'status' => $data['status'] ?? PageStatus::Draft,
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->createVersion($page, $actor, $changeSummary ?? 'Initial version');
    $this->auditService->record(CmsAuditEventType::Created, 'page', $page->id, $actor, null, ['slug' => $page->slug]);
    $this->cacheManager->flushPage($page->slug);

    return $page;
  }

  public function update(CmsPage $page, array $data, User $actor, ?string $changeSummary = null): CmsPage
  {
    $old = $page->only(['title', 'slug', 'status', 'blocks']);
    $page->fill([...$data, 'updated_by' => $actor->id]);

    if (($data['status'] ?? null) === PageStatus::Published->value && $page->published_at === null) {
      $page->published_at = now();
    }

    $page->save();
    $this->createVersion($page, $actor, $changeSummary ?? 'Page updated');
    $this->auditService->record(CmsAuditEventType::Updated, 'page', $page->id, $actor, $old, $page->only(['title', 'slug', 'status']));
    $this->cacheManager->flushPage($page->slug);

    return $page->fresh();
  }

  public function publish(CmsPage $page, User $actor, ?string $scheduledAt = null): CmsPage
  {
    $old = $page->only(['status', 'published_at', 'scheduled_at']);

    if ($scheduledAt) {
      $page->status = PageStatus::Scheduled;
      $page->scheduled_at = $scheduledAt;
    } else {
      $page->status = PageStatus::Published;
      $page->published_at = now();
      $page->scheduled_at = null;
    }

    $page->updated_by = $actor->id;
    $page->save();

    $this->createVersion($page, $actor, $scheduledAt ? 'Scheduled for publish' : 'Published');
    $this->auditService->record(CmsAuditEventType::Published, 'page', $page->id, $actor, $old, $page->only(['status', 'published_at', 'scheduled_at']));
    $this->cacheManager->flushPage($page->slug);

    return $page->fresh();
  }

  public function unpublish(CmsPage $page, User $actor): CmsPage
  {
    $old = $page->only(['status', 'scheduled_at']);
    $page->status = PageStatus::Draft;
    $page->scheduled_at = null;
    $page->updated_by = $actor->id;
    $page->save();

    $this->createVersion($page, $actor, 'Unpublished');
    $this->auditService->record(CmsAuditEventType::Updated, 'page', $page->id, $actor, $old, ['status' => PageStatus::Draft->value]);
    $this->cacheManager->flushPage($page->slug);

    return $page->fresh();
  }

  public function archive(CmsPage $page, User $actor): CmsPage
  {
    $old = $page->only(['status']);
    $page->status = PageStatus::Archived;
    $page->updated_by = $actor->id;
    $page->save();

    $this->createVersion($page, $actor, 'Archived');
    $this->auditService->record(CmsAuditEventType::Archived, 'page', $page->id, $actor, $old, ['status' => PageStatus::Archived->value]);
    $this->cacheManager->flushPage($page->slug);

    return $page->fresh();
  }

  public function delete(CmsPage $page, User $actor): void
  {
    $slug = $page->slug;
    $page->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'page', $page->id, $actor, ['slug' => $slug], null);
    $this->cacheManager->flushPage($slug);
  }

  public function duplicate(CmsPage $page, User $actor): CmsPage
  {
    $newSlug = $this->uniqueSlug("{$page->slug}-copy");
    $newTitle = "{$page->title} (Copy)";

    $duplicate = CmsPage::query()->create([
      'title' => $newTitle,
      'slug' => $newSlug,
      'status' => PageStatus::Draft,
      'hero_title' => $page->hero_title,
      'hero_subtitle' => $page->hero_subtitle,
      'hero_media_id' => $page->hero_media_id,
      'blocks' => $page->blocks,
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->createVersion($duplicate, $actor, 'Duplicated from '.$page->slug);
    $this->auditService->record(
      CmsAuditEventType::Created,
      'page',
      $duplicate->id,
      $actor,
      null,
      ['slug' => $duplicate->slug, 'duplicated_from' => $page->slug],
    );

    CmsPageSection::query()
      ->where('page_slug', $page->slug)
      ->orderBy('sort_order')
      ->each(function (CmsPageSection $section) use ($duplicate, $newSlug, $actor): void {
        CmsPageSection::query()->create([
          'page_id' => $duplicate->id,
          'page_slug' => $newSlug,
          'section_key' => $section->section_key,
          'section_type' => $section->section_type,
          'title' => $section->title,
          'content' => $section->content,
          'draft_content' => $section->draft_content,
          'is_active' => $section->is_active,
          'status' => $section->status,
          'sort_order' => $section->sort_order,
          'published_at' => null,
          'created_by' => $actor->id,
          'updated_by' => $actor->id,
        ]);
      });

    $this->cacheManager->flushPage($page->slug);
    $this->cacheManager->flushPage($newSlug);

    return $duplicate->fresh();
  }

  /**
   * @return \Illuminate\Database\Eloquent\Collection<int, CmsPageVersion>
   */
  public function versions(CmsPage $page)
  {
    return $page->versions()->with('page')->orderByDesc('version_number')->get();
  }

  public function restoreVersion(CmsPage $page, CmsPageVersion $version, User $actor): CmsPage
  {
    $snapshot = $version->snapshot;
    $page->fill([
      'title' => $snapshot['title'] ?? $page->title,
      'slug' => $snapshot['slug'] ?? $page->slug,
      'status' => $snapshot['status'] ?? $page->status,
      'hero_title' => $snapshot['hero_title'] ?? null,
      'hero_subtitle' => $snapshot['hero_subtitle'] ?? null,
      'blocks' => $snapshot['blocks'] ?? [],
      'published_at' => $snapshot['published_at'] ?? null,
      'scheduled_at' => $snapshot['scheduled_at'] ?? null,
      'updated_by' => $actor->id,
    ]);
    $page->save();
    $this->createVersion($page, $actor, "Restored version {$version->version_number}");
    $this->auditService->record(CmsAuditEventType::Restored, 'page', $page->id, $actor, null, ['version' => $version->version_number]);
    $this->cacheManager->flushPage($page->slug);

    return $page->fresh();
  }

  public function compareVersions(CmsPageVersion $a, CmsPageVersion $b): array
  {
    return [
      'from' => $a->snapshot,
      'to' => $b->snapshot,
    ];
  }

  private function uniqueSlug(string $base): string
  {
    $candidate = $base;
    $suffix = 2;

    while (CmsPage::query()->where('slug', $candidate)->exists()) {
      $candidate = "{$base}-{$suffix}";
      $suffix++;
    }

    return $candidate;
  }

  private function createVersion(CmsPage $page, User $actor, string $changeSummary): CmsPageVersion
  {
    $next = (int) $page->versions()->max('version_number') + 1;

    return CmsPageVersion::query()->create([
      'page_id' => $page->id,
      'version_number' => $next,
      'title' => $page->title,
      'status' => $page->status->value,
      'snapshot' => $page->only([
        'title', 'slug', 'status', 'hero_title', 'hero_subtitle', 'hero_media_id', 'blocks', 'published_at', 'scheduled_at',
      ]),
      'change_summary' => $changeSummary,
      'created_by' => $actor->id,
    ]);
  }
}
