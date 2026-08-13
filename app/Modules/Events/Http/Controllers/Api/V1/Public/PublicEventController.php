<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventPresentation;
use App\Modules\Events\Support\PublicEventAccess;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicEventController extends ApiController
{
  public function index(Request $request): JsonResponse
  {
    $query = Event::query()
      ->published()
      ->visible()
      ->withCount('registrations')
      ->with(['ministry', 'venue', 'country'])
      ->orderBy('starts_at');

    if ($request->filled('ministry_id')) {
      $query->where('ministry_id', $request->query('ministry_id'));
    }

    if ($request->filled('event_category_id')) {
      $query->where('event_category_id', $request->query('event_category_id'));
    }

    $upcomingOnly = $request->boolean('upcoming_only', true);
    $pastOnly = $request->boolean('past_only', false);

    if ($pastOnly) {
      $query->where('starts_at', '<', now())->reorder('starts_at', 'desc');
    } elseif ($upcomingOnly) {
      $query->upcoming();
    }

    if ($request->has('featured')) {
      $query->where('is_featured', $request->boolean('featured'));
    }

    if ($request->filled('search')) {
      $search = (string) $request->query('search');
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")
          ->orWhere('theme', 'like', "%{$search}%")
          ->orWhere('summary', 'like', "%{$search}%");
      });
    }

    if ($request->has('is_paid') && $request->query('is_paid') !== null && $request->query('is_paid') !== '') {
      $query->where('is_paid', $request->boolean('is_paid'));
    }

    if ($request->filled('format')) {
      $format = strtolower((string) $request->query('format'));
      match ($format) {
        'online' => $query->where(function ($q): void {
          $q->whereNull('venue_id')
            ->orWhere('metadata->format', 'online')
            ->orWhere('metadata->delivery_mode', 'online');
        }),
        'physical' => $query->where(function ($q): void {
          $q->whereNotNull('venue_id')
            ->where(function ($inner): void {
              $inner->whereNull('metadata->format')
                ->orWhere('metadata->format', 'physical')
                ->orWhere('metadata->delivery_mode', 'physical');
            });
        }),
        'hybrid' => $query->where(function ($q): void {
          $q->where('metadata->format', 'hybrid')
            ->orWhere('metadata->delivery_mode', 'hybrid');
        }),
        default => null,
      };
    }

    $events = $query->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($events, EventResource::class),
      message: 'Events retrieved.',
    );
  }

  public function show(Request $request, string $event): JsonResponse
  {
    $record = Event::query()
      ->withCount('registrations')
      ->where('uuid', $event)
      ->orWhere('slug', $event)
      ->firstOrFail();
    PublicEventAccess::ensure($record);

    $record->load([
      'ministry',
      'venue',
      'country',
      'speakers',
      'sessions.speaker',
      'galleryItems',
      'resources',
      'faqs',
      'sponsors',
      'registrationQuestions' => fn ($query) => $query->enabled()->orderBy('sort_order'),
      'registrationFieldSettings' => fn ($query) => $query->where('is_enabled', true)->orderBy('sort_order'),
    ]);

    $payload = array_merge(
      (new EventResource($record))->resolve($request),
      [
        'formatted_date' => EventPresentation::eventDate($record),
        'formatted_time' => EventPresentation::eventTime($record),
        'venue_name' => EventPresentation::venue($record),
      ],
    );

    return $this->responder->success(
      data: ['event' => $payload],
      message: 'Event retrieved.',
    );
  }
}
