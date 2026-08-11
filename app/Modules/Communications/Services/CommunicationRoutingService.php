<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Models\CommunicationRoute;
use Illuminate\Support\Collection;

final class CommunicationRoutingService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationSettingsService $settings,
  ) {}

  /**
   * @param  array<string, mixed>  $context
   * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
   */
  public function resolve(string $section, string $eventKey, array $context = []): array
  {
    $recipients = ['to' => [], 'cc' => [], 'bcc' => []];
    $seen = [];

    $routes = CommunicationRoute::query()
      ->where('is_active', true)
      ->where(function ($q) use ($section, $eventKey): void {
        $q->where(function ($inner) use ($eventKey): void {
          $inner->where('event_key', $eventKey);
        })->orWhere(function ($inner) use ($section): void {
          $inner->where('section', $section)->whereNull('event_key');
        });
      })
      ->orderBy('sort_order')
      ->with('user')
      ->get();

    foreach ($routes as $route) {
      $email = $this->emailForRoute($route, $context);
      if ($email === null || isset($seen[strtolower($email)])) {
        continue;
      }
      $role = in_array($route->recipient_role, ['to', 'cc', 'bcc'], true) ? $route->recipient_role : 'to';
      $recipients[$role][] = $email;
      $seen[strtolower($email)] = true;

      if ($route->include_section_fallback) {
        $this->appendSectionFallback($recipients, $seen, $section);
      }
      if ($route->include_ministry_fallback) {
        $this->appendMinistryFallback($recipients, $seen);
      }
    }

    if ($recipients['to'] === []) {
      $this->appendSectionFallback($recipients, $seen, $section);
    }
    if ($recipients['to'] === []) {
      $this->appendMinistryFallback($recipients, $seen);
    }

    $assignedAdminId = $context['assigned_admin_user_id'] ?? $context['assigned_user_id'] ?? null;
    if ($assignedAdminId) {
      $admin = User::query()->find($assignedAdminId);
      if ($admin?->email && ! isset($seen[strtolower($admin->email)])) {
        array_unshift($recipients['to'], $admin->email);
        $seen[strtolower($admin->email)] = true;
      }
    }

    $assignedCounsellorId = $context['assigned_counsellor_user_id'] ?? null;
    if ($assignedCounsellorId) {
      $counsellor = User::query()->find($assignedCounsellorId);
      if ($counsellor?->email && ! isset($seen[strtolower($counsellor->email)])) {
        $recipients['to'][] = $counsellor->email;
        $seen[strtolower($counsellor->email)] = true;
      }
    }

    return $recipients;
  }

  /**
   * @param  array{to: list<string>, cc: list<string>, bcc: list<string>}  $recipients
   * @param  array<string, bool>  $seen
   */
  private function appendSectionFallback(array &$recipients, array &$seen, string $section): void
  {
    $sectionRoutes = CommunicationRoute::query()
      ->where('is_active', true)
      ->where('section', $section)
      ->whereNull('event_key')
      ->where('recipient_type', 'section_email')
      ->orderBy('sort_order')
      ->get();

    foreach ($sectionRoutes as $route) {
      $email = $route->email;
      if (! is_string($email) || $email === '' || isset($seen[strtolower($email)])) {
        continue;
      }
      $role = in_array($route->recipient_role, ['to', 'cc', 'bcc'], true) ? $route->recipient_role : 'cc';
      $recipients[$role][] = $email;
      $seen[strtolower($email)] = true;
    }
  }

  /**
   * @param  array{to: list<string>, cc: list<string>, bcc: list<string>}  $recipients
   * @param  array<string, bool>  $seen
   */
  private function appendMinistryFallback(array &$recipients, array &$seen): void
  {
    $ministry = $this->settings->ministryEmail() ?? config('cms.notifications.admin_inbox_email');
    if (! is_string($ministry) || $ministry === '' || isset($seen[strtolower($ministry)])) {
      return;
    }
    $recipients['cc'][] = $ministry;
    $seen[strtolower($ministry)] = true;
  }

  /**
   * @param  array<string, mixed>  $context
   */
  private function emailForRoute(CommunicationRoute $route, array $context): ?string
  {
    return match ($route->recipient_type) {
      'email', 'section_email' => is_string($route->email) && $route->email !== '' ? $route->email : null,
      'assigned_admin', 'assigned_user' => $route->user?->email
        ?? (isset($context['assigned_admin_user_id']) ? User::query()->find($context['assigned_admin_user_id'])?->email : null),
      'ministry' => $this->settings->ministryEmail(),
      default => null,
    };
  }
}
