<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MemberDocument extends Model
{
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'member_id',
    'uploaded_by',
    'document_type',
    'title',
    'file_path',
    'file_name',
    'mime_type',
    'file_size',
    'disk',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberDocument $document): void {
      if (empty($document->uuid)) {
        $document->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'document_type' => MemberDocumentType::class,
      'file_size' => 'integer',
    ];
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function uploader(): BelongsTo
  {
    return $this->belongsTo(User::class, 'uploaded_by');
  }

  public function fileUrl(): string
  {
    return asset('storage/'.$this->file_path);
  }
}
