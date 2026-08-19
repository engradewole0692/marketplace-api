<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Models\CmsTestimonial;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

final class PublicContentService implements ServiceContract
{
  private const CACHE_TTL = 300;

  public function siteBootstrap(): array
  {
    return Cache::remember('cms:public:site-bootstrap', self::CACHE_TTL, function (): array {
      return [
        'settings' => $this->publicSettings(),
        'menus' => $this->menus(),
      ];
    });
  }

  public function home(): array
  {
    return Cache::remember('cms:public:home', self::CACHE_TTL, function (): array {
      return [
        'sections' => $this->sectionsForPage('home'),
        // Explicitly inactive keys only — missing keys must fall back to GitHub static data.
        'hidden_section_keys' => CmsPageSection::query()
          ->where('page_slug', 'home')
          ->where('is_active', false)
          ->orderBy('sort_order')
          ->pluck('section_key')
          ->values()
          ->all(),
        'countries' => $this->countries(limit: 9),
        'ministries' => $this->ministries(limit: 6),
        'leadership' => $this->leadership(limit: 6),
        'testimonials' => $this->testimonials(limit: 4, placement: 'homepage'),
        'partners' => $this->partners(limit: 12),
        'seo' => $this->seoForPath('/'),
      ];
    });
  }

  public function page(string $slug): ?array
  {
    return Cache::remember("cms:public:page:{$slug}", self::CACHE_TTL, function () use ($slug): ?array {
      $page = CmsPage::query()
        ->where('slug', $slug)
        ->where(function ($query): void {
          $query->where('status', PageStatus::Published)
            ->orWhere(function ($q): void {
              $q->where('status', PageStatus::Scheduled)
                ->where('scheduled_at', '<=', now());
            });
        })
        ->first();

      if ($page === null) {
        return null;
      }

      return [
        'page' => $page,
        'sections' => $this->sectionsForPage($slug),
        'seo' => $this->seoForPath('/'.$slug),
      ];
    });
  }

  /**
   * @return Collection<int, CmsCatalogItem>
   */
  public function catalog(CatalogItemType $type, ?string $category = null): Collection
  {
    $cacheKey = "cms:public:catalog:{$type->value}".($category ? ":{$category}" : '');

    return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($type, $category): Collection {
      $query = CmsCatalogItem::query()
        ->where('type', $type)
        ->where('is_active', true)
        ->where(function ($builder): void {
          $builder->whereNull('status')
            ->orWhere('status', 'published');
        })
        ->with('featuredMedia')
        ->orderByDesc('is_featured')
        ->orderBy('sort_order');

      if ($category !== null) {
        $query->where('category', $category);
      }

      return $query->get();
    });
  }

  public function catalogItem(CatalogItemType $type, string $slug): ?CmsCatalogItem
  {
    return CmsCatalogItem::query()
      ->where('type', $type)
      ->where('slug', $slug)
      ->where('is_active', true)
      ->with('featuredMedia')
      ->first();
  }

  /**
   * @return Collection<int, CmsPageSection>
   */
  public function sectionsForPage(string $pageSlug): Collection
  {
    return CmsPageSection::query()
      ->where('page_slug', $pageSlug)
      ->where('is_active', true)
      ->where(function ($query): void {
        // Live when explicitly published, or previously published while draft/review edits pending.
        $query->where('status', 'published')
          ->orWhereNull('status')
          ->orWhereNotNull('published_at');
      })
      ->orderBy('sort_order')
      ->get();
  }

  /**
   * @return array<string, mixed>
   */
  public function publicSettings(): array
  {
    return CmsSetting::query()
      ->where('is_public', true)
      ->get()
      ->mapWithKeys(fn (CmsSetting $setting): array => [$setting->key => $setting->value])
      ->all();
  }

  /**
   * @return Collection<int, CmsMenu>
   */
  public function menus(): Collection
  {
    return CmsMenu::query()
      ->where('is_active', true)
      ->with(['items' => fn ($q) => $q->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')])
      ->get();
  }

  /**
   * @return Collection<int, CmsCountry>
   */
  public function countries(?int $limit = null): Collection
  {
    $query = CmsCountry::query()
      ->where('is_active', true)
      ->with('heroMedia')
      ->orderBy('sort_order');

    if ($limit !== null) {
      $query->limit($limit);
    }

    return $query->get();
  }

  public function country(string $slug): ?CmsCountry
  {
    return CmsCountry::query()
      ->where('slug', $slug)
      ->where('is_active', true)
      ->with(['heroMedia', 'leaders.photoMedia'])
      ->first();
  }

  /**
   * @return Collection<int, CmsMinistry>
   */
  public function ministries(?int $limit = null): Collection
  {
    $query = CmsMinistry::query()
      ->where('is_active', true)
      ->with(['heroMedia', 'leaders.photoMedia'])
      ->orderBy('sort_order');

    if ($limit !== null) {
      $query->limit($limit);
    }

    return $query->get();
  }

  public function ministry(string $slug): ?CmsMinistry
  {
    return CmsMinistry::query()
      ->where('slug', $slug)
      ->where('is_active', true)
      ->with(['heroMedia', 'leaders.photoMedia'])
      ->first();
  }

  /**
   * @return Collection<int, CmsLeadershipProfile>
   */
  public function leadership(?string $category = null, ?int $limit = null): Collection
  {
    $query = CmsLeadershipProfile::query()
      ->where('is_active', true)
      ->with(['country', 'ministry', 'photoMedia'])
      ->orderBy('sort_order');

    if ($category !== null) {
      $query->where('category', $category);
    }

    if ($limit !== null) {
      $query->limit($limit);
    }

    return $query->get();
  }

  /**
   * @return Collection<int, CmsPartner>
   */
  public function partners(?int $limit = null): Collection
  {
    $query = CmsPartner::query()
      ->where('is_active', true)
      ->with('logoMedia')
      ->orderBy('sort_order');

    if ($limit !== null) {
      $query->limit($limit);
    }

    return $query->get();
  }

  /**
   * @return Collection<int, CmsTestimonial>
   */
  public function testimonials(?int $limit = null, ?string $placement = null, ?string $category = null): Collection
  {
    $query = CmsTestimonial::query()
      ->with(['photoMedia', 'videoMedia'])
      ->where('is_active', true)
      ->where('status', \App\Modules\Cms\Enums\TestimonialStatus::Approved)
      ->orderByDesc('is_featured')
      ->orderBy('sort_order');

    if ($placement === 'homepage') {
      $query->where('show_on_homepage', true);
    } elseif ($placement === 'page') {
      $query->where('show_on_page', true);
    }

    if ($category !== null && $category !== '') {
      $query->where('category', $category);
    }

    if ($limit !== null) {
      $query->limit($limit);
    }

    $results = $query->get();

    // Fallback for homepage: featured/active approved if no explicit homepage flags yet.
    if ($placement === 'homepage' && $results->isEmpty()) {
      return CmsTestimonial::query()
        ->with(['photoMedia', 'videoMedia'])
        ->where('is_active', true)
        ->where('status', \App\Modules\Cms\Enums\TestimonialStatus::Approved)
        ->orderByDesc('is_featured')
        ->orderBy('sort_order')
        ->when($limit !== null, fn ($q) => $q->limit($limit))
        ->get();
    }

    return $results;
  }

  public function seoForPath(string $path): ?CmsSeo
  {
    return CmsSeo::query()->with('ogImage')->where('path', $path)->first();
  }
}
