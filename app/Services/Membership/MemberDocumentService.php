<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberDocumentType;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MemberDocumentService implements ServiceContract
{
  public function __construct(
    private readonly MemberAuditService $auditService,
    private readonly MemberTimelineService $timelineService,
  ) {}

  /**
   * @return LengthAwarePaginator<int, MemberDocument>
   */
  public function paginate(Member $member, array $filters = []): LengthAwarePaginator
  {
    $query = $member->documents()->with('uploader');

    if (! empty($filters['document_type'])) {
      $query->where('document_type', $filters['document_type']);
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->orderByDesc('created_at')->paginate($perPage);
  }

  public function upload(
    Member $member,
    UploadedFile $file,
    MemberDocumentType $documentType,
    string $title,
    User $actor,
    ?string $disk = null,
  ): MemberDocument {
    $disk ??= config('filesystems.default', 'local');
    $directory = 'members/'.$member->uuid.'/documents';
    $storedName = Str::uuid().'.'.$file->getClientOriginalExtension();
    $path = $file->storeAs($directory, $storedName, $disk);

    $document = $member->documents()->create([
      'uploaded_by' => $actor->id,
      'document_type' => $documentType,
      'title' => $title,
      'file_path' => $path,
      'file_name' => $file->getClientOriginalName(),
      'mime_type' => $file->getMimeType(),
      'file_size' => $file->getSize(),
      'disk' => $disk,
    ]);

    if ($documentType === MemberDocumentType::Photo) {
      $member->update(['photo_path' => $path]);
    }

    $this->auditService->record(
      MemberAuditEventType::DocumentUploaded,
      $member,
      $actor,
      metadata: ['document_id' => $document->id, 'type' => $documentType->value],
    );

    $this->timelineService->record(
      $member,
      MemberTimelineEventType::DocumentUploaded,
      "Document uploaded: {$title}.",
      $actor,
      ['document_id' => $document->id],
    );

    return $document->load('uploader');
  }

  public function delete(MemberDocument $document, User $actor): void
  {
    $member = $document->member;

    if ($document->disk && Storage::disk($document->disk)->exists($document->file_path)) {
      Storage::disk($document->disk)->delete($document->file_path);
    }

    $document->delete();

    $this->auditService->record(
      MemberAuditEventType::DocumentDeleted,
      $member,
      $actor,
      metadata: ['document_id' => $document->id],
    );
  }
}
