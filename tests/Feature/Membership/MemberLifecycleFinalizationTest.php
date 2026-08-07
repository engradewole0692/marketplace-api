<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberInterviewStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberInterview;
use App\Modules\Cms\Models\CmsMinistry;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MemberLifecycleFinalizationTest extends IamTestCase
{
  public function test_interview_schedule_queues_notification_and_pass_advances_lifecycle(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewRequired->value,
      'approval_status' => 'pending',
      'email' => 'interview.pass@example.com',
    ]);

    $schedule = $this->postJson("/api/v1/members/{$member->id}/interviews", [
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '11:30',
      'meeting_link' => 'https://meet.example.com/room',
      'physical_location' => 'HQ Boardroom',
      'remarks' => 'Bring ID',
    ])->assertCreated();

    $interviewUuid = $schedule->json('data.interview.id');

    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'interview_scheduled',
    ]);

    $member->refresh();
    $this->assertSame(MemberStatus::InterviewInvitationSent, $member->status);

    $this->putJson("/api/v1/member-interviews/{$interviewUuid}", [
      'status' => MemberInterviewStatus::Passed->value,
    ])->assertOk();

    $member->refresh();
    $this->assertSame(MemberStatus::Active, $member->status);
    $this->assertNotNull($member->user_id);

    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'template' => 'application_approved',
    ]);
  }

  public function test_interview_fail_rejects_member(): void
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

  public function test_complete_orientation_marks_checklist_and_activation_opens_portal(): void
  {
    $ministry = CmsMinistry::query()->create([
      'name' => 'Portal Test Ministry',
      'slug' => 'portal-test-ministry-'.uniqid(),
      'summary' => 'Test ministry for portal activation',
      'is_active' => true,
      'sort_order' => 1,
    ]);

    $member = Member::factory()->create([
      'status' => MemberStatus::Approved->value,
      'approval_status' => 'approved',
      'email' => 'portal.ready@example.com',
      'ministry_id' => $ministry->id,
    ]);

    $this->postJson("/api/v1/members/{$member->id}/complete-orientation", [
      'notes' => 'Completed orientation session',
    ])->assertOk();

    $this->assertDatabaseHas('member_onboarding_checklist_items', [
      'member_id' => $member->id,
      'step_key' => 'orientation_completed',
      'is_completed' => true,
    ]);

    $this->postJson("/api/v1/members/{$member->id}/activate-automation")
      ->assertOk()
      ->assertJsonPath('data.member.status', MemberStatus::Active->value);

    $member->refresh();
    $member->load('user');
    $this->assertNotNull($member->user);

    Sanctum::actingAs($member->user);
    $this->getJson('/api/v1/member-portal/dashboard')
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->getJson('/api/v1/member-portal/my-ministry')
      ->assertOk()
      ->assertJsonPath('data.ministry.id', $ministry->uuid);

    $this->getJson('/api/v1/member-portal/activity')->assertOk();
    $this->getJson('/api/v1/member-portal/notifications')->assertOk();
  }

  public function test_dashboard_includes_lifecycle_breakdown_metrics(): void
  {
    $this->getJson('/api/v1/dashboard/overview')
      ->assertOk()
      ->assertJsonStructure([
        'data' => [
          'membership' => [
            'pending_applications',
            'under_review',
            'awaiting_onboarding',
            'awaiting_ministry_assignment',
            'interviews_today',
            'upcoming_interviews_count',
            'recently_activated_count',
            'members_by_ministry',
            'members_by_country',
            'leadership_stats',
            'ministry_stats',
          ],
        ],
      ]);
  }
}
