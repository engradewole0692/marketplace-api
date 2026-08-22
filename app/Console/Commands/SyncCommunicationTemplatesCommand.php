<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationSeederDefaults;
use Illuminate\Console\Command;

final class SyncCommunicationTemplatesCommand extends Command
{
  protected $signature = 'communications:sync-templates';

  protected $description = 'Insert missing system email templates without overwriting customized templates.';

  public function handle(CommunicationSeederDefaults $defaults): int
  {
    $created = 0;
    foreach ($defaults->allTemplates() as $eventKey => $template) {
      $existing = CommunicationTemplate::query()->where('event_key', $eventKey)->first();
      if ($existing !== null) {
        continue;
      }

      CommunicationTemplate::query()->create($template);
      $this->line("Created template for {$eventKey}");
      $created++;
    }

    $this->info("Inserted {$created} missing communication template(s). Existing templates were left unchanged.");

    return self::SUCCESS;
  }
}
