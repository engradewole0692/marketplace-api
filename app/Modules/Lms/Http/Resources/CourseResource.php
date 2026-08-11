<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use App\Modules\Lms\Services\CourseThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\Course */
final class CourseResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'course_code' => $this->course_code,
      'title' => $this->title,
      'slug' => $this->slug,
      'subtitle' => $this->subtitle,
      'summary' => $this->summary,
      'description' => $this->description,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'access_scope' => $this->access_scope instanceof \BackedEnum ? $this->access_scope->value : ($this->access_scope ?? 'general'),
      'audience' => $this->audience instanceof \BackedEnum ? $this->audience->value : ($this->audience ?? 'both'),
      'difficulty' => $this->difficulty,
      'is_featured' => (bool) $this->is_featured,
      'is_popular' => (bool) $this->is_popular,
      'is_recommended' => (bool) $this->is_recommended,
      'sort_order' => (int) ($this->sort_order ?? 0),
      'is_free' => (bool) $this->is_free,
      'visitor_free' => (bool) ($this->visitor_free ?? false),
      'member_free' => (bool) ($this->member_free ?? false),
      'certificate_enabled' => (bool) ($this->certificate_enabled ?? true),
      'certificate_requires_assessment_pass' => (bool) ($this->certificate_requires_assessment_pass ?? true),
      'certificate_min_score' => $this->certificate_min_score,
      'certificate_min_completion_percent' => $this->certificate_min_completion_percent,
      'certificate_auto_issue' => (bool) ($this->certificate_auto_issue ?? true),
      'assessment_required' => (bool) ($this->assessment_required ?? false),
      'assignment_required' => (bool) ($this->assignment_required ?? false),
      'passing_score' => $this->passing_score !== null ? (float) $this->passing_score : null,
      'max_attempts' => $this->max_attempts !== null ? (int) $this->max_attempts : null,
      'completion_rule' => $this->completion_rule instanceof \BackedEnum
        ? $this->completion_rule->value
        : ($this->completion_rule ?? 'all_mandatory_lessons'),
      'certificate_template_id' => $this->whenLoaded('certificateTemplate', fn () => $this->certificateTemplate?->uuid),
      'member_price' => $this->member_price !== null ? (float) $this->member_price : null,
      'public_price' => $this->public_price !== null ? (float) $this->public_price : null,
      'promotional_price' => $this->promotional_price !== null ? (float) $this->promotional_price : null,
      'promotional_starts_at' => $this->promotional_starts_at?->toIso8601String(),
      'promotional_ends_at' => $this->promotional_ends_at?->toIso8601String(),
      'currency' => $this->currency,
      'enrollment_count' => (int) $this->enrollment_count,
      'average_rating' => $this->average_rating !== null ? (float) $this->average_rating : null,
      'review_count' => (int) $this->review_count,
      'duration_minutes' => $this->duration_minutes,
      'estimated_completion_minutes' => $this->estimated_completion_minutes,
      'requirements' => $this->requirements ?? [],
      'learning_objectives' => $this->learning_objectives ?? [],
      'trailer_youtube_url' => $this->trailer_youtube_url,
      'youtube_playlist_url' => $this->youtube_playlist_url,
      'cover_media_id' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->uuid),
      'cover_url' => ($this->relationLoaded('coverMedia') ? $this->coverMedia?->url() : null)
        ?? ($this->metadata['import']['thumbnail_url'] ?? null),
      'thumbnail_media_id' => $this->whenLoaded('thumbnailMedia', fn () => $this->thumbnailMedia?->uuid),
      'thumbnail_url' => app(CourseThumbnailService::class)->resolve($this->resource),
      'banner_media_id' => $this->whenLoaded('bannerMedia', fn () => $this->bannerMedia?->uuid),
      'banner_url' => $this->whenLoaded('bannerMedia', fn () => $this->bannerMedia?->url()),
      'trailer_media_id' => $this->whenLoaded('trailerMedia', fn () => $this->trailerMedia?->uuid),
      'seo_title' => $this->seo_title,
      'seo_description' => $this->seo_description,
      'seo_keywords' => $this->seo_keywords ?? [],
      'metadata' => $this->metadata,
      'published_at' => $this->published_at?->toIso8601String(),
      'scheduled_publish_at' => $this->scheduled_publish_at?->toIso8601String(),
      'category' => $this->whenLoaded('category', fn () => $this->category ? [
        'id' => $this->category->uuid,
        'name' => $this->category->name,
        'slug' => $this->category->slug,
      ] : null),
      'category_id' => $this->whenLoaded('category', fn () => $this->category?->uuid),
      'subcategory' => $this->whenLoaded('subcategory', fn () => $this->subcategory ? [
        'id' => $this->subcategory->uuid,
        'name' => $this->subcategory->name,
        'slug' => $this->subcategory->slug,
      ] : null),
      'subcategory_id' => $this->whenLoaded('subcategory', fn () => $this->subcategory?->uuid),
      'level' => $this->whenLoaded('level', fn () => $this->level ? [
        'id' => $this->level->uuid,
        'name' => $this->level->name,
        'slug' => $this->level->slug,
      ] : null),
      'language' => $this->whenLoaded('language', fn () => $this->language ? [
        'id' => $this->language->uuid,
        'name' => $this->language->name,
        'code' => $this->language->code,
      ] : null),
      'primary_ministry' => $this->whenLoaded('primaryMinistry', fn () => $this->primaryMinistry ? [
        'id' => $this->primaryMinistry->uuid,
        'name' => $this->primaryMinistry->name,
        'slug' => $this->primaryMinistry->slug,
      ] : null),
      'primary_ministry_id' => $this->whenLoaded('primaryMinistry', fn () => $this->primaryMinistry?->uuid),
      'school' => $this->whenLoaded('school', fn () => $this->school ? [
        'id' => $this->school->uuid,
        'title' => $this->school->title,
        'slug' => $this->school->slug,
      ] : null),
      'school_id' => $this->whenLoaded('school', fn () => $this->school?->uuid),
      'program_module' => $this->whenLoaded('programModule', fn () => $this->programModule ? [
        'id' => $this->programModule->uuid,
        'title' => $this->programModule->title,
        'slug' => $this->programModule->slug,
      ] : null),
      'program_module_id' => $this->whenLoaded('programModule', fn () => $this->programModule?->uuid),
      'ministries' => $this->whenLoaded('ministries', fn () => $this->ministries->map(fn ($m) => [
        'id' => $m->uuid,
        'name' => $m->name,
        'slug' => $m->slug,
      ])),
      'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
        'id' => $t->uuid,
        'name' => $t->name,
        'slug' => $t->slug,
      ])),
      'instructors' => InstructorResource::collection($this->whenLoaded('instructors')),
      'modules' => ModuleResource::collection($this->whenLoaded('modules')),
      'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($f) => [
        'id' => $f->uuid,
        'question' => $f->question,
        'answer' => $f->answer,
        'sort_order' => $f->sort_order,
      ])),
      'downloads' => $this->whenLoaded('downloads', fn () => $this->downloads->map(fn ($d) => [
        'id' => $d->uuid,
        'title' => $d->title,
        'description' => $d->description,
        'external_url' => $d->external_url,
        'file_media_id' => $d->relationLoaded('fileMedia') ? $d->fileMedia?->uuid : null,
        'is_public' => (bool) $d->is_public,
      ])),
      'modules_count' => $this->when(isset($this->modules_count), fn () => (int) $this->modules_count),
      'lessons_count' => $this->when(isset($this->lessons_count), fn () => (int) $this->lessons_count),
      'enrollments_count' => $this->when(isset($this->enrollments_count), fn () => (int) $this->enrollments_count),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
