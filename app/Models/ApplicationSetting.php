<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'group',
    'key',
    'value',
    'type',
    'description',
  ];
}
