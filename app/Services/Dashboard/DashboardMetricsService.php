<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Contracts\ServiceContract;
use App\Enums\MemberStatus;
use App\Models\IamAuditLog;
use App\Models\Member;
use App\Models\MemberAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsAuditLog;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Services\Health\HealthCheckService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardMetricsService implements ServiceContract
{
    private const OVERVIEW_CACHE_KEY = 'dashboard:overview:v4';

  private const OVERVIEW_CACHE_TTL_SECONDS = 120;

  private const EDITOR_PERMISSION_SLUGS = ['cms.pages.manage', 'cms.manage'];

  public function __construct(
    private readonly HealthCheckService $healthCheckService,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function overview(): array
  {
    return Cache::remember(self::OVERVIEW_CACHE_KEY, self::OVERVIEW_CACHE_TTL_SECONDS, function (): array {
      $feed = $this->activityFeed();

      return [
        'generated_at' => now()->toIso8601String(),
        'membership' => $this->membershipMetrics(),
        'cms' => $this->cmsMetrics(),
        'users' => $this->systemMetrics(),
        'activity' => $feed['items'],
        'activity_meta' => $feed['meta'],
        'health' => $this->healthMetrics(),
        'charts' => $this->charts(),
        'events' => $this->eventMetrics(),
        'learning' => $this->learningMetrics(),
        'donations' => $this->donationMetrics(),
        'notifications' => [
          'module' => 'cms',
          'unread_count_endpoint' => '/api/v1/cms/notifications/unread-count',
        ],
      ];
    });
  }

  public function forgetCache(): void
  {
    Cache::forget(self::OVERVIEW_CACHE_KEY);
    Cache::forget('dashboard:activity_total:v1');
  }

  /**
   * @return array<string, mixed>
   */
  public function membershipMetrics(): array
  {
    $now = Carbon::now();
    $thirtyDaysAgo = $now->copy()->subDays(30);
    $sixtyDaysAgo = $now->copy()->subDays(60);

    $new30Days = Member::query()->where('created_at', '>=', $thirtyDaysAgo)->count();
    $previous30Days = Member::query()
      ->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])
      ->count();

    $growthPercent = round((($new30Days - $previous30Days) / max($previous30Days, 1)) * 100, 1);

    $recentRegistrations = Member::query()
      ->orderByDesc('created_at')
      ->limit(5)
      ->get(['uuid', 'first_name', 'middle_name', 'last_name', 'display_name', 'membership_number', 'status', 'created_at'])
      ->map(fn (Member $member): array => [
        'id' => $member->uuid,
        'name' => $member->fullName(),
        'status' => $member->status instanceof MemberStatus ? $member->status->value : $member->status,
        'created_at' => $member->created_at?->toIso8601String(),
      ])
      ->values()
      ->all();

    $pendingApplications = Member::query()->whereIn('status', [
      MemberStatus::ApplicationSubmitted->value,
      MemberStatus::PendingReview->value,
    ])->count();
    $underReview = Member::query()->where('status', MemberStatus::UnderReview->value)->count();
    $pipelinePending = Member::query()->whereIn('status', [
      MemberStatus::ApplicationSubmitted->value,
      MemberStatus::PendingReview->value,
      MemberStatus::UnderReview->value,
      MemberStatus::InterviewRequired->value,
      MemberStatus::InterviewScheduled->value,
      MemberStatus::InterviewCompleted->value,
    ])->count();

    $membersByMinistry = Member::query()
      ->select('ministry_id', DB::raw('count(*) as total'))
      ->whereNotNull('ministry_id')
      ->groupBy('ministry_id')
      ->orderByDesc('total')
      ->limit(10)
      ->get()
      ->map(function ($row) {
        $ministry = \App\Modules\Cms\Models\CmsMinistry::query()->find($row->ministry_id);

        return [
          'ministry_id' => $ministry?->uuid,
          'name' => $ministry?->name ?? 'Unknown',
          'total' => (int) $row->total,
        ];
      })
      ->values()
      ->all();

    $membersByCountry = Member::query()
      ->select('country_id', DB::raw('count(*) as total'))
      ->whereNotNull('country_id')
      ->groupBy('country_id')
      ->orderByDesc('total')
      ->limit(10)
      ->get()
      ->map(function ($row) {
        $country = \App\Modules\Cms\Models\CmsCountry::query()->find($row->country_id);

        return [
          'country_id' => $country?->uuid,
          'name' => $country?->name ?? 'Unknown',
          'total' => (int) $row->total,
        ];
      })
      ->values()
      ->all();

    $recentlyActivated = Member::query()
      ->whereNotNull('activated_at')
      ->orderByDesc('activated_at')
      ->limit(5)
      ->get(['uuid', 'first_name', 'middle_name', 'last_name', 'display_name', 'membership_number', 'status', 'activated_at'])
      ->map(fn (Member $member): array => [
        'id' => $member->uuid,
        'name' => $member->fullName(),
        'status' => $member->status instanceof MemberStatus ? $member->status->value : $member->status,
        'activated_at' => $member->activated_at?->toIso8601String(),
      ])
      ->values()
      ->all();

    return [
      'total' => Member::query()->count(),
      'new_30_days' => $new30Days,
      'pending' => $pipelinePending,
      'pending_applications' => $pendingApplications,
      'under_review' => $underReview,
      'awaiting_onboarding' => Member::query()->where('status', MemberStatus::Orientation->value)->count(),
      'awaiting_ministry_assignment' => Member::query()
        ->whereIn('status', [MemberStatus::Approved->value, MemberStatus::Orientation->value, MemberStatus::MinistryAssigned->value])
        ->whereNull('ministry_id')
        ->count(),
      'interview_required' => Member::query()->where('status', MemberStatus::InterviewRequired->value)->count(),
      'interview_scheduled' => Member::query()->where('status', MemberStatus::InterviewScheduled->value)->count(),
      'interview_completed' => Member::query()->where('status', MemberStatus::InterviewCompleted->value)->count(),
      'orientation' => Member::query()->where('status', MemberStatus::Orientation->value)->count(),
      'assignment' => Member::query()
        ->whereIn('status', [MemberStatus::Approved->value, MemberStatus::Orientation->value])
        ->whereNull('ministry_id')
        ->count(),
      'interviews_today' => \App\Models\MemberInterview::query()->whereDate('scheduled_date', Carbon::today())->count(),
      'upcoming_interviews_count' => \App\Models\MemberInterview::query()
        ->whereDate('scheduled_date', '>=', Carbon::today())
        ->whereIn('status', ['scheduled', 'pending'])
        ->count(),
      'upcoming_interviews' => \App\Models\MemberInterview::query()
        ->whereDate('scheduled_date', '>=', Carbon::today())
        ->whereIn('status', ['scheduled', 'pending'])
        ->orderBy('scheduled_date')
        ->limit(5)
        ->get(['uuid', 'member_id', 'scheduled_date', 'scheduled_time', 'status'])
        ->map(fn ($row) => [
          'id' => $row->uuid,
          'member_id' => $row->member_id,
          'scheduled_date' => $row->scheduled_date?->toDateString(),
          'scheduled_time' => $row->scheduled_time,
          'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
        ])
        ->values()
        ->all(),
      'recently_activated_count' => Member::query()
        ->where('activated_at', '>=', Carbon::now()->subDays(30))
        ->count(),
      'recently_activated' => $recentlyActivated,
      'members_by_ministry' => $membersByMinistry,
      'members_by_country' => $membersByCountry,
      'leadership_stats' => [
        'total' => \App\Modules\Cms\Models\CmsLeadershipProfile::query()->count(),
        'active' => \App\Modules\Cms\Models\CmsLeadershipProfile::query()->where('is_active', true)->count(),
      ],
      'ministry_stats' => [
        'total' => \App\Modules\Cms\Models\CmsMinistry::query()->count(),
        'active' => \App\Modules\Cms\Models\CmsMinistry::query()->where('is_active', true)->count(),
      ],
      'active' => Member::query()->where('status', MemberStatus::Active->value)->count(),
      'inactive' => Member::query()->where('status', MemberStatus::Inactive->value)->count(),
      'suspended' => Member::query()->where('status', MemberStatus::Suspended->value)->count(),
      'growth_percent_30d' => $growthPercent,
      'recent_registrations' => $recentRegistrations,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function cmsMetrics(): array
  {
    $pageCounts = CmsPage::query()
      ->select('status', DB::raw('count(*) as total'))
      ->groupBy('status')
      ->pluck('total', 'status');

    $publishedPages = (int) ($pageCounts[PageStatus::Published->value] ?? 0);
    $storageBytes = (int) CmsMedia::query()->sum('size');
    $seoTotal = CmsSeo::query()->count();
    $thirtyDaysAgo = Carbon::now()->subDays(30);

    $recentlyEditedPages = CmsPage::query()
      ->orderByDesc('updated_at')
      ->limit(5)
      ->get(['uuid', 'title', 'slug', 'status', 'updated_at'])
      ->map(fn (CmsPage $page): array => [
        'id' => $page->uuid,
        'title' => $page->title,
        'slug' => $page->slug,
        'status' => $page->status instanceof PageStatus ? $page->status->value : $page->status,
        'updated_at' => $page->updated_at?->toIso8601String(),
      ])
      ->values()
      ->all();

    $recentlyPublishedPages = CmsPage::query()
      ->where('status', PageStatus::Published->value)
      ->whereNotNull('published_at')
      ->orderByDesc('published_at')
      ->limit(5)
      ->get(['uuid', 'title', 'slug', 'published_at'])
      ->map(fn (CmsPage $page): array => [
        'id' => $page->uuid,
        'title' => $page->title,
        'slug' => $page->slug,
        'published_at' => $page->published_at?->toIso8601String(),
      ])
      ->values()
      ->all();

    return [
      'pages' => [
        'total' => CmsPage::query()->count(),
        'draft' => (int) ($pageCounts[PageStatus::Draft->value] ?? 0),
        'review' => (int) ($pageCounts[PageStatus::Review->value] ?? 0),
        'published' => $publishedPages,
        'scheduled' => (int) ($pageCounts[PageStatus::Scheduled->value] ?? 0),
        'archived' => (int) ($pageCounts[PageStatus::Archived->value] ?? 0),
      ],
      'media' => [
        'total' => CmsMedia::query()->count(),
        'storage_bytes' => $storageBytes,
        'storage_mb' => round($storageBytes / 1024 / 1024, 2),
      ],
      'partners' => [
        'total' => CmsPartner::query()->count(),
        'active' => CmsPartner::query()->where('is_active', true)->count(),
      ],
      'testimonials' => [
        'total' => CmsTestimonial::query()->count(),
        'active' => CmsTestimonial::query()->where('is_active', true)->count(),
        'featured' => CmsTestimonial::query()->where('is_featured', true)->count(),
      ],
      'menus' => [
        'total' => CmsMenu::query()->count(),
        'active' => CmsMenu::query()->where('is_active', true)->count(),
      ],
      'seo' => [
        'total' => $seoTotal,
        'coverage_percent' => round(($seoTotal / max($publishedPages, 1)) * 100, 1),
      ],
      'form_submissions' => [
        'total' => CmsFormSubmission::query()->count(),
        'new_30_days' => CmsFormSubmission::query()->where('created_at', '>=', $thirtyDaysAgo)->count(),
      ],
      'recently_edited_pages' => $recentlyEditedPages,
      'recently_published_pages' => $recentlyPublishedPages,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function systemMetrics(): array
  {
    $administrators = User::query()
      ->where(function ($query): void {
        $query->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'admin.access'))
          ->orWhereHas('permissions', fn ($q) => $q->where('slug', 'admin.access'));
      })
      ->count();

    $editors = User::query()
      ->where(function ($query): void {
        $query->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', self::EDITOR_PERMISSION_SLUGS))
          ->orWhereHas('permissions', fn ($q) => $q->whereIn('slug', self::EDITOR_PERMISSION_SLUGS));
      })
      ->count();

    $recentLogins = User::query()
      ->whereNotNull('last_login_at')
      ->orderByDesc('last_login_at')
      ->limit(5)
      ->get(['uuid', 'name', 'display_name', 'email', 'last_login_at'])
      ->map(fn (User $user): array => [
        'id' => $user->uuid,
        'name' => $user->display_name ?? $user->name,
        'email' => $user->email,
        'last_login_at' => $user->last_login_at?->toIso8601String(),
      ])
      ->values()
      ->all();

    $roleDistribution = Role::query()
      ->withCount('users')
      ->orderBy('name')
      ->get()
      ->map(fn (Role $role): array => [
        'slug' => $role->slug,
        'name' => $role->name,
        'users_count' => $role->users_count,
      ])
      ->values()
      ->all();

    return [
      'total' => User::query()->count(),
      'administrators' => $administrators,
      'editors' => $editors,
      'recent_logins' => $recentLogins,
      'role_distribution' => $roleDistribution,
      'permission_count' => Permission::query()->count(),
      'failed_authorization_attempts' => null,
    ];
  }

  /**
   * @return array{items: list<array<string, mixed>>, meta: array{total_available: int, limit: int, offset: int}}
   */
  public function activityFeed(int $limit = 20, int $offset = 0): array
  {
    $fetchSize = max(20, $offset + $limit);

    $cmsEvents = CmsAuditLog::query()
      ->with('actor:id,display_name,email')
      ->orderByDesc('created_at')
      ->limit($fetchSize)
      ->get()
      ->map(fn (CmsAuditLog $log): array => [
        'id' => $log->uuid,
        'source' => 'cms',
        'event_type' => $log->event_type instanceof \BackedEnum ? $log->event_type->value : $log->event_type,
        'entity_type' => $log->entity_type,
        'entity_id' => $log->entity_id,
        'summary' => $this->summarize(
          $log->event_type instanceof \BackedEnum ? $log->event_type->value : (string) $log->event_type,
          (string) $log->entity_type,
          $log->entity_id,
        ),
        'actor' => $this->actorLabel($log->actor),
        'created_at' => $log->created_at?->toIso8601String(),
        'sort_at' => $log->created_at,
      ]);

    $iamEvents = IamAuditLog::query()
      ->with('actor:id,display_name,email')
      ->orderByDesc('created_at')
      ->limit($fetchSize)
      ->get()
      ->map(fn (IamAuditLog $log): array => [
        'id' => $log->uuid,
        'source' => 'iam',
        'event_type' => $log->event_type,
        'entity_type' => $log->subject_type,
        'entity_id' => $log->subject_id,
        'summary' => $this->summarize((string) $log->event_type, (string) $log->subject_type, $log->subject_id),
        'actor' => $this->actorLabel($log->actor),
        'created_at' => $log->created_at?->toIso8601String(),
        'sort_at' => $log->created_at,
      ]);

    $memberEvents = MemberAuditLog::query()
      ->with('actor:id,display_name,email')
      ->orderByDesc('created_at')
      ->limit($fetchSize)
      ->get()
      ->map(fn (MemberAuditLog $log): array => [
        'id' => 'member-'.$log->id,
        'source' => 'member',
        'event_type' => $log->event_type,
        'entity_type' => 'member',
        'entity_id' => $log->member_id,
        'summary' => $this->summarize((string) $log->event_type, 'member', $log->member_id),
        'actor' => $this->actorLabel($log->actor),
        'created_at' => $log->created_at?->toIso8601String(),
        'sort_at' => $log->created_at,
      ]);

    $merged = $cmsEvents
      ->concat($iamEvents)
      ->concat($memberEvents)
      ->sortByDesc(fn (array $item) => $item['sort_at']?->getTimestamp() ?? 0)
      ->values()
      ->map(fn (array $item): array => array_diff_key($item, ['sort_at' => null]));

    $items = $merged->slice($offset, $limit)->values()->all();

    $totalAvailable = Cache::remember('dashboard:activity_total:v1', 120, static function (): int {
      return CmsAuditLog::query()->count()
        + IamAuditLog::query()->count()
        + MemberAuditLog::query()->count();
    });

    return [
      'items' => $items,
      'meta' => [
        'total_available' => $totalAvailable,
        'limit' => $limit,
        'offset' => $offset,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function healthMetrics(): array
  {
    $mediaBytes = (int) CmsMedia::query()->sum('size');
    $diskFreeBytes = @disk_free_space(storage_path());
    $diskFreeBytes = is_float($diskFreeBytes) ? (int) $diskFreeBytes : 0;

    $databaseDriver = (string) config('database.default');
    $databaseStatus = $this->quickDatabaseCheck();
    $healthStatus = $this->healthCheckService->getStatus();

    return [
      'application' => (string) config('app.name'),
      'environment' => (string) config('app.env'),
      'version' => (string) config('app.version', '1.0.0'),
      'php_version' => PHP_VERSION,
      'laravel_version' => app()->version(),
      'database' => [
        'driver' => (string) config("database.connections.{$databaseDriver}.driver", $databaseDriver),
        'status' => $databaseStatus,
      ],
      'cache_driver' => (string) config('cache.default'),
      'queue_driver' => (string) config('queue.default'),
      'mail_driver' => (string) config('mail.default'),
      'storage' => [
        'media_bytes' => $mediaBytes,
        'media_mb' => round($mediaBytes / 1024 / 1024, 2),
        'disk_free_bytes' => $diskFreeBytes,
        'disk_free_mb' => round($diskFreeBytes / 1024 / 1024, 2),
      ],
      'last_deployment_at' => env('APP_DEPLOYED_AT') ?: null,
      'health_endpoint_status' => $healthStatus->status,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function eventMetrics(): array
  {
    $now = Carbon::now();

    $upcomingEvents = Event::query()
      ->published()
      ->where('starts_at', '>=', $now)
      ->with(['venue:id,name'])
      ->withCount('registrations')
      ->orderBy('starts_at')
      ->limit(5)
      ->get()
      ->map(fn (Event $event): array => [
        'id' => $event->uuid,
        'title' => $event->title,
        'starts_at' => $event->starts_at?->toIso8601String(),
        'venue' => $event->venue?->name,
        'registrations_count' => $event->registrations_count,
        'capacity' => $event->capacity,
      ])
      ->values()
      ->all();

    $registrationsByStatus = EventRegistration::query()
      ->select('status', DB::raw('count(*) as total'))
      ->groupBy('status')
      ->pluck('total', 'status');

    $totalRegistrations = EventRegistration::query()->count();
    $checkedInCount = (int) ($registrationsByStatus[RegistrationStatus::CheckedIn->value] ?? 0);
    $attendedCount = (int) ($registrationsByStatus[RegistrationStatus::Attended->value] ?? 0);
    $attendanceRate = $totalRegistrations > 0
      ? round((($checkedInCount + $attendedCount) / $totalRegistrations) * 100, 1)
      : 0.0;

    $certificatesIssued = EventCertificateIssuance::query()->count();
    $volunteerAssignments = EventVolunteerAssignment::query()->count();
    $revenueTotal = (float) EventRegistrationPayment::query()
      ->where('status', PaymentStatus::Paid->value)
      ->sum('amount');

    return [
      'module_installed' => true,
      'total_events' => Event::query()->count(),
      'published_events' => Event::query()->where('status', EventStatus::Published->value)->count(),
      'upcoming_events_count' => Event::query()->published()->where('starts_at', '>=', $now)->count(),
      'upcoming_events' => $upcomingEvents,
      'registration_count' => $totalRegistrations,
      'registrations_today' => EventRegistration::query()->whereDate('created_at', $now->toDateString())->count(),
      'registrations_by_status' => $registrationsByStatus,
      'checked_in_count' => $checkedInCount,
      'attended_count' => $attendedCount,
      'new_registrations_30_days' => EventRegistration::query()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
      'certificates_issued' => $certificatesIssued,
      'certificates_issued_today' => EventCertificateIssuance::query()->whereDate('created_at', $now->toDateString())->count(),
      'volunteer_assignments' => $volunteerAssignments,
      'attendance_rate' => $attendanceRate,
      'revenue' => $revenueTotal,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function learningMetrics(): array
  {
    $now = Carbon::now();

    try {
      $coursesTotal = \App\Modules\Lms\Models\Course::query()->count();
      $coursesPublished = \App\Modules\Lms\Models\Course::query()->where('status', 'published')->count();
      $coursesDraft = \App\Modules\Lms\Models\Course::query()->where('status', 'draft')->count();
      $enrollments = \App\Modules\Lms\Models\Enrollment::query()->count();
      $enrollmentsActive = \App\Modules\Lms\Models\Enrollment::query()->where('status', 'active')->count();
      $certificatesToday = \App\Modules\Lms\Models\CourseCertificate::query()
        ->whereDate('created_at', $now->toDateString())
        ->count();
      $assessmentAttempts = \App\Modules\Lms\Models\AssessmentAttempt::query()->count();
      $assessmentPassed = \App\Modules\Lms\Models\AssessmentAttempt::query()
        ->where('passed', true)
        ->count();
      $enrollmentsCompleted = \App\Modules\Lms\Models\Enrollment::query()
        ->where('status', 'completed')
        ->count();
      $commerceRevenue = (float) \App\Modules\Lms\Models\CourseOrder::query()
        ->where('status', 'paid')
        ->sum('amount');
      $popularCourses = \App\Modules\Lms\Models\Course::query()
        ->where(function ($q): void {
          $q->where('is_popular', true)->orWhere('is_featured', true);
        })
        ->orderByDesc('enrollment_count')
        ->limit(5)
        ->get(['uuid', 'title', 'slug', 'status', 'enrollment_count', 'is_featured', 'is_popular']);

      return [
        'module_installed' => true,
        'courses_total' => $coursesTotal,
        'courses_published' => $coursesPublished,
        'courses_draft' => $coursesDraft,
        'courses_pending_review' => $coursesDraft,
        'enrollments_total' => $enrollments,
        'enrollments_active' => $enrollmentsActive,
        'enrollments_completed' => $enrollmentsCompleted,
        'certificates_issued_today' => $certificatesToday,
        'assessment_attempts' => $assessmentAttempts,
        'assessment_passed' => $assessmentPassed,
        'assessment_completion_rate' => $assessmentAttempts > 0
          ? round(($assessmentPassed / $assessmentAttempts) * 100, 1)
          : 0.0,
        'commerce_revenue' => round($commerceRevenue, 2),
        'popular_courses' => $popularCourses->map(fn ($c) => [
          'id' => $c->uuid,
          'title' => $c->title,
          'slug' => $c->slug,
          'status' => $c->status instanceof \BackedEnum ? $c->status->value : $c->status,
          'enrollment_count' => (int) $c->enrollment_count,
          'is_featured' => (bool) $c->is_featured,
          'is_popular' => (bool) $c->is_popular,
        ])->values()->all(),
      ];
    } catch (\Throwable) {
      return ['module_installed' => false];
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function donationMetrics(): array
  {
    $now = Carbon::now();

    try {
      $donationClass = \App\Modules\Donations\Models\Donation::class;
      if (! class_exists($donationClass)) {
        return ['module_installed' => false];
      }

      $total = $donationClass::query()->count();
      $recent = $donationClass::query()
        ->orderByDesc('created_at')
        ->limit(5)
        ->get(['uuid', 'amount', 'currency', 'status', 'created_at', 'donor_name'])
        ->map(fn ($d): array => [
          'id' => $d->uuid,
          'amount' => (float) $d->amount,
          'currency' => $d->currency,
          'status' => $d->status instanceof \BackedEnum ? $d->status->value : (string) $d->status,
          'donor_name' => $d->donor_name ?? null,
          'created_at' => $d->created_at?->toIso8601String(),
        ])
        ->values()
        ->all();

      $todayCount = $donationClass::query()->whereDate('created_at', $now->toDateString())->count();
      $amountSum = (float) $donationClass::query()->where('status', 'completed')->sum('amount');

      return [
        'module_installed' => true,
        'total' => $total,
        'today' => $todayCount,
        'completed_amount' => $amountSum,
        'recent' => $recent,
      ];
    } catch (\Throwable) {
      return ['module_installed' => false];
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function charts(): array
  {
    return [
      'member_growth' => $this->monthlyCountSeries('members', 'created_at'),
      'cms_publishing' => $this->monthlyCountSeries('cms_pages', 'published_at', [
        ['status', '=', PageStatus::Published->value],
      ]),
      'media_uploads' => $this->monthlyCountSeries('cms_media', 'created_at'),
      'form_submissions' => $this->monthlyCountSeries('cms_form_submissions', 'created_at'),
      'audit_activity' => $this->dailyAuditSeries(30),
    ];
  }

  /**
   * @param  list<array{0: string, 1: string, 2: mixed}>  $wheres
   * @return list<array{period: string, count: int}>
   */
  private function monthlyCountSeries(string $table, string $column, array $wheres = []): array
  {
    $start = Carbon::now()->copy()->subMonths(11)->startOfMonth();
    $periodExpr = $this->sqlPeriodExpression($column, 'month');

    $query = DB::table($table)
      ->selectRaw("{$periodExpr} as period, COUNT(*) as aggregate")
      ->whereNotNull($column)
      ->where($column, '>=', $start);

    foreach ($wheres as [$field, $operator, $value]) {
      $query->where($field, $operator, $value);
    }

    $rows = $query
      ->groupBy('period')
      ->pluck('aggregate', 'period');

    $series = [];
    $now = Carbon::now();
    for ($i = 11; $i >= 0; $i--) {
      $period = $now->copy()->subMonths($i)->format('Y-m');
      $series[] = [
        'period' => $period,
        'count' => (int) ($rows[$period] ?? 0),
      ];
    }

    return $series;
  }

  /**
   * @return list<array{period: string, count: int}>
   */
  private function dailyAuditSeries(int $days): array
  {
    $start = Carbon::now()->copy()->subDays($days - 1)->startOfDay();
    $counts = [];

    foreach (['cms_audit_logs', 'iam_audit_logs', 'member_audit_logs'] as $table) {
      $periodExpr = $this->sqlPeriodExpression('created_at', 'day');
      $rows = DB::table($table)
        ->selectRaw("{$periodExpr} as period, COUNT(*) as aggregate")
        ->where('created_at', '>=', $start)
        ->groupBy('period')
        ->pluck('aggregate', 'period');

      foreach ($rows as $period => $aggregate) {
        $counts[(string) $period] = ($counts[(string) $period] ?? 0) + (int) $aggregate;
      }
    }

    $series = [];
    $now = Carbon::now();
    for ($i = $days - 1; $i >= 0; $i--) {
      $period = $now->copy()->subDays($i)->format('Y-m-d');
      $series[] = [
        'period' => $period,
        'count' => (int) ($counts[$period] ?? 0),
      ];
    }

    return $series;
  }

  private function sqlPeriodExpression(string $column, string $granularity): string
  {
    $driver = DB::connection()->getDriverName();

    if ($granularity === 'day') {
      return match ($driver) {
        'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
        'sqlite' => "strftime('%Y-%m-%d', {$column})",
        default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
      };
    }

    return match ($driver) {
      'pgsql' => "to_char({$column}, 'YYYY-MM')",
      'sqlite' => "strftime('%Y-%m', {$column})",
      default => "DATE_FORMAT({$column}, '%Y-%m')",
    };
  }

  /**
   * @return list<array{period: string, count: int}>
   */
  private function monthlySeries(Closure $countCallback): array
  {
    $series = [];
    $now = Carbon::now();

    for ($i = 11; $i >= 0; $i--) {
      $periodStart = $now->copy()->subMonths($i)->startOfMonth();
      $periodEnd = $periodStart->copy()->endOfMonth();

      $series[] = [
        'period' => $periodStart->format('Y-m'),
        'count' => $countCallback($periodStart, $periodEnd),
      ];
    }

    return $series;
  }

  /**
   * @return list<array{period: string, count: int}>
   */
  private function dailySeries(int $days, Closure $countCallback): array
  {
    $series = [];
    $now = Carbon::now();

    for ($i = $days - 1; $i >= 0; $i--) {
      $periodStart = $now->copy()->subDays($i)->startOfDay();
      $periodEnd = $periodStart->copy()->endOfDay();

      $series[] = [
        'period' => $periodStart->format('Y-m-d'),
        'count' => $countCallback($periodStart, $periodEnd),
      ];
    }

    return $series;
  }

  private function summarize(string $eventType, string $entityType, mixed $entityId): string
  {
    $label = str_replace('_', ' ', $eventType);
    $entity = str_replace('_', ' ', $entityType) ?: 'record';

    return sprintf('%s %s #%s', ucfirst($label), $entity, (string) ($entityId ?? '—'));
  }

  private function actorLabel(?User $actor): ?string
  {
    if ($actor === null) {
      return null;
    }

    return $actor->display_name ?? $actor->email;
  }

  private function quickDatabaseCheck(): string
  {
    try {
      DB::connection()->getPdo();

      return 'ok';
    } catch (\Throwable) {
      return 'error';
    }
  }
}
