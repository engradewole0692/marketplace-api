<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberDocument
 */
final class MemberDocumentResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'document_type' => $this->document_type instanceof \BackedEnum ? $this->document_type->value : $this->document_type,
      'title' => $this->title,
      'file_name' => $this->file_name,
      'file_url' => $this->fileUrl(),
      'mime_type' => $this->mime_type,
      'file_size' => $this->file_size,
      'disk' => $this->disk,
      'uploader' => new UserResource($this->whenLoaded('uploader')),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
