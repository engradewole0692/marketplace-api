<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationFieldSetting;
use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationFieldSetting */
final class EventRegistrationFieldSettingResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $metadata = is_array($this->metadata) ? $this->metadata : [];

    $fieldType = $metadata['field_type'] ?? null;
    if (! is_string($fieldType) || $fieldType === '') {
      $fieldType = match ($this->field_key) {
        'email' => 'email',
        'phone', 'emergency_contact_phone' => 'phone',
        'arrival_date', 'departure_date', 'date_of_birth' => 'date',
        'accommodation_required', 'airport_pickup_required', 'volunteer_interest' => 'yes_no',
        'dietary_requirements', 'medical_notes', 'prayer_requests', 'additional_notes', 'address', 'special_requirements' => 'textarea',
        'gender', 'membership_status' => 'select',
        default => 'text',
      };
    }

    $showOnPublic = array_key_exists('show_on_public', $metadata)
      ? (bool) $metadata['show_on_public']
      : true;
    $showOnQuick = array_key_exists('show_on_quick', $metadata)
      ? (bool) $metadata['show_on_quick']
      : in_array($this->field_key, ['name', 'email', 'phone'], true);

    return [
      'id' => $this->uuid,
      'field_key' => $this->field_key,
      'label' => $this->label,
      'is_enabled' => $this->is_enabled,
      'is_required' => $this->is_required,
      'sort_order' => $this->sort_order,
      'field_type' => $fieldType,
      'help_text' => $metadata['help_text'] ?? null,
      'placeholder' => $metadata['placeholder'] ?? null,
      'options' => $metadata['options'] ?? null,
      'show_on_public' => $showOnPublic,
      'show_on_quick' => $showOnQuick,
      'metadata' => $metadata,
      'storage' => in_array($this->field_key, RegistrationFormConfigService::COLUMN_FIELDS, true)
        ? 'column'
        : (in_array($this->field_key, ['name', 'email', 'phone'], true) ? 'registrant' : 'profile'),
    ];
  }
}
