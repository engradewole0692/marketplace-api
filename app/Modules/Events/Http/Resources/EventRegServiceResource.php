<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Models\EventRegService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegService */
final class EventRegServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof EventRegServiceStatus
            ? $this->status
            : EventRegServiceStatus::tryFrom((string) $this->status);
        $type = $this->type instanceof \BackedEnum ? $this->type->value : (string) $this->type;
        $confirmed = $status?->isConfirmed() ?? false;

        return [
            'id' => $this->uuid,
            'type' => $type,
            'status' => $status?->value ?? $this->status,
            'status_label' => $confirmed ? ($status?->label() ?? 'Confirmed') : ($status?->label() ?? 'Pending Confirmation'),
            'confirmed' => $confirmed,
            'pending' => ! $confirmed,
            'details' => is_array($this->details) ? $this->details : (object) [],
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
    }
}
