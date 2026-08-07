<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()?->can('create', Member::class) ?? false;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return $this->memberRules(requiredName: true);
  }

  /**
   * @return array<string, mixed>
   */
  protected function memberRules(bool $requiredName = false): array
  {
    return [
      'title' => ['nullable', 'string', 'max:40'],
      'first_name' => [$requiredName ? 'required' : 'nullable', 'string', 'max:120'],
      'middle_name' => ['nullable', 'string', 'max:120'],
      'last_name' => [$requiredName ? 'required' : 'nullable', 'string', 'max:120'],
      'display_name' => ['nullable', 'string', 'max:180'],
      'gender' => ['nullable', 'string', 'max:20'],
      'date_of_birth' => ['nullable', 'date'],
      'phone' => ['nullable', 'string', 'max:40'],
      'alternate_phone' => ['nullable', 'string', 'max:40'],
      'email' => ['nullable', 'email', 'max:255'],
      'occupation' => ['nullable', 'string', 'max:180'],
      'organization' => ['nullable', 'string', 'max:180'],
      'marketplace_sector' => ['nullable', 'string', 'max:120'],
      'skills' => ['nullable', 'array'],
      'skills.*' => ['string', 'max:80'],
      'languages' => ['nullable', 'array'],
      'languages.*' => ['string', 'max:40'],
      'biography' => ['nullable', 'string'],
      'country_id' => ['nullable', 'integer'],
      'region_id' => ['nullable', 'integer'],
      'ministry_id' => ['nullable', 'integer'],
      'status' => ['nullable', Rule::enum(MemberStatus::class)],
      'approval_status' => ['nullable', Rule::enum(MemberApprovalStatus::class)],
      'joined_at' => ['nullable', 'date'],
      'tag_ids' => ['nullable', 'array'],
      'tag_ids.*' => ['integer', 'exists:member_tags,id'],
      'contacts' => ['nullable', 'array'],
      'contacts.*.contact_type' => ['required_with:contacts', 'string', 'max:40'],
      'contacts.*.name' => ['required_with:contacts', 'string', 'max:180'],
      'contacts.*.relationship' => ['nullable', 'string', 'max:80'],
      'contacts.*.phone' => ['nullable', 'string', 'max:40'],
      'contacts.*.email' => ['nullable', 'email'],
      'contacts.*.is_primary' => ['nullable', 'boolean'],
      'addresses' => ['nullable', 'array'],
      'addresses.*.address_type' => ['nullable', 'string', 'max:40'],
      'addresses.*.address_line_1' => ['nullable', 'string', 'max:255'],
      'addresses.*.address_line_2' => ['nullable', 'string', 'max:255'],
      'addresses.*.city' => ['nullable', 'string', 'max:120'],
      'addresses.*.state' => ['nullable', 'string', 'max:120'],
      'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
      'addresses.*.country_code' => ['nullable', 'string', 'max:3'],
      'addresses.*.is_primary' => ['nullable', 'boolean'],
    ];
  }
}
