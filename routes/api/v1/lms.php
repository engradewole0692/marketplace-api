<?php

declare(strict_types=1);

use App\Modules\Lms\Http\Controllers\Api\V1\Admin\AssessmentAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\AssignmentAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CategoryAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CertificateAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CourseAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CourseImportAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CourseCommerceAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\CurriculumAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\EnrollmentAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\InstructorAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\LmsCatalogAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\LmsReportsAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\ProgressAnalyticsController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\ProgramModuleAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\SchoolAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\SchoolCommerceAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Admin\SchoolEnrollmentAdminController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerAssessmentController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerAssignmentController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerCommerceController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerExperienceController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerPortalController;
use App\Modules\Lms\Http\Controllers\Api\V1\Learner\LearnerWorkspaceController;
use App\Modules\Lms\Http\Controllers\Api\V1\Public\PublicCourseController;
use App\Modules\Lms\Http\Controllers\Api\V1\Public\PublicFreeCategoryController;
use App\Modules\Lms\Http\Controllers\Api\V1\Public\PublicSchoolController;
use App\Http\Controllers\Api\V1\Public\PublicCertificateVerifyController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')
  ->name('public.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/certificates/verify/{code}', PublicCertificateVerifyController::class)
      ->name('certificates.verify');
  });

Route::prefix('public/courses')
  ->name('public.courses.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [PublicCourseController::class, 'index'])->name('index');
    Route::get('/certificates/verify/{code}', [PublicCourseController::class, 'verifyCertificate'])->name('certificates.verify');
    Route::get('/{slug}', [PublicCourseController::class, 'show'])->name('show');
    Route::post('/{slug}/enroll', [PublicCourseController::class, 'enroll'])
      ->middleware(['auth:sanctum', 'throttle:20,1'])
      ->name('enroll');
  });

Route::prefix('public/free-categories')
  ->name('public.free-categories.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [PublicFreeCategoryController::class, 'index'])->name('index');
    Route::get('/{slug}', [PublicFreeCategoryController::class, 'show'])->name('show');
  });

Route::prefix('public/schools')
  ->name('public.schools.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [PublicSchoolController::class, 'index'])->name('index');
    Route::get('/{slug}', [PublicSchoolController::class, 'show'])->name('show');
    Route::post('/{slug}/enroll', [PublicSchoolController::class, 'enroll'])
      ->middleware(['auth:sanctum', 'throttle:20,1'])
      ->name('enroll');
  });

