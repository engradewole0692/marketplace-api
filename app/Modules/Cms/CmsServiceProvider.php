<?php

declare(strict_types=1);

namespace App\Modules\Cms;

use App\Modules\AbstractModuleServiceProvider;

final class CmsServiceProvider extends AbstractModuleServiceProvider
{
  protected function moduleName(): string
  {
    return 'Cms';
  }

  public function boot(): void
  {
    //
  }
}
