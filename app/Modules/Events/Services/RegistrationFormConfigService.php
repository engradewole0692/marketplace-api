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
  public const CONTEXT_PUBLIC = 'public';

  public const CONTEXT_QUICK = 'quick';

  /**
   * Registration columns that map 1:1 to form field keys.
   *
   * @var list<string>
   */
  public const COLUMN_FIELDS = [
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
    'arrival_date',
    'departure_date',
    'accommodation_required',
    'airport_pickup_required',
    'dietary_requirements',
    'medical_notes',
    'volunteer_interest',
    'prayer_requests',
    'additional_notes',
    'seat_reservation',
  ];

  /**
   * Profile-style fields stored on registration.metadata.profile (no dedicated columns).
   *
   * @var list<string>
   */
  public const METADATA_PROFILE_FIELDS = [
    'first_name',
    'last_name',
    'gender',
    'date_of_birth',
    'country',
    'state_region',
    'city',
    'address',
    'occupation',
    'organization',
    'ministry',
    'membership_status',
    'accommodation_type',
    'special_requirements',
  ];

  /**
   * @return list<array{
   *   field_key: string,
   *   label: string,
   *   is_enabled: bool,
   *   is_required: bool,
   *   sort_order: int,
   *   metadata: array<string, mixed>
   * }>
   */
  public static function defaultFieldDefinitions(): array
  {
    return [
      self::definition('name', 'Full name', true, true, 10, 'text', true, true),
      self::definition('first_name', 'First name', false, false, 12, 'text', false, false),
      self::definition('last_name', 'Last name', false, false, 14, 'text', false, false),
      self::definition('email', 'Email', true, true, 20, 'email', true, true),
      self::definition('phone', 'Phone', true, false, 30, 'phone', true, true),
      self::definition('gender', 'Gender', false, false, 35, 'select', false, false, null, null, ['Male', 'Female', 'Prefer not to say', 'Other']),
      self::definition('date_of_birth', 'Date of birth', false, false, 36, 'date', false, false),
      self::definition('country', 'Country', true, false, 37, 'text', false, true),
      self::definition('state_region', 'State / region', true, false, 38, 'text', false, true),
      self::definition('city', 'City / location', false, false, 39, 'text', false, true),
      self::definition('address', 'Address', false, false, 40, 'textarea', false, false),
      self::definition('occupation', 'Occupation', true, false, 41, 'text', false, true),
      self::definition('organization', 'Organization / company', false, false, 42, 'text', false, true),
      self::definition('ministry', 'Ministry', false, false, 43, 'text', false, true),
      self::definition('membership_status', 'Membership status', false, false, 44, 'select', false, false, null, null, ['Member', 'Visitor', 'Guest', 'Staff', 'Other']),
      self::definition('emergency_contact_name', 'Emergency contact name', false, false, 50, 'text', false, false),
      self::definition('emergency_contact_relationship', 'Emergency contact relationship', false, false, 55, 'text', false, false),
      self::definition('emergency_contact_phone', 'Emergency contact phone', false, false, 60, 'phone', false, false),
      self::definition('arrival_date', 'Arrival date', false, false, 70, 'date', false, false),
      self::definition('departure_date', 'Departure date', false, false, 80, 'date', false, false),
      self::definition('accommodation_required', 'Accommodation required', false, false, 90, 'yes_no', false, false),
      self::definition('accommodation_type', 'Accommodation type', false, false, 95, 'text', false, false),
      self::definition('airport_pickup_required', 'Airport pickup required', false, false, 100, 'yes_no', false, false),
      self::definition('dietary_requirements', 'Dietary requirements', false, false, 110, 'textarea', false, false),
      self::definition('special_requirements', 'Special requirements', false, false, 115, 'textarea', false, false),
      self::definition('medical_notes', 'Medical notes', false, false, 120, 'textarea', false, false),
      self::definition('volunteer_interest', 'Volunteer interest', false, false, 130, 'yes_no', false, false),
      self::definition('prayer_requests', 'Prayer requests', false, false, 140, 'textarea', false, false),
      self::definition('additional_notes', 'Additional notes', false, false, 150, 'textarea', false, false),
      self::definition('seat_reservation', 'Seat / table preference', false, false, 160, 'text', false, false),
    ];
  }

  /**
   * @param  list<string>|null  $options
   * @return array{
   *   field_key: string,
   *   label: string,
   *   is_enabled: bool,
   *   is_required: bool,
   *   sort_order: int,
   *   metadata: array<string, mixed>
   * }
   */
  private static function definition(
    string $key,
    string $label,
    bool $enabled,
    bool $required,
    int $sort,
    string $type,
    bool $showPublic,
    bool $showQuick,
    ?string $help = null,
    ?string $placeholder = null,
    ?array $options = null,
  ): array {
    $metadata = [
      'field_type' => $type,
      'show_on_public' => $showPublic,
      'show_on_quick' => $showQuick,
    ];

    if ($help !== null) {
      $metadata['help_text'] = $help;
    }
    if ($placeholder !== null) {
      $metadata['placeholder'] = $placeholder;
    }
    if ($options !== null) {
      $metadata['options'] = $options;
    }

    return [
      'field_key' => $key,
      'label' => $label,
      'is_enabled' => $enabled,
      'is_required' => $required,
      'sort_order' => $sort,
      'metadata' => $metadata,
    ];
  }

  public function ensureDefaultFieldSettings(Event $event): void
  {
    $existingKeys = $event->registrationFieldSettings()->pluck('field_key')->all();

    if ($existingKeys === []) {
      foreach (self::defaultFieldDefinitions() as $definition) {
        $event->registrationFieldSettings()->create($definition);
      }

      return;
    }

    // Additive: seed newly introduced catalog fields without changing existing config.
    foreach (self::defaultFieldDefinitions() as $definition) {
      if (in_array($definition['field_key'], $existingKeys, true)) {
        continue;
      }

      $event->registrationFieldSettings()->create([
        ...$definition,
        'is_enabled' => false,
        'is_required' => false,
      ]);
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

      $fieldKey = $this->sanitizeFieldKey((string) $payload['field_key']);
      if ($fieldKey === '') {
        continue;
      }

      $setting = $event->registrationFieldSettings()->where('field_key', $fieldKey)->first();

      if ($setting === null) {
        continue;
      }

      $metadata = is_array($setting->metadata) ? $setting->metadata : [];
      if (isset($payload['metadata']) && is_array($payload['metadata'])) {
        $metadata = $this->sanitizeFieldMetadata(array_merge($metadata, $payload['metadata']));
      }

      foreach (['show_on_public', 'show_on_quick', 'help_text', 'placeholder', 'field_type', 'options'] as $metaKey) {
        if (array_key_exists($metaKey, $payload)) {
          $metadata[$metaKey] = $payload[$metaKey];
        }
      }
      $metadata = $this->sanitizeFieldMetadata($metadata);

      $setting->fill([
        'label' => $payload['label'] ?? $setting->label,
        'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : $setting->is_enabled,
        'is_required' => array_key_exists('is_required', $payload) ? (bool) $payload['is_required'] : $setting->is_required,
        'sort_order' => $payload['sort_order'] ?? ($index + 1) * 10,
        'metadata' => $metadata,
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
    $fieldKey = $this->sanitizeFieldKey((string) ($data['field_key'] ?? Str::slug((string) ($data['question'] ?? 'question'), '_')));
    $metadata = $this->sanitizeQuestionMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : []);

    foreach (['show_on_public', 'show_on_quick'] as $metaKey) {
      if (array_key_exists($metaKey, $data)) {
        $metadata[$metaKey] = (bool) $data[$metaKey];
      }
    }

    if (! array_key_exists('show_on_public', $metadata)) {
      $metadata['show_on_public'] = true;
    }
    if (! array_key_exists('show_on_quick', $metadata)) {
      $metadata['show_on_quick'] = false;
    }

    return $event->registrationQuestions()->create([
      'field_key' => $fieldKey !== '' ? $fieldKey : 'question_'.Str::random(6),
      'question' => strip_tags((string) $data['question']),
      'help_text' => isset($data['help_text']) ? strip_tags((string) $data['help_text']) : null,
      'answer_type' => $this->normalizeAnswerType($data['answer_type'] ?? 'text'),
      'options' => $this->sanitizeOptions($data['options'] ?? null),
      'is_enabled' => $data['is_enabled'] ?? true,
      'is_required' => $data['is_required'] ?? false,
      'maps_to_member_field' => $data['maps_to_member_field'] ?? null,
      'sort_order' => $sortOrder,
      'metadata' => $metadata,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateQuestion(EventRegistrationQuestion $question, array $data): EventRegistrationQuestion
  {
    $metadata = is_array($question->metadata) ? $question->metadata : [];
    if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
      $metadata = array_merge($metadata, $data['metadata']);
    }
    foreach (['show_on_public', 'show_on_quick'] as $metaKey) {
      if (array_key_exists($metaKey, $data)) {
        $metadata[$metaKey] = (bool) $data[$metaKey];
      }
    }
    $metadata = $this->sanitizeQuestionMetadata($metadata);

    $question->fill([
      'field_key' => array_key_exists('field_key', $data)
        ? ($this->sanitizeFieldKey((string) $data['field_key']) ?: $question->field_key)
        : $question->field_key,
      'question' => array_key_exists('question', $data) ? strip_tags((string) $data['question']) : $question->question,
      'help_text' => array_key_exists('help_text', $data)
        ? ($data['help_text'] !== null ? strip_tags((string) $data['help_text']) : null)
        : $question->help_text,
      'answer_type' => array_key_exists('answer_type', $data)
        ? $this->normalizeAnswerType($data['answer_type'])
        : $question->answer_type,
      'options' => array_key_exists('options', $data) ? $this->sanitizeOptions($data['options']) : $question->options,
      'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : $question->is_enabled,
      'is_required' => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $question->is_required,
      'maps_to_member_field' => array_key_exists('maps_to_member_field', $data)
        ? $data['maps_to_member_field']
        : $question->maps_to_member_field,
      'sort_order' => $data['sort_order'] ?? $question->sort_order,
      'metadata' => $metadata,
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
   * Authoritative form schema for a given surface (public or quick/on-site).
   *
   * @return array{context: string, fields: list<array<string, mixed>>}
   */
  public function buildFormSchema(Event $event, string $context = self::CONTEXT_PUBLIC): array
  {
    $context = $context === self::CONTEXT_QUICK ? self::CONTEXT_QUICK : self::CONTEXT_PUBLIC;
    $fields = [];

    foreach ($this->listFieldSettings($event) as $setting) {
      if (! $setting->is_enabled || ! $this->isVisibleOnContext($setting->metadata, $context, defaultPublic: true, defaultQuick: in_array($setting->field_key, ['name', 'email', 'phone'], true))) {
        continue;
      }

      $meta = is_array($setting->metadata) ? $setting->metadata : [];
      $fields[] = [
        'key' => $setting->field_key,
        'source' => 'standard',
        'id' => $setting->uuid,
        'label' => $setting->label ?: Str::headline($setting->field_key),
        'type' => $this->normalizeAnswerType($meta['field_type'] ?? $this->inferType($setting->field_key)),
        'required' => (bool) $setting->is_required,
        'help_text' => isset($meta['help_text']) ? (string) $meta['help_text'] : null,
        'placeholder' => isset($meta['placeholder']) ? (string) $meta['placeholder'] : null,
        'options' => $this->sanitizeOptions($meta['options'] ?? null),
        'sort_order' => (int) $setting->sort_order,
        'storage' => $this->storageForField($setting->field_key),
      ];
    }

    foreach ($this->listQuestions($event)->where('is_enabled', true) as $question) {
      if (! $this->isVisibleOnContext($question->metadata, $context, defaultPublic: true, defaultQuick: false)) {
        continue;
      }

      $fields[] = [
        'key' => 'question_'.$question->uuid,
        'source' => 'question',
        'id' => $question->uuid,
        'label' => $question->question,
        'type' => $this->normalizeAnswerType($question->answer_type ?? 'text'),
        'required' => (bool) $question->is_required,
        'help_text' => $question->help_text,
        'placeholder' => null,
        'options' => $this->sanitizeOptions($question->options),
        'sort_order' => (int) $question->sort_order,
        'storage' => 'answer',
      ];
    }

    usort($fields, static fn (array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']));

    return [
      'context' => $context,
      'fields' => array_values($fields),
    ];
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  public function validateSubmission(
    Event $event,
    array $payload,
    Validator $validator,
    string $context = self::CONTEXT_PUBLIC,
  ): void {
    $context = $context === self::CONTEXT_QUICK ? self::CONTEXT_QUICK : self::CONTEXT_PUBLIC;
    $settings = $this->listFieldSettings($event);
    $registrant = is_array($payload['registrant'] ?? null) ? $payload['registrant'] : [];
    $hasMember = ! empty($payload['member_id']);
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];

    // Normalize first/last into name for validation convenience.
    if (empty($registrant['name'])) {
      $first = trim((string) ($registrant['first_name'] ?? $profile['first_name'] ?? $payload['first_name'] ?? ''));
      $last = trim((string) ($registrant['last_name'] ?? $profile['last_name'] ?? $payload['last_name'] ?? ''));
      $combined = trim($first.' '.$last);
      if ($combined !== '') {
        $registrant['name'] = $combined;
      }
    }

    foreach ($settings as $setting) {
      if (! $setting->is_enabled) {
        continue;
      }

      if (! $this->isVisibleOnContext(
        $setting->metadata,
        $context,
        defaultPublic: true,
        defaultQuick: in_array($setting->field_key, ['name', 'email', 'phone'], true),
      )) {
        continue;
      }

      $key = $setting->field_key;
      $label = $setting->label ?: Str::headline($key);

      if (in_array($key, ['name', 'email', 'phone', 'first_name', 'last_name'], true)) {
        if ($setting->is_required && ! $hasMember) {
          if ($key === 'name') {
            $value = $registrant['name'] ?? null;
          } elseif ($key === 'first_name' || $key === 'last_name') {
            $value = $registrant[$key] ?? $profile[$key] ?? $payload[$key] ?? null;
            // If full name is present, first/last are satisfied.
            if (($value === null || trim((string) $value) === '') && ! empty($registrant['name'])) {
              continue;
            }
          } else {
            $value = $registrant[$key] ?? null;
          }

          if ($value === null || trim((string) $value) === '') {
            $path = in_array($key, ['name', 'email', 'phone'], true) ? "registrant.{$key}" : $key;
            $validator->errors()->add($path, "{$label} is required.");
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

      if (! $setting->is_required) {
        continue;
      }

      $value = $payload[$key] ?? $profile[$key] ?? null;
      if ($value === null || (is_string($value) && trim($value) === '')) {
        $validator->errors()->add($key, "{$label} is required.");
      }
    }

    $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
    $questions = $this->listQuestions($event)->where('is_enabled', true);

    foreach ($questions as $question) {
      if (! $this->isVisibleOnContext($question->metadata, $context, defaultPublic: true, defaultQuick: false)) {
        continue;
      }

      if (! $question->is_required) {
        continue;
      }

      $answer = $answers[$question->uuid]
        ?? $answers[(string) $question->id]
        ?? $answers['question_'.$question->uuid]
        ?? null;

      if ($answer === null || (is_string($answer) && trim($answer) === '')) {
        $validator->errors()->add(
          "answers.{$question->uuid}",
          ($question->question ?: 'Question').' is required.',
        );
      }
    }
  }

  /**
   * Extract column attributes + metadata profile from a submission payload.
   *
   * @param  array<string, mixed>  $payload
   * @return array{attributes: array<string, mixed>, profile: array<string, mixed>}
   */
  public function extractPersistableFields(Event $event, array $payload): array
  {
    $settings = $this->listFieldSettings($event)->keyBy('field_key');
    $profileInput = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $attributes = [];
    $profile = [];

    foreach (self::COLUMN_FIELDS as $key) {
      $setting = $settings->get($key);
      if ($setting === null || ! $setting->is_enabled) {
        continue;
      }
      if (array_key_exists($key, $payload)) {
        $attributes[$key] = $payload[$key];
      }
    }

    foreach (self::METADATA_PROFILE_FIELDS as $key) {
      $setting = $settings->get($key);
      if ($setting === null || ! $setting->is_enabled) {
        continue;
      }

      if (array_key_exists($key, $payload)) {
        $profile[$key] = $payload[$key];
      } elseif (array_key_exists($key, $profileInput)) {
        $profile[$key] = $profileInput[$key];
      }
    }

    $registrant = is_array($payload['registrant'] ?? null) ? $payload['registrant'] : [];
    foreach (['first_name', 'last_name', 'occupation', 'organization', 'country', 'state_region', 'city'] as $key) {
      if (! array_key_exists($key, $profile) && array_key_exists($key, $registrant)) {
        $setting = $settings->get($key);
        if ($setting !== null && $setting->is_enabled) {
          $profile[$key] = $registrant[$key];
        }
      }
    }

    return [
      'attributes' => $attributes,
      'profile' => $profile,
    ];
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

  /**
   * @param  array<string, mixed>|null  $metadata
   */
  public function isVisibleOnContext(?array $metadata, string $context, bool $defaultPublic = true, bool $defaultQuick = false): bool
  {
    $meta = is_array($metadata) ? $metadata : [];

    if ($context === self::CONTEXT_QUICK) {
      return array_key_exists('show_on_quick', $meta) ? (bool) $meta['show_on_quick'] : $defaultQuick;
    }

    return array_key_exists('show_on_public', $meta) ? (bool) $meta['show_on_public'] : $defaultPublic;
  }

  public function sanitizeFieldKey(string $key): string
  {
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?? '';
    $key = trim($key, '_');

    return substr($key, 0, 80);
  }

  /**
   * @param  array<string, mixed>  $metadata
   * @return array<string, mixed>
   */
  private function sanitizeFieldMetadata(array $metadata): array
  {
    $clean = [];

    if (array_key_exists('show_on_public', $metadata)) {
      $clean['show_on_public'] = (bool) $metadata['show_on_public'];
    }
    if (array_key_exists('show_on_quick', $metadata)) {
      $clean['show_on_quick'] = (bool) $metadata['show_on_quick'];
    }
    if (array_key_exists('field_type', $metadata)) {
      $clean['field_type'] = $this->normalizeAnswerType($metadata['field_type']);
    }
    if (array_key_exists('help_text', $metadata) && $metadata['help_text'] !== null) {
      $clean['help_text'] = strip_tags((string) $metadata['help_text']);
    }
    if (array_key_exists('placeholder', $metadata) && $metadata['placeholder'] !== null) {
      $clean['placeholder'] = strip_tags((string) $metadata['placeholder']);
    }
    if (array_key_exists('options', $metadata)) {
      $clean['options'] = $this->sanitizeOptions($metadata['options']);
    }

    return $clean;
  }

  /**
   * @param  array<string, mixed>  $metadata
   * @return array<string, mixed>
   */
  private function sanitizeQuestionMetadata(array $metadata): array
  {
    $clean = [];
    if (array_key_exists('show_on_public', $metadata)) {
      $clean['show_on_public'] = (bool) $metadata['show_on_public'];
    }
    if (array_key_exists('show_on_quick', $metadata)) {
      $clean['show_on_quick'] = (bool) $metadata['show_on_quick'];
    }

    return $clean;
  }

  private function normalizeAnswerType(mixed $type): string
  {
    $value = strtolower(trim((string) ($type ?? 'text')));
    $allowed = ['text', 'textarea', 'number', 'email', 'phone', 'date', 'select', 'radio', 'checkbox', 'yes_no'];

    if ($value === 'yes/no' || $value === 'boolean' || $value === 'bool') {
      return 'yes_no';
    }

    return in_array($value, $allowed, true) ? $value : 'text';
  }

  /**
   * @return list<string>|null
   */
  private function sanitizeOptions(mixed $options): ?array
  {
    if (! is_array($options)) {
      return null;
    }

    $clean = [];
    foreach ($options as $option) {
      if (is_string($option) || is_numeric($option)) {
        $label = strip_tags(trim((string) $option));
        if ($label !== '') {
          $clean[] = $label;
        }
        continue;
      }

      if (is_array($option)) {
        $label = strip_tags(trim((string) ($option['label'] ?? $option['value'] ?? '')));
        if ($label !== '') {
          $clean[] = $label;
        }
      }
    }

    return $clean === [] ? null : array_values(array_unique($clean));
  }

  private function inferType(string $fieldKey): string
  {
    return match ($fieldKey) {
      'email' => 'email',
      'phone', 'emergency_contact_phone' => 'phone',
      'arrival_date', 'departure_date', 'date_of_birth' => 'date',
      'accommodation_required', 'airport_pickup_required', 'volunteer_interest' => 'yes_no',
      'dietary_requirements', 'medical_notes', 'prayer_requests', 'additional_notes', 'address', 'special_requirements' => 'textarea',
      'gender', 'membership_status' => 'select',
      default => 'text',
    };
  }

  private function storageForField(string $fieldKey): string
  {
    if (in_array($fieldKey, ['name', 'email', 'phone'], true)) {
      return 'registrant';
    }

    if (in_array($fieldKey, self::COLUMN_FIELDS, true)) {
      return 'column';
    }

    if (in_array($fieldKey, self::METADATA_PROFILE_FIELDS, true)) {
      return 'profile';
    }

    return 'profile';
  }
}
