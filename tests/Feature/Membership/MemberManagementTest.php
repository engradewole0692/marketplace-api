<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MemberStatus;
use App\Models\Member;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MemberManagementTest extends IamTestCase
{
  public function test_members_index_requires_permission(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/members')->assertForbidden();
  }

  public function test_super_admin_can_list_members(): void
  {
    Member::factory()->count(2)->create();

    $response = $this->getJson('/api/v1/members');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_super_admin_can_create_member_with_membership_number(): void
  {
    $response = $this->postJson('/api/v1/members', [
      'first_name' => 'John',
      'last_name' => 'Marketplace',
      'email' => 'john.marketplace@example.com',
      'phone' => '+1234567890',
      'marketplace_sector' => 'finance',
    ]);

    $response
      ->assertCreated()
      ->assertJsonPath('data.member.first_name', 'John')
      ->assertJsonStructure(['data' => ['member' => ['membership_number']]]);

    $number = $response->json('data.member.membership_number');
    $this->assertStringStartsWith('MM-', $number);
    $this->assertDatabaseHas('members', ['email' => 'john.marketplace@example.com']);
    $this->assertDatabaseHas('member_timelines', ['event_type' => 'member_created']);
    $this->assertDatabaseHas('member_audit_logs', ['event_type' => 'member_created']);
  }

  public function test_super_admin_can_update_and_soft_delete_member(): void
  {
    $member = Member::factory()->create();

    $this->putJson("/api/v1/members/{$member->id}", [
      'occupation' => 'Pastor',
    ])
      ->assertOk()
      ->assertJsonPath('data.member.occupation', 'Pastor');

    $this->deleteJson("/api/v1/members/{$member->id}")->assertOk();
    $this->assertSoftDeleted('members', ['id' => $member->id]);
  }

  public function test_super_admin_can_restore_member(): void
  {
    $member = Member::factory()->create();
    $member->delete();

    $this->postJson("/api/v1/members/{$member->id}/restore")
      ->assertOk()
      ->assertJsonPath('data.member.id', $member->id);

    $this->assertDatabaseHas('members', ['id' => $member->id, 'deleted_at' => null]);
  }

  public function test_approval_workflow(): void
  {
    $member = Member::factory()->create([
      'status' => MemberStatus::InterviewCompleted->value,
      'approval_status' => 'pending',
    ]);

    $this->postJson("/api/v1/members/{$member->id}/approve", ['reason' => 'Verified'])
      ->assertOk()
      ->assertJsonPath('data.member.approval_status', 'approved');

    $member->refresh();
    $this->assertSame(MemberStatus::Orientation, $member->status);
    $this->assertDatabaseHas('member_status_transitions', ['member_id' => $member->id]);
  }

  public function test_bulk_approve_members(): void
  {
    $members = Member::factory()->count(2)->create([
      'status' => MemberStatus::InterviewCompleted->value,
      'approval_status' => 'pending',
    ]);

    $response = $this->postJson('/api/v1/members/bulk', [
      'action' => 'approve',
      'member_ids' => $members->pluck('id')->all(),
    ]);

    $response->assertOk()->assertJsonPath('data.affected', 2);
  }

  public function test_member_notes_and_timeline(): void
  {
    $member = Member::factory()->create();

    $this->postJson("/api/v1/members/{$member->id}/notes", [
      'body' => 'Follow up next week.',
      'is_private' => true,
    ])
      ->assertCreated()
      ->assertJsonPath('data.note.body', 'Follow up next week.');

    $this->getJson("/api/v1/members/{$member->id}/timeline")
      ->assertOk()
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_membership_number_generator_is_sequential(): void
  {
    $service = app(\App\Services\Membership\MembershipNumberGeneratorService::class);

    $first = $service->generate();
    $second = $service->generate();

    $this->assertNotSame($first, $second);
    $this->assertMatchesRegularExpression('/^MM-\d{4}-\d{6}$/', $first);
  }

  public function test_super_admin_can_export_members_csv(): void
  {
    Member::factory()->count(2)->create();

    $response = $this->get('/api/v1/members/export?status=active');

    $response
      ->assertOk()
      ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $this->assertStringContainsString('membership_number', $response->streamedContent());
  }

  public function test_members_export_requires_permission(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->get('/api/v1/members/export')->assertForbidden();
  }
}
