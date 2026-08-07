<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryPaymentMethod extends Model
{
  use HasDonationUuid;

  protected $table = 'country_payment_methods';

  protected $fillable = [
    'uuid', 'country_id', 'method', 'provider_key', 'label', 'config', 'is_enabled', 'sort_order',
  ];

  protected function casts(): array
  {
    return [
      'method' => PaymentMethod::class,
      'config' => 'array',
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
