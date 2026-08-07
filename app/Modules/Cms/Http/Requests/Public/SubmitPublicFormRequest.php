<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPublicFormRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'name' => ['sometimes', 'string', 'max:255'],
      'fullName' => ['sometimes', 'string', 'max:255'],
      'title' => ['sometimes', 'string', 'max:50'],
      'firstName' => ['sometimes', 'string', 'max:120'],
      'middleName' => ['sometimes', 'nullable', 'string', 'max:120'],
      'lastName' => ['sometimes', 'string', 'max:120'],
      'email' => ['sometimes', 'email', 'max:255'],
      'phone' => ['sometimes', 'string', 'max:50'],
      'whatsapp' => ['sometimes', 'nullable', 'string', 'max:50'],
      'country' => ['sometimes', 'string', 'max:120'],
      'state' => ['sometimes', 'nullable', 'string', 'max:120'],
      'city' => ['sometimes', 'nullable', 'string', 'max:120'],
      'address' => ['sometimes', 'nullable', 'string', 'max:500'],
      'subject' => ['sometimes', 'string', 'max:255'],
      'message' => ['sometimes', 'string', 'max:5000'],
      'gender' => ['sometimes', 'string', 'max:50'],
      'dob' => ['sometimes', 'nullable', 'string', 'max:50'],
      'maritalStatus' => ['sometimes', 'nullable', 'string', 'max:50'],
      'preferredCounselor' => ['sometimes', 'string', 'max:120'],
      'topic' => ['sometimes', 'string', 'max:255'],
      'contactMethod' => ['sometimes', 'string', 'max:50'],
      'preferredDate' => ['sometimes', 'string', 'max:50'],
      'preferredTime' => ['sometimes', 'string', 'max:50'],
      'reason' => ['sometimes', 'string', 'max:5000'],
      'prayerRequest' => ['sometimes', 'string', 'max:5000'],
      'partnerType' => ['sometimes', 'string', 'max:120'],
      'organization' => ['sometimes', 'string', 'max:255'],
      'employer' => ['sometimes', 'nullable', 'string', 'max:255'],
      'area' => ['sometimes', 'string', 'max:255'],
      'purpose' => ['sometimes', 'string', 'max:120'],
      'ministry' => ['sometimes', 'string', 'max:120'],
      'currency' => ['sometimes', 'string', 'max:10'],
      'amount' => ['sometimes', 'string', 'max:50'],
      'frequency' => ['sometimes', 'string', 'max:50'],
      'notes' => ['sometimes', 'string', 'max:2000'],
      'request' => ['sometimes', 'string', 'max:5000'],
      'category' => ['sometimes', 'string', 'max:120'],
      'preferredMinistry' => ['sometimes', 'string', 'max:120'],
      'occupation' => ['sometimes', 'string', 'max:255'],
      'industry' => ['sometimes', 'string', 'max:255'],
      'marketplaceSector' => ['sometimes', 'string', 'max:255'],
      'skills' => ['sometimes', 'string', 'max:1000'],
      'languages' => ['sometimes', 'string', 'max:500'],
      'biography' => ['sometimes', 'string', 'max:5000'],
      'testimony' => ['sometimes', 'string', 'max:5000'],
      'whyJoin' => ['sometimes', 'string', 'max:5000'],
      'availability' => ['sometimes', 'nullable', 'string', 'max:255'],
      'churchName' => ['sometimes', 'nullable', 'string', 'max:255'],
      'churchAddress' => ['sometimes', 'nullable', 'string', 'max:500'],
      'churchRole' => ['sometimes', 'nullable', 'string', 'max:255'],
      'affiliation' => ['sometimes', 'nullable', 'string', 'max:255'],
      'yearsInFaith' => ['sometimes', 'nullable', 'string', 'max:50'],
      'ministryExperience' => ['sometimes', 'nullable', 'string', 'max:2000'],
      'leadership' => ['sometimes', 'nullable', 'string', 'max:1000'],
      'education' => ['sometimes', 'nullable', 'string', 'max:255'],
      'interests' => ['sometimes', 'nullable', 'string', 'max:1000'],
      'personalVision' => ['sometimes', 'nullable', 'string', 'max:2000'],
      'spouseFirstName' => ['sometimes', 'nullable', 'string', 'max:120'],
      'spouseLastName' => ['sometimes', 'nullable', 'string', 'max:120'],
      'spouseEmail' => ['sometimes', 'nullable', 'email', 'max:255'],
      'spousePhone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'nextOfKin' => ['sometimes', 'nullable', 'string', 'max:255'],
      'nextOfKinName' => ['sometimes', 'nullable', 'string', 'max:255'],
      'nextOfKinPhone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'nextOfKinRelationship' => ['sometimes', 'nullable', 'string', 'max:120'],
      'kinRelationship' => ['sometimes', 'nullable', 'string', 'max:120'],
      'kinPhone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'kinEmail' => ['sometimes', 'nullable', 'email', 'max:255'],
      'prayerRequests' => ['sometimes', 'nullable', 'string', 'max:5000'],
      'howHeard' => ['sometimes', 'nullable', 'string', 'max:255'],
      'consent' => ['sometimes', 'boolean'],
      'declaration' => ['sometimes', 'boolean'],
    ];
  }
}
