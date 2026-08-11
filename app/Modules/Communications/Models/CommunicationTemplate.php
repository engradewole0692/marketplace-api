<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;

class CommunicationTemplate extends Model
{
  use HasCommunicationUuid;

  protected $table = 'communication_templates';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'slug',
    'name',
    'section',
    'event_key',
    'description',
    'subject',
    'html_body',
    'text_body',
    'available_variables',
    'sample_variables',
    'is_active',
    'is_system',
  ];

  protected function casts(): array
  {
    return [
      'available_variables' => 'array',
      'sample_variables' => 'array',
      'is_active' => 'boolean',
      'is_system' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
