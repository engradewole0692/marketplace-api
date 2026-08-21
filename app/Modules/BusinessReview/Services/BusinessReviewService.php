<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Services;

use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\BusinessReview\Models\BusinessReviewNote;
use App\Modules\BusinessReview\Models\BusinessReviewStatusHistory;
use App\Modules\BusinessReview\Support\BusinessReviewConfig;
use App\Modules\Communications\Models\PlatformConversation;
use App\Modules\Communications\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BusinessReviewService
{
    public const STATUSES = BusinessReviewConfig::STATUSES;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, ?string $ip = null, ?User $user = null): BusinessReview
    {
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $fullName = trim($first.' '.$last);

        $review = BusinessReview::query()->create([
            'user_id' => $user?->id,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $fullName !== '' ? $fullName : ($user?->name ?? 'Applicant'),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? $user?->phone,
            'business_name' => $data['business_name'],
            'business_industry' => $data['business_industry'] ?? null,
            'business_description' => $data['business_description'] ?? null,
            'country' => $data['country'] ?? null,
            'state_province' => $data['state_province'] ?? null,
            'business_location' => trim(implode(', ', array_filter([
                $data['state_province'] ?? null,
                $data['country'] ?? null,
            ]))),
            'years_in_operation' => $data['years_in_operation'] ?? null,
            'employee_count' => $data['employee_count'] ?? null,
            'business_stage' => $data['business_stage'] ?? null,
            'advice_areas' => $data['advice_areas'] ?? null,
            'main_challenges' => $data['advice_areas'] ?? null,
            'business_goals' => $data['business_goals'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'social_links' => $data['social_links'] ?? null,
            'website_social' => trim(implode("\n", array_filter([
                $data['website_url'] ?? null,
                $data['social_links'] ?? null,
            ]))),
            'additional_info' => $data['additional_info'] ?? null,
            'referral_source' => $data['referral_source'] ?? null,
            'preferred_contact' => 'email',
            'status' => 'new',
            'ip_address' => $ip,
        ]);

        $this->recordStatus($review, null, 'new', $user, 'Submission received');
        $this->sendApplicantConfirmation($review);
        $this->notifyAdmins($review);

        return $review;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = BusinessReview::query()
            ->with(['assignedTo'])
            ->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['country'])) {
            $query->where('country', $filters['country']);
        }

        if (! empty($filters['state_province'])) {
            $query->where('state_province', $filters['state_province']);
        }

        if (! empty($filters['business_industry'])) {
            $query->where('business_industry', $filters['business_industry']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $query->paginate($perPage);
    }

    public function updateStatus(BusinessReview $review, string $status, User $actor, ?string $note = null): BusinessReview
    {
        $from = $review->status;
        $review->update(['status' => $status]);
        $this->recordStatus($review, $from, $status, $actor, $note);
        $this->notifyApplicantOfStatus($review, $status);

        return $review->fresh(['assignedTo', 'notes.author', 'conversation', 'statusHistories.actor']);
    }

    public function assign(BusinessReview $review, ?int $userId, User $actor): BusinessReview
    {
        $review->update(['assigned_to' => $userId]);
        $this->recordStatus($review, $review->status, $review->status, $actor, $userId
            ? 'Reviewer assigned'
            : 'Reviewer unassigned');

        return $review->fresh(['assignedTo', 'notes.author', 'conversation', 'statusHistories.actor']);
    }

    public function addNote(BusinessReview $review, string $content, User $actor): BusinessReviewNote
    {
        return BusinessReviewNote::query()->create([
            'business_review_id' => $review->id,
            'created_by' => $actor->id,
            'content' => $content,
        ]);
    }

    public function openConversation(BusinessReview $review, User $actor): PlatformConversation
    {
        if ($review->conversation_id) {
            return $review->conversation()->first();
        }

        $conversation = PlatformConversation::query()->create([
            'uuid' => Str::uuid()->toString(),
            'type' => 'group',
            'subject' => "Business Review: {$review->business_name}",
            'module' => 'business_review',
            'module_entity_type' => 'business_review',
            'module_entity_id' => (string) $review->id,
        ]);

        $conversation->participants()->attach($actor->id, ['role' => 'owner']);

        $review->update(['conversation_id' => $conversation->id]);

        return $conversation;
    }

    /**
     * @return Collection<int, User>
     */
    public function assignees(): Collection
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereHas('roles.permissions', function ($q): void {
                    $q->whereIn('permissions.slug', ['business-review.view', 'business-review.manage', 'settings.manage']);
                })->orWhereHas('roles', function ($q): void {
                    $q->whereIn('slug', ['super_administrator', 'administrator']);
                });
            })
            ->orderBy('first_name')
            ->limit(100)
            ->get(['id', 'uuid', 'first_name', 'last_name', 'email', 'name']);
    }

    public function export(array $filters = []): StreamedResponse
    {
        $query = BusinessReview::query()->with('assignedTo')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['country'])) {
            $query->where('country', $filters['country']);
        }
        if (! empty($filters['state_province'])) {
            $query->where('state_province', $filters['state_province']);
        }
        if (! empty($filters['business_industry'])) {
            $query->where('business_industry', $filters['business_industry']);
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $filename = 'business-reviews-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Owner name',
                'Email',
                'Phone',
                'Business name',
                'Business category',
                'Description',
                'Country',
                'State',
                'Business website',
                'Social links',
                'Business stage',
                'Years in operation',
                'Requested advice',
                'Primary goal',
                'Additional information',
                'Status',
                'Assigned reviewer',
                'Submission date',
            ]);

            $query->chunk(200, function ($rows) use ($handle): void {
                foreach ($rows as $review) {
                    fputcsv($handle, [
                        $review->full_name,
                        $review->email,
                        $review->phone,
                        $review->business_name,
                        $review->business_industry,
                        $review->business_description,
                        $review->country,
                        $review->state_province,
                        $review->website_url,
                        $review->social_links,
                        $review->business_stage,
                        $review->years_in_operation,
                        $review->advice_areas ?: $review->main_challenges,
                        $review->business_goals,
                        $review->additional_info,
                        $review->status,
                        $review->assignedTo?->name,
                        optional($review->created_at)?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function recordStatus(
        BusinessReview $review,
        ?string $from,
        string $to,
        ?User $actor,
        ?string $note = null,
    ): void {
        BusinessReviewStatusHistory::query()->create([
            'business_review_id' => $review->id,
            'changed_by' => $actor?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
        ]);
    }

    private function sendApplicantConfirmation(BusinessReview $review): void
    {
        try {
            $subject = 'Your Business Review Application — '.config('app.name');
            $body = view('emails.business-review.applicant-confirmation', compact('review'))->render();
            Mail::html($body, fn ($m) => $m->to($review->email, $review->full_name)->subject($subject));
        } catch (\Throwable $e) {
            Log::error('BusinessReview applicant confirmation email failed', [
                'review_uuid' => $review->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdmins(BusinessReview $review): void
    {
        try {
            $adminEmail = config('business_review.admin_email', config('mail.from.address'));
            if ($adminEmail) {
                $subject = 'New Business Review Application — '.$review->business_name;
                $body = view('emails.business-review.admin-notification', compact('review'))->render();
                Mail::html($body, fn ($m) => $m->to($adminEmail)->subject($subject));
            }

            foreach (['administrator', 'super_administrator'] as $roleSlug) {
                $this->notificationService->sendBulk(
                    title: 'New Business Review Application',
                    body: "{$review->full_name} submitted a business review for {$review->business_name}.",
                    targetType: 'role',
                    options: [
                        'role_slug' => $roleSlug,
                        'action_url' => '/admin/business-review',
                        'related_type' => 'business_review',
                        'related_id' => $review->uuid,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('BusinessReview admin notification failed', [
                'review_uuid' => $review->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyApplicantOfStatus(BusinessReview $review, string $status): void
    {
        $messages = [
            'information_requested' => 'The Faith & Works team has requested more information about your business review.',
            'review_completed' => 'Your Faith & Works Business Review has been completed.',
            'closed' => 'Your Faith & Works Business Review has been closed.',
            'under_review' => 'Your Faith & Works Business Review is now under review.',
        ];

        $body = $messages[$status] ?? null;
        if ($body === null) {
            return;
        }

        if ($review->user_id) {
            try {
                $user = $review->user()->first();
                if ($user) {
                    $this->notificationService->sendToUser(
                        $user,
                        'Business Review update',
                        $body,
                        'info',
                        null,
                        'business_review',
                        $review->uuid,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('BusinessReview applicant in-app notification failed', [
                    'review_uuid' => $review->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            Mail::raw($body, fn ($m) => $m->to($review->email, $review->full_name)->subject('Business Review update'));
        } catch (\Throwable $e) {
            Log::error('BusinessReview applicant status email failed', [
                'review_uuid' => $review->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
