<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncRegistrationFieldSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');
        if (! is_array($settings)) {
            return;
        }

        foreach ($settings as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('options', $row) && is_string($row['options'])) {
                $decoded = json_decode($row['options'], true);
                if (is_array($decoded)) {
                    $settings[$index]['options'] = $decoded;
                } elseif (trim($row['options']) === '') {
                    $settings[$index]['options'] = null;
                } else {
                    $split = preg_split('/\s*,\s*/', $row['options']);
                    $settings[$index]['options'] = $split === false ? null : $split;
                }
            }

            foreach (['is_enabled', 'is_required', 'show_on_public', 'show_on_quick'] as $boolKey) {
                if (! array_key_exists($boolKey, $row)) {
                    continue;
                }
                $value = $row[$boolKey];
                if ($value === 'true' || $value === 'false') {
                    $settings[$index][$boolKey] = $value === 'true';
                }
            }
        }

        $this->merge(['settings' => $settings]);
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*.field_key' => ['required', 'string', 'max:80'],
            'settings.*.label' => ['nullable', 'string', 'max:255'],
            'settings.*.is_enabled' => ['sometimes', 'boolean'],
            'settings.*.is_required' => ['sometimes', 'boolean'],
            'settings.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'settings.*.show_on_public' => ['sometimes', 'boolean'],
            'settings.*.show_on_quick' => ['sometimes', 'boolean'],
            'settings.*.help_text' => ['nullable', 'string', 'max:1000'],
            'settings.*.placeholder' => ['nullable', 'string', 'max:255'],
            'settings.*.field_type' => ['nullable', 'string', 'max:40'],
            'settings.*.options' => ['nullable', 'array'],
            'settings.*.metadata' => ['nullable', 'array'],
        ];
    }
}
