<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\CmsCatalogItem;
use Illuminate\Support\Facades\Cache;

final class CmsCacheManager
{
  private const KEYS = [
    'cms:public:site-bootstrap',
    'cms:public:home',
    'cms:public:sitemap',
    'cms:public:vlog-youtube-feed',
  ];

  public function flushAll(): void
  {
    foreach (self::KEYS as $key) {
      Cache::forget($key);
    }
  }

  public function flushPublic(): void
  {
    $this->flushAll();
  }

  public function flushPage(string $slug): void
  {
    Cache::forget("cms:public:page:{$slug}");
    $this->flushPublic();
  }

  public function flushPageFromPath(?string $path): void
  {
    if ($path === null || $path === '' || $path === '/') {
      $this->flushPublic();

      return;
    }

    $slug = trim($path, '/');
    if ($slug !== '') {
      $this->flushPage($slug);
    } else {
      $this->flushPublic();
    }
  }

  public function flushCatalog(string $type): void
  {
    Cache::forget("cms:public:catalog:{$type}");

    $categories = CmsCatalogItem::query()
      ->where('type', $type)
      ->whereNotNull('category')
      ->where('category', '!=', '')
      ->distinct()
      ->pluck('category');

    foreach ($categories as $category) {
      Cache::forget("cms:public:catalog:{$type}:{$category}");
    }

    $this->flushPublic();
  }
}
