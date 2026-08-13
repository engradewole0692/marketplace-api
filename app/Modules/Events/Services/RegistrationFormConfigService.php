<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistrationFieldSetting;
use App\Modules\Events\Models\EventRegistrationQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class RegistrationFormConfigService implements ServiceContract
{
  /**
   * @return list<array{field_key: string, label: string, is_enabled: bool, is_required: bool, sort_order: int}>
   */
  public static function defaultFieldDefinitions(): array
  {
    return [
      ['field_key' => 'name', 'label' => 'Full name', 'is_enabled' => true, 'is_required' => true, 'sort_order' => 10],
      ['field_key' => 'email', 'label' => 'Email', 'is_enabled' => true, 'is_required' => true, 'sort_order' => 20],
      ['field_key' => 'phone', 'label' => 'Phone', 'is_enabled' => true, 'is_required' => false, 'sort_order' => 30],
      ['field_key' => 'emergency_contact_name', 'label' => 'Emergency contact name', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 40],
      ['field_key' => 'emergency_contact_relationship', 'label' => 'Emergency contact relationship', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 50],
      ['field_key' => 'emergency_contact_phone', 'label' => 'Emergency contact phone', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 60],
      ['field_key' => 'arrival_date', 'label' => 'Arrival date', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 70],
      ['field_key' => 'departure_date', 'label' => 'Departure date', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 80],
      ['field_key' => 'accommodation_required', 'label' => 'Accommodation required', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 90],
      ['field_key' => 'airport_pickup_required', 'label' => 'Airport pickup required', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 100],
      ['field_key' => 'dietary_requirements', 'label' => 'Dietary requirements', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 110],
      ['field_key' => 'medical_notes', 'label' => 'Medical notes', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 120],
      ['field_key' => 'volunteer_interest', 'label' => 'Volunteer interest', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 130],
      ['field_key' => 'prayer_requests', 'label' => 'Prayer requests', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 140],
      ['field_key' => 'additional_notes', 'label' => 'Additional notes', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 150],
      ['field_key' => 'seat_reservation', 'label' => 'Seat / table preference', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 160],
    ];
  }

  public function ensureDefaultFieldSettings(Event $event): void
  {
    if ($event->registrationFieldSettings()->exists()) {
      return;
    }

    foreach (self::defaultFieldDefinitions() as $definition) {
      $event->registrationFieldSettings()->create($definition);
    }
  }

  /**
   * @return Collection<int, EventRegistrationFieldSetting>
   */
  public function listFieldSettings(Event $event): Collection
  {
    $this->ensureDefaultFieldSettings($event);

    return $event->registrationFieldSettings()->orderBy('sort_order')->get();
  }

  /**
   * @param  list<array<string, mixed>>  $settings
   * @return Collection<int, EventRegistrationFieldSetting>
   */
  public function syncFieldSettings(Event $event, array $settings): Collection
  {
    $this->ensureDefaultFieldSettings($event);

    foreach ($settings as $index => $payload) {
      if (! is_array($payload) || empty($payload['field_key'])) {
        continue;
      }

      $fieldKey = (string) $payload['field_key'];
      $setting = $event->registrationFieldSettings()->where('field_key', $fieldKey)->first();

      if ($setting === null) {
        continue;
      }

      $setting->fill([
        'label' => $payload['label'] ?? $setting->label,
        'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : $setting->is_enabled,
        'is_required' => array_key_exists('is_required', $payload) ? (bool) $payload['is_required'] : $setting->is_required,
        'sort_order' => $payload['sort_order'] ?? ($index + 1) * 10,
        'metadata' => $payload['metadata'] ?? $setting->metadata,
      ]);
      $setting->save();
    }

    return $this->listFieldSettings($event);
  }

  /**
   * @return Collection<int, EventRegistrationQuestion>
   */
  public function listQuestions(Event $event): Collection
  {
    return $event->registrationQuestions()->orderBy('sort_order')->get();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createQuestion(Event $event, array $data): EventRegistrationQuestion
  {
    $sortOrder = (int) ($data['sort_order'] ?? (($event->registrationQuestions()->max('sort_order') ?? 0) + 10));

    return $event->registrationQuestions()->create([
      'field_key' => $data['field_key'] ?? Str::slug((string) ($data['question'] ?? 'question'), '_'),
      'question' => $data['question'],
      'help_text' => $data['help_text'] ?? null,
      'answer_type' => $data['answer_type'] ?? 'text',
      'options' => $data['options'] ?? null,
      'is_enabled' => $data['is_enabled'] ?? true,
      'is_required' => $data['is_required'] ?? false,
      'maps_to_member_field' => $data['maps_to_member_field'] ?? null,
      'sort_order' => $sortOrder,
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateQuestion(EventRegistrationQuestion $question, array $data): EventRegistrationQuestion
  {
    $question->fill([
      'field_key' => $data['field_key'] ?? $question->field_key,
      'question' => $data['question'] ?? $question->question,
      'help_text' => array_key_exists('help_text', $data) ? $data['help_text'] : $question->help_text,
      'answer_type' => $data['answer_type'] ?? $question->answer_type,
      'options' => array_key_exists('options', $data) ? $data['options'] : $question->options,
      'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : $question->is_enabled,
      'is_required' => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $question->is_required,
      'maps_to_member_field' => array_key_exists('maps_to_member_field', $data)
        ? $data['maps_to_member_field']
        : $question->maps_to_member_field,
      'sort_order' => $data['sort_order'] ?? $question->sort_order,
      'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : $question->metadata,
    ]);
    $question->save();

    return $question->fresh();
  }

  public function deleteQuestion(EventRegistrationQuestion $question): void
  {
    $question->delete();
  }

  /**
   * @param  list<string>  $orderedQuestionIds
   */
  public function reorderQuestions(Event $event, array $orderedQuestionIds): Collection
  {
    foreach ($orderedQuestionIds as $index => $questionId) {
      $event->registrationQuestions()->where('uuid', $questionId)->update([
        'sort_order' => ($index + 1) * 10,
      ]);
    }

    return $this->listQuestions($event);
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  public function validateSubmission(Event $event, array $payload, Validator $validator): void
  {
    $settings = $this->listFieldSettings($event);
    $registrant = is_array($payload['registrant'] ?? null) ? $payload['registrant'] : [];
    $hasMember = ! empty($payload['member_id']);

    foreach ($settings as $setting) {
      if (! $setting->is_enabled) {
        continue;
      }

      $key = $setting->field_key;
      $label = $setting->label ?: Str::headline($key);

      if (in_array($key, ['name', 'email', 'phone'], true)) {
        if ($setting->is_required && ! $hasMember) {
          $value = $registrant[$key] ?? null;
          if ($value === null || trim((string) $value) === '') {
            $validator->errors()->add("registrant.{$key}", "{$label} is required.");
          }
        }

        continue;
      }

      if ($key === 'consent_accepted') {
        if ($setting->is_required && empty($payload['consent_accepted'])) {
          $validator->errors()->add('consent_accepted', 'Consent is required.');
        }

        continue;
      }

      if ($setting->is_required) {
        $value = $payload[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
          $validator->errors()->add($key, "{$label} is required.");
        }
      }
    }

    $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
    $questions = $this->listQuestions($event)->where('is_enabled', true);

    foreach ($questions as $question) {
      if (! $question->is_required) {
        continue;
      }

      $answer = $answers[$question->uuid] ?? $answers[(string) $question->id] ?? null;
      if ($answer === null || (is_string($answer) && trim($answer) === '')) {
        $validator->errors()->add(
          "answers.{$question->uuid}",
          ($question->question ?: 'Question').' is required.',
        );
      }
    }
  }

  public function copyFormConfig(Event $source, Event $target): void
  {
    foreach ($source->registrationFieldSettings as $setting) {
      $target->registrationFieldSettings()->updateOrCreate(
        ['field_key' => $setting->field_key],
        [
          'label' => $setting->label,
          'is_enabled' => $setting->is_enabled,
          'is_required' => $setting->is_required,
          'sort_order' => $setting->sort_order,
          'metadata' => $setting->metadata,
        ],
      );
    }

    foreach ($source->registrationQuestions as $question) {
      $target->registrationQuestions()->create([
        'field_key' => $question->field_key,
        'question' => $question->question,
        'help_text' => $question->help_text,
        'answer_type' => $question->answer_type,
        'options' => $question->options,
        'is_enabled' => $question->is_enabled,
        'is_required' => $question->is_required,
        'maps_to_member_field' => $question->maps_to_member_field,
        'sort_order' => $question->sort_order,
        'metadata' => $question->metadata,
      ]);
    }
  }
}
