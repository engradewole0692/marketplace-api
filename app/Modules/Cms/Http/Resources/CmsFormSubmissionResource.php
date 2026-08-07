<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsFormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsFormSubmission */
final class CmsFormSubmissionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'type' => $this->type->value,
      'status' => $this->status->value,
      'payload' => $this->payload,
      'submitter_name' => $this->submitter_name,
      'submitter_email' => $this->submitter_email,
      'source_ip' => $this->source_ip,
      'assigned_to' => $this->assigned_to,
      'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->display_name ?? $this->assignee?->name),
      'created_at' => $this->created_at?->toIso8601String(),
      'processed_at' => $this->processed_at?->toIso8601String(),
      'deleted_at' => $this->deleted_at?->toIso8601String(),
      'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
        'id' => $attachment->uuid,
        'filename' => $attachment->file_name,
        'url' => $attachment->url(),
        'mime_type' => $attachment->mime_type,
        'size' => $attachment->size,
      ])->values()->all()),
    ];
  }
}
