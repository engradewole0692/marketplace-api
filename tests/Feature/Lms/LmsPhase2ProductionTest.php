<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Lms\Enums\CourseAudience;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\PricingEngine;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsPhase2ProductionTest extends IamTestCase
{
  public function test_pricing_engine_respects_audience_free_flags(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Phase2 Pricing',
      'slug' => 'phase2-pricing',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Audience Pricing Course',
      'slug' => 'audience-pricing-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'audience' => CourseAudience::Both,
      'is_free' => false,
      'visitor_free' => true,
      'member_free' => false,
      'public_price' => 50,
      'member_price' => 25,
      'currency' => 'USD',
    ]);

    $engine = app(PricingEngine::class);
    $visitor = $engine->resolve($course, LearnerType::Public);
    $member = $engine->resolve($course, LearnerType::Member);

    $this->assertTrue($visitor['is_free']);
    $this->assertSame(0.0, $visitor['amount']);
    $this->assertFalse($member['is_free']);
    $this->assertSame(25.0, $member['amount']);
  }

  public function test_member_only_course_blocks_visitor_enrollment(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Members Only Cat',
      'slug' => 'members-only-cat',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Members Only Course',
      'slug' => 'members-only-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'audience' => CourseAudience::MemberOnly,
      'is_free' => true,
    ]);

    $visitor = User::factory()->create();
    $permission = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $visitor->permissions()->syncWithoutDetaching([$permission->id]);

    Sanctum::actingAs($visitor);
    $this->postJson('/api/v1/public/courses/members-only-course/enroll')
      ->assertStatus(403);
  }

  public function test_learner_can_submit_assignment_and_admin_can_grade(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Assignments Cat',
      'slug' => 'assignments-cat',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Assignment Course',
      'slug' => 'assignment-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'audience' => CourseAudience::Both,
      'is_free' => true,
    ]);

    $learner = User::factory()->create();
    $permission = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $learner->permissions()->syncWithoutDetaching([$permission->id]);

    $enrollment = Enrollment::query()->create([
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Public,
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'progress_percent' => 0,
    ]);

    Sanctum::actingAs($this->admin);
    $created = $this->postJson('/api/v1/lms/assignments', [
      'course_id' => $course->uuid,
      'title' => 'Marketplace Essay',
      'type' => 'essay',
      'instructions' => 'Write a reflection.',
      'max_score' => 100,
      'pass_mark' => 70,
      'status' => 'published',
    ])->assertCreated();

    $assignmentUuid = $created->json('data.assignment.id');

    Sanctum::actingAs($learner);
    $submitted = $this->postJson("/api/v1/learner/assignments/{$assignmentUuid}/submit", [
      'enrollment_id' => $enrollment->uuid,
      'essay_body' => 'Kingdom professionals transform the marketplace.',
    ])->assertCreated();

    $submissionUuid = $submitted->json('data.submission.id');
    $this->assertSame('submitted', $submitted->json('data.submission.status'));

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/lms/assignment-submissions/{$submissionUuid}/grade", [
      'score' => 95,
      'teacher_comments' => 'Excellent work.',
    ])->assertOk()
      ->assertJsonPath('data.submission.status', 'passed');
  }
}
