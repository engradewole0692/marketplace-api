<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Models;

use App\Models\User;
use App\Modules\Communications\Models\PlatformConversation;
use Database\Factories\BusinessReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessReview extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): BusinessReviewFactory
    {
        return BusinessReviewFactory::new();
    }

    protected $table = 'business_reviews';

    protected $fillable = [
        'uuid', 'full_name', 'email', 'phone', 'business_name', 'business_location',
        'business_industry', 'business_description', 'business_stage', 'main_challenges',
        'business_goals', 'website_social', 'preferred_contact', 'additional_info',
        'extra_answers', 'status', 'assigned_to', 'admin_notes', 'ip_address', 'conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'extra_answers' => 'array',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PlatformConversation::class, 'conversation_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BusinessReviewNote::class, 'business_review_id');
    }
}
