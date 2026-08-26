<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Cms\Services\CmsGallerySourceAdminService;
use Illuminate\Console\Command;

final class SyncCmsGallerySourcesCommand extends Command
{
  protected $signature = 'cms:sync-gallery-sources {--now : Run synchronously instead of dispatching jobs}';

  protected $description = 'Synchronize enabled CMS gallery sources from Google Drive into the local catalog';

  public function handle(CmsGallerySourceAdminService $service): int
  {
    if ($this->option('now')) {
      foreach ($service->all()->where('is_enabled', true) as $source) {
        $result = $service->syncNow($source);
        $this->info(sprintf(
          '%s [%s]: imported=%d skipped=%d duplicates=%d failed=%d status=%s',
          $source->name,
          $source->provider,
          $result['imported'],
          $result['skipped'],
          $result['duplicates'],
          $result['failed'],
          $result['status'],
        ));
        if ($result['error']) {
          $this->warn($result['error']);
        }
      }

      return self::SUCCESS;
    }

    $count = $service->dispatchEnabled();
    $this->info("Dispatched {$count} gallery source sync job(s).");

    return self::SUCCESS;
  }
}
