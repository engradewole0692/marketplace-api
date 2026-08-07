<?php

declare(strict_types=1);

namespace App\Modules\Donations\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Donations\Enums\ReceiptType;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationReceipt;
use Illuminate\Support\Facades\Storage;

final class DonationReceiptService implements ServiceContract
{
  public function __construct(
    private readonly DonationAuditService $auditService,
  ) {}

  public function issue(Donation $donation, ReceiptType $type, ?User $actor = null): DonationReceipt
  {
    $existing = DonationReceipt::query()
      ->where('donation_id', $donation->id)
      ->where('type', $type->value)
      ->first();

    if ($existing !== null) {
      return $existing;
    }

    $number = sprintf(
      'RCPT-%s-%s-%04d',
      strtoupper($type->value === 'tax' ? 'TAX' : 'STD'),
      now()->format('Ymd'),
      DonationReceipt::query()->count() + 1,
    );

    $content = $this->renderReceiptHtml($donation, $type, $number);
    $path = 'donations/receipts/'.$number.'.html';
    Storage::disk('public')->put($path, $content);

    $receipt = DonationReceipt::query()->create([
      'donation_id' => $donation->id,
      'type' => $type,
      'number' => $number,
      'tax_year' => (int) now()->format('Y'),
      'country_id' => $donation->country_id,
      'pdf_path' => $path,
      'issued_at' => now(),
      'issued_by' => $actor?->id,
    ]);

    $this->auditService->record('receipt_issued', 'donation_receipt', $receipt->id, $actor, null, [
      'number' => $number,
      'type' => $type->value,
      'donation_id' => $donation->uuid,
    ]);

    return $receipt;
  }

  private function renderReceiptHtml(Donation $donation, ReceiptType $type, string $number): string
  {
    $donor = e($donation->displayDonorName());
    $amount = e($donation->currency.' '.$donation->amount);
    $fund = e($donation->fund?->name ?? 'General');
    $label = $type === ReceiptType::Tax ? 'Tax Receipt' : 'Donation Receipt';

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$label} {$number}</title></head>
<body>
  <h1>{$label}</h1>
  <p>Receipt #: {$number}</p>
  <p>Reference: {$donation->reference}</p>
  <p>Donor: {$donor}</p>
  <p>Amount: {$amount}</p>
  <p>Fund: {$fund}</p>
  <p>Date: {$donation->paid_at?->toDateString()}</p>
  <p>Marketplace Ministers — Thank you for your generosity.</p>
</body></html>
HTML;
  }
}
