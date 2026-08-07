<?php

declare(strict_types=1);

use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;

return [
  AppServiceProvider::class,
  ApiServiceProvider::class,
  AuthServiceProvider::class,
  App\Modules\Cms\CmsServiceProvider::class,
];
