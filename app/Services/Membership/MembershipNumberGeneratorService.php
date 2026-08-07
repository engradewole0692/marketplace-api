<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Models\MembershipNumberSequence;
use Illuminate\Support\Facades\DB;

final class MembershipNumberGeneratorService implements ServiceContract
{
  public function generate(?int $year = null): string
  {
    $config = config('membership.number');
    $year ??= (int) date('Y');

    return DB::transaction(function () use ($config, $year): string {
      $sequence = MembershipNumberSequence::query()
        ->lockForUpdate()
        ->firstOrCreate(['year' => $year], ['last_sequence' => 0]);

      $sequence->increment('last_sequence');
      $sequence->refresh();

      $replacements = [
        '{prefix}' => (string) $config['prefix'],
        '{year}' => (string) $year,
        '{sequence}' => str_pad(
          (string) $sequence->last_sequence,
          (int) $config['sequence_padding'],
          '0',
          STR_PAD_LEFT,
        ),
      ];

      $format = (string) $config['format'];

      if (! ($config['include_year'] ?? true)) {
        $format = str_replace('-{year}', '', $format);
        $format = str_replace('{year}-', '', $format);
      }

      return str_replace(array_keys($replacements), array_values($replacements), $format);
    });
  }
}
