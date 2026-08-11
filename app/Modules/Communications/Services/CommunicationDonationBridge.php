<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Donations\Models\Donation;

final class CommunicationDonationBridge implements ServiceContract
{
  public function __construct(
    private readonly CommunicationDispatchService $dispatch,
  ) {}

  public function notifyInitiated(Donation $donation): void
  {
    if ($this->isLmsCommerceDonation($donation)) {
      return;
    }

    $this->safeDispatch(
      'donation.initiated',
      'donations',
      $this->variables($donation),
      $donation->donor_email,
      $donation->donor_name ?? 'Donor',
      $donation,
      "donation.initiated:{$donation->uuid}",
      false,
    );
  }

  public function notifySucceeded(Donation $donation): void
  {
    if ($this->isLmsCommerceDonation($donation)) {
      return;
    }

    $this->safeDispatch(
      'donation.succeeded',
      'donations',
      $this->variables($donation),
      $donation->donor_email,
      $donation->donor_name ?? 'Donor',
      $donation,
      "donation.succeeded:{$donation->uuid}",
      false,
    );

    $this->safeDispatch(
      'donation.succeeded.admin',
      'donations',
      $this->variables($donation),
      null,
      null,
      $donation,
      "donation.succeeded.admin:{$donation->uuid}",
      true,
    );
  }

  public function notifyFailed(Donation $donation, ?string $reason = null): void
  {
    if ($this->isLmsCommerceDonation($donation)) {
      return;
    }

    $vars = array_merge($this->variables($donation), [
      'reason' => $reason ?? 'Payment could not be completed.',
    ]);

    $this->safeDispatch(
      'donation.failed',
      'donations',
      $vars,
      $donation->donor_email,
      $donation->donor_name ?? 'Donor',
      $donation,
      "donation.failed:{$donation->uuid}",
      false,
    );
  }

  private function isLmsCommerceDonation(Donation $donation): bool
  {
    $purpose = $donation->metadata['purpose'] ?? null;

    return in_array($purpose, ['course_order', 'school_order'], true);
  }

  /** @return array<string, mixed> */
  private function variables(Donation $donation): array
  {
    $donation->loadMissing(['fund']);

    return [
      'applicant_name' => $donation->donor_name ?? 'Donor',
      'member_name' => $donation->donor_name ?? 'Donor',
      'email' => $donation->donor_email ?? '',
      'amount' => number_format((float) $donation->amount, 2),
      'currency' => $donation->currency ?? 'USD',
      'payment_reference' => $donation->reference,
      'fund_name' => $donation->fund?->name ?? 'General fund',
      'in_app_title' => 'Donation '.$donation->reference,
      'in_app_body' => number_format((float) $donation->amount, 2).' '.$donation->currency,
    ];
  }

  /**
   * @param  array<string, mixed>  $variables
   */
  private function safeDispatch(
    string $eventKey,
    string $section,
    array $variables,
    ?string $email,
    ?string $name,
    Donation $donation,
    ?string $idempotencyKey,
    bool $includeRouting,
  ): void {
    if ($idempotencyKey && app(CommunicationIdempotencyService::class)->alreadyDispatched($idempotencyKey)) {
      return;
    }

    try {
      $this->dispatch->dispatchEvent(
        eventKey: $eventKey,
        section: $section,
        variables: $variables,
        recipientEmail: $email,
        recipientName: $name,
        related: $donation,
        includeRouting: $includeRouting,
        idempotencyKey: $idempotencyKey,
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
