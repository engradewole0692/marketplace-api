<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\BusinessReview\Http\Resources\BusinessReviewResource;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\BusinessReview\Services\BusinessReviewService;
use App\Modules\BusinessReview\Support\BusinessReviewConfig;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BusinessReviewAdminController extends ApiController
{
    public function index(Request $request, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('viewAny', BusinessReview::class);

        $paginator = $service->paginate($request->query());

        return $this->responder->success(
            data: PaginatedResponseBuilder::fromPaginator($paginator, BusinessReviewResource::class),
            message: 'Business review applications retrieved.',
        );
    }

    public function export(Request $request, BusinessReviewService $service): StreamedResponse
    {
        $this->authorize('export', BusinessReview::class);

        return $service->export($request->query());
    }

    public function assignees(BusinessReviewService $service): JsonResponse
    {
        $this->authorize('viewAny', BusinessReview::class);

        $users = $service->assignees()->map(fn ($user) => [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return $this->responder->success(
            data: ['assignees' => $users],
            message: 'Reviewers retrieved.',
        );
    }

    public function show(BusinessReview $businessReview): JsonResponse
    {
        $this->authorize('view', $businessReview);

        $businessReview->load(['assignedTo', 'notes.author', 'conversation', 'statusHistories.actor', 'user']);

        return $this->responder->success(
            data: ['review' => new BusinessReviewResource($businessReview)],
            message: 'Business review retrieved.',
        );
    }

    public function updateStatus(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('update', $businessReview);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', BusinessReviewConfig::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $service->updateStatus(
            $businessReview,
            $validated['status'],
            $request->user(),
            $validated['note'] ?? null,
        );

        return $this->responder->success(
            data: ['review' => new BusinessReviewResource($updated)],
            message: 'Status updated.',
        );
    }

    public function assign(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('assign', $businessReview);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $updated = $service->assign($businessReview, $validated['user_id'] ?? null, $request->user());

        return $this->responder->success(
            data: ['review' => new BusinessReviewResource($updated)],
            message: 'Assignment updated.',
        );
    }

    public function addNote(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('update', $businessReview);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $note = $service->addNote($businessReview, $validated['content'], $request->user());

        return $this->responder->success(
            data: ['note' => ['id' => $note->uuid, 'content' => $note->content, 'created_at' => $note->created_at->toISOString()]],
            message: 'Note added.',
            status: 201,
        );
    }

    public function openConversation(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('update', $businessReview);

        $conversation = $service->openConversation($businessReview, $request->user());

        return $this->responder->success(
            data: ['conversation_id' => $conversation->uuid],
            message: 'Conversation ready.',
        );
    }
}
