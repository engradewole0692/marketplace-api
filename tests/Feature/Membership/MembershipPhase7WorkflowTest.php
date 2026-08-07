<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberInterviewStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberInterview;
use App\Modules\Cms\Models\CmsMinistry;
use App\Services\Membership\MemberCredentialPasswordService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MembershipPhase7WorkflowTest extends IamTestCase
{
  public function test_interview_pass_auto_activates_member_with_surname_password(): void
  {
    $ministry = CmsMinistry::query()->create([
      'name' => 'Phase7 Ministry',
      'slug' => 'phase7-ministry-'.uniqid(),
      'summary' => 'Auto assign',
      'is_active' => true,
      'sort_order' => 1,
      'whatsapp_link' => 'https://chat.whatsapp.com/phase7',
      'telegram_link' => 'https://t.me/phase7',
    ]);

    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewRequired->value,
      'approval_status' => 'pending',
      'email' => 'adewale.pass@example.com',
      'first_name' => 'David',
      'last_name' => 'Adewale',
      'preferred_ministry_id' => $ministry->id,
      'country_id' => null,
    ]);

    $schedule = $this->postJson("/api/v1/members/{$member->id}/interviews", [
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '11:30',
      'meeting_link' => 'https://meet.example.com/room',
      'interview_type' => 'online',
    ])->assertCreated();

    $interviewUuid = $schedule->json('data.interview.id');

    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'interview_invitation',
    ]);

    $this->putJson("/api/v1/member-interviews/{$interviewUuid}", [
      'status' => MemberInterviewStatus::Passed->value,
    ])->assertOk();

    $member->refresh();
    $this->assertSame(MemberStatus::Active, $member->status);
    $this->assertSame($ministry->id, $member->ministry_id);
    $this->assertNotNull($member->user_id);
    $this->assertNotNull($member->activated_at);

    $user = $member->user()->first();
    $this->assertNotNull($user);
    $this->assertSame('adewale.pass@example.com', $user->email);
    $expectedPassword = app(MemberCredentialPasswordService::class)->generate($member);
    $this->assertSame('Adewale', $expectedPassword);
    $this->assertTrue(Hash::check($expectedPassword, $user->password));
    $this->assertTrue($user->must_change_password);

    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'member_account_created',
    ]);

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/member-portal/dashboard')->assertOk();
  }

  public function test_existing_visitor_account_is_upgraded_without_new_password(): void
  {
    $visitor = \App\Models\User::factory()->create([
      'email' => 'visitor.upgrade@example.com',
      'password' => Hash::make('VisitorKeepMe1!'),
      'must_change_password' => false,
    ]);
    $learnerPermission = \App\Models\Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $visitor->permissions()->syncWithoutDetaching([$learnerPermission->id]);

    $ministry = CmsMinistry::query()->create([
      'name' => 'Upgrade Ministry',
      'slug' => 'upgrade-ministry-'.uniqid(),
      'summary' => 'Upgrade path',
      'is_active' => true,
      'sort_order' => 1,
    ]);

    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewRequired->value,
      'approval_status' => 'pending',
      'email' => 'visitor.upgrade@example.com',
      'first_name' => 'Visitor',
      'last_name' => 'Upgrade',
      'user_id' => $visitor->id,
      'preferred_ministry_id' => $ministry->id,
    ]);

    $schedule = $this->postJson("/api/v1/members/{$member->id}/interviews", [
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '14:00',
      'meeting_link' => 'https://meet.example.com/upgrade',
      'interview_type' => 'online',
    ])->assertCreated();

    $this->putJson("/api/v1/member-interviews/{$schedule->json('data.interview.id')}", [
      'status' => MemberInterviewStatus::Passed->value,
    ])->assertOk();

    $member->refresh();
    $visitor->refresh();

    $this->assertSame($visitor->id, $member->user_id);
    $this->assertTrue(Hash::check('VisitorKeepMe1!', $visitor->password));
    $this->assertFalse((bool) $visitor->must_change_password);
    $this->assertTrue($visitor->hasPermission('member.portal'));
    $this->assertTrue($visitor->hasPermission('learner.portal'));

    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'member_account_upgraded',
    ]);
    $this->assertDatabaseMissing('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'member_account_created',
    ]);

    Sanctum::actingAs($visitor);
    $this->getJson('/api/v1/member-portal/dashboard')->assertOk();
  }

  public function test_short_surname_password_combines_first_name(): void
  {
    $service = app(MemberCredentialPasswordService::class);
    $member = new Member([
      'first_name' => 'David',
      'last_name' => 'Ojo',
    ]);

    $this->assertSame('OjoDavid', $service->generate($member));
  }

  public function test_interview_fail_leaves_failed_not_auto_rejected(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewScheduled->value,
      'approval_status' => 'pending',
    ]);

    $interview = MemberInterview::query()->create([
      'member_id' => $member->id,
      'status' => MemberInterviewStatus::Scheduled,
      'scheduled_date' => now()->toDateString(),
      'scheduled_time' => '09:00',
    ]);

    $this->putJson("/api/v1/member-interviews/{$interview->uuid}", [
      'status' => MemberInterviewStatus::Failed->value,
    ])->assertOk();

    $member->refresh();
    $this->assertSame(MemberStatus::InterviewFailed, $member->status);
  }

  public function test_applicant_can_track_status_and_confirm_interview(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewInvitationSent->value,
      'approval_status' => 'pending',
      'application_tracking_token' => 'track-token-phase7-abcdefghijklmnop',
      'application_number' => 'MM-TEST-0001',
    ]);

    $interview = MemberInterview::query()->create([
      'member_id' => $member->id,
      'status' => MemberInterviewStatus::InvitationSent,
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '10:00',
      'confirmation_token' => 'confirm-token-phase7-abcdefghijklmnop',
      'invitation_sent_at' => now(),
    ]);

    $this->getJson('/api/v1/public/membership/status?token=track-token-phase7-abcdefghijklmnop')
      ->assertOk()
      ->assertJsonPath('data.application_number', 'MM-TEST-0001')
      ->assertJsonPath('data.interview.id', $interview->uuid);

    $this->postJson('/api/v1/public/membership/interview/confirm', [
      'token' => 'confirm-token-phase7-abcdefghijklmnop',
    ])->assertOk();

    $interview->refresh();
    $this->assertSame(MemberInterviewStatus::Confirmed, $interview->status);
    $this->assertNotNull($interview->confirmed_at);
  }
}
