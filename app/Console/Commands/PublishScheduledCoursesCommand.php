<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\CourseService;
use Illuminate\Console\Command;

final class PublishScheduledCoursesCommand extends Command
{
  protected $signature = 'lms:publish-scheduled';

  protected $description = 'Publish courses whose scheduled_publish_at has elapsed (homepage/portal sync via status).';

  public function handle(CourseService $courses): int
  {
    $due = Course::query()
      ->whereNotNull('scheduled_publish_at')
      ->where('scheduled_publish_at', '<=', now())
      ->whereIn('status', [CourseStatus::Draft->value, CourseStatus::ComingSoon->value, CourseStatus::Hidden->value])
      ->get();

    if ($due->isEmpty()) {
      $this->info('No scheduled courses due.');

      return self::SUCCESS;
    }

    $actor = User::query()->orderBy('id')->first();
    if (! $actor) {
      $this->error('No user available to attribute scheduled publish.');

      return self::FAILURE;
    }

    foreach ($due as $course) {
      $courses->publish($course, $actor);
      $course->forceFill(['scheduled_publish_at' => null])->save();
      $this->line('Published: '.$course->slug);
    }

    $this->info($due->count().' course(s) published.');

    return self::SUCCESS;
  }
}
