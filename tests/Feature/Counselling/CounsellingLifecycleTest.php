<?php

declare(strict_types=1);

namespace Tests\Feature\Counselling;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\PaymentStatus;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingService;
use App\Modules\Counselling\Models\Counsellor;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class CounsellingLifecycleTest extends IamTestCase
{
  public function test_authenticated_request_creates_submitted_case_and_admin_can_manage_lifecycle(): void
  {
    $category = CounsellingCategory::query()->create([
      'name' => 'Marriage',
      'slug' => 'marriage-test',
      'is_visible' => true,
      'status' => 'active',
    ]);

    $service = CounsellingService::query()->create([
      'category_id' => $category->id,
      'title' => 'Marriage Session',
      'slug' => 'marriage-session-test',
      'short_description' => 'Counselling',
      'duration_minutes' => 60,
      'format' => 'virtual',
      'maximum_sessions' => 3,
      'requires_approval' => true,
      'requires_payment' => false,
      'is_free' => true,
      'currency' => 'USD',
      'is_visible' => true,
      'is_featured' => true,
      'status' => 'published',
    ]);

    $visitor = User::factory()->create([
      'name' => 'Ada Visitor',
      'email' => 'ada.visitor@example.com',
    ]);
    $learnerPermission = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $visitor->permissions()->syncWithoutDetaching([$learnerPermission->id]);

    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/public/counselling/request', [
      'category_id' => $category->uuid,
      'subject' => 'Marriage support',
      'description' => 'Need confidential guidance for marriage challenges at home.',
      'preferred_format' => 'virtual',
      'urgency' => 'normal',
      'terms_accepted' => true,
    ])->assertUnauthorized();

    Sanctum::actingAs($visitor);

    $created = $this->postJson('/api/v1/public/counselling/request', [
      'category_id' => $category->uuid,
      'service_id' => $service->uuid,
      'subject' => 'Marriage support',
      'description' => 'Need confidential guidance for marriage challenges at home.',
      'preferred_language' => 'English',
      'preferred_counsellor_gender' => 'any',
      'preferred_format' => 'virtual',
      'urgency' => 'normal',
      'terms_accepted' => true,
    ])->assertCreated();

    $caseUuid = $created->json('data.case.id');
    $this->assertSame(CaseStatus::Submitted->value, $created->json('data.case.status'));
    $this->assertDatabaseHas('counselling_cases', [
      'uuid' => $caseUuid,
      'status' => CaseStatus::Submitted->value,
    ]);
    $this->assertDatabaseHas('counselling_case_events', [
      'case_id' => CounsellingCase::query()->where('uuid', $caseUuid)->value('id'),
      'event_type' => 'case.created',
    ]);

    $admin = $this->createAdminWithCounsellingPermissions();
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/counselling/dashboard')->assertOk();

    $this->postJson("/api/v1/counselling/cases/{$caseUuid}/transition", [
      'status' => CaseStatus::UnderReview->value,
    ])->assertOk();

    $counsellorUser = User::factory()->create(['name' => 'Counsellor One']);
    $counsellorPermission = Permission::query()->where('slug', 'counsellor.portal')->firstOrFail();
    $counsellorUser->permissions()->syncWithoutDetaching([$counsellorPermission->id]);

    $counsellor = Counsellor::query()->create([
      'user_id' => $counsellorUser->id,
      'display_name' => 'Counsellor One',
      'slug' => 'counsellor-one-phase3b',
      'specializations' => ['Marriage'],
      'languages' => ['English'],
      'is_active' => true,
      'max_daily_sessions' => 6,
    ]);

    $this->postJson("/api/v1/counselling/cases/{$caseUuid}/assign", [
      'counsellor_id' => $counsellor->uuid,
    ])->assertOk();

    $this->assertDatabaseHas('counselling_cases', [
      'uuid' => $caseUuid,
      'status' => CaseStatus::Assigned->value,
    ]);

    $this->postJson("/api/v1/counselling/cases/{$caseUuid}/appointments", [
      'starts_at' => now()->addDay()->toIso8601String(),
      'format' => 'virtual',
      'meeting_link' => 'https://meet.example.com/demo',
    ])->assertCreated();

    $this->assertDatabaseHas('counselling_cases', [
      'uuid' => $caseUuid,
      'status' => CaseStatus::AppointmentScheduled->value,
    ]);

    $this->postJson("/api/v1/counselling/cases/{$caseUuid}/require-payment", [
      'amount' => 40,
      'currency' => 'USD',
      'payment_type' => 'paid',
    ])->assertCreated();

    $this->assertDatabaseHas('counselling_payments', [
      'case_id' => CounsellingCase::query()->where('uuid', $caseUuid)->value('id'),
      'status' => PaymentStatus::Pending->value,
    ]);

    Sanctum::actingAs($counsellorUser);
    $this->getJson('/api/v1/counsellor/cases')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $caseUuid);
  }

  private function createAdminWithCounsellingPermissions(): User
  {
    $admin = User::factory()->create(['name' => 'Counselling Admin']);
    $slugs = ['admin.access', 'counselling.view', 'counselling.manage', 'counsellor.portal'];
    $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
    $admin->permissions()->syncWithoutDetaching($ids);

    return $admin;
  }
}
