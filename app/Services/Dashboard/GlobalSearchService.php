<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Events\Models\Event;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use Illuminate\Support\Str;

/**
 * Cross-module admin search — groups results by module for quick navigation.
 */
final class GlobalSearchService implements ServiceContract
{
  /**
   * @return array{query: string, groups: list<array{module: string, label: string, items: list<array{id: string, title: string, subtitle?: string|null, href: string, type: string}>}>}
   */
  public function search(string $query, int $perGroup = 5): array
  {
    $q = trim($query);
    if (mb_strlen($q) < 2) {
      return ['query' => $q, 'groups' => []];
    }

    $like = '%'.$q.'%';
    $groups = [];

    $members = Member::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('first_name', 'like', $like)
          ->orWhere('last_name', 'like', $like)
          ->orWhere('display_name', 'like', $like)
          ->orWhere('email', 'like', $like)
          ->orWhere('membership_number', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'display_name', 'first_name', 'last_name', 'email', 'membership_number']);

    if ($members->isNotEmpty()) {
      $groups[] = [
        'module' => 'members',
        'label' => 'Members',
        'items' => $members->map(fn (Member $m): array => [
          'id' => $m->uuid,
          'title' => $m->fullName(),
          'subtitle' => $m->membership_number ?? $m->email,
          'href' => '/admin/members/'.$m->uuid,
          'type' => 'member',
        ])->all(),
      ];
    }

