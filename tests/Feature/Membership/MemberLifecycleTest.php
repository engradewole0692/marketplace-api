<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Modules\Cms\Models\CmsMinistry;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MemberLifecycleTest extends IamTestCase
{
  public function test_full_approval_pipeline_requires_interview_before_final_approval(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::ApplicationSubmitted->value,
      'approval_status' => 'pending',
    ]);

    $this->postJson("/api/v1/members/{$member->id}/approve")
      ->assertStatus(422);

    $this->postJson("/api/v1/members/{$member->id}/start-review")
      ->assertOk()
      ->assertJsonPath('data.member.status', MemberStatus::UnderReview->value);

    $this->postJson("/api/v1/members/{$member->id}/require-interview")
      ->assertOk()
      ->assertJsonPath('data.member.status', MemberStatus::InterviewRequired->value);

    $this->postJson("/api/v1/members/{$member->id}/interviews", [
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '10:00',
    ])->assertCreated();

    $member->refresh();
    $this->assertSame(MemberStatus::InterviewInvitationSent, $member->status);

    $this->postJson("/api/v1/members/{$member->id}/transition", [
      'status' => MemberStatus::InterviewCompleted->value,
    ])->assertOk();

    $this->postJson("/api/v1/members/{$member->id}/approve", ['reason' => 'Strong calling'])
      ->assertOk()
      ->assertJsonPath('data.member.status', MemberStatus::Orientation->value)
      ->assertJsonPath('data.member.approval_status', 'approved');
  }

  public function test_activation_creates_user_and_queues_notifications(): void
  {
    $ministry = CmsMinistry::query()->first();
    $member = Member::factory()->create([
      'status' => MemberStatus::Approved->value,
      'approval_status' => 'approved',
      'email' => 'activate.member@example.com',
      'ministry_id' => $ministry?->id,
      'preferred_ministry_id' => $ministry?->id,
    ]);

    $this->postJson("/api/v1/members/{$member->id}/activate-automation")
      ->assertOk()
      ->assertJsonPath('data.member.status', MemberStatus::Active->value);

    $member->refresh();
    $this->assertNotNull($member->user_id);
    $this->assertDatabaseHas('member_notification_queue', [
      'member_id' => $member->id,
      'channel' => 'email',
      'template' => 'member_welcome',
    ]);
  }

  public function test_member_portal_requires_active_member(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/member-portal/dashboard')->assertForbidden();
  }

  public function test_interviews_index_requires_permission(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/member-interviews')->assertForbidden();
  }
}
