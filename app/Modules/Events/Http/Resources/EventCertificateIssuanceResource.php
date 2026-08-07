<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventCertificateIssuance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventCertificateIssuance */
final class EventCertificateIssuanceResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'registration_id' => $this->registration?->uuid,
      'member_id' => $this->member?->uuid,
      'certificate_number' => $this->certificate_number,
      'verification_code' => $this->verification_code,
      'download_count' => (int) $this->download_count,
      'template_id' => $this->template_id,
      'reissued_from_id' => $this->reissued_from_id,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'certificate_url' => $this->certificate_url,
      'issued_at' => $this->issued_at?->toIso8601String(),
      'revoked_at' => $this->revoked_at?->toIso8601String(),
    ];
  }
}
