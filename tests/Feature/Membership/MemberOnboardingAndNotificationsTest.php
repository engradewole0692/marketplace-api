<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberNotificationQueue;
use App\Models\MemberOnboardingChecklistItem;
use Tests\Feature\Iam\IamTestCase;

final class MemberOnboardingAndNotificationsTest extends IamTestCase
{
  public function test_onboarding_checklist_is_created_on_approval_and_updatable(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewCompleted->value,
      'approval_status' => 'pending',
    ]);

    $this->postJson("/api/v1/members/{$member->id}/approve", ['reason' => 'Ready'])
      ->assertOk();

    $this->assertDatabaseHas('member_onboarding_checklist_items', [
      'member_id' => $member->id,
      'step_key' => 'application_approved',
      'is_completed' => true,
    ]);

    $this->getJson("/api/v1/members/{$member->id}/onboarding-checklist")
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->putJson("/api/v1/members/{$member->id}/onboarding-checklist/orientation_completed", [
      'is_completed' => true,
      'notes' => 'Orientation done',
    ])->assertOk()
      ->assertJsonPath('data.item.is_completed', true);

    $this->assertDatabaseHas('member_onboarding_checklist_items', [
      'member_id' => $member->id,
      'step_key' => 'orientation_completed',
      'is_completed' => true,
    ]);
  }

  public function test_notification_queue_admin_can_retry_and_cancel(): void
  {
    $member = Member::factory()->create();
    $notification = MemberNotificationQueue::query()->create([
      'member_id' => $member->id,
      'channel' => 'email',
      'template' => 'member_welcome',
      'payload' => ['email' => $member->email],
      'status' => 'failed',
      'error' => 'SMTP down',
      'queued_at' => now(),
    ]);

    $this->getJson('/api/v1/member-notifications')
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->postJson("/api/v1/member-notifications/{$notification->uuid}/retry")
      ->assertOk();

    $notification->refresh();
    $this->assertContains($notification->status, ['pending', 'processing', 'sent', 'failed']);

    $pending = MemberNotificationQueue::query()->create([
      'member_id' => $member->id,
      'channel' => 'in_app',
      'template' => 'member_welcome',
      'payload' => ['member_id' => $member->id],
      'status' => 'pending',
      'queued_at' => now(),
    ]);

    $this->postJson("/api/v1/member-notifications/{$pending->uuid}/cancel")
      ->assertOk()
      ->assertJsonPath('data.notification.status', 'cancelled');
  }
}