Route::prefix('learner')
  ->name('learner.')
  ->middleware('throttle:30,1')
  ->group(function (): void {
    Route::post('/register', [LearnerPortalController::class, 'register'])->name('register');

    Route::middleware('auth:sanctum')->group(function (): void {
      Route::get('/dashboard', [LearnerPortalController::class, 'dashboard'])->name('dashboard');
      Route::get('/experience', [LearnerExperienceController::class, 'experience'])->name('experience');
      Route::get('/courses', [LearnerPortalController::class, 'myCourses'])->name('courses');
      Route::get('/workspace/prayer-requests', [LearnerWorkspaceController::class, 'prayerRequests'])->name('workspace.prayer');
      Route::get('/workspace/counselling-requests', [LearnerWorkspaceController::class, 'counsellingRequests'])->name('workspace.counselling');
      Route::get('/workspace/notifications', [LearnerWorkspaceController::class, 'notifications'])->name('workspace.notifications');
      Route::get('/assignments', [LearnerAssignmentController::class, 'index'])->name('assignments.index');
      Route::post('/assignments/{assignment}/submit', [LearnerAssignmentController::class, 'submit'])->name('assignments.submit');
      Route::get('/player/{enrollmentId}/{lessonId}', [LearnerExperienceController::class, 'player'])->name('player');
      Route::get('/enrollments/{enrollmentId}/curriculum', [LearnerExperienceController::class, 'curriculum'])->name('curriculum');
      Route::get('/schools/{school}/curriculum', [LearnerExperienceController::class, 'schoolCurriculum'])->name('schools.curriculum');
      Route::post('/progress', [LearnerExperienceController::class, 'progress'])->name('progress');
      Route::get('/bookmarks', [LearnerExperienceController::class, 'bookmarks'])->name('bookmarks.index');
      Route::post('/bookmarks', [LearnerExperienceController::class, 'storeBookmark'])->name('bookmarks.store');
      Route::delete('/bookmarks/{bookmarkId}', [LearnerExperienceController::class, 'destroyBookmark'])->name('bookmarks.destroy');
      Route::get('/notes', [LearnerExperienceController::class, 'notes'])->name('notes.index');
      Route::post('/notes', [LearnerExperienceController::class, 'storeNote'])->name('notes.store');
      Route::put('/notes/{note}', [LearnerExperienceController::class, 'updateNote'])->name('notes.update');
      Route::delete('/notes/{note}', [LearnerExperienceController::class, 'destroyNote'])->name('notes.destroy');
      Route::get('/downloads', [LearnerExperienceController::class, 'downloads'])->name('downloads');
      Route::get('/certificates', [LearnerExperienceController::class, 'certificates'])->name('certificates');
      Route::get('/assessments/lessons/{lessonId}', [LearnerAssessmentController::class, 'forLesson'])->name('assessments.lesson');
      Route::post('/assessments/{assessment}/start', [LearnerAssessmentController::class, 'start'])->name('assessments.start');
      Route::post('/attempts/{attempt}/submit', [LearnerAssessmentController::class, 'submit'])->name('attempts.submit');
      Route::get('/attempts/{attempt}/result', [LearnerAssessmentController::class, 'result'])->name('attempts.result');
      Route::get('/transcript', [LearnerAssessmentController::class, 'transcript'])->name('transcript');
      Route::get('/orders', [LearnerCommerceController::class, 'myOrders'])->name('orders');
      Route::post('/enrollments/{enrollment}/checkout', [LearnerCommerceController::class, 'checkout'])->name('checkout');
      Route::post('/school-enrollments/{enrollment}/checkout', [LearnerCommerceController::class, 'checkoutSchool'])->name('school-checkout');
      Route::get('/wishlist', [LearnerPortalController::class, 'wishlistIndex'])->name('wishlist.index');
      Route::post('/wishlist', [LearnerPortalController::class, 'wishlistStore'])->name('wishlist.store');
      Route::delete('/wishlist/{courseId}', [LearnerPortalController::class, 'wishlistDestroy'])->name('wishlist.destroy');
    });
  });

