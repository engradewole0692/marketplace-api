<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\PricingEngine;
use Tests\Feature\Iam\IamTestCase;

final class LmsFoundationTest extends IamTestCase
{
  public function test_admin_can_create_publish_course_with_curriculum(): void
  {
    $category = $this->postJson('/api/v1/lms/categories', [
      'name' => 'Leadership',
      'slug' => 'leadership',
    ])->assertCreated()->json('data.category.id');

    $instructor = $this->postJson('/api/v1/lms/instructors', [
      'name' => 'Dr Teacher',
      'slug' => 'dr-teacher',
      'title' => 'Lead Instructor',
    ])->assertCreated()->json('data.instructor.id');

    $courseId = $this->postJson('/api/v1/lms/courses', [
      'title' => 'Kingdom Foundations',
      'slug' => 'kingdom-foundations',
      'summary' => 'Core discipleship path',
      'category_id' => $category,
      'member_price' => 25,
      'public_price' => 49,
      'instructors' => [['id' => $instructor, 'is_primary' => true]],
    ])->assertCreated()->json('data.course.id');

    $moduleId = $this->postJson("/api/v1/lms/courses/{$courseId}/modules", [
      'title' => 'Module 1',
      'status' => 'published',
    ])->assertCreated()->json('data.module.id');

    $this->postJson("/api/v1/lms/modules/{$moduleId}/lessons", [
      'title' => 'Welcome Lesson',
      'status' => 'published',
      'lesson_type' => 'video',
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'is_mandatory' => true,
    ])->assertCreated();

    $this->postJson("/api/v1/lms/courses/{$courseId}/publish")
      ->assertOk()
      ->assertJsonPath('data.course.status', 'published');

    $this->getJson('/api/v1/public/courses')
      ->assertOk()
      ->assertJsonPath('data.data.0.slug', 'kingdom-foundations');

    $this->getJson('/api/v1/public/courses/kingdom-foundations')
      ->assertOk()
      ->assertJsonPath('data.course.title', 'Kingdom Foundations');
  }

  public function test_learner_can_enroll_and_complete_for_certificate(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Faith',
      'slug' => 'faith',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Public Path',
      'slug' => 'public-path',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);

    $module = $course->modules()->create([
      'title' => 'Intro',
      'slug' => 'intro',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Lesson A',
      'slug' => 'lesson-a',
      'status' => 'published',
      'lesson_type' => 'text',
      'video_source' => 'none',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/public-path/enroll')
      ->assertCreated()
      ->assertJsonPath('data.enrollment.status', 'active');

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'completed');

    $enrollment->refresh();
    $this->assertNotNull($enrollment->certificate);
    $this->assertSame('issued', $enrollment->certificate->status->value);

    $this->getJson('/api/v1/public/courses/certificates/verify/'.$enrollment->certificate->verification_code)
      ->assertOk()
      ->assertJsonPath('data.certificate.certificate_number', $enrollment->certificate->certificate_number);
  }

  public function test_pricing_engine_resolves_member_public_and_coupon(): void
  {
    $course = Course::query()->create([
      'title' => 'Priced Course',
      'slug' => 'priced-course',
      'status' => CourseStatus::Published,
      'member_price' => 20,
      'public_price' => 40,
      'currency' => 'USD',
    ]);

    CourseCoupon::query()->create([
      'code' => 'SAVE10',
      'name' => 'Save 10%',
      'discount_type' => 'percent',
      'discount_value' => 10,
      'applies_to' => 'all',
      'status' => 'active',
    ]);

    $engine = app(PricingEngine::class);

    $member = $engine->resolve($course, LearnerType::Member);
    $this->assertSame(20.0, $member['amount']);

    $public = $engine->resolve($course, LearnerType::Public);
    $this->assertSame(40.0, $public['amount']);

    $couponed = $engine->resolve($course, LearnerType::Public, 'SAVE10');
    $this->assertTrue($couponed['coupon_applied']);
    $this->assertSame(36.0, $couponed['amount']);
  }

  public function test_learner_can_register(): void
  {
    $this->postJson('/api/v1/learner/register', [
      'name' => 'Public Learner',
      'email' => 'learner@example.com',
      'password' => 'Password1!',
      'password_confirmation' => 'Password1!',
    ])->assertCreated()
      ->assertJsonPath('data.permissions.0', 'learner.portal');

    $user = \App\Models\User::query()->where('email', 'learner@example.com')->firstOrFail();
    $this->assertTrue($user->hasPermission('learner.portal'));
    $this->assertTrue($user->roles->contains(fn ($role) => $role->slug === 'learner'));

    // Authenticate as the new learner (replaces IamTestCase Sanctum admin actor).
    \Laravel\Sanctum\Sanctum::actingAs($user);

    $me = $this->getJson('/api/v1/auth/me')->assertOk();
    $permissions = $me->json('data.permissions') ?? [];
    $this->assertContains('learner.portal', $permissions);
  }

  public function test_admin_catalog_settings_announcements_and_resources(): void
  {
    $this->getJson('/api/v1/lms/settings')
      ->assertOk()
      ->assertJsonPath('data.settings.default_currency', 'USD');

    $this->putJson('/api/v1/lms/settings', [
      'certificate_prefix' => 'KC-LMS',
      'featured_limit' => 8,
    ])->assertOk()
      ->assertJsonPath('data.settings.certificate_prefix', 'KC-LMS');

    $this->postJson('/api/v1/lms/announcements', [
      'title' => 'Welcome learners',
      'body' => 'New courses are live.',
      'status' => 'published',
    ])->assertCreated();

    $this->getJson('/api/v1/lms/announcements')
      ->assertOk()
      ->assertJsonPath('data.data.0.title', 'Welcome learners');

    $course = Course::query()->create([
      'title' => 'Resource Course',
      'slug' => 'resource-course',
      'status' => CourseStatus::Draft,
    ]);

    $this->postJson('/api/v1/lms/resources/download', [
      'course_id' => $course->uuid,
      'title' => 'Workbook PDF',
      'external_url' => 'https://example.com/workbook.pdf',
      'is_public' => true,
    ])->assertCreated();

    $this->getJson('/api/v1/lms/resources')
      ->assertOk()
      ->assertJsonFragment(['title' => 'Workbook PDF']);

    $this->getJson('/api/v1/lms/students')->assertOk();
    $this->postJson('/api/v1/lms/coupons', [
      'code' => 'LAUNCH20',
      'name' => 'Launch',
      'discount_type' => 'percent',
      'discount_value' => 20,
    ])->assertCreated();
  }
}