    $courses = Course::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('title', 'like', $like)
          ->orWhere('slug', 'like', $like)
          ->orWhere('course_code', 'like', $like)
          ->orWhere('summary', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'title', 'slug', 'course_code', 'status']);

    if ($courses->isNotEmpty()) {
      $groups[] = [
        'module' => 'learning',
        'label' => 'Courses',
        'items' => $courses->map(fn (Course $c): array => [
          'id' => $c->uuid,
          'title' => $c->title,
          'subtitle' => trim(($c->course_code ?? '').' · '.$c->status?->value),
          'href' => '/admin/courses/'.$c->uuid,
          'type' => 'course',
        ])->all(),
      ];
    }

    $events = Event::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('title', 'like', $like)->orWhere('slug', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'title', 'slug', 'status']);

    if ($events->isNotEmpty()) {
      $groups[] = [
        'module' => 'events',
        'label' => 'Events',
        'items' => $events->map(fn (Event $e): array => [
          'id' => $e->uuid,
          'title' => $e->title,
          'subtitle' => $e->status instanceof \BackedEnum ? $e->status->value : (string) $e->status,
          'href' => '/admin/events/'.$e->uuid,
          'type' => 'event',
        ])->all(),
      ];
    }

    $pages = CmsPage::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('title', 'like', $like)->orWhere('slug', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'title', 'slug', 'status']);

    if ($pages->isNotEmpty()) {
      $groups[] = [
        'module' => 'cms',
        'label' => 'Pages',
        'items' => $pages->map(fn (CmsPage $p): array => [
          'id' => $p->uuid,
          'title' => $p->title,
          'subtitle' => $p->slug,
          'href' => '/admin/cms/pages/'.$p->uuid,
          'type' => 'page',
        ])->all(),
      ];
    }

    $media = CmsMedia::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('name', 'like', $like)
          ->orWhere('file_name', 'like', $like)
          ->orWhere('alt_text', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'name', 'file_name', 'mime_type']);

    if ($media->isNotEmpty()) {
      $groups[] = [
        'module' => 'media',
        'label' => 'Media',
        'items' => $media->map(fn (CmsMedia $m): array => [
          'id' => $m->uuid,
          'title' => $m->name,
          'subtitle' => $m->mime_type,
          'href' => '/admin/media',
          'type' => 'media',
        ])->all(),
      ];
    }

    $users = User::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('name', 'like', $like)
          ->orWhere('email', 'like', $like)
          ->orWhere('display_name', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'name', 'email', 'display_name']);

    if ($users->isNotEmpty()) {
      $groups[] = [
        'module' => 'users',
        'label' => 'Users',
        'items' => $users->map(fn (User $u): array => [
          'id' => $u->uuid,
          'title' => $u->display_name ?? $u->name,
          'subtitle' => $u->email,
          'href' => '/admin/users',
          'type' => 'user',
        ])->all(),
      ];
    }

    $testimonials = CmsTestimonial::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('author_name', 'like', $like)->orWhere('quote', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'author_name', 'quote']);

    if ($testimonials->isNotEmpty()) {
      $groups[] = [
        'module' => 'cms',
        'label' => 'Testimonials',
        'items' => $testimonials->map(fn (CmsTestimonial $t): array => [
          'id' => $t->uuid,
          'title' => $t->author_name,
          'subtitle' => Str::limit((string) $t->quote, 80),
          'href' => '/admin/cms/testimonials',
          'type' => 'testimonial',
        ])->all(),
      ];
    }

    $forms = CmsFormSubmission::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('submitter_name', 'like', $like)
          ->orWhere('submitter_email', 'like', $like);
      })
      ->orderByDesc('created_at')
      ->limit($perGroup)
      ->get(['uuid', 'submitter_name', 'submitter_email', 'type', 'status']);

    if ($forms->isNotEmpty()) {
      $groups[] = [
        'module' => 'cms',
        'label' => 'Forms',
        'items' => $forms->map(fn (CmsFormSubmission $f): array => [
          'id' => $f->uuid,
          'title' => (string) ($f->submitter_name ?: $f->submitter_email ?: 'Form submission'),
          'subtitle' => trim(($f->type instanceof \BackedEnum ? $f->type->value : (string) $f->type).' · '.($f->status instanceof \BackedEnum ? $f->status->value : (string) $f->status)),
          'href' => '/admin/cms/forms/'.$f->uuid,
          'type' => 'form',
        ])->all(),
      ];
    }

    $assessments = Assessment::query()
      ->where('title', 'like', $like)
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'title', 'assessment_type', 'status']);

    if ($assessments->isNotEmpty()) {
      $groups[] = [
        'module' => 'assessments',
        'label' => 'Assessments',
        'items' => $assessments->map(fn (Assessment $a): array => [
          'id' => $a->uuid,
          'title' => $a->title,
          'subtitle' => $a->assessment_type instanceof \BackedEnum ? $a->assessment_type->value : (string) $a->assessment_type,
          'href' => '/admin/assessments',
          'type' => 'assessment',
        ])->all(),
      ];
    }

    $certificates = CourseCertificate::query()
      ->with(['user:id,name', 'course:id,title'])
      ->where(function ($builder) use ($like): void {
        $builder->where('certificate_number', 'like', $like)
          ->orWhere('verification_code', 'like', $like);
      })
      ->orderByDesc('created_at')
      ->limit($perGroup)
      ->get(['uuid', 'certificate_number', 'verification_code', 'user_id', 'course_id']);

    if ($certificates->isNotEmpty()) {
      $groups[] = [
        'module' => 'certificates',
        'label' => 'Certificates',
        'items' => $certificates->map(fn (CourseCertificate $c): array => [
          'id' => $c->uuid,
          'title' => $c->certificate_number ?? 'Certificate',
          'subtitle' => trim(($c->user?->name ?? '').' · '.($c->course?->title ?? '')),
          'href' => '/admin/lms/certificates',
          'type' => 'certificate',
        ])->all(),
      ];
    }

    $catalog = CmsCatalogItem::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('title', 'like', $like)->orWhere('slug', 'like', $like);
      })
      ->orderByDesc('updated_at')
      ->limit($perGroup)
      ->get(['uuid', 'title', 'slug', 'type']);

    if ($catalog->isNotEmpty()) {
      $groups[] = [
        'module' => 'cms',
        'label' => 'Catalog',
        'items' => $catalog->map(fn (CmsCatalogItem $item): array => [
          'id' => $item->uuid,
          'title' => $item->title,
          'subtitle' => $item->type instanceof \BackedEnum ? $item->type->value : (string) $item->type,
          'href' => '/admin/cms',
          'type' => 'catalog',
        ])->all(),
      ];
    }

    return ['query' => $q, 'groups' => $groups];
  }
}
