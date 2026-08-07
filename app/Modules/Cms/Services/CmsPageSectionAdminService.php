<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsPageSectionVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CmsPageSectionAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
  ) {}

  /**
   * @return Collection<int, CmsPageSection>
   */
  public function forPage(?string $pageSlug = null): Collection
  {
    $query = CmsPageSection::query()->orderBy('sort_order');

    if ($pageSlug !== null) {
      $query->where('page_slug', $pageSlug);
    }

    return $query->get();
  }

  public function update(CmsPageSection $section, array $data, User $actor): CmsPageSection
  {
    $old = $section->only(['title', 'content', 'draft_content', 'is_active', 'sort_order', 'status']);

    // Content edits always land in draft_content. Never overwrite live `content` here.
    if (array_key_exists('content', $data) && ! array_key_exists('draft_content', $data)) {
      $data['draft_content'] = $data['content'];
      unset($data['content']);
    }

    // Mark workflow as draft when saving content changes without an explicit status.
    // Live visibility stays tied to published_at + content (see PublicContentService).
    if (array_key_exists('draft_content', $data) && ! array_key_exists('status', $data)) {
      $data['status'] = 'draft';
    }

    if (($data['status'] ?? null) === 'published' && $section->published_at === null) {
      $data['published_at'] = now();
    }

    $section->fill([...$data, 'updated_by' => $actor->id]);
    $section->save();

    $this->auditService->record(
      CmsAuditEventType::Updated,
      'page_section',
      $section->id,
      $actor,
      $old,
      $section->only(['title', 'content', 'draft_content', 'is_active', 'sort_order', 'status']),
    );

    Cache::forget('cms:public:home');

    return $section->fresh();
  }

  /**
   * @param  array<int, array{id: string, sort_order: int}>  $items
   * @return Collection<int, CmsPageSection>
   */
  public function reorder(array $items, User $actor): Collection
  {
    return DB::transaction(function () use ($items, $actor) {
      foreach ($items as $item) {
        $section = CmsPageSection::query()->where('uuid', $item['id'])->first();
        if ($section === null) {
          continue;
        }

        $old = ['sort_order' => $section->sort_order];
        $section->fill([
          'sort_order' => (int) $item['sort_order'],
          'updated_by' => $actor->id,
        ])->save();

        $this->auditService->record(
          CmsAuditEventType::Updated,
          'page_section',
          $section->id,
          $actor,
          $old,
          ['sort_order' => $section->sort_order],
        );
      }

      Cache::forget('cms:public:home');

      return $this->forPage('home');
    });
  }

  public function submitForReview(CmsPageSection $section, User $actor): CmsPageSection
  {
    $section->fill([
      'status' => 'review',
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(
      CmsAuditEventType::Updated,
      'page_section',
      $section->id,
      $actor,
      ['status' => 'draft'],
      ['status' => 'review'],
    );

    return $section->fresh();
  }

  public function publish(CmsPageSection $section, User $actor, ?string $summary = null): CmsPageSection
  {
    return DB::transaction(function () use ($section, $actor, $summary) {
      $payload = $section->draft_content ?? $section->content ?? [];

      $nextVersion = (int) $section->versions()->max('version_number') + 1;

      CmsPageSectionVersion::query()->create([
        'section_id' => $section->id,
        'version_number' => $nextVersion,
        'status' => 'published',
        'content' => $payload,
        'change_summary' => $summary,
        'created_by' => $actor->id,
      ]);

      $old = $section->only(['content', 'status', 'published_at']);

      $section->fill([
        'content' => $payload,
        'draft_content' => $payload,
        'status' => 'published',
        'published_at' => now(),
        'updated_by' => $actor->id,
      ])->save();

      $this->auditService->record(
        CmsAuditEventType::Published,
        'page_section',
        $section->id,
        $actor,
        $old,
        $section->only(['content', 'status', 'published_at']),
      );

      Cache::forget('cms:public:home');

      return $section->fresh('versions');
    });
  }

  public function restoreVersion(CmsPageSection $section, CmsPageSectionVersion $version, User $actor): CmsPageSection
  {
    $section->fill([
      'draft_content' => $version->content,
      'status' => 'draft',
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(
      CmsAuditEventType::Restored,
      'page_section',
      $section->id,
      $actor,
      null,
      ['version' => $version->version_number],
    );

    Cache::forget('cms:public:home');

    return $section->fresh('versions');
  }
}
