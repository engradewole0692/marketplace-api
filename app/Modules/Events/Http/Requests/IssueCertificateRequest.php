<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCertificateTemplate;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class IssueCertificateRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'registration_id' => EventRegistration::class,
      'template_id' => EventCertificateTemplate::class,
      'event_id' => Event::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'registration_id' => ['nullable', 'integer', 'exists:event_registrations,id'],
      'template_id' => ['nullable', 'integer', 'exists:event_certificate_templates,id'],
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'only_attended' => ['boolean'],
    ];
  }
}
