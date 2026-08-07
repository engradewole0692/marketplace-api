<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseInvoice extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_course_invoices';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'order_id', 'invoice_number', 'type', 'pdf_path',
    'issued_at', 'issued_by_user_id', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'issued_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function order(): BelongsTo
  {
    return $this->belongsTo(CourseOrder::class, 'order_id');
  }

  public function issuer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'issued_by_user_id');
  }

  public function url(): ?string
  {
    return $this->pdf_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->pdf_path) : null;
  }
}
