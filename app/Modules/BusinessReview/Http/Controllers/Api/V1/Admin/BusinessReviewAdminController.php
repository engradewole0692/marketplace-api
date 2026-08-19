<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\BusinessReview\Http\Resources\BusinessReviewResource;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\BusinessReview\Services\BusinessReviewService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(BusinessReview $businessReview): JsonResponse
    {
        $this->authorize('view', $businessReview);

        $businessReview->load(['assignedTo', 'notes.author', 'conversation']);

        return $this->responder->success(
            data: ['review' => new BusinessReviewResource($businessReview)],
            message: 'Business review retrieved.',
        );
    }

    public function updateStatus(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('update', $businessReview);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', BusinessReviewService::STATUSES)],
        ]);

        $updated = $service->updateStatus($businessReview, $validated['status'], $request->user());

        return $this->responder->success(
            data: ['review' => new BusinessReviewResource($updated)],
            message: 'Status updated.',
        );
    }

    public function assign(Request $request, BusinessReview $businessReview, BusinessReviewService $service): JsonResponse
    {
        $this->authorize('update', $businessReview);

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
