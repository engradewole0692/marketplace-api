<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum CatalogItemType: string
{
  case Blog = 'blog';
  case Gallery = 'gallery';
  case Resource = 'resource';
  case Vlog = 'vlog';
}
