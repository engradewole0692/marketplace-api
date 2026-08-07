<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\Question;
use Tests\Feature\Iam\IamTestCase;

final class LmsCourseCertificationTest extends IamTestCase
{
  public function test_certificate_requires_course_complete_and_assessment_pass(): void
  {
    $course = Course::query()->create([
      'title' => 'Cert Path',
      'slug' => 'cert-path',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'certificate_enabled' => true,
      'certificate_requires_assessment_pass' => true,
      'is_free' => true,
      'member_price' => 0,
      'public_price' => 0,
    ]);

    $module = $course->modules()->create([
      'title' => 'Module 1',
      'slug' => 'module-1',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Lesson 1',
      'slug' => 'lesson-1',
      'status' => 'published',
      'lesson_type' => 'video',
      'video_source' => 'none',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $question = Question::query()->create([
      'prompt' => '2+2?',
      'question_type' => QuestionType::MultipleChoice,
      'default_points' => 1,
      'correct_payload' => [],
    ]);
    $option = $question->options()->create([
      'label' => 'B',
      'body' => '4',
      'is_correct' => true,
      'sort_order' => 1,
    ]);
    $question->options()->create([
      'label' => 'A',
      'body' => '3',
      'is_correct' => false,
      'sort_order' => 0,
    ]);

    $assessment = Assessment::query()->create([
      'course_id' => $course->id,
      'lesson_id' => $lesson->id,
      'title' => 'Final Quiz',
      'slug' => 'final-quiz',
      'assessment_type' => AssessmentType::Quiz,
      'status' => AssessmentStatus::Published,
      'pass_mark' => 50,
      'show_immediate_result' => true,
      'max_attempts' => 3,
    ]);
    $assessment->questions()->attach($question->id, ['points' => 1, 'sort_order' => 1]);

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/cert-path/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'completed');

    $this->assertNull(
      CourseCertificate::query()->where('enrollment_id', $enrollment->id)->where('status', 'issued')->first(),
      'Certificate must not issue before assessment pass',
    );

    $take = $this->actingAs($learner)
      ->postJson('/api/v1/learner/assessments/'.$assessment->uuid.'/start', [
        'enrollment_id' => $enrollment->uuid,
      ])
      ->assertCreated()
      ->json('data');

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/attempts/'.$take['attempt']['id'].'/submit', [
        'answers' => [
          ['question_id' => $question->uuid, 'response' => ['selected_option_id' => $option->uuid]],
        ],
      ])
      ->assertOk()
      ->assertJsonPath('data.result.passed', true);

    $certificate = CourseCertificate::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('status', 'issued')
      ->first();

    $this->assertNotNull($certificate);
    $this->assertNotEmpty($certificate->certificate_number);
    $this->assertNotEmpty($certificate->verification_code);

    $this->getJson('/api/v1/public/certificates/verify/'.$certificate->verification_code)
      ->assertOk()
      ->assertJsonPath('data.certificate.type', 'course')
      ->assertJsonPath('data.certificate.certificate_number', $certificate->certificate_number);

    $this->getJson('/api/v1/public/courses/certificates/verify/'.$certificate->verification_code)
      ->assertOk()
      ->assertJsonPath('data.certificate.certificate_number', $certificate->certificate_number);
  }

  public function test_admin_can_manage_templates_and_list_issuances(): void
  {
    $templateId = $this->postJson('/api/v1/lms/certificate-templates', [
      'name' => 'Default LMS Cert',
      'html_body' => '<h1>{{name}}</h1><p>{{course}}</p>{{qr}}',
      'is_active' => true,
      'is_default' => true,
    ])->assertCreated()->json('data.template.id');

    $this->assertNotEmpty($templateId);

    $this->getJson('/api/v1/lms/certificate-templates')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $templateId);

    $this->getJson('/api/v1/lms/certificates')
      ->assertOk();
  }

  public function test_shared_pdf_engine_composes_html_with_placeholders(): void
  {
    $engine = app(\App\Services\Certificates\CertificatePdfEngine::class);
    $html = $engine->composeHtml(
      '<h1>{{name}}</h1><p>{{course}}</p>',
      [
        '{{name}}' => 'Ada',
        '{{course}}' => 'Discipleship',
        '{{verification_url}}' => 'https://example.test/certificate/ABC',
        '{{certificate_number}}' => 'MM-1',
      ],
      [],
    );

    $this->assertStringContainsString('Ada', $html);
    $this->assertStringContainsString('Discipleship', $html);
  }
}
