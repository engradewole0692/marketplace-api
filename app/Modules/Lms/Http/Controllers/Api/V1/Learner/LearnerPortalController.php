<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Enums\AuthGuardName;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Lms\Http\Resources\EnrollmentResource;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\Wishlist;
use App\Modules\Lms\Services\EnrollmentService;
use App\Modules\Lms\Services\ProgressService;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Support\Api\PaginatedResponseBuilder;
use App\Support\Iam\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

final class LearnerPortalController extends ApiController
{
  public function register(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'email' => ['required', 'email', 'max:255', 'unique:users,email'],
      'password' => ['required', 'confirmed', Password::defaults()],
    ]);

    $user = DB::transaction(function () use ($validated): User {
      $parts = preg_split('/\s+/', trim($validated['name']), 2) ?: [];
      $firstName = $parts[0] !== '' ? $parts[0] : 'Learner';
      $lastName = $parts[1] ?? '';

      $user = User::query()->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'display_name' => $validated['name'],
        'email' => $validated['email'],
        // Cast `hashed` hashes once — do not Hash::make here.
        'password' => $validated['password'],
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'locale' => 'en',
      ]);

      $this->ensureLearnerAccess($user);

      return $user->fresh(['roles', 'permissions']) ?? $user;
    });

    app(CommunicationLmsBridge::class)->notifyLearnerRegistered($user);

    return $this->responder->success(
      data: [
        'user' => [
          'id' => $user->uuid,
          'name' => $user->name,
          'email' => $user->email,
        ],
        'permissions' => ['learner.portal'],
      ],
      message: 'Learner account created. Please sign in.',
      status: 201,
    );
  }

  private function ensureLearnerAccess(User $user): void
  {
    $meta = collect(PermissionCatalog::all())->firstWhere('slug', 'learner.portal');

    $permission = Permission::query()->updateOrCreate(
      ['slug' => 'learner.portal'],
      [
        'name' => $meta['name'] ?? 'Learner Portal Access',
        'module' => $meta['module'] ?? 'learning',
        'group' => $meta['group'] ?? 'portal',
        'description' => $meta['description'] ?? 'Access the public learner portal.',
        'is_system' => true,
      ],
    );

    $role = Role::query()->updateOrCreate(
      ['slug' => 'learner'],
      [
        'name' => 'Learner',
        'guard_name' => AuthGuardName::Member->value,
        'description' => 'Public learner portal access for courses.',
        'is_system' => true,
      ],
    );

    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $user->roles()->syncWithoutDetaching([$role->id]);
    // Direct grant so portal login succeeds even if role pivots lag behind catalog changes.
    $user->permissions()->syncWithoutDetaching([$permission->id]);
  }

  public function dashboard(Request $request, EnrollmentService $enrollments): JsonResponse
  {
    $user = $request->user();
    $active = Enrollment::query()
      ->where('user_id', $user->id)
      ->whereIn('status', ['active', 'completed'])
      ->with(['course.coverMedia', 'certificate'])
      ->latest('enrolled_at')
      ->limit(12)
      ->get();

    return $this->responder->success(
      data: [
        'enrollments' => EnrollmentResource::collection($active),
        'stats' => [
          'active' => Enrollment::query()->where('user_id', $user->id)->where('status', 'active')->count(),
          'completed' => Enrollment::query()->where('user_id', $user->id)->where('status', 'completed')->count(),
          'wishlist' => Wishlist::query()->where('user_id', $user->id)->count(),
        ],
      ],
      message: 'Learner dashboard retrieved.',
    );
  }

  public function myCourses(Request $request, EnrollmentService $enrollments): JsonResponse
  {
    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $enrollments->paginateForUser($request->user(), $request->query()),
        EnrollmentResource::class,
      ),
      message: 'My courses retrieved.',
    );
  }

  public function progress(Request $request, ProgressService $progress): JsonResponse
  {
    $validated = $request->validate([
      'enrollment_id' => ['required', 'uuid'],
      'lesson_id' => ['required', 'uuid'],
      'progress_percent' => ['required', 'numeric', 'min:0', 'max:100'],
      'position_seconds' => ['nullable', 'integer', 'min:0'],
    ]);

    $enrollment = Enrollment::query()
      ->where('uuid', $validated['enrollment_id'])
      ->where('user_id', $request->user()->id)
      ->firstOrFail();

    $lesson = Lesson::query()->where('uuid', $validated['lesson_id'])->firstOrFail();
    $row = $progress->markLessonProgress(
      $enrollment,
      $lesson,
      (float) $validated['progress_percent'],
      $validated['position_seconds'] ?? null,
    );

    return $this->responder->success(
      data: [
        'progress' => [
          'id' => $row->uuid,
          'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
          'progress_percent' => (float) $row->progress_percent,
        ],
        'enrollment' => new EnrollmentResource($enrollment->fresh(['course', 'certificate'])),
      ],
      message: 'Progress updated.',
    );
  }

  public function wishlistIndex(Request $request): JsonResponse
  {
    $items = Wishlist::query()
      ->where('user_id', $request->user()->id)
      ->with(['course.coverMedia', 'course.instructors'])
      ->latest()
      ->paginate(25);

    return $this->responder->success(
      data: [
        'data' => $items->getCollection()->map(fn (Wishlist $w) => [
          'id' => $w->uuid,
          'course' => $w->course ? new \App\Modules\Lms\Http\Resources\CourseResource($w->course) : null,
        ]),
        'meta' => [
          'current_page' => $items->currentPage(),
          'last_page' => $items->lastPage(),
          'per_page' => $items->perPage(),
          'total' => $items->total(),
        ],
      ],
      message: 'Wishlist retrieved.',
    );
  }

  public function wishlistStore(Request $request): JsonResponse
  {
    $validated = $request->validate(['course_id' => ['required', 'uuid']]);
    $courseId = \App\Modules\Lms\Models\Course::query()->where('uuid', $validated['course_id'])->value('id');
    abort_unless($courseId, 404);

    $item = Wishlist::query()->firstOrCreate([
      'user_id' => $request->user()->id,
      'course_id' => $courseId,
    ]);

    return $this->responder->success(
      data: ['id' => $item->uuid],
      message: 'Added to wishlist.',
      status: 201,
    );
  }

  public function wishlistDestroy(Request $request, string $courseId): JsonResponse
  {
    $id = \App\Modules\Lms\Models\Course::query()->where('uuid', $courseId)->value('id');
    Wishlist::query()->where('user_id', $request->user()->id)->where('course_id', $id)->delete();

    return $this->responder->success(message: 'Removed from wishlist.');
  }
}
