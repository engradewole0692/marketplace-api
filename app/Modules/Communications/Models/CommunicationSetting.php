<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationSetting extends Model
{
  protected $table = 'communication_settings';

  /** @var list<string> */
  protected $fillable = [
    'ministry_email',
    'reply_to_email',
    'reply_to_name',
    'from_name',
    'branding',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'branding' => 'array',
      'metadata' => 'array',
    ];
  }
}