Route::middleware(['auth:sanctum'])
  ->prefix('lms')
  ->name('lms.')
  ->group(function (): void {
    Route::get('/reports', [LmsReportsAdminController::class, 'dashboard'])->name('reports');
    Route::get('/reports/{type}', [LmsReportsAdminController::class, 'show'])->name('reports.show');
    Route::get('/reports/{type}/export', [LmsReportsAdminController::class, 'export'])->name('reports.export');
    Route::get('/progress-analytics', [ProgressAnalyticsController::class, 'dashboard'])->name('progress.analytics');

    Route::get('/questions', [AssessmentAdminController::class, 'questions'])->name('questions.index');
    Route::post('/questions', [AssessmentAdminController::class, 'storeQuestion'])->name('questions.store');
    Route::get('/questions/import/template', [AssessmentAdminController::class, 'questionImportTemplate'])->name('questions.import.template');
    Route::post('/questions/import', [AssessmentAdminController::class, 'importQuestions'])->name('questions.import');
    Route::put('/questions/{question}', [AssessmentAdminController::class, 'updateQuestion'])->name('questions.update');
    Route::delete('/questions/{question}', [AssessmentAdminController::class, 'destroyQuestion'])->name('questions.destroy');

    Route::get('/assessments', [AssessmentAdminController::class, 'index'])->name('assessments.index');
    Route::post('/assessments', [AssessmentAdminController::class, 'store'])->name('assessments.store');
    Route::get('/assessments/{assessment}', [AssessmentAdminController::class, 'show'])->name('assessments.show');
    Route::put('/assessments/{assessment}', [AssessmentAdminController::class, 'update'])->name('assessments.update');
    Route::delete('/assessments/{assessment}', [AssessmentAdminController::class, 'destroy'])->name('assessments.destroy');
    Route::get('/grading-queue', [AssessmentAdminController::class, 'gradingQueue'])->name('grading.queue');
    Route::post('/attempts/{attempt}/grade', [AssessmentAdminController::class, 'gradeAttempt'])->name('attempts.grade');

    Route::get('/certificates', [CertificateAdminController::class, 'index'])->name('certificates.index');
    Route::post('/certificates/issue', [CertificateAdminController::class, 'issue'])->name('certificates.issue');
    Route::post('/certificates/{certificate}/reissue', [CertificateAdminController::class, 'reissue'])->name('certificates.reissue');
    Route::get('/certificate-templates', [CertificateAdminController::class, 'templates'])->name('certificate-templates.index');
    Route::post('/certificate-templates', [CertificateAdminController::class, 'storeTemplate'])->name('certificate-templates.store');
    Route::put('/certificate-templates/{template}', [CertificateAdminController::class, 'updateTemplate'])->name('certificate-templates.update');
    Route::delete('/certificate-templates/{template}', [CertificateAdminController::class, 'destroyTemplate'])->name('certificate-templates.destroy');

    Route::get('/orders', [CourseCommerceAdminController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [CourseCommerceAdminController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/confirm', [CourseCommerceAdminController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/reject', [CourseCommerceAdminController::class, 'reject'])->name('orders.reject');
    Route::post('/orders/{order}/refund', [CourseCommerceAdminController::class, 'refund'])->name('orders.refund');

    Route::get('/school-orders', [SchoolCommerceAdminController::class, 'index'])->name('school-orders.index');
    Route::get('/school-orders/{order}', [SchoolCommerceAdminController::class, 'show'])->name('school-orders.show');
    Route::post('/school-orders/{order}/confirm', [SchoolCommerceAdminController::class, 'confirm'])->name('school-orders.confirm');
    Route::post('/school-orders/{order}/reject', [SchoolCommerceAdminController::class, 'reject'])->name('school-orders.reject');

    Route::get('/settings', [LmsCatalogAdminController::class, 'settings'])->name('settings.show');
    Route::put('/settings', [LmsCatalogAdminController::class, 'updateSettings'])->name('settings.update');

    Route::get('/students', [LmsCatalogAdminController::class, 'students'])->name('students.index');
    Route::get('/students/{user}', [LmsCatalogAdminController::class, 'showStudent'])->name('students.show');
    Route::get('/announcements', [LmsCatalogAdminController::class, 'announcements'])->name('announcements.index');
    Route::post('/announcements', [LmsCatalogAdminController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::put('/announcements/{announcement}', [LmsCatalogAdminController::class, 'updateAnnouncement'])->name('announcements.update');
    Route::delete('/announcements/{announcement}', [LmsCatalogAdminController::class, 'destroyAnnouncement'])->name('announcements.destroy');

    Route::get('/resources', [LmsCatalogAdminController::class, 'resources'])->name('resources.index');
    Route::post('/resources/lesson', [LmsCatalogAdminController::class, 'storeLessonResource'])->name('resources.lesson.store');
    Route::post('/resources/download', [LmsCatalogAdminController::class, 'storeCourseDownload'])->name('resources.download.store');

    Route::get('/coupons', [LmsCatalogAdminController::class, 'coupons'])->name('coupons.index');
    Route::post('/coupons', [LmsCatalogAdminController::class, 'storeCoupon'])->name('coupons.store');
    Route::put('/coupons/{coupon}', [LmsCatalogAdminController::class, 'updateCoupon'])->name('coupons.update');
    Route::delete('/coupons/{coupon}', [LmsCatalogAdminController::class, 'destroyCoupon'])->name('coupons.destroy');

    Route::get('/assignments', [AssignmentAdminController::class, 'index'])->name('assignments.index');
    Route::post('/assignments', [AssignmentAdminController::class, 'store'])->name('assignments.store');
    Route::put('/assignments/{assignment}', [AssignmentAdminController::class, 'update'])->name('assignments.update');
    Route::delete('/assignments/{assignment}', [AssignmentAdminController::class, 'destroy'])->name('assignments.destroy');
    Route::get('/assignment-grading-queue', [AssignmentAdminController::class, 'gradingQueue'])->name('assignments.grading');
    Route::post('/assignment-submissions/{submission}/grade', [AssignmentAdminController::class, 'grade'])->name('assignments.grade');

    Route::get('/categories', [CategoryAdminController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryAdminController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [CategoryAdminController::class, 'update'])->name('categories.update');
    Route::get('/categories/{category}/curriculum-integrity', [CategoryAdminController::class, 'curriculumIntegrity'])->name('categories.curriculum-integrity');
    Route::delete('/categories/{category}', [CategoryAdminController::class, 'destroy'])->name('categories.destroy');

    Route::get('/instructors', [InstructorAdminController::class, 'index'])->name('instructors.index');
    Route::post('/instructors', [InstructorAdminController::class, 'store'])->name('instructors.store');
    Route::put('/instructors/{instructor}', [InstructorAdminController::class, 'update'])->name('instructors.update');
    Route::delete('/instructors/{instructor}', [InstructorAdminController::class, 'destroy'])->name('instructors.destroy');

    Route::get('/enrollments', [CurriculumAdminController::class, 'enrollments'])->name('enrollments.index');
    Route::post('/enrollments', [EnrollmentAdminController::class, 'store'])->name('enrollments.store');
    Route::post('/enrollments/{enrollment}/cancel', [EnrollmentAdminController::class, 'cancel'])->name('enrollments.cancel');
    Route::post('/enrollments/{enrollment}/lock', [EnrollmentAdminController::class, 'lock'])->name('enrollments.lock');
    Route::post('/enrollments/{enrollment}/restart', [EnrollmentAdminController::class, 'restart'])->name('enrollments.restart');

    Route::get('/schools', [SchoolAdminController::class, 'index'])->name('schools.index');
    Route::post('/schools', [SchoolAdminController::class, 'store'])->name('schools.store');
    Route::get('/schools/{school}', [SchoolAdminController::class, 'show'])->name('schools.show');
    Route::get('/schools/{school}/curriculum-integrity', [SchoolAdminController::class, 'curriculumIntegrity'])->name('schools.curriculum-integrity');
    Route::put('/schools/{school}', [SchoolAdminController::class, 'update'])->name('schools.update');
    Route::post('/schools/{school}/publish', [SchoolAdminController::class, 'publish'])->name('schools.publish');
    Route::post('/schools/{school}/unpublish', [SchoolAdminController::class, 'unpublish'])->name('schools.unpublish');
    Route::post('/schools/{school}/archive', [SchoolAdminController::class, 'archive'])->name('schools.archive');
    Route::delete('/schools/{school}', [SchoolAdminController::class, 'destroy'])->name('schools.destroy');

    Route::get('/school-enrollments', [SchoolEnrollmentAdminController::class, 'index'])->name('school-enrollments.index');
    Route::post('/school-enrollments', [SchoolEnrollmentAdminController::class, 'store'])->name('school-enrollments.store');
    Route::post('/school-enrollments/{enrollment}/cancel', [SchoolEnrollmentAdminController::class, 'cancel'])->name('school-enrollments.cancel');
    Route::post('/school-enrollments/{enrollment}/activate', [SchoolEnrollmentAdminController::class, 'activate'])->name('school-enrollments.activate');

    Route::get('/schools/{school}/program-modules', [ProgramModuleAdminController::class, 'indexForSchool'])->name('schools.program-modules.index');
    Route::post('/schools/{school}/program-modules', [ProgramModuleAdminController::class, 'storeForSchool'])->name('schools.program-modules.store');
    Route::get('/categories/{category}/program-modules', [ProgramModuleAdminController::class, 'indexForCategory'])->name('categories.program-modules.index');
    Route::post('/categories/{category}/program-modules', [ProgramModuleAdminController::class, 'storeForCategory'])->name('categories.program-modules.store');
    Route::put('/program-modules/{programModule}', [ProgramModuleAdminController::class, 'update'])->name('program-modules.update');
    Route::post('/program-modules/{programModule}/assign-course', [ProgramModuleAdminController::class, 'assignCourse'])->name('program-modules.assign-course');
    Route::post('/program-modules/{programModule}/courses/{course}/unassign', [ProgramModuleAdminController::class, 'unassignCourse'])->name('program-modules.unassign-course');
    Route::delete('/program-modules/{programModule}', [ProgramModuleAdminController::class, 'destroy'])->name('program-modules.destroy');
    Route::post('/schools/{school}/program-modules/reorder', [ProgramModuleAdminController::class, 'reorderSchoolModules'])->name('schools.program-modules.reorder');
    Route::post('/categories/{category}/program-modules/reorder', [ProgramModuleAdminController::class, 'reorderCategoryModules'])->name('categories.program-modules.reorder');
    Route::post('/program-modules/{programModule}/courses/reorder', [ProgramModuleAdminController::class, 'reorderCourses'])->name('program-modules.courses.reorder');

    Route::get('/courses', [CourseAdminController::class, 'index'])->name('courses.index');
    Route::post('/courses', [CourseAdminController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}', [CourseAdminController::class, 'show'])->name('courses.show');
    Route::put('/courses/{course}', [CourseAdminController::class, 'update'])->name('courses.update');
    Route::post('/courses/{course}/publish', [CourseAdminController::class, 'publish'])->name('courses.publish');
    Route::post('/courses/{course}/unpublish', [CourseAdminController::class, 'unpublish'])->name('courses.unpublish');
    Route::post('/courses/{course}/archive', [CourseAdminController::class, 'archive'])->name('courses.archive');
    Route::post('/courses/{course}/duplicate', [CourseAdminController::class, 'duplicate'])->name('courses.duplicate');
    Route::post('/courses/{course}/clone', [CourseAdminController::class, 'clone'])->name('courses.clone');
    Route::post('/courses/{course}/schedule', [CourseAdminController::class, 'schedule'])->name('courses.schedule');
    Route::delete('/courses/{course}', [CourseAdminController::class, 'destroy'])->name('courses.destroy');

    Route::post('/youtube/resolve', [CourseAdminController::class, 'resolveYoutube'])->name('youtube.resolve');
    Route::get('/import/schema', [CourseAdminController::class, 'importSchema'])->name('import.schema');
    Route::post('/import/dry-run', [CourseAdminController::class, 'importDryRun'])->name('import.dry-run');
    Route::post('/import/run', [CourseAdminController::class, 'importRun'])->name('import.run');
    Route::get('/import/verify', [CourseAdminController::class, 'importVerify'])->name('import.verify');
    Route::get('/import/prayer-training/schema', [CourseAdminController::class, 'importPrayerTrainingSchema'])->name('import.prayer-training.schema');
    Route::post('/import/prayer-training/dry-run', [CourseAdminController::class, 'importPrayerTrainingDryRun'])->name('import.prayer-training.dry-run');
    Route::post('/import/prayer-training/run', [CourseAdminController::class, 'importPrayerTrainingRun'])->name('import.prayer-training.run');

    Route::get('/import/courses/schema', [CourseImportAdminController::class, 'schema'])->name('import.courses.schema');
    Route::get('/import/courses/template', [CourseImportAdminController::class, 'template'])->name('import.courses.template');
    Route::post('/import/courses/dry-run', [CourseImportAdminController::class, 'dryRun'])->name('import.courses.dry-run');
    Route::post('/import/courses/run', [CourseImportAdminController::class, 'run'])->name('import.courses.run');
    Route::get('/import/courses/history', [CourseImportAdminController::class, 'index'])->name('import.courses.history');
    Route::get('/import/courses/history/{courseImport}', [CourseImportAdminController::class, 'show'])->name('import.courses.history.show');

    Route::post('/courses/{course}/modules', [CurriculumAdminController::class, 'storeModule'])->name('modules.store');
    Route::post('/courses/{course}/modules/reorder', [CurriculumAdminController::class, 'reorderModules'])->name('modules.reorder');
    Route::put('/modules/{module}', [CurriculumAdminController::class, 'updateModule'])->name('modules.update');
    Route::post('/modules/{module}/duplicate', [CurriculumAdminController::class, 'duplicateModule'])->name('modules.duplicate');
    Route::delete('/modules/{module}', [CurriculumAdminController::class, 'destroyModule'])->name('modules.destroy');

    Route::post('/modules/{module}/lessons', [CurriculumAdminController::class, 'storeLesson'])->name('lessons.store');
    Route::post('/modules/{module}/lessons/reorder', [CurriculumAdminController::class, 'reorderLessons'])->name('lessons.reorder');
    Route::put('/lessons/{lesson}', [CurriculumAdminController::class, 'updateLesson'])->name('lessons.update');
    Route::post('/lessons/{lesson}/duplicate', [CurriculumAdminController::class, 'duplicateLesson'])->name('lessons.duplicate');
    Route::delete('/lessons/{lesson}', [CurriculumAdminController::class, 'destroyLesson'])->name('lessons.destroy');
  });
