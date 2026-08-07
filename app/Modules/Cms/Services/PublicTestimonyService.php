<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Enums\CmsNotificationType;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Enums\TestimonialStatus;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class PublicTestimonyService implements ServiceContract
{
  public const CATEGORIES = [
    'marketplace',
    'faith',
    'leadership',
    'healing',
    'family',
    'nation',
    'ministry',
    'other',
  ];

  public function __construct(
    private readonly FormSubmissionService $formSubmissionService,
    private readonly CmsAuditService $auditService,
    private readonly CmsNotificationService $notificationService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  /**
   * @param  array<string, mixed>  $payload
   */
  public function submit(array $payload, Request $request, ?User $user = null): CmsTestimonial
  {
    $isAnonymous = (bool) ($payload['is_anonymous'] ?? false);
    $submitterType = ($payload['submitter_type'] ?? null) === 'member' || $user !== null
      ? 'member'
      : 'guest';

    $memberId = null;
    if ($submitterType === 'member') {
      $memberId = $this->resolveMemberId($user, $payload['member_id'] ?? null);
    }

    $authorName = $isAnonymous
      ? 'Anonymous'
      : (string) ($payload['author_name'] ?? $payload['name'] ?? 'Guest');

    $photoId = $this->resolveOrUploadMedia($payload['photo_media_id'] ?? null, $payload['photo'] ?? null, $user);
    $videoId = $this->resolveOrUploadMedia($payload['video_media_id'] ?? null, $payload['video'] ?? null, $user);

    $inboxPayload = collect($payload)
      ->except(['photo', 'video'])
      ->map(fn ($value) => $value instanceof UploadedFile ? null : $value)
      ->filter(fn ($value) => $value !== null)
      ->all();

    $submission = $this->formSubmissionService->submit(FormSubmissionType::Testimony, [
      ...$inboxPayload,
      'name' => $authorName,
      'email' => $payload['submitter_email'] ?? $payload['email'] ?? null,
      'phone' => $payload['submitter_phone'] ?? $payload['phone'] ?? null,
      'photo_media_id' => $photoId ? CmsMedia::query()->find($photoId)?->uuid : null,
      'video_media_id' => $videoId ? CmsMedia::query()->find($videoId)?->uuid : null,
    ], $request);

    $testimonial = CmsTestimonial::query()->create([
      'author_name' => $authorName,
      'author_title' => $payload['author_title'] ?? $payload['role'] ?? null,
      'author_location' => $payload['author_location'] ?? $payload['country'] ?? null,
      'quote' => (string) ($payload['quote'] ?? $payload['testimony'] ?? ''),
      'status' => TestimonialStatus::Pending,
      'category' => $payload['category'] ?? 'other',
      'is_anonymous' => $isAnonymous,
      'submitter_type' => $submitterType,
      'submitter_email' => $payload['submitter_email'] ?? $payload['email'] ?? null,
      'submitter_phone' => $payload['submitter_phone'] ?? $payload['phone'] ?? null,
      'member_id' => $memberId,
      'photo_media_id' => $photoId,
      'video_media_id' => $videoId,
      'is_featured' => false,
      'is_active' => false,
      'show_on_homepage' => false,
      'show_on_page' => false,
      'source_submission_id' => $submission->id,
      'sort_order' => 9999,
      'created_by' => $user?->id,
      'updated_by' => $user?->id,
    ]);

    $submission->forceFill([
      'payload' => [
        ...($submission->payload ?? []),
        'testimonial_id' => $testimonial->uuid,
      ],
    ])->save();

    $this->auditService->record(
      CmsAuditEventType::Created,
      'testimonial',
      $testimonial->id,
      $user,
      null,
      ['status' => 'pending', 'source' => 'public'],
    );

    $this->notificationService->notifyAdminsWithPermission(
      'cms.testimonials.manage',
      CmsNotificationType::FormSubmission,
      'New testimony pending review',
      sprintf('%s submitted a testimony for moderation.', $authorName),
      ['testimonial_id' => $testimonial->uuid, 'submission_id' => $submission->uuid],
    );

    return $testimonial->load(['photoMedia', 'videoMedia']);
  }

  private function resolveMemberId(?User $user, mixed $memberUuid): ?int
  {
    if (is_string($memberUuid) && $memberUuid !== '') {
      $id = Member::query()->where('uuid', $memberUuid)->value('id');
      if ($id !== null) {
        return (int) $id;
      }
    }

    if ($user === null) {
      return null;
    }

    return Member::query()->where('user_id', $user->id)->value('id');
  }

  private function resolveOrUploadMedia(mixed $mediaUuid, mixed $file, ?User $user): ?int
  {
    if (is_string($mediaUuid) && $mediaUuid !== '') {
      return CmsMedia::query()->where('uuid', $mediaUuid)->value('id');
    }

    if (! $file instanceof UploadedFile) {
      return null;
    }

    $path = $file->store('cms/media/testimony-submissions', 'public');
    $mime = $file->getMimeType() ?? 'application/octet-stream';

    $media = CmsMedia::query()->create([
      'folder_id' => null,
      'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'testimony-media',
      'file_name' => $file->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $mime,
      'size' => $file->getSize() ?: 0,
      'title' => $file->getClientOriginalName(),
      'created_by' => $user?->id,
      'updated_by' => $user?->id,
      'metadata' => ['source' => 'public_testimony'],
    ]);

    return $media->id;
  }
}
