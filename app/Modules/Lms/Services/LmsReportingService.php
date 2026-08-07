<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Instructor;
use App\Modules\Lms\Models\LessonProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class LmsReportingService implements ServiceContract
{
  public const TYPES = [
    'revenue',
    'students',
    'instructors',
    'completion',
    'assessments',
    'certificates',
    'enrollments',
  ];

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function dashboard(array $filters = []): array
  {
    [$from, $to] = $this->dateRange($filters);

    $paidOrders = CourseOrder::query()
      ->where('status', CourseOrderStatus::Paid->value)
      ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('paid_at', '<=', $to));

    $enrollments = Enrollment::query()
      ->when($from, fn ($q) => $q->where('enrolled_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('enrolled_at', '<=', $to));

    $attemptsQuery = AssessmentAttempt::query()
      ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

    $certificates = CourseCertificate::query()
      ->where('status', 'issued')
      ->when($from, fn ($q) => $q->where('issued_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('issued_at', '<=', $to));

    $totalEnrollments = (clone $enrollments)->count();
    $completed = (clone $enrollments)->where('status', 'completed')->count();
    $attemptCollection = (clone $attemptsQuery)->get(['id', 'passed', 'percentage']);

    return [
      'generated_at' => now()->toIso8601String(),
      'filters' => [
        'from' => $from?->toDateString(),
        'to' => $to?->toDateString(),
      ],
      // Legacy card keys (M6-A FE compatibility)
      'courses_total' => Course::query()->count(),
      'courses_published' => Course::query()->where('status', 'published')->count(),
      'enrollments_total' => $totalEnrollments,
      'enrollments_active' => (clone $enrollments)->where('status', 'active')->count(),
      'enrollments_completed' => $completed,
      'certificates_issued' => $certificates->count(),
      // Dashboard analytics
      'analytics' => [
        'revenue_total' => round((float) (clone $paidOrders)->sum('amount'), 2),
        'orders_paid' => (clone $paidOrders)->count(),
        'avg_order_value' => round((float) (clone $paidOrders)->avg('amount') ?: 0, 2),
        'completion_rate' => $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100, 2) : 0.0,
        'avg_progress' => round((float) (clone $enrollments)->avg('progress_percent') ?: 0, 2),
        'assessment_attempts' => $attemptCollection->count(),
        'assessment_pass_rate' => $this->passRate($attemptCollection),
        'instructors_active' => Instructor::query()->where('status', 'active')->count(),
        'lessons_completed' => LessonProgress::query()->where('status', 'completed')->count(),
      ],
      'report_types' => self::TYPES,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  public function report(string $type, array $filters = []): array
  {
    if (! in_array($type, self::TYPES, true)) {
      throw ValidationException::withMessages(['type' => ['Unknown report type.']]);
    }

    return match ($type) {
      'revenue' => $this->revenueReport($filters),
      'students' => $this->studentsReport($filters),
      'instructors' => $this->instructorsReport($filters),
      'completion' => $this->completionReport($filters),
      'assessments' => $this->assessmentsReport($filters),
      'certificates' => $this->certificatesReport($filters),
      'enrollments' => $this->enrollmentsReport($filters),
    };
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function revenueReport(array $filters): array
  {
    [$from, $to] = $this->dateRange($filters);

    $orders = CourseOrder::query()
      ->with(['course:id,uuid,title', 'user:id,name,email'])
      ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('paid_at', '<=', $to))
      ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
      ->latest('paid_at')
      ->limit(500)
      ->get();

    $paid = $orders->filter(fn (CourseOrder $o) => $o->status === CourseOrderStatus::Paid);

    $rows = $orders->map(fn (CourseOrder $o) => [
      'order_number' => $o->order_number,
      'course' => $o->course?->title,
      'learner' => $o->user?->name,
      'email' => $o->user?->email,
      'amount' => (float) $o->amount,
      'discount' => (float) $o->discount_amount,
      'currency' => $o->currency,
      'status' => $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status,
      'payment_method' => $o->payment_method,
      'coupon_code' => $o->coupon_code,
      'paid_at' => $o->paid_at?->toDateTimeString(),
    ])->values()->all();

    return [
      'type' => 'revenue',
      'summary' => [
        'orders' => $orders->count(),
        'paid_orders' => $paid->count(),
        'revenue_total' => round((float) $paid->sum('amount'), 2),
        'discounts_total' => round((float) $paid->sum('discount_amount'), 2),
      ],
      'columns' => ['order_number', 'course', 'learner', 'email', 'amount', 'discount', 'currency', 'status', 'payment_method', 'coupon_code', 'paid_at'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function studentsReport(array $filters): array
  {
    [$from, $to] = $this->dateRange($filters);

    $aggregated = Enrollment::query()
      ->selectRaw(
        'user_id, COUNT(*) as enrollments_count, AVG(progress_percent) as avg_progress, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_count, MAX(enrolled_at) as last_enrolled_at',
        ['completed'],
      )
      ->when($from, fn ($q) => $q->where('enrolled_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('enrolled_at', '<=', $to))
      ->groupBy('user_id')
      ->orderByDesc('enrollments_count')
      ->limit(500)
      ->get();

    $users = \App\Models\User::query()
      ->whereIn('id', $aggregated->pluck('user_id')->all())
      ->get(['id', 'uuid', 'name', 'email'])
      ->keyBy('id');

    $rows = $aggregated->map(function ($row) use ($users) {
      $user = $users->get($row->user_id);

      return [
        'student' => $user?->name,
        'email' => $user?->email,
        'enrollments' => (int) $row->enrollments_count,
        'avg_progress' => round((float) $row->avg_progress, 2),
        'completed' => (int) $row->completed_count,
        'last_enrolled_at' => $row->last_enrolled_at
          ? Carbon::parse($row->last_enrolled_at)->toDateTimeString()
          : null,
      ];
    })->values()->all();

    return [
      'type' => 'students',
      'summary' => [
        'students' => count($rows),
        'total_enrollments' => array_sum(array_column($rows, 'enrollments')),
      ],
      'columns' => ['student', 'email', 'enrollments', 'avg_progress', 'completed', 'last_enrolled_at'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function instructorsReport(array $filters): array
  {
    $instructors = Instructor::query()
      ->withCount('courses')
      ->with('courses:id,title')
      ->orderBy('name')
      ->limit(200)
      ->get();

    $rows = $instructors->map(function (Instructor $i) {
      return [
        'instructor' => $i->name,
        'title' => $i->title,
        'status' => $i->status instanceof \BackedEnum ? $i->status->value : (string) $i->status,
        'courses' => (int) $i->courses_count,
        'enrollments' => (int) Enrollment::query()->whereIn('course_id', $i->courses->pluck('id'))->count(),
        'course_titles' => $i->courses->pluck('title')->implode('; '),
      ];
    })->values()->all();

    return [
      'type' => 'instructors',
      'summary' => [
        'instructors' => count($rows),
        'courses_taught' => array_sum(array_column($rows, 'courses')),
      ],
      'columns' => ['instructor', 'title', 'status', 'courses', 'enrollments', 'course_titles'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function completionReport(array $filters): array
  {
    $courses = Course::query()
      ->withCount([
        'enrollments',
        'enrollments as completed_enrollments_count' => fn ($q) => $q->where('status', 'completed'),
      ])
      ->withAvg('enrollments as avg_progress', 'progress_percent')
      ->orderByDesc('enrollments_count')
      ->limit(200)
      ->get();

    $rows = $courses->map(fn (Course $c) => [
      'course' => $c->title,
      'status' => $c->status instanceof \BackedEnum ? $c->status->value : (string) $c->status,
      'enrollments' => (int) $c->enrollments_count,
      'completed' => (int) $c->completed_enrollments_count,
      'completion_rate' => $c->enrollments_count > 0
        ? round(($c->completed_enrollments_count / $c->enrollments_count) * 100, 2)
        : 0.0,
      'avg_progress' => round((float) ($c->avg_progress ?? 0), 2),
    ])->values()->all();

    $totalEnroll = array_sum(array_column($rows, 'enrollments'));
    $totalCompleted = array_sum(array_column($rows, 'completed'));

    return [
      'type' => 'completion',
      'summary' => [
        'courses' => count($rows),
        'enrollments' => $totalEnroll,
        'completed' => $totalCompleted,
        'completion_rate' => $totalEnroll > 0 ? round(($totalCompleted / $totalEnroll) * 100, 2) : 0.0,
      ],
      'columns' => ['course', 'status', 'enrollments', 'completed', 'completion_rate', 'avg_progress'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function assessmentsReport(array $filters): array
  {
    [$from, $to] = $this->dateRange($filters);

    $attempts = AssessmentAttempt::query()
      ->with([
        'assessment' => fn ($q) => $q->select('id', 'uuid', 'title', 'assessment_type', 'course_id')->with('course:id,title'),
        'user:id,name,email',
      ])
      ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
      ->latest()
      ->limit(500)
      ->get();

    $rows = $attempts->map(fn (AssessmentAttempt $a) => [
      'assessment' => $a->assessment?->title,
      'type' => $a->assessment?->assessment_type instanceof \BackedEnum
        ? $a->assessment->assessment_type->value
        : (string) ($a->assessment?->assessment_type ?? ''),
      'course' => $a->assessment?->course?->title,
      'learner' => $a->user?->name,
      'email' => $a->user?->email,
      'attempt' => $a->attempt_number,
      'status' => $a->status instanceof \BackedEnum ? $a->status->value : (string) $a->status,
      'score' => $a->score !== null ? (float) $a->score : null,
      'percentage' => $a->percentage !== null ? (float) $a->percentage : null,
      'passed' => $a->passed === null ? null : ($a->passed ? 'yes' : 'no'),
      'graded_at' => $a->graded_at?->toDateTimeString(),
    ])->values()->all();

    $graded = $attempts->filter(fn (AssessmentAttempt $a) => $a->passed !== null);

    return [
      'type' => 'assessments',
      'summary' => [
        'attempts' => $attempts->count(),
        'assessments' => Assessment::query()->count(),
        'pass_rate' => $this->passRate($attempts),
        'avg_percentage' => round((float) $graded->avg('percentage') ?: 0, 2),
      ],
      'columns' => ['assessment', 'type', 'course', 'learner', 'email', 'attempt', 'status', 'score', 'percentage', 'passed', 'graded_at'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function certificatesReport(array $filters): array
  {
    [$from, $to] = $this->dateRange($filters);

    $certs = CourseCertificate::query()
      ->with(['course:id,uuid,title', 'user:id,name,email'])
      ->when($from, fn ($q) => $q->where('issued_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('issued_at', '<=', $to))
      ->latest('issued_at')
      ->limit(500)
      ->get();

    $rows = $certs->map(fn (CourseCertificate $c) => [
      'certificate_number' => $c->certificate_number,
      'verification_code' => $c->verification_code,
      'course' => $c->course?->title,
      'learner' => $c->user?->name,
      'email' => $c->user?->email,
      'status' => $c->status instanceof \BackedEnum ? $c->status->value : (string) $c->status,
      'issued_at' => $c->issued_at?->toDateTimeString(),
    ])->values()->all();

    return [
      'type' => 'certificates',
      'summary' => [
        'total' => $certs->count(),
        'issued' => $certs->filter(fn ($c) => ($c->status instanceof \BackedEnum ? $c->status->value : $c->status) === 'issued')->count(),
      ],
      'columns' => ['certificate_number', 'verification_code', 'course', 'learner', 'email', 'status', 'issued_at'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}
   */
  private function enrollmentsReport(array $filters): array
  {
    [$from, $to] = $this->dateRange($filters);

    $enrollments = Enrollment::query()
      ->with(['course:id,uuid,title', 'user:id,name,email'])
      ->when($from, fn ($q) => $q->where('enrolled_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('enrolled_at', '<=', $to))
      ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
      ->latest('enrolled_at')
      ->limit(500)
      ->get();

    $rows = $enrollments->map(fn (Enrollment $e) => [
      'course' => $e->course?->title,
      'learner' => $e->user?->name,
      'email' => $e->user?->email,
      'learner_type' => $e->learner_type instanceof \BackedEnum ? $e->learner_type->value : (string) $e->learner_type,
      'status' => $e->status instanceof \BackedEnum ? $e->status->value : (string) $e->status,
      'progress_percent' => (float) $e->progress_percent,
      'price_paid' => $e->price_paid !== null ? (float) $e->price_paid : null,
      'currency' => $e->currency,
      'coupon_code' => $e->coupon_code,
      'enrolled_at' => $e->enrolled_at?->toDateTimeString(),
      'completed_at' => $e->completed_at?->toDateTimeString(),
    ])->values()->all();

    return [
      'type' => 'enrollments',
      'summary' => [
        'total' => $enrollments->count(),
        'active' => $enrollments->filter(fn ($e) => ($e->status instanceof \BackedEnum ? $e->status->value : $e->status) === 'active')->count(),
        'completed' => $enrollments->filter(fn ($e) => ($e->status instanceof \BackedEnum ? $e->status->value : $e->status) === 'completed')->count(),
        'pending_payment' => $enrollments->filter(fn ($e) => ($e->status instanceof \BackedEnum ? $e->status->value : $e->status) === 'pending_payment')->count(),
      ],
      'columns' => ['course', 'learner', 'email', 'learner_type', 'status', 'progress_percent', 'price_paid', 'currency', 'coupon_code', 'enrolled_at', 'completed_at'],
      'rows' => $rows,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{0: ?Carbon, 1: ?Carbon}
   */
  private function dateRange(array $filters): array
  {
    $from = ! empty($filters['from']) ? Carbon::parse((string) $filters['from'])->startOfDay() : null;
    $to = ! empty($filters['to']) ? Carbon::parse((string) $filters['to'])->endOfDay() : null;

    return [$from, $to];
  }

  private function passRate(Collection $attempts): float
  {
    $graded = $attempts->filter(fn ($a) => $a->passed !== null);
    if ($graded->isEmpty()) {
      return 0.0;
    }

    return round(($graded->filter(fn ($a) => (bool) $a->passed)->count() / $graded->count()) * 100, 2);
  }
}
