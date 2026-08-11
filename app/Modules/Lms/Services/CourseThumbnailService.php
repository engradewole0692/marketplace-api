<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Models\Course;

/**
 * Resolves the best public display thumbnail for a course:
 * custom upload → import URL → cover image → YouTube trailer → first lesson YouTube.
 */
final class CourseThumbnailService implements ServiceContract
{
  public function __construct(
    private readonly YoutubeMetadataService $youtube,
  ) {}

  public function resolve(Course $course): ?string
  {
    if ($course->relationLoaded('thumbnailMedia')) {
      $url = $course->thumbnailMedia?->url();
      if (is_string($url) && $url !== '') {
        return $url;
      }
    }

    $importUrl = $course->metadata['import']['thumbnail_url'] ?? null;
    if (is_string($importUrl) && $importUrl !== '') {
      return $importUrl;
    }

    if ($course->relationLoaded('coverMedia')) {
      $url = $course->coverMedia?->url();
      if (is_string($url) && $url !== '') {
        return $url;
      }
    }

    $trailerId = $this->youtube->extractVideoId((string) ($course->trailer_youtube_url ?? ''));
    if ($trailerId !== null && $trailerId !== '') {
      return 'https://i.ytimg.com/vi/'.$trailerId.'/hqdefault.jpg';
    }

    if ($course->relationLoaded('modules')) {
      foreach ($course->modules as $module) {
        if (! $module->relationLoaded('lessons')) {
          continue;
        }
        foreach ($module->lessons as $lesson) {
          $lessonId = (string) ($lesson->youtube_video_id ?? '');
          if ($lessonId !== '') {
            return 'https://i.ytimg.com/vi/'.$lessonId.'/hqdefault.jpg';
          }
          $fromUrl = $this->youtube->extractVideoId((string) ($lesson->youtube_url ?? ''));
          if ($fromUrl !== null && $fromUrl !== '') {
            return 'https://i.ytimg.com/vi/'.$fromUrl.'/hqdefault.jpg';
          }
        }
      }
    }

    return null;
  }
}
