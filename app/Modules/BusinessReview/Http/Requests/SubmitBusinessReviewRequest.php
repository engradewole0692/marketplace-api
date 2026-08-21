<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitBusinessReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+\-\s().]{7,40}$/'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_industry' => ['required', 'string', 'max:120'],
            'business_description' => ['required', 'string', 'min:20', 'max:4000'],
            'country' => ['required', 'string', 'max:120'],
            'state_province' => ['required', 'string', 'max:120'],
            'years_in_operation' => ['required', 'integer', 'min:0', 'max:200'],
            'business_stage' => ['required', 'string', Rule::in([
                'Business Idea',
                'Startup',
                'Existing Business',
                'Growing Business',
                'Expanding Business',
            ])],
            'website_url' => ['nullable', 'url', 'max:500'],
            'social_links' => ['nullable', 'string', 'max:1000'],
            'advice_areas' => ['required', 'string', 'min:10', 'max:3000'],
            'business_goals' => ['nullable', 'string', 'max:2000'],
            'employee_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'referral_source' => ['nullable', 'string', 'max:255'],
            'additional_info' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'email.email' => 'Please enter a valid email address.',
            'phone.required' => 'Please enter a phone number.',
            'phone.regex' => 'Please enter a valid phone number.',
            'business_name.required' => 'Please enter your business name.',
            'business_industry.required' => 'Please choose a business category.',
            'business_description.required' => 'Please describe your business.',
            'business_description.min' => 'Business description should be at least 20 characters.',
            'country.required' => 'Please enter your country.',
            'state_province.required' => 'Please enter your state or province.',
            'business_stage.required' => 'Please select your business stage.',
            'website_url.url' => 'Please enter a valid website URL (including https://).',
            'advice_areas.required' => 'Please tell us which areas you would like advice on.',
            'advice_areas.min' => 'Please share a little more about the advice you need.',
        ];
    }
}
