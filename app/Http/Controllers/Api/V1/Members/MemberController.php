<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Enums\BulkMemberAction;
use App\Enums\MemberStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Members\ApproveMemberRequest;
use App\Http\Requests\Members\BulkMemberRequest;
use App\Http\Requests\Members\StoreMemberDocumentRequest;
use App\Http\Requests\Members\StoreMemberNoteRequest;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\TransitionMemberStatusRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Http\Resources\MemberDocumentResource;
use App\Http\Resources\MemberNoteResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\MemberTimelineResource;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\MemberNote;
use App\Services\Membership\MemberDocumentService;
use App\Services\Membership\MemberManagementService;
use App\Services\Membership\MemberNoteService;
use App\Services\Membership\MemberTimelineService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MemberController extends ApiController
{
  public function index(Request $request, MemberManagementService $service): JsonResponse
  {
    $this->authorize('viewAny', Member::class);

    $paginator = $service->paginate($request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, MemberResource::class),
      message: 'Members retrieved.',
    );
  }

  public function export(Request $request, MemberManagementService $service): StreamedResponse
  {
    $this->authorize('export', Member::class);

    $filters = $request->query();
    $count = $service->exportCount($filters);
    $service->recordExportAudit($filters, $request->user(), $count);

    $query = $service->buildFilteredQuery($filters)->orderBy('membership_number');

    return response()->streamDownload(function () use ($query): void {
      $out = fopen('php://output', 'w');
      fputcsv($out, [
        'membership_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'status',
        'approval_status',
        'occupation',
        'organization',
        'marketplace_sector',
        'country_id',
        'region_id',
        'ministry_id',
        'joined_at',
        'created_at',
      ]);

      foreach ($query->cursor() as $member) {
        fputcsv($out, [
          $member->membership_number,
          $member->first_name,
          $member->last_name,
          $member->email,
          $member->phone,
          $member->status instanceof MemberStatus ? $member->status->value : $member->status,
          $member->approval_status instanceof \BackedEnum ? $member->approval_status->value : $member->approval_status,
          $member->occupation,
          $member->organization,
          $member->marketplace_sector,
          $member->country_id,
          $member->region_id,
          $member->ministry_id,
          $member->joined_at?->toDateString(),
          $member->created_at?->toIso8601String(),
        ]);
      }

      fclose($out);
    }, 'members-export.csv', ['Content-Type' => 'text/csv']);
  }

  public function store(StoreMemberRequest $request, MemberManagementService $service): JsonResponse
  {
    $member = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member created.',
      status: 201,
    );
  }

  public function show(Member $member): JsonResponse
  {
    $this->authorize('view', $member);
    $member->load([
      'tags',
      'contacts',
      'addresses',
      'creator',
      'photoMedia',
      'ministry',
      'preferredMinistry',
      'country',
      'interviews.interviewer',
      'interviews.interviewers',
    ]);

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member retrieved.',
    );
  }

  public function update(UpdateMemberRequest $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $member = $service->update($member, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member updated.',
    );
  }

  public function destroy(Member $member, MemberManagementService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $member);
    $service->delete($member, $request->user());

    return $this->responder->success(message: 'Member deleted.');
  }

  public function restore(int $memberId, MemberManagementService $service, Request $request): JsonResponse
  {
    $trashed = Member::query()->onlyTrashed()->findOrFail($memberId);
    $this->authorize('restore', $trashed);
    $member = $service->restore($memberId, $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member restored.',
    );
  }

  public function bulk(BulkMemberRequest $request, MemberManagementService $service): JsonResponse
  {
    $validated = $request->validated();
    $count = $service->bulk(
      BulkMemberAction::from($validated['action']),
      $validated['member_ids'],
      $request->user(),
      $validated['reason'] ?? null,
    );

    return $this->responder->success(
      data: ['affected' => $count],
      message: 'Bulk action completed.',
    );
  }

  public function approve(ApproveMemberRequest $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $member = $service->approve($member, $request->user(), $request->validated('reason'));

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member approved.',
    );
  }

  public function reject(ApproveMemberRequest $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $member = $service->reject($member, $request->user(), $request->validated('reason'));

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member rejected.',
    );
  }

  public function transition(TransitionMemberStatusRequest $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $member = $service->transitionStatus(
      $member,
      MemberStatus::from($request->validated('status')),
      $request->user(),
      $request->validated('reason'),
    );

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member status updated.',
    );
  }

  public function timeline(Request $request, Member $member, MemberTimelineService $service): JsonResponse
  {
    $this->authorize('view', $member);
    $paginator = $service->paginate($member, $request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, MemberTimelineResource::class),
      message: 'Member timeline retrieved.',
    );
  }

  public function notes(Request $request, Member $member, MemberNoteService $service): JsonResponse
  {
    $this->authorize('view', $member);
    $paginator = $service->paginate($member, $request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, MemberNoteResource::class),
      message: 'Member notes retrieved.',
    );
  }

  public function storeNote(StoreMemberNoteRequest $request, Member $member, MemberNoteService $service): JsonResponse
  {
    $note = $service->create(
      $member,
      $request->validated('body'),
      $request->user(),
      $request->boolean('is_private', true),
    );

    return $this->responder->success(
      data: ['note' => new MemberNoteResource($note)],
      message: 'Note created.',
      status: 201,
    );
  }

  public function destroyNote(Member $member, MemberNote $note, MemberNoteService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $member);
    abort_unless($note->member_id === $member->id, 404);
    $service->delete($note, $request->user());

    return $this->responder->success(message: 'Note deleted.');
  }

  public function documents(Request $request, Member $member, MemberDocumentService $service): JsonResponse
  {
    $this->authorize('view', $member);
    $paginator = $service->paginate($member, $request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, MemberDocumentResource::class),
      message: 'Member documents retrieved.',
    );
  }

  public function storeDocument(StoreMemberDocumentRequest $request, Member $member, MemberDocumentService $service): JsonResponse
  {
    $document = $service->upload(
      $member,
      $request->file('file'),
      \App\Enums\MemberDocumentType::from($request->validated('document_type')),
      $request->validated('title'),
      $request->user(),
    );

    return $this->responder->success(
      data: ['document' => new MemberDocumentResource($document)],
      message: 'Document uploaded.',
      status: 201,
    );
  }

  public function destroyDocument(
    Member $member,
    MemberDocument $document,
    MemberDocumentService $service,
    Request $request,
  ): JsonResponse {
    $this->authorize('update', $member);
    abort_unless($document->member_id === $member->id, 404);
    $service->delete($document, $request->user());

    return $this->responder->success(message: 'Document deleted.');
  }
}
