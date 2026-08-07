<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\DonationType;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonationFund extends Model
{
  use HasDonationUuid;
  use SoftDeletes;

  protected $table = 'donation_funds';

  protected $fillable = [
    'uuid', 'slug', 'name', 'type', 'description', 'is_active', 'sort_order',
  ];

  protected function casts(): array
  {
    return [
      'type' => DonationType::class,
      'is_active' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function donations(): HasMany
  {
    return $this->hasMany(Donation::class, 'fund_id');
  }
}
