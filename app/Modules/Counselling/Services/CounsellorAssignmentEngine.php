<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\Counsellor;

final class CounsellorAssignmentEngine implements ServiceContract
{
  /** @var list<string> */
  private const CLOSED_STATUSES = [
    CaseStatus::Completed->value,
    CaseStatus::Cancelled->value,
    CaseStatus::Rejected->value,
  ];

  public function autoAssign(CounsellingCase $case): ?Counsellor
  {
    $case->loadMissing(['service.category', 'category']);

    $categoryName = strtolower((string) ($case->category?->name ?? $case->service?->category?->name ?? ''));
    $categorySlug = strtolower((string) ($case->category?->slug ?? $case->service?->category?->slug ?? ''));
    $preferredWeekday = $case->preferred_at?->dayOfWeek;

    $candidates = Counsellor::query()
      ->with(['availability', 'user'])
      ->withCount([
        'cases as open_cases_count' => fn ($q) => $q->whereNotIn('status', self::CLOSED_STATUSES),
      ])
      ->where('is_active', true)
      ->get();

    if ($candidates->isEmpty()) {
      return null;
    }

    $filtered = $candidates->filter(function (Counsellor $counsellor) use ($categoryName, $categorySlug, $preferredWeekday, $case): bool {
      if (! $this->matchesSpecialization($counsellor, $categoryName, $categorySlug)) {
        return false;
      }

      if ($preferredWeekday !== null && ! $this->isAvailableOnWeekday($counsellor, $preferredWeekday)) {
        return false;
      }

      if ($case->client_country && ! $this->matchesCountry($counsellor, $case->client_country)) {
        return false;
      }

      return true;
    });

    if ($filtered->isEmpty()) {
      $filtered = $candidates;
    }

    /** @var Counsellor|null $best */
    $best = $filtered
      ->sortBy([
        ['open_cases_count', 'asc'],
        ['sort_order', 'asc'],
      ])
      ->first();

    return $best;
  }

  private function matchesSpecialization(Counsellor $counsellor, string $categoryName, string $categorySlug): bool
  {
    if ($categoryName === '' && $categorySlug === '') {
      return true;
    }

    $specializations = collect($counsellor->specializations ?? [])
      ->map(fn ($item) => strtolower((string) $item));

    if ($specializations->isEmpty()) {
      return true;
    }

    return $specializations->contains(fn (string $spec) => str_contains($categoryName, $spec)
      || str_contains($categorySlug, $spec)
      || str_contains($spec, $categorySlug)
      || str_contains($spec, $categoryName));
  }

  private function isAvailableOnWeekday(Counsellor $counsellor, int $weekday): bool
  {
    return $counsellor->availability
      ->where('is_active', true)
      ->contains(fn ($slot) => (int) $slot->weekday === $weekday);
  }

  private function matchesCountry(Counsellor $counsellor, string $clientCountry): bool
  {
    $metadata = is_array($counsellor->metadata) ? $counsellor->metadata : [];
    $countries = collect($metadata['countries'] ?? $metadata['regions'] ?? [])
      ->map(fn ($item) => strtolower((string) $item));

    if ($countries->isEmpty()) {
      return true;
    }

    $needle = strtolower(trim($clientCountry));

    return $countries->contains(fn (string $country) => $country === $needle || str_contains($needle, $country));
  }
}
