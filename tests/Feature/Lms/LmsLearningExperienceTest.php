<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonNote;
use Tests\Feature\Iam\IamTestCase;

final class LmsLearningExperienceTest extends IamTestCase
{
  public function test_learner_experience_player_notes_bookmarks_and_progress(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Discipleship',
      'slug' => 'discipleship',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Experience Course',
      'slug' => 'experience-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);

    $module = $course->modules()->create([
      'title' => 'Module One',
      'slug' => 'module-one',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Video Lesson',
      'slug' => 'video-lesson',
      'status' => 'published',
      'lesson_type' => LessonType::Video,
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $assignment = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Write Reflection',
      'slug' => 'write-reflection',
      'status' => 'published',
      'lesson_type' => LessonType::Assignment,
      'video_source' => 'none',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 2,
    ]);

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/experience-course/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk()
      ->assertJsonPath('data.stats.active', 1)
      ->assertJsonPath('data.assignments.0.title', 'Write Reflection');

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lesson->uuid}")
      ->assertOk()
      ->assertJsonPath('data.lesson.title', 'Video Lesson')
      ->assertJsonPath('data.progress.last_position_seconds', 0);

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 40,
        'position_seconds' => 120,
        'time_spent_delta_seconds' => 30,
      ])
      ->assertOk()
      ->assertJsonPath('data.progress.last_position_seconds', 120)
      ->assertJsonPath('data.progress.time_spent_seconds', 30);

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/bookmarks', [
        'lesson_id' => $lesson->uuid,
        'label' => 'Key moment',
        'position_seconds' => 120,
      ])
      ->assertCreated();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/notes', [
        'lesson_id' => $lesson->uuid,
        'enrollment_id' => $enrollment->uuid,
        'body' => 'Remember this teaching.',
        'position_seconds' => 120,
      ])
      ->assertCreated();

    $this->assertDatabaseHas('lms_lesson_notes', [
      'user_id' => $learner->id,
      'lesson_id' => $lesson->id,
    ]);

    $this->actingAs($learner)
      ->getJson('/api/v1/learner/notes?lesson_id='.$lesson->uuid)
      ->assertOk()
      ->assertJsonPath('data.data.0.body', 'Remember this teaching.');

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
        'position_seconds' => 300,
        'time_spent_delta_seconds' => 20,
      ])
      ->assertOk();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $assignment->uuid,
        'progress_percent' => 100,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'completed');

    $this->actingAs($learner)
      ->getJson('/api/v1/learner/certificates')
      ->assertOk()
      ->assertJsonCount(1, 'data.data');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/lms/progress-analytics')
      ->assertOk()
      ->assertJsonStructure([
        'data' => [
          'summary' => ['completion_rate', 'avg_progress', 'engagement_score'],
          'course_progress',
          'student_progress',
        ],
      ]);

    $this->assertTrue(LessonNote::query()->where('user_id', $learner->id)->exists());
  }

  public function test_mark_complete_advances_enrollment_when_lessons_are_still_draft(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Draft Track',
      'slug' => 'draft-track',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Draft Curriculum Course',
      'slug' => 'draft-curriculum-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);

    $module = $course->modules()->create([
      'title' => 'Draft Module',
      'slug' => 'draft-module',
      'status' => 'draft',
      'sort_order' => 1,
    ]);

    $lessonA = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Draft Lesson A',
      'slug' => 'draft-lesson-a',
      'status' => 'draft',
      'lesson_type' => LessonType::Video,
      'video_source' => 'none',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $lessonB = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Draft Lesson B',
      'slug' => 'draft-lesson-b',
      'status' => 'draft',
      'lesson_type' => LessonType::Text,
      'video_source' => 'none',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 2,
    ]);

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/draft-curriculum-course/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lessonA->uuid,
        'progress_percent' => 100,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.progress_percent', 50);

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lessonB->uuid,
        'progress_percent' => 100,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.progress_percent', 100)
      ->assertJsonPath('data.enrollment.status', 'completed');
  }
}
