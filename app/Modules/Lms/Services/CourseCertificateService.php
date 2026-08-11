<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\CertificateStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Mail\CourseCertificateIssuedMail;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\CertificateTemplate;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LmsSetting;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Services\Certificates\CertificatePdfEngine;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LMS course certification — reuses shared CertificatePdfEngine (Events engine pattern).
 */
final class CourseCertificateService implements ServiceContract
{
  public function __construct(
    private readonly CertificatePdfEngine $pdfEngine,
    private readonly MemberNotificationQueueService $memberNotificationQueueService,
    private readonly CommunicationDispatchService $communicationDispatch,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateTemplates(array $filters = []): LengthAwarePaginator
  {
    $query = CertificateTemplate::query()
      ->with(['course:id,uuid,title', 'backgroundMedia', 'logoMedia', 'watermarkMedia', 'instructorSignatureMedia', 'directorSignatureMedia'])
      ->orderBy('sort_order')
      ->orderBy('name');

    if (! empty($filters['course_id'])) {
      $courseId = Course::query()->where('uuid', $filters['course_id'])->value('id')
        ?? (is_numeric($filters['course_id']) ? (int) $filters['course_id'] : null);
      if ($courseId) {
        $query->where(function ($q) use ($courseId): void {
          $q->where('course_id', $courseId)->orWhereNull('course_id');
        });
      }
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createTemplate(array $data, User $actor): CertificateTemplate
  {
    $data['slug'] ??= Str::slug($data['name']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;
    $data['course_id'] = $this->resolveCourseId($data['course_id'] ?? null);

    $template = CertificateTemplate::query()->create($data)->fresh();
    if ($template->is_default) {
      $this->clearOtherDefaults($template);
    }

    return $template;
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateTemplate(CertificateTemplate $template, array $data, User $actor): CertificateTemplate
  {
    if (isset($data['name']) && empty($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }
    if (array_key_exists('course_id', $data)) {
      $data['course_id'] = $this->resolveCourseId($data['course_id']);
    }
    $data['updated_by_user_id'] = $actor->id;
    $template->fill($data);
    $template->save();
    if ($template->is_default) {
      $this->clearOtherDefaults($template);
    }

    return $template->fresh();
  }

  public function deleteTemplate(CertificateTemplate $template): void
  {
    $template->delete();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateIssuances(array $filters = []): LengthAwarePaginator
  {
    $query = CourseCertificate::query()
      ->with(['course:id,uuid,title,slug', 'user:id,uuid,name,email', 'certificateMedia', 'template'])
      ->orderByDesc('issued_at');

    if (! empty($filters['course_id'])) {
      $courseId = Course::query()->where('uuid', $filters['course_id'])->value('id');
      if ($courseId) {
        $query->where('course_id', $courseId);
      }
    }
    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('certificate_number', 'like', "%{$search}%")
          ->orWhere('verification_code', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function eligibility(Enrollment $enrollment): array
  {
    $enrollment->loadMissing(['course']);
    $course = $enrollment->course;
    $reasons = [];

    if ($course === null) {
      return ['eligible' => false, 'reasons' => ['Course missing.']];
    }

    if (! (bool) ($course->certificate_enabled ?? true)) {
      $reasons[] = 'Certificates are disabled for this course.';
    }

    $completed = $enrollment->status === EnrollmentStatus::Completed
      || (float) $enrollment->progress_percent >= 100;
    if (! $completed) {
      $reasons[] = 'Course is not complete.';
    }

    $requiresAssessment = (bool) ($course->certificate_requires_assessment_pass ?? true);
    $assessmentOk = ! $requiresAssessment || $this->assessmentsPassed($enrollment);
    if (! $assessmentOk) {
      $reasons[] = 'Required assessments have not been passed.';
    }

    $existing = CourseCertificate::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('status', '!=', CertificateStatus::Revoked->value)
      ->exists();

    return [
      'eligible' => $reasons === [] && ! $existing,
      'already_issued' => $existing,
      'course_complete' => $completed,
      'assessments_passed' => $assessmentOk,
      'reasons' => $reasons,
    ];
  }

  public function tryIssue(Enrollment $enrollment, ?User $actor = null): ?CourseCertificate
  {
    $check = $this->eligibility($enrollment);
    if (! empty($check['already_issued'])) {
      return CourseCertificate::query()
        ->where('enrollment_id', $enrollment->id)
        ->where('status', '!=', CertificateStatus::Revoked->value)
        ->first();
    }

    if (! $check['eligible']) {
      return null;
    }

    return $this->issue($enrollment, $actor);
  }

  public function issue(Enrollment $enrollment, ?User $actor = null, ?int $templateId = null): CourseCertificate
  {
    return DB::transaction(function () use ($enrollment, $actor, $templateId): CourseCertificate {
      $enrollment->loadMissing(['course', 'user.member']);
      $course = $enrollment->course;
      if ($course === null) {
        throw ValidationException::withMessages(['enrollment' => ['Enrollment is missing its course.']]);
      }

      if (! (bool) ($course->certificate_enabled ?? true)) {
        throw ValidationException::withMessages(['course' => ['Certificates are disabled for this course.']]);
      }

      $existing = CourseCertificate::query()
        ->where('enrollment_id', $enrollment->id)
        ->where('status', '!=', CertificateStatus::Revoked->value)
        ->first();
      if ($existing !== null) {
        return $existing;
      }

      $completed = $enrollment->status === EnrollmentStatus::Completed
        || (float) $enrollment->progress_percent >= 100;
      if (! $completed) {
        throw ValidationException::withMessages(['enrollment' => ['Course must be completed before issuing a certificate.']]);
      }

      if ((bool) ($course->certificate_requires_assessment_pass ?? true) && ! $this->assessmentsPassed($enrollment)) {
        throw ValidationException::withMessages(['assessments' => ['All required assessments must be passed.']]);
      }

      $template = $this->resolveTemplate($course, $templateId);
      $prefix = (string) (LmsSetting::defaultsMerged()['certificate_prefix'] ?? 'MM-LMS');
      $verificationCode = strtoupper(Str::random(12));
      $baseNumber = sprintf(
        '%s-%s-%s',
        Str::upper(Str::slug($prefix, '')),
        $course->id,
        str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT),
      );
      $certificateNumber = $baseNumber;
      $suffix = 1;
      while (CourseCertificate::withTrashed()->where('certificate_number', $certificateNumber)->exists()) {
        $certificateNumber = $baseNumber.'-R'.$suffix;
        $suffix++;
      }

      $certificate = CourseCertificate::query()->create([
        'enrollment_id' => $enrollment->id,
        'course_id' => $course->id,
        'user_id' => $enrollment->user_id,
        'certificate_number' => $certificateNumber,
        'verification_code' => $verificationCode,
        'status' => CertificateStatus::Issued,
        'issued_at' => now(),
        'template_id' => $template?->id,
        'issued_by_user_id' => $actor?->id,
      ]);

      $media = $this->generatePdfMedia($enrollment, $certificate, $template, $actor);
      $certificate->certificate_media_id = $media?->id;
      $certificate->save();

      $this->sendCopies($certificate->fresh(['course', 'user.member', 'certificateMedia']));

      return $certificate->fresh(['course', 'user', 'certificateMedia', 'template']);
    });
  }

  public function reissue(CourseCertificate $certificate, User $actor): CourseCertificate
  {
    return DB::transaction(function () use ($certificate, $actor): CourseCertificate {
      $certificate->loadMissing(['enrollment']);
      $enrollment = $certificate->enrollment;
      if ($enrollment === null) {
        throw ValidationException::withMessages(['certificate' => ['Certificate has no enrollment.']]);
      }

      $certificate->forceFill([
        'status' => CertificateStatus::Revoked,
        'revoked_at' => now(),
      ])->save();

      return $this->issue($enrollment, $actor, $certificate->template_id);
    });
  }

  /**
   * @return array<string, mixed>|null
   */
  public function verify(string $code): ?array
  {
    /** @var CourseCertificate|null $certificate */
    $certificate = CourseCertificate::query()
      ->with(['course', 'user', 'certificateMedia'])
      ->where('verification_code', $code)
      ->where('status', CertificateStatus::Issued->value)
      ->first();

    if ($certificate === null) {
      return null;
    }

    $certificate->increment('download_count');

    return [
      'type' => 'course',
      'certificate_number' => $certificate->certificate_number,
      'verification_code' => $certificate->verification_code,
      'issued_at' => $certificate->issued_at?->toIso8601String(),
      'status' => CertificateStatus::Issued->value,
      'course' => $certificate->course ? [
        'id' => $certificate->course->uuid,
        'title' => $certificate->course->title,
        'slug' => $certificate->course->slug,
      ] : null,
      'recipient' => [
        'name' => $certificate->user?->name,
        'email' => $certificate->user?->email,
      ],
      'certificate_url' => $certificate->certificateMedia?->url(),
      'verification_url' => URL::to('/certificate/'.$certificate->verification_code),
    ];
  }

  public function assessmentsPassed(Enrollment $enrollment): bool
  {
    $assessmentIds = Assessment::query()
      ->where('course_id', $enrollment->course_id)
      ->where('status', AssessmentStatus::Published->value)
      ->pluck('id');

    if ($assessmentIds->isEmpty()) {
      return true;
    }

    foreach ($assessmentIds as $assessmentId) {
      $passed = AssessmentAttempt::query()
        ->where('assessment_id', $assessmentId)
        ->where('user_id', $enrollment->user_id)
        ->where('passed', true)
        ->exists();

      if (! $passed) {
        return false;
      }
    }

    return true;
  }

  private function resolveTemplate(Course $course, ?int $templateId): ?CertificateTemplate
  {
    if ($templateId !== null) {
      return CertificateTemplate::query()->with([
        'backgroundMedia', 'logoMedia', 'watermarkMedia',
        'instructorSignatureMedia', 'directorSignatureMedia',
      ])->find($templateId);
    }

    if ($course->certificate_template_id) {
      return CertificateTemplate::query()->with([
        'backgroundMedia', 'logoMedia', 'watermarkMedia',
        'instructorSignatureMedia', 'directorSignatureMedia',
      ])->find($course->certificate_template_id);
    }

    return CertificateTemplate::query()
      ->with([
        'backgroundMedia', 'logoMedia', 'watermarkMedia',
        'instructorSignatureMedia', 'directorSignatureMedia',
      ])
      ->where('is_active', true)
      ->where(function ($q) use ($course): void {
        $q->where('course_id', $course->id)->orWhereNull('course_id');
      })
      ->orderByDesc('is_default')
      ->orderBy('sort_order')
      ->first();
  }

  private function generatePdfMedia(
    Enrollment $enrollment,
    CourseCertificate $certificate,
    ?CertificateTemplate $template,
    ?User $actor,
  ): ?\App\Modules\Cms\Models\CmsMedia {
    $enrollment->loadMissing(['course', 'user']);
    $verificationUrl = URL::to('/certificate/'.$certificate->verification_code);

    $placeholders = [
      '{{name}}' => e((string) ($enrollment->user?->name ?? 'Learner')),
      '{{member_name}}' => e((string) ($enrollment->user?->name ?? 'Learner')),
      '{{course}}' => e((string) ($enrollment->course?->title ?? '')),
      '{{date}}' => e(Carbon::parse($certificate->issued_at ?? now())->toFormattedDateString()),
      '{{certificate_number}}' => e($certificate->certificate_number),
      '{{verification_code}}' => e($certificate->verification_code),
      '{{verification_url}}' => e($verificationUrl),
    ];

    $assets = [
      'background_url' => $this->absoluteMediaUrl($template?->backgroundMedia),
      'logo_url' => $this->absoluteMediaUrl($template?->logoMedia),
      'watermark_url' => $this->absoluteMediaUrl($template?->watermarkMedia),
      'instructor_signature_url' => $this->absoluteMediaUrl($template?->instructorSignatureMedia),
      'director_signature_url' => $this->absoluteMediaUrl($template?->directorSignatureMedia),
    ];

    return $this->pdfEngine->renderToMedia(
      (string) ($template?->html_body ?? ''),
      $placeholders,
      $certificate->certificate_number,
      $actor,
      $assets,
      'certificates/lms',
    );
  }

  private function sendCopies(CourseCertificate $certificate): void
  {
    $user = $certificate->user;
    if ($user === null) {
      return;
    }

    $payload = [
      'course_title' => $certificate->course?->title,
      'course_name' => $certificate->course?->title,
      'certificate_name' => $certificate->course?->title,
      'certificate_number' => $certificate->certificate_number,
      'verification_code' => $certificate->verification_code,
      'verification_url' => URL::to('/certificate/'.$certificate->verification_code),
      'certificate_url' => $certificate->certificateMedia?->url(),
      'issued_at' => $certificate->issued_at?->toIso8601String(),
      'in_app_title' => 'Certificate issued: '.($certificate->course?->title ?? 'Course'),
      'in_app_body' => 'Certificate '.$certificate->certificate_number.' is ready to download.',
    ];

    $member = $user->member;
    if ($member) {
      try {
        $this->communicationDispatch->dispatchEvent(
          eventKey: 'lms.certificate.issued',
          section: 'learning',
          variables: array_merge($payload, [
            'member_name' => $user->display_name ?: $user->name ?: 'Learner',
          ]),
          recipientUser: $user,
          recipientEmail: $user->email,
          recipientName: $user->display_name ?: $user->name ?: 'Learner',
          related: $certificate,
          includeRouting: false,
        );
      } catch (\Throwable $exception) {
        report($exception);
        $this->memberNotificationQueueService->queue($member, 'email', 'lms.certificate.issued', $payload);
        $this->memberNotificationQueueService->queue($member, 'in_app', 'lms.certificate.issued', [
          'title' => $payload['in_app_title'],
          'body' => $payload['in_app_body'],
          ...$payload,
        ]);
      }

      return;
    }

    if ($user->email) {
      Mail::to($user->email)->send(new CourseCertificateIssuedMail($certificate, (string) $user->name));
    }
  }

  private function absoluteMediaUrl(?\App\Modules\Cms\Models\CmsMedia $media): ?string
  {
    if ($media === null) {
      return null;
    }

    $url = $media->url();
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
      return $url;
    }

    return URL::to($url);
  }

  private function resolveCourseId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }

    return Course::query()->where('uuid', $value)->value('id');
  }

  private function clearOtherDefaults(CertificateTemplate $template): void
  {
    CertificateTemplate::query()
      ->where('id', '!=', $template->id)
      ->where(function ($q) use ($template): void {
        if ($template->course_id) {
          $q->where('course_id', $template->course_id);
        } else {
          $q->whereNull('course_id');
        }
      })
      ->update(['is_default' => false]);
  }
}
