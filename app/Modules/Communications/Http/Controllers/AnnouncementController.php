<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\PlatformAnnouncement;
use App\Modules\Communications\Services\AnnouncementService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnnouncementController extends ApiController
{
  // ── Admin ─────────────────────────────────────────────────────────────

  public function index(Request $request, AnnouncementService $service): JsonResponse
  {
    $this->authorize('viewAny', PlatformAnnouncement::class);

    $paginator = $service->paginate($request->only(['status', 'target_audience', 'show_on_public']));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn ($a) => $this->transform($a)),
      message: 'Announcements retrieved.',
    );
  }

  public function store(Request $request, AnnouncementService $service): JsonResponse
  {
    $this->authorize('create', PlatformAnnouncement::class);

    $validated = $this->validated($request);

    $announcement = $service->create($validated, $request->user());

    return $this->responder->created(
      data: ['announcement' => $this->transform($announcement)],
      message: 'Announcement created.',
    );
  }

  public function show(PlatformAnnouncement $announcement): JsonResponse
  {
    $this->authorize('view', $announcement);

    return $this->responder->success(
      data: ['announcement' => $this->transform($announcement)],
      message: 'Announcement retrieved.',
    );
  }

  public function update(Request $request, PlatformAnnouncement $announcement, AnnouncementService $service): JsonResponse
  {
    $this->authorize('update', $announcement);

    $validated = $this->validated($request, false);

    $announcement = $service->update($announcement, $validated, $request->user());

    return $this->responder->success(
      data: ['announcement' => $this->transform($announcement)],
      message: 'Announcement updated.',
    );
  }

  public function publish(Request $request, PlatformAnnouncement $announcement, AnnouncementService $service): JsonResponse
  {
    $this->authorize('update', $announcement);

    $announcement = $service->publish($announcement, $request->user());

    return $this->responder->success(
      data: ['announcement' => $this->transform($announcement)],
      message: 'Announcement published.',
    );
  }

  public function destroy(PlatformAnnouncement $announcement, AnnouncementService $service): JsonResponse
  {
    $this->authorize('delete', $announcement);

    $service->delete($announcement);

    return $this->responder->success(message: 'Announcement deleted.');
  }

  // ── Public ────────────────────────────────────────────────────────────

  public function publicIndex(AnnouncementService $service): JsonResponse
  {
    $announcements = $service->publicActive(20);

    return $this->responder->success(
      data: ['announcements' => $announcements->map(fn ($a) => $this->transform($a))],
      message: 'Active announcements retrieved.',
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  private function validated(Request $request, bool $required = true): array
  {
    $requiredFlag = fn ($rule) => $required ? 'required|'.$rule : 'nullable|'.$rule;

    return $request->validate([
      'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
      'content' => [$required ? 'required' : 'sometimes', 'string'],
      'image_path' => ['nullable', 'string', 'max:500'],
      'status' => ['nullable', 'string', 'in:draft,published,archived'],
      'target_audience' => ['nullable', 'string', 'in:all,members,visitors,staff,admins,custom'],
      'show_on_public' => ['nullable', 'boolean'],
      'send_email' => ['nullable', 'boolean'],
      'send_notification' => ['nullable', 'boolean'],
      'target_countries' => ['nullable', 'array'],
      'target_countries.*' => ['string'],
      'target_regions' => ['nullable', 'array'],
      'target_regions.*' => ['string'],
      'target_ministries' => ['nullable', 'array'],
      'target_ministries.*' => ['string'],
      'target_roles' => ['nullable', 'array'],
      'target_roles.*' => ['string'],
      'publish_at' => ['nullable', 'date'],
      'expires_at' => ['nullable', 'date'],
    ]);
  }

  private function transform(PlatformAnnouncement $a): array
  {
    return [
      'id' => $a->uuid,
      'title' => $a->title,
      'content' => $a->content,
      'image_path' => $a->image_path,
      'status' => $a->status,
      'target_audience' => $a->target_audience,
      'show_on_public' => $a->show_on_public,
      'send_email' => $a->send_email,
      'send_notification' => $a->send_notification,
      'target_countries' => $a->target_countries,
      'target_regions' => $a->target_regions,
      'target_ministries' => $a->target_ministries,
      'target_roles' => $a->target_roles,
      'publish_at' => $a->publish_at?->toIso8601String(),
      'expires_at' => $a->expires_at?->toIso8601String(),
      'published_at' => $a->published_at?->toIso8601String(),
      'created_at' => $a->created_at?->toIso8601String(),
      'updated_at' => $a->updated_at?->toIso8601String(),
    ];
  }
}
