<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberNotificationQueue;
use App\Models\Permission;
use App\Modules\Cms\Enums\FormSubmissionStatus;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MemberPortalWorkspaceTest extends IamTestCase
{
  public function test_portal_requires_member_portal_permission_and_active_member(): void
  {
    $user = $this->memberUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/member-portal/dashboard')->assertForbidden();

    $permission = Permission::query()->where('slug', 'member.portal')->firstOrFail();
    $user->permissions()->syncWithoutDetaching([$permission->id]);

    $this->getJson('/api/v1/member-portal/dashboard')->assertForbidden();

    Member::factory()->create([
      'user_id' => $user->id,
      'email' => $user->email,
      'status' => MemberStatus::Active->value,
      'approval_status' => 'approved',
    ]);

    $this->getJson('/api/v1/member-portal/dashboard')
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'member' => ['uuid', 'membership_number', 'email'],
          'widgets' => ['membership_status', 'profile_completion', 'unread_notifications'],
          'sections' => ['activity', 'notifications', 'courses', 'events'],
        ],
      ]);
  }

  public function test_portal_activity_and_forms_are_scoped_to_member(): void
  {
    $user = $this->memberUser();
    $permission = Permission::query()->where('slug', 'member.portal')->firstOrFail();
    $user->permissions()->syncWithoutDetaching([$permission->id]);
    Sanctum::actingAs($user);

    $member = Member::factory()->create([
      'user_id' => $user->id,
      'email' => $user->email,
      'status' => MemberStatus::Active->value,
      'approval_status' => 'approved',
    ]);

    $other = Member::factory()->create([
      'email' => 'other.member@example.com',
      'status' => MemberStatus::Active->value,
    ]);

    MemberNotificationQueue::query()->create([
      'member_id' => $member->id,
      'channel' => 'in_app',
      'template' => 'member_welcome',
      'payload' => [],
      'status' => 'queued',
      'queued_at' => now(),
    ]);

    MemberNotificationQueue::query()->create([
      'member_id' => $other->id,
      'channel' => 'in_app',
      'template' => 'other_secret',
      'payload' => [],
      'status' => 'queued',
      'queued_at' => now(),
    ]);

    CmsFormSubmission::query()->create([
      'type' => FormSubmissionType::Prayer,
      'status' => FormSubmissionStatus::New,
      'payload' => ['prayerRequest' => 'Mine'],
      'submitter_name' => $member->fullName(),
      'submitter_email' => $member->email,
    ]);

    CmsFormSubmission::query()->create([
      'type' => FormSubmissionType::Prayer,
      'status' => FormSubmissionStatus::New,
      'payload' => ['prayerRequest' => 'Not mine'],
      'submitter_name' => 'Other',
      'submitter_email' => $other->email,
    ]);

    $notifications = $this->getJson('/api/v1/member-portal/notifications')->assertOk()->json('data.notifications');
    $this->assertCount(1, $notifications);
    $this->assertSame('member_welcome', $notifications[0]['template']);

    $prayer = $this->getJson('/api/v1/member-portal/prayer-requests')->assertOk()->json('data.requests');
    $this->assertCount(1, $prayer);
    $this->assertSame('Mine', $prayer[0]['payload']['prayerRequest']);

    $this->putJson('/api/v1/member-portal/profile', [
      'phone' => '+2348000000000',
      'occupation' => 'Engineer',
      'city' => 'Lagos',
      'biography' => 'Serving in the marketplace.',
    ])->assertOk()->assertJsonPath('data.member.phone', '+2348000000000');
  }
}
