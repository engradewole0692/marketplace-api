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
        'uuid', 'user_id', 'full_name', 'first_name', 'last_name', 'email', 'phone',
        'business_name', 'business_location', 'country', 'state_province',
        'business_industry', 'business_description', 'business_stage', 'years_in_operation',
        'employee_count', 'main_challenges', 'advice_areas', 'business_goals',
        'website_social', 'website_url', 'social_links', 'preferred_contact',
        'additional_info', 'referral_source', 'extra_answers', 'status', 'assigned_to',
        'admin_notes', 'ip_address', 'conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'extra_answers' => 'array',
            'years_in_operation' => 'integer',
            'employee_count' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BusinessReviewStatusHistory::class, 'business_review_id')->orderByDesc('created_at');
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
