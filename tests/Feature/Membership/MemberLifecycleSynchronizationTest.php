<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsMinistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MemberLifecycleSynchronizationTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      SuperAdminSeeder::class,
    ]);
  }

  public function test_full_lifecycle_transitions_and_notification_queue_process(): void
  {
    $admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
    $ministry = CmsMinistry::query()->create([
      'name' => 'Marketplace Leadership',
      'slug' => 'marketplace-leadership-'.uniqid(),
      'summary' => 'Test ministry',
      'is_active' => true,
      'sort_order' => 1,
      'created_by' => $admin->id,
      'updated_by' => $admin->id,
    ]);

    $member = Member::factory()->create([
      'email' => 'lifecycle-'.uniqid().'@example.com',
      'status' => MemberStatus::ApplicationSubmitted->value,
      'approval_status' => 'pending',
      'preferred_ministry_id' => $ministry->id,
    ]);

    $this->actingAs($admin)->postJson("/api/v1/members/{$member->uuid}/start-review")->assertOk();
    $this->assertSame(MemberStatus::UnderReview->value, $member->fresh()->status->value ?? $member->fresh()->status);

    $this->actingAs($admin)->postJson("/api/v1/members/{$member->uuid}/require-interview")->assertOk();
    $this->assertSame(MemberStatus::InterviewRequired->value, $member->fresh()->status->value ?? $member->fresh()->status);

    $interview = $this->actingAs($admin)->postJson("/api/v1/members/{$member->uuid}/interviews", [
      'scheduled_date' => now()->addDay()->toDateString(),
      'scheduled_time' => '10:00',
      'interview_type' => 'online',
    ])->assertCreated();

    $interviewId = $interview->json('data.interview.id') ?? $interview->json('data.interview.uuid');
    $this->assertNotEmpty($interviewId);
    $this->assertSame(MemberStatus::InterviewInvitationSent->value, $member->fresh()->status->value ?? $member->fresh()->status);

    $this->actingAs($admin)->putJson("/api/v1/member-interviews/{$interviewId}", [
      'status' => 'passed',
      'result' => 'passed',
    ])->assertOk();

    $member->refresh();
    $this->assertSame(MemberStatus::Active->value, $member->status->value ?? $member->status);
    $this->assertSame($ministry->id, $member->ministry_id);
    $this->assertNotNull($member->user_id);

    $notifications = $this->actingAs($admin)->getJson('/api/v1/member-notifications?per_page=25');
    $notifications->assertOk();
  }
}
