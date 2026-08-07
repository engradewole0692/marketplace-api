<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderConfig extends Model
{
  use HasDonationUuid;

  protected $table = 'payment_provider_configs';

  protected $fillable = [
    'uuid', 'provider', 'country_id', 'credentials', 'webhook_secret', 'is_live', 'is_enabled',
  ];

  protected function casts(): array
  {
    return [
      'credentials' => 'array',
      'is_live' => 'boolean',
      'is_enabled' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }
}
