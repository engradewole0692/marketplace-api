<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\AnnouncementStatus;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Enums\CouponAppliesTo;
use App\Modules\Lms\Enums\CouponDiscountType;
use App\Modules\Lms\Enums\ResourceType;
use App\Modules\Lms\Models\Announcement;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\CourseDownload;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonResource;
use App\Modules\Lms\Models\LmsSetting;
use App\Modules\Lms\Services\LmsAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LmsCatalogAdminController extends ApiController
{
  public function students(Request $request): JsonResponse
  {
    $this->authorize('viewAny', Enrollment::class);

    $search = trim((string) $request->query('search', ''));
    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

    $query = DB::table('lms_enrollments')
      ->join('users', 'users.id', '=', 'lms_enrollments.user_id')
      ->whereNull('lms_enrollments.deleted_at')
      ->select([
        'users.uuid as id',
        'users.name',
        'users.email',
        DB::raw('COUNT(lms_enrollments.id) as enrollments_count'),
        DB::raw('MAX(lms_enrollments.enrolled_at) as last_enrolled_at'),
        DB::raw('AVG(lms_enrollments.progress_percent) as avg_progress'),
      ])
      ->groupBy('users.id', 'users.uuid', 'users.name', 'users.email')
      ->orderByDesc(DB::raw('MAX(lms_enrollments.enrolled_at)'));

    if ($search !== '') {
      $query->where(function ($q) use ($search): void {
        $q->where('users.name', 'like', "%{$search}%")
          ->orWhere('users.email', 'like', "%{$search}%");
      });
    }

    $paginator = $query->paginate($perPage);
    $rows = collect($paginator->items())->map(fn ($row) => [
      'id' => $row->id,
      'name' => $row->name,
      'email' => $row->email,
      'enrollments_count' => (int) $row->enrollments_count,
      'avg_progress' => round((float) $row->avg_progress, 1),
      'last_enrolled_at' => $row->last_enrolled_at,
    ]);

    return $this->responder->success(
      data: [
        'data' => $rows,
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Students retrieved.',
    );
  }

  public function showStudent(string $user): JsonResponse
  {
    $this->authorize('viewAny', Enrollment::class);

    $learner = User::query()->where('uuid', $user)->firstOrFail();

    $enrollmentModels = Enrollment::query()
      ->with(['course:id,uuid,title,slug,status'])
      ->where('user_id', $learner->id)
      ->latest('enrolled_at')
      ->get();

    $enrollments = $enrollmentModels->map(fn (Enrollment $enrollment) => [
        'id' => $enrollment->uuid,
        'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : $enrollment->status,
        'learner_type' => $enrollment->learner_type instanceof \BackedEnum
          ? $enrollment->learner_type->value
          : $enrollment->learner_type,
        'progress_percent' => (float) $enrollment->progress_percent,
        'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
        'completed_at' => $enrollment->completed_at?->toIso8601String(),
        'course' => $enrollment->course ? [
          'id' => $enrollment->course->uuid,
          'title' => $enrollment->course->title,
          'slug' => $enrollment->course->slug,
          'status' => $enrollment->course->status instanceof \BackedEnum
            ? $enrollment->course->status->value
            : $enrollment->course->status,
        ] : null,
      ]);

    $certificates = CourseCertificate::query()
      ->with(['course:id,uuid,title,slug'])
      ->where('user_id', $learner->id)
      ->latest('issued_at')
      ->get()
      ->map(fn (CourseCertificate $certificate) => [
        'id' => $certificate->uuid,
        'certificate_number' => $certificate->certificate_number,
        'verification_code' => $certificate->verification_code,
        'status' => $certificate->status instanceof \BackedEnum ? $certificate->status->value : $certificate->status,
        'issued_at' => $certificate->issued_at?->toIso8601String(),
        'certificate_url' => $certificate->certificate_url,
        'course' => $certificate->course ? [
          'id' => $certificate->course->uuid,
          'title' => $certificate->course->title,
          'slug' => $certificate->course->slug,
        ] : null,
      ]);

    $orders = CourseOrder::query()
      ->with(['course:id,uuid,title,slug'])
      ->where('user_id', $learner->id)
      ->latest()
      ->limit(50)
      ->get()
      ->map(fn (CourseOrder $order) => [
        'id' => $order->uuid,
        'order_number' => $order->order_number,
        'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status,
        'amount' => (float) $order->amount,
        'currency' => $order->currency,
        'created_at' => $order->created_at?->toIso8601String(),
        'course' => $order->course ? [
          'id' => $order->course->uuid,
          'title' => $order->course->title,
          'slug' => $order->course->slug,
        ] : null,
      ]);

    $assessments = AssessmentAttempt::query()
      ->with(['assessment:id,uuid,title,assessment_type'])
      ->where('user_id', $learner->id)
      ->latest('submitted_at')
      ->limit(50)
      ->get()
      ->map(fn (AssessmentAttempt $attempt) => [
        'id' => $attempt->uuid,
        'status' => $attempt->status instanceof \BackedEnum ? $attempt->status->value : $attempt->status,
        'percentage' => $attempt->percentage !== null ? (float) $attempt->percentage : null,
        'passed' => $attempt->passed,
        'submitted_at' => $attempt->submitted_at?->toIso8601String(),
        'assessment' => $attempt->assessment ? [
          'id' => $attempt->assessment->uuid,
          'title' => $attempt->assessment->title,
          'assessment_type' => $attempt->assessment->assessment_type instanceof \BackedEnum
            ? $attempt->assessment->assessment_type->value
            : $attempt->assessment->assessment_type,
        ] : null,
      ]);

    return $this->responder->success(
      data: [
        'student' => [
          'id' => $learner->uuid,
          'name' => $learner->name,
          'email' => $learner->email,
          'enrollments_count' => $enrollmentModels->count(),
          'avg_progress' => $enrollmentModels->count() > 0
            ? round((float) $enrollmentModels->avg('progress_percent'), 1)
            : 0.0,
          'enrollments' => $enrollments,
          'certificates' => $certificates,
          'orders' => $orders,
          'assessments' => $assessments,
        ],
      ],
      message: 'Student profile retrieved.',
    );
  }

  public function announcements(Request $request): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    $paginator = Announcement::query()
      ->with(['course:id,uuid,title,slug'])
      ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
      ->when($request->query('course_id'), function ($q) use ($request): void {
        $course = Course::query()->where('uuid', $request->query('course_id'))->first();
        if ($course) {
          $q->where('course_id', $course->id);
        }
      })
      ->latest()
      ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

    $data = collect($paginator->items())->map(fn (Announcement $item) => $this->announcementPayload($item));

    return $this->responder->success(
      data: [
        'data' => $data,
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Announcements retrieved.',
    );
  }

  public function storeAnnouncement(Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'body' => ['required', 'string'],
      'course_id' => ['nullable', 'uuid'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
    ]);

    $courseId = null;
    if (! empty($validated['course_id'])) {
      $course = Course::query()->where('uuid', $validated['course_id'])->firstOrFail();
      $this->authorize('update', $course);
      $courseId = $course->id;
    }

    $status = AnnouncementStatus::tryFrom($validated['status'] ?? 'draft') ?? AnnouncementStatus::Draft;
    $announcement = Announcement::query()->create([
      'title' => $validated['title'],
      'body' => $validated['body'],
      'course_id' => $courseId,
      'status' => $status,
      'published_at' => $status === AnnouncementStatus::Published ? now() : null,
      'created_by_user_id' => $request->user()?->id,
      'updated_by_user_id' => $request->user()?->id,
    ]);
    $announcement->load('course:id,uuid,title,slug');
    $audit->record($courseId ? Course::query()->find($courseId) : null, $request->user(), 'announcement.created', metadata: [
      'announcement_id' => $announcement->uuid,
    ]);

    return $this->responder->success(
      data: ['announcement' => $this->announcementPayload($announcement)],
      message: 'Announcement created.',
      status: 201,
    );
  }

  public function updateAnnouncement(Request $request, Announcement $announcement, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);
    if ($announcement->course) {
      $this->authorize('update', $announcement->course);
    }

    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'body' => ['sometimes', 'string'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
    ]);

    if (isset($validated['status'])) {
      $status = AnnouncementStatus::from($validated['status']);
      $validated['status'] = $status;
      if ($status === AnnouncementStatus::Published && ! $announcement->published_at) {
        $validated['published_at'] = now();
      }
    }

    $announcement->fill($validated);
    $announcement->updated_by_user_id = $request->user()?->id;
    $announcement->save();
    $announcement->load('course:id,uuid,title,slug');
    $audit->record($announcement->course, $request->user(), 'announcement.updated', metadata: [
      'announcement_id' => $announcement->uuid,
    ]);

    return $this->responder->success(
      data: ['announcement' => $this->announcementPayload($announcement)],
      message: 'Announcement updated.',
    );
  }

  public function destroyAnnouncement(Announcement $announcement, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);
    if ($announcement->course) {
      $this->authorize('update', $announcement->course);
    }
    $audit->record($announcement->course, request()->user(), 'announcement.deleted', metadata: [
      'announcement_id' => $announcement->uuid,
    ]);
    $announcement->delete();

    return $this->responder->success(message: 'Announcement deleted.');
  }

  public function resources(Request $request): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    $lessonResources = LessonResource::query()
      ->with(['lesson.course:id,uuid,title,slug', 'fileMedia'])
      ->latest()
      ->limit(200)
      ->get()
      ->map(fn (LessonResource $resource) => [
        'id' => $resource->uuid,
        'scope' => 'lesson',
        'title' => $resource->title,
        'resource_type' => $resource->resource_type instanceof \BackedEnum
          ? $resource->resource_type->value
          : $resource->resource_type,
        'external_url' => $resource->external_url,
        'is_downloadable' => (bool) $resource->is_downloadable,
        'file_media_id' => $resource->fileMedia?->uuid,
        'file_url' => $resource->fileMedia ? $resource->fileMedia->url() : null,
        'course' => $resource->lesson?->course ? [
          'id' => $resource->lesson->course->uuid,
          'title' => $resource->lesson->course->title,
          'slug' => $resource->lesson->course->slug,
        ] : null,
        'lesson' => $resource->lesson ? [
          'id' => $resource->lesson->uuid,
          'title' => $resource->lesson->title,
        ] : null,
      ]);

    $downloads = CourseDownload::query()
      ->with(['course:id,uuid,title,slug', 'fileMedia'])
      ->latest()
      ->limit(200)
      ->get()
      ->map(fn (CourseDownload $download) => [
        'id' => $download->uuid,
        'scope' => 'course',
        'title' => $download->title,
        'resource_type' => 'download',
        'external_url' => $download->external_url,
        'is_downloadable' => true,
        'file_media_id' => $download->fileMedia?->uuid,
        'file_url' => $download->fileMedia ? $download->fileMedia->url() : null,
        'course' => $download->course ? [
          'id' => $download->course->uuid,
          'title' => $download->course->title,
          'slug' => $download->course->slug,
        ] : null,
        'lesson' => null,
      ]);

    return $this->responder->success(
      data: ['data' => $lessonResources->concat($downloads)->values()],
      message: 'Resources retrieved.',
    );
  }

  public function storeLessonResource(Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'lesson_id' => ['required', 'uuid'],
      'title' => ['required', 'string', 'max:255'],
      'resource_type' => ['required', 'string', Rule::in(array_column(ResourceType::cases(), 'value'))],
      'file_media_id' => ['nullable', 'uuid'],
      'external_url' => ['nullable', 'string', 'max:1000'],
      'is_downloadable' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $lesson = Lesson::query()->with('course')->where('uuid', $validated['lesson_id'])->firstOrFail();
    $this->authorize('update', $lesson->course);

    $mediaId = null;
    if (! empty($validated['file_media_id'])) {
      $mediaId = CmsMedia::query()->where('uuid', $validated['file_media_id'])->value('id');
    }

    $resource = LessonResource::query()->create([
      'lesson_id' => $lesson->id,
      'title' => $validated['title'],
      'resource_type' => ResourceType::from($validated['resource_type']),
      'file_media_id' => $mediaId,
      'external_url' => $validated['external_url'] ?? null,
      'is_downloadable' => $validated['is_downloadable'] ?? true,
      'sort_order' => $validated['sort_order'] ?? 0,
    ]);

    $audit->record($lesson->course, $request->user(), 'lesson_resource.created', metadata: [
      'resource_id' => $resource->uuid,
      'lesson_id' => $lesson->uuid,
    ]);

    return $this->responder->success(
      data: [
        'resource' => [
          'id' => $resource->uuid,
          'title' => $resource->title,
          'resource_type' => $resource->resource_type->value,
        ],
      ],
      message: 'Lesson resource created.',
      status: 201,
    );
  }

  public function storeCourseDownload(Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'course_id' => ['required', 'uuid'],
      'title' => ['required', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'file_media_id' => ['nullable', 'uuid'],
      'external_url' => ['nullable', 'string', 'max:1000'],
      'is_public' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $course = Course::query()->where('uuid', $validated['course_id'])->firstOrFail();
    $this->authorize('update', $course);

    $mediaId = null;
    if (! empty($validated['file_media_id'])) {
      $mediaId = CmsMedia::query()->where('uuid', $validated['file_media_id'])->value('id');
    }

    $download = CourseDownload::query()->create([
      'course_id' => $course->id,
      'title' => $validated['title'],
      'description' => $validated['description'] ?? null,
      'file_media_id' => $mediaId,
      'external_url' => $validated['external_url'] ?? null,
      'is_public' => $validated['is_public'] ?? false,
      'sort_order' => $validated['sort_order'] ?? 0,
    ]);

    $audit->record($course, $request->user(), 'course_download.created', metadata: [
      'download_id' => $download->uuid,
    ]);

    return $this->responder->success(
      data: [
        'download' => [
          'id' => $download->uuid,
          'title' => $download->title,
        ],
      ],
      message: 'Course download created.',
      status: 201,
    );
  }

  public function coupons(Request $request): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    $paginator = CourseCoupon::query()
      ->with(['course:id,uuid,title,slug'])
      ->latest()
      ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

    $data = collect($paginator->items())->map(fn (CourseCoupon $coupon) => [
      'id' => $coupon->uuid,
      'code' => $coupon->code,
      'name' => $coupon->name,
      'discount_type' => $coupon->discount_type instanceof \BackedEnum ? $coupon->discount_type->value : $coupon->discount_type,
      'discount_value' => (float) $coupon->discount_value,
      'applies_to' => $coupon->applies_to instanceof \BackedEnum ? $coupon->applies_to->value : $coupon->applies_to,
      'status' => $coupon->status instanceof \BackedEnum ? $coupon->status->value : $coupon->status,
      'redeemed_count' => (int) $coupon->redeemed_count,
      'max_redemptions' => $coupon->max_redemptions,
      'course' => $coupon->course ? [
        'id' => $coupon->course->uuid,
        'title' => $coupon->course->title,
      ] : null,
    ]);

    return $this->responder->success(
      data: [
        'data' => $data,
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Coupons retrieved.',
    );
  }

  public function storeCoupon(Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'code' => ['required', 'string', 'max:60', 'unique:lms_coupons,code'],
      'name' => ['required', 'string', 'max:255'],
      'discount_type' => ['required', 'string', Rule::in(array_column(CouponDiscountType::cases(), 'value'))],
      'discount_value' => ['required', 'numeric', 'min:0'],
      'applies_to' => ['nullable', 'string', Rule::in(array_column(CouponAppliesTo::cases(), 'value'))],
      'course_id' => ['nullable', 'uuid'],
      'max_redemptions' => ['nullable', 'integer', 'min:1'],
      'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
    ]);

    $courseId = null;
    if (! empty($validated['course_id'])) {
      $course = Course::query()->where('uuid', $validated['course_id'])->firstOrFail();
      $courseId = $course->id;
    }

    $coupon = CourseCoupon::query()->create([
      'code' => strtoupper($validated['code']),
      'name' => $validated['name'],
      'discount_type' => CouponDiscountType::from($validated['discount_type']),
      'discount_value' => $validated['discount_value'],
      'applies_to' => CouponAppliesTo::tryFrom($validated['applies_to'] ?? 'all') ?? CouponAppliesTo::All,
      'course_id' => $courseId,
      'max_redemptions' => $validated['max_redemptions'] ?? null,
      'status' => CatalogStatus::tryFrom($validated['status'] ?? 'active') ?? CatalogStatus::Active,
      'created_by_user_id' => $request->user()?->id,
      'updated_by_user_id' => $request->user()?->id,
    ]);

    $audit->record($courseId ? Course::query()->find($courseId) : null, $request->user(), 'coupon.created', metadata: [
      'coupon_id' => $coupon->uuid,
      'code' => $coupon->code,
    ]);

    return $this->responder->success(
      data: [
        'coupon' => [
          'id' => $coupon->uuid,
          'code' => $coupon->code,
          'name' => $coupon->name,
        ],
      ],
      message: 'Coupon created.',
      status: 201,
    );
  }

  public function updateCoupon(Request $request, CourseCoupon $coupon, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'code' => ['sometimes', 'string', 'max:60', Rule::unique('lms_coupons', 'code')->ignore($coupon->id)],
      'name' => ['sometimes', 'string', 'max:255'],
      'discount_type' => ['sometimes', 'string', Rule::in(array_column(CouponDiscountType::cases(), 'value'))],
      'discount_value' => ['sometimes', 'numeric', 'min:0'],
      'applies_to' => ['sometimes', 'string', Rule::in(array_column(CouponAppliesTo::cases(), 'value'))],
      'course_id' => ['nullable', 'uuid'],
      'max_redemptions' => ['nullable', 'integer', 'min:1'],
      'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
    ]);

    if (array_key_exists('course_id', $validated)) {
      $validated['course_id'] = $validated['course_id']
        ? Course::query()->where('uuid', $validated['course_id'])->value('id')
        : null;
    }
    if (isset($validated['code'])) {
      $validated['code'] = strtoupper($validated['code']);
    }
    if (isset($validated['discount_type'])) {
      $validated['discount_type'] = CouponDiscountType::from($validated['discount_type']);
    }
    if (isset($validated['applies_to'])) {
      $validated['applies_to'] = CouponAppliesTo::from($validated['applies_to']);
    }
    if (isset($validated['status'])) {
      $validated['status'] = CatalogStatus::from($validated['status']);
    }
    $validated['updated_by_user_id'] = $request->user()?->id;

    $coupon->fill($validated)->save();

    $audit->record($coupon->course, $request->user(), 'coupon.updated', metadata: [
      'coupon_id' => $coupon->uuid,
      'code' => $coupon->code,
    ]);

    return $this->responder->success(
      data: ['coupon' => ['id' => $coupon->uuid, 'code' => $coupon->code, 'name' => $coupon->name]],
      message: 'Coupon updated.',
    );
  }

  public function destroyCoupon(CourseCoupon $coupon, Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $audit->record($coupon->course, $request->user(), 'coupon.deleted', metadata: [
      'coupon_id' => $coupon->uuid,
      'code' => $coupon->code,
    ]);

    $coupon->delete();

    return $this->responder->success(data: null, message: 'Coupon deleted.');
  }

  public function settings(): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: ['settings' => LmsSetting::defaultsMerged()],
      message: 'LMS settings retrieved.',
    );
  }

  public function updateSettings(Request $request, LmsAuditService $audit): JsonResponse
  {
    $this->authorize('create', Course::class);

    $validated = $request->validate([
      'default_currency' => ['sometimes', 'string', 'size:3'],
      'allow_public_registration' => ['sometimes', 'boolean'],
      'allow_member_discount' => ['sometimes', 'boolean'],
      'certificate_prefix' => ['sometimes', 'string', 'max:20'],
      'default_completion_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
      'featured_limit' => ['sometimes', 'integer', 'min:1', 'max:24'],
    ]);

    foreach ($validated as $key => $value) {
      LmsSetting::query()->updateOrCreate(
        ['key' => $key],
        ['value' => $value],
      );
    }

    $audit->record(null, $request->user(), 'settings.updated', metadata: $validated);

    return $this->responder->success(
      data: ['settings' => LmsSetting::defaultsMerged()],
      message: 'LMS settings updated.',
    );
  }

  /** @return array<string, mixed> */
  private function announcementPayload(Announcement $announcement): array
  {
    return [
      'id' => $announcement->uuid,
      'title' => $announcement->title,
      'body' => $announcement->body,
      'status' => $announcement->status instanceof \BackedEnum
        ? $announcement->status->value
        : $announcement->status,
      'published_at' => $announcement->published_at?->toIso8601String(),
      'course' => $announcement->course ? [
        'id' => $announcement->course->uuid,
        'title' => $announcement->course->title,
        'slug' => $announcement->course->slug,
      ] : null,
    ];
  }
}
