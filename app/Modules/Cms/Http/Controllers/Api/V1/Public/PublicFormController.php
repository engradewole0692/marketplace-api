<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Http\Requests\Public\SubmitPublicFormRequest;
use App\Modules\Cms\Services\FormSubmissionService;
use App\Modules\Cms\Services\MembershipApplicationService;
use App\Modules\Cms\Services\PublicTestimonyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicFormController extends ApiController
{
  public function contact(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::Contact, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Contact message received.',
      status: 201,
    );
  }

  public function counseling(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::Counseling, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Counseling request received.',
      status: 201,
    );
  }

  public function newsletter(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::Newsletter, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Newsletter subscription received.',
      status: 201,
    );
  }

  public function partnership(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $payload = $request->validated();
    $partnerType = strtolower((string) ($payload['partnerType'] ?? ''));
    $area = strtolower((string) ($payload['area'] ?? ''));
    $type = ($partnerType === 'volunteer' || $area === 'volunteer')
      ? FormSubmissionType::Volunteer
      : FormSubmissionType::Partnership;

    $submission = $service->submit($type, $payload);

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: $type === FormSubmissionType::Volunteer
        ? 'Volunteer application received.'
        : 'Partnership application received.',
      status: 201,
    );
  }

  public function volunteer(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::Volunteer, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Volunteer application received.',
      status: 201,
    );
  }

  public function newsletterUnsubscribe(Request $request, FormSubmissionService $service): JsonResponse
  {
    $validated = $request->validate([
      'email' => ['required', 'email', 'max:255'],
      'name' => ['nullable', 'string', 'max:255'],
    ]);

    $submission = $service->submit(FormSubmissionType::Newsletter, [
      ...$validated,
      'action' => 'unsubscribe',
    ]);

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Newsletter unsubscribe request received.',
      status: 201,
    );
  }

  public function donationInterest(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::DonationInterest, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Donation interest received.',
      status: 201,
    );
  }

  public function prayer(SubmitPublicFormRequest $request, FormSubmissionService $service): JsonResponse
  {
    $submission = $service->submit(FormSubmissionType::Prayer, $request->validated());

    return $this->responder->success(
      data: ['id' => $submission->uuid, 'received_at' => $submission->created_at?->toIso8601String()],
      message: 'Prayer request received.',
      status: 201,
    );
  }

  public function testimony(Request $request, PublicTestimonyService $service): JsonResponse
  {
    $validated = $request->validate([
      'author_name' => ['required_unless:is_anonymous,true,1', 'nullable', 'string', 'max:255'],
      'name' => ['nullable', 'string', 'max:255'],
      'author_title' => ['nullable', 'string', 'max:255'],
      'role' => ['nullable', 'string', 'max:255'],
      'author_location' => ['nullable', 'string', 'max:255'],
      'country' => ['nullable', 'string', 'max:120'],
      'quote' => ['required_without:testimony', 'nullable', 'string', 'max:5000'],
      'testimony' => ['required_without:quote', 'nullable', 'string', 'max:5000'],
      'category' => ['nullable', 'string', Rule::in(PublicTestimonyService::CATEGORIES)],
      'is_anonymous' => ['sometimes', 'boolean'],
      'submitter_type' => ['nullable', 'string', 'in:guest,member'],
      'submitter_email' => ['nullable', 'email', 'max:255'],
      'email' => ['nullable', 'email', 'max:255'],
      'submitter_phone' => ['nullable', 'string', 'max:50'],
      'phone' => ['nullable', 'string', 'max:50'],
      'member_id' => ['nullable', 'string'],
      'photo_media_id' => ['nullable', 'string', 'exists:cms_media,uuid'],
      'video_media_id' => ['nullable', 'string', 'exists:cms_media,uuid'],
      'photo' => ['nullable', 'file', 'image', 'max:10240'],
      'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
    ]);

    $testimonial = $service->submit($validated, $request, $request->user());

    return $this->responder->success(
      data: [
        'id' => $testimonial->uuid,
        'status' => $testimonial->status->value,
        'received_at' => $testimonial->created_at?->toIso8601String(),
      ],
      message: 'Testimony submitted for review.',
      status: 201,
    );
  }

  public function membership(SubmitPublicFormRequest $request, MembershipApplicationService $service): JsonResponse
  {
    $member = $service->submit($request->validated());

    return $this->responder->success(
      data: [
        'id' => $member->uuid,
        'membership_number' => $member->membership_number,
        'application_number' => $member->application_number ?? $member->membership_number,
        'tracking_token' => $member->application_tracking_token,
        'status_url' => $member->application_tracking_token
          ? rtrim((string) config('app-frontend.url', config('app.url')), '/').'/membership/status?token='.$member->application_tracking_token
          : null,
        'received_at' => $member->created_at?->toIso8601String(),
      ],
      message: 'Membership application received.',
      status: 201,
    );
  }
}
