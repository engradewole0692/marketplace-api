<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\ReceiptType;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DonationReceipt extends Model
{
  use HasDonationUuid;

  protected $table = 'donation_receipts';

  protected $fillable = [
    'uuid', 'donation_id', 'type', 'number', 'tax_year', 'country_id',
    'pdf_path', 'issued_at', 'issued_by',
  ];

  protected function casts(): array
  {
    return [
      'type' => ReceiptType::class,
      'issued_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function donation(): BelongsTo
  {
    return $this->belongsTo(Donation::class, 'donation_id');
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function issuer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'issued_by');
  }

  public function url(): ?string
  {
    return $this->pdf_path ? Storage::disk('public')->url($this->pdf_path) : null;
  }
}
