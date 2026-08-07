<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use Illuminate\Support\Facades\Cache;

final class CmsCacheManager
{
  private const KEYS = [
    'cms:public:site-bootstrap',
    'cms:public:home',
    'cms:public:sitemap',
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

  public function flushCatalog(string $type): void
  {
    Cache::forget("cms:public:catalog:{$type}");
    $this->flushPublic();
  }
}
