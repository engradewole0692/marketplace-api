<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\LmsCourseImport */
final class LmsCourseImportResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'filename' => $this->filename,
      'status' => $this->status,
      'publish_after_import' => (bool) $this->publish_after_import,
      'create_missing_schools' => (bool) $this->create_missing_schools,
      'create_missing_categories' => (bool) $this->create_missing_categories,
      'create_missing_program_modules' => (bool) $this->create_missing_program_modules,
      'summary' => $this->summary ?? [],
      'report' => $this->report ?? [],
      'settings' => $this->settings ?? [],
      'administrator' => $this->whenLoaded('administrator', fn () => [
        'id' => $this->administrator?->uuid,
        'name' => $this->administrator?->name,
        'email' => $this->administrator?->email,
      ]),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
