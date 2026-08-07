<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonationBankAccount extends Model
{
  use HasDonationUuid;
  use SoftDeletes;

  protected $table = 'donation_bank_accounts';

  protected $fillable = [
    'uuid', 'country_id', 'bank_name', 'account_name', 'account_number',
    'routing_number', 'swift_code', 'iban', 'currency', 'instructions',
    'is_active', 'sort_order',
  ];

  protected function casts(): array
  {
    return ['is_active' => 'boolean'];
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
