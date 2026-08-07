<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Enums\CmsNotificationType;
use App\Modules\Cms\Enums\TestimonialStatus;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CmsTestimonialAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
    private readonly CmsNotificationService $notificationService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsTestimonial::query()
      ->with(['photoMedia', 'videoMedia', 'moderator'])
      ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
      ->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('author_name', 'like', "%{$search}%")
          ->orWhere('author_title', 'like', "%{$search}%")
          ->orWhere('author_location', 'like', "%{$search}%")
          ->orWhere('quote', 'like', "%{$search}%")
          ->orWhere('category', 'like', "%{$search}%")
          ->orWhere('submitter_email', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['category'])) {
      $query->where('category', $filters['category']);
    }

    if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
      $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
    }

    if (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== null && $filters['is_featured'] !== '') {
      $query->where('is_featured', filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN));
    }

    if (array_key_exists('show_on_homepage', $filters) && $filters['show_on_homepage'] !== null && $filters['show_on_homepage'] !== '') {
      $query->where('show_on_homepage', filter_var($filters['show_on_homepage'], FILTER_VALIDATE_BOOLEAN));
    }

    if (array_key_exists('show_on_page', $filters) && $filters['show_on_page'] !== null && $filters['show_on_page'] !== '') {
      $query->where('show_on_page', filter_var($filters['show_on_page'], FILTER_VALIDATE_BOOLEAN));
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor): CmsTestimonial
  {
    $normalized = $this->normalize($data);
    $normalized['status'] = $normalized['status'] ?? TestimonialStatus::Approved->value;
    if (($normalized['status'] ?? null) === TestimonialStatus::Approved->value) {
      $normalized['is_active'] = $normalized['is_active'] ?? true;
      $normalized['show_on_page'] = $normalized['show_on_page'] ?? true;
      $normalized['moderated_by'] = $actor->id;
      $normalized['moderated_at'] = now();
    }

    $testimonial = CmsTestimonial::query()->create([
      ...$normalized,
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);
    $this->auditService->record(CmsAuditEventType::Created, 'testimonial', $testimonial->id, $actor, null, ['author' => $testimonial->author_name]);
    $this->cacheManager->flushPublic();

    return $testimonial->load(['photoMedia', 'videoMedia']);
  }

  public function update(CmsTestimonial $testimonial, array $data, User $actor): CmsTestimonial
  {
    $old = $testimonial->only(['author_name', 'is_active', 'is_featured', 'sort_order', 'status', 'show_on_homepage', 'show_on_page', 'category']);
    $testimonial->fill([...$this->normalize($data), 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'testimonial', $testimonial->id, $actor, $old, $testimonial->only(['author_name', 'is_active', 'is_featured', 'sort_order', 'status', 'show_on_homepage', 'show_on_page', 'category']));
    $this->cacheManager->flushPublic();

    return $testimonial->fresh(['photoMedia', 'videoMedia']);
  }

  public function approve(CmsTestimonial $testimonial, User $actor, array $options = []): CmsTestimonial
  {
    $old = $testimonial->only(['status', 'is_active', 'show_on_homepage', 'show_on_page', 'is_featured']);

    $testimonial->fill([
      'status' => TestimonialStatus::Approved,
      'is_active' => true,
      'show_on_page' => array_key_exists('show_on_page', $options) ? (bool) $options['show_on_page'] : true,
      'show_on_homepage' => array_key_exists('show_on_homepage', $options) ? (bool) $options['show_on_homepage'] : (bool) $testimonial->show_on_homepage,
      'is_featured' => array_key_exists('is_featured', $options) ? (bool) $options['is_featured'] : (bool) $testimonial->is_featured,
      'rejection_reason' => null,
      'moderated_by' => $actor->id,
      'moderated_at' => now(),
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(CmsAuditEventType::Updated, 'testimonial', $testimonial->id, $actor, $old, $testimonial->only(['status', 'is_active', 'show_on_homepage', 'show_on_page', 'is_featured']));
    $this->cacheManager->flushPublic();

    return $testimonial->fresh(['photoMedia', 'videoMedia']);
  }

  public function reject(CmsTestimonial $testimonial, User $actor, ?string $reason = null): CmsTestimonial
  {
    $old = $testimonial->only(['status', 'is_active', 'rejection_reason']);

    $testimonial->fill([
      'status' => TestimonialStatus::Rejected,
      'is_active' => false,
      'show_on_homepage' => false,
      'show_on_page' => false,
      'is_featured' => false,
      'rejection_reason' => $reason,
      'moderated_by' => $actor->id,
      'moderated_at' => now(),
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(CmsAuditEventType::Updated, 'testimonial', $testimonial->id, $actor, $old, $testimonial->only(['status', 'is_active', 'rejection_reason']));
    $this->cacheManager->flushPublic();

    if ($testimonial->submitter_email) {
      $this->notificationService->notifyAdminsWithPermission(
        'cms.testimonials.manage',
        CmsNotificationType::FormSubmission,
        'Testimony rejected',
        sprintf('Testimony from %s was rejected.', $testimonial->displayName()),
        ['testimonial_id' => $testimonial->uuid],
      );
    }

    return $testimonial->fresh(['photoMedia', 'videoMedia']);
  }

  public function delete(CmsTestimonial $testimonial, User $actor): void
  {
    $testimonial->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'testimonial', $testimonial->id, $actor, null, null);
    $this->cacheManager->flushPublic();
  }

  /**
   * @param  list<string>  $ids
   */
  public function reorder(array $ids, User $actor): void
  {
    foreach ($ids as $index => $uuid) {
      CmsTestimonial::query()->where('uuid', $uuid)->update(['sort_order' => $index, 'updated_by' => $actor->id]);
    }

    $this->auditService->record(CmsAuditEventType::Updated, 'testimonial', 0, $actor, null, ['reordered' => count($ids)]);
    $this->cacheManager->flushPublic();
  }

  /**
   * @param  list<string>  $ids
   */
  public function bulkUpdate(array $ids, array $data, User $actor): int
  {
    $count = 0;
    foreach (CmsTestimonial::query()->whereIn('uuid', $ids)->get() as $testimonial) {
      $this->update($testimonial, $data, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  list<string>  $ids
   */
  public function bulkDelete(array $ids, User $actor): int
  {
    $count = 0;
    foreach (CmsTestimonial::query()->whereIn('uuid', $ids)->get() as $testimonial) {
      $this->delete($testimonial, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalize(array $data): array
  {
    $normalized = $data;

    foreach (['photo_media_id', 'video_media_id'] as $field) {
      if (! array_key_exists($field, $data)) {
        continue;
      }
      if ($data[$field] === null || $data[$field] === '') {
        $normalized[$field] = null;
      } elseif (! is_numeric($data[$field])) {
        $normalized[$field] = CmsMedia::query()->where('uuid', $data[$field])->value('id');
      }
    }

    if (array_key_exists('status', $normalized) && $normalized['status'] instanceof TestimonialStatus) {
      $normalized['status'] = $normalized['status']->value;
    }

    return $normalized;
  }
}
