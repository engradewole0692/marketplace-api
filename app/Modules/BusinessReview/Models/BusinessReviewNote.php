<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessReviewNote extends Model
{
    protected $table = 'business_review_notes';

    protected $fillable = ['uuid', 'business_review_id', 'created_by', 'content'];

    protected static function booting(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(BusinessReview::class, 'business_review_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
