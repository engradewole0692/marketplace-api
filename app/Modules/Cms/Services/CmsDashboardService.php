<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsAuditLog;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Models\CmsTestimonial;
use Illuminate\Support\Facades\DB;

final class CmsDashboardService implements ServiceContract
{
  /**
   * @return array<string, mixed>
   */
  public function overview(): array
  {
    $pageCounts = CmsPage::query()
      ->select('status', DB::raw('count(*) as total'))
      ->groupBy('status')
      ->pluck('total', 'status');

    $storageBytes = (int) CmsMedia::query()->sum('size');

    $recentEdits = CmsAuditLog::query()
      ->with('actor:id,display_name,email')
      ->orderByDesc('created_at')
      ->limit(10)
      ->get()
      ->map(fn (CmsAuditLog $log) => [
        'event_type' => $log->event_type,
        'entity_type' => $log->entity_type,
        'entity_id' => $log->entity_id,
        'actor' => $log->actor?->display_name ?? $log->actor?->email,
        'created_at' => $log->created_at?->toIso8601String(),
      ]);

    return [
      'pages' => [
        'total' => CmsPage::query()->count(),
        'draft' => (int) ($pageCounts[PageStatus::Draft->value] ?? 0),
        'review' => (int) ($pageCounts[PageStatus::Review->value] ?? 0),
        'published' => (int) ($pageCounts[PageStatus::Published->value] ?? 0),
        'scheduled' => (int) ($pageCounts[PageStatus::Scheduled->value] ?? 0),
        'archived' => (int) ($pageCounts[PageStatus::Archived->value] ?? 0),
      ],
      'media' => [
        'total' => CmsMedia::query()->count(),
        'storage_bytes' => $storageBytes,
        'storage_mb' => round($storageBytes / 1024 / 1024, 2),
      ],
      'partners' => [
        'total' => CmsPartner::query()->count(),
        'active' => CmsPartner::query()->where('is_active', true)->count(),
      ],
      'testimonials' => [
        'total' => CmsTestimonial::query()->count(),
        'active' => CmsTestimonial::query()->where('is_active', true)->count(),
        'featured' => CmsTestimonial::query()->where('is_featured', true)->count(),
      ],
      'menus' => [
        'total' => CmsMenu::query()->count(),
        'active' => CmsMenu::query()->where('is_active', true)->count(),
      ],
      'seo' => [
        'total' => CmsSeo::query()->count(),
      ],
      'recent_edits' => $recentEdits,
    ];
  }
}
