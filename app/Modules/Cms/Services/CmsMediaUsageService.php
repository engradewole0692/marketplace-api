<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseDownload;
use App\Modules\Lms\Models\Instructor;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonResource;

final class CmsMediaUsageService implements ServiceContract
{
  /**
   * @return list<array{type: string, id: string, label: string}>
   */
  public function references(CmsMedia $media): array
  {
    $usages = [];

    $this->collect($usages, 'page', CmsPage::query()->where('hero_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'country', CmsCountry::query()->where('hero_media_id', $media->id)->get(['uuid', 'name']), 'name');
    $this->collect($usages, 'ministry', CmsMinistry::query()->where('hero_media_id', $media->id)->get(['uuid', 'name']), 'name');
    $this->collect($usages, 'leadership', CmsLeadershipProfile::query()->where('photo_media_id', $media->id)->get(['uuid', 'name']), 'name');
    $this->collect($usages, 'partner', CmsPartner::query()->where('logo_media_id', $media->id)->get(['uuid', 'name']), 'name');
    $this->collect($usages, 'testimonial', CmsTestimonial::query()->where('photo_media_id', $media->id)->orWhere('video_media_id', $media->id)->get(['uuid', 'author_name']), 'author_name');
    $this->collect($usages, 'catalog_item', CmsCatalogItem::query()->where('featured_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'seo', CmsSeo::query()->where('og_image_id', $media->id)->get(['uuid', 'path']), 'path');
    $this->collect($usages, 'lms_course_cover', Course::query()->where('cover_media_id', $media->id)->orWhere('trailer_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'lms_lesson_video', Lesson::query()->where('video_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'lms_course_download', CourseDownload::query()->where('file_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'lms_lesson_resource', LessonResource::query()->where('file_media_id', $media->id)->get(['uuid', 'title']), 'title');
    $this->collect($usages, 'lms_instructor', Instructor::query()->where('photo_media_id', $media->id)->get(['uuid', 'name']), 'name');

    $needles = array_values(array_filter([
      $media->uuid,
      $media->path,
      $media->url(),
      $media->thumbnailUrl(),
    ]));

    foreach (CmsPageSection::query()->get(['uuid', 'section_key', 'title', 'content', 'draft_content']) as $section) {
      $blob = json_encode([
        'content' => $section->content,
        'draft_content' => $section->draft_content,
      ], JSON_THROW_ON_ERROR);

      foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($blob, $needle)) {
          $usages[] = [
            'type' => 'page_section',
            'id' => (string) $section->uuid,
            'label' => (string) ($section->title ?: $section->section_key),
          ];
          break;
        }
      }
    }

    return $usages;
  }

  public function isInUse(CmsMedia $media): bool
  {
    return $this->references($media) !== [];
  }

  /**
   * @param  list<array{type: string, id: string, label: string}>  $usages
   * @param  \Illuminate\Support\Collection<int, object>  $records
   */
  private function collect(array &$usages, string $type, $records, string $labelKey): void
  {
    foreach ($records as $record) {
      $usages[] = [
        'type' => $type,
        'id' => (string) $record->uuid,
        'label' => (string) ($record->{$labelKey} ?? $type),
      ];
    }
  }
}
