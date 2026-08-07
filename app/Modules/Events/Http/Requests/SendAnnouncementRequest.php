<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class SendAnnouncementRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'event_id' => Event::class,
      'ministry_id' => CmsMinistry::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'subject' => ['required', 'string', 'max:255'],
      'body' => ['required', 'string', 'max:10000'],
      'recipient_scope' => ['nullable', 'string', 'in:selected_event,selected_ministry,checked_in,checked_out,everyone'],
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
    ];
  }
}
