<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Services;

use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\BusinessReview\Models\BusinessReviewNote;
use App\Modules\Communications\Models\PlatformConversation;
use App\Modules\Communications\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class BusinessReviewService
{
    public const STATUSES = [
        'new', 'under_review', 'contacted', 'scheduled', 'in_progress', 'completed', 'declined',
    ];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function submit(array $data, ?string $ip = null): BusinessReview
    {
        $review = BusinessReview::query()->create([
            ...$data,
            'status' => 'new',
            'ip_address' => $ip,
        ]);

        $this->sendApplicantConfirmation($review);
        $this->notifyAdmins($review);

        return $review;
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = BusinessReview::query()
            ->with(['assignedTo'])
            ->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $query->paginate($perPage);
    }

    public function updateStatus(BusinessReview $review, string $status, User $actor): BusinessReview
    {
        $review->update(['status' => $status]);

        return $review->fresh(['assignedTo', 'notes.author', 'conversation']);
    }

    public function assign(BusinessReview $review, ?int $userId, User $actor): BusinessReview
    {
        $review->update(['assigned_to' => $userId]);

        return $review->fresh(['assignedTo', 'notes.author', 'conversation']);
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
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
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

    private function sendApplicantConfirmation(BusinessReview $review): void
    {
        try {
            $subject = 'Your Business Review Application — ' . config('app.name');
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
                $subject = 'New Business Review Application — ' . $review->business_name;
                $body = view('emails.business-review.admin-notification', compact('review'))->render();
                Mail::html($body, fn ($m) => $m->to($adminEmail)->subject($subject));
            }

            $this->notificationService->sendBulk(
                title: 'New Business Review Application',
                body: "{$review->full_name} submitted a business review for {$review->business_name}.",
                targetType: 'role',
                options: ['role_slug' => 'business-review-admin'],
            );
        } catch (\Throwable $e) {
            Log::error('BusinessReview admin notification failed', [
                'review_uuid' => $review->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
