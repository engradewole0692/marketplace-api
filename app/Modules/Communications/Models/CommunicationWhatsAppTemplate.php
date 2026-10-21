<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry of provider-approved WhatsApp templates. Only approved, active templates can be used for campaigns.
 *
 * parameters: list of {key, label, source} where source is a personalization variable (e.g. first_name)
 * or a fixed value supplied at compose time.
 */
class CommunicationWhatsAppTemplate extends Model
{
    use HasCommunicationUuid;

    protected $table = 'communication_whatsapp_templates';

    protected $fillable = [
        'uuid', 'provider', 'name', 'language', 'category', 'status', 'body_preview', 'parameters',
        'provider_template_id', 'is_active', 'created_by', 'status_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_active' => 'boolean',
            'status_synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->status === 'approved';
    }
}
