<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Models\CourseInvoice;
use App\Modules\Lms\Models\CourseOrder;
use Illuminate\Support\Facades\Storage;

final class CourseInvoiceService implements ServiceContract
{
  public function issueInvoice(CourseOrder $order, ?User $actor = null): CourseInvoice
  {
    $existing = CourseInvoice::query()
      ->where('order_id', $order->id)
      ->where('type', 'invoice')
      ->first();
    if ($existing) {
      return $existing;
    }

    $order->loadMissing(['course', 'user', 'enrollment']);
    $number = sprintf('INV-LMS-%s-%04d', now()->format('Ymd'), CourseInvoice::query()->count() + 1);
    $html = $this->render($order, $number, 'Invoice');
    $path = 'lms/invoices/'.$number.'.html';
    Storage::disk('public')->put($path, $html);

    return CourseInvoice::query()->create([
      'order_id' => $order->id,
      'invoice_number' => $number,
      'type' => 'invoice',
      'pdf_path' => $path,
      'issued_at' => now(),
      'issued_by_user_id' => $actor?->id,
    ]);
  }

  public function issueReceipt(CourseOrder $order, ?User $actor = null): CourseInvoice
  {
    $existing = CourseInvoice::query()
      ->where('order_id', $order->id)
      ->where('type', 'receipt')
      ->first();
    if ($existing) {
      return $existing;
    }

    $order->loadMissing(['course', 'user', 'enrollment']);
    $number = sprintf('RCPT-LMS-%s-%04d', now()->format('Ymd'), CourseInvoice::query()->count() + 1);
    $html = $this->render($order, $number, 'Payment Receipt');
    $path = 'lms/receipts/'.$number.'.html';
    Storage::disk('public')->put($path, $html);

    return CourseInvoice::query()->create([
      'order_id' => $order->id,
      'invoice_number' => $number,
      'type' => 'receipt',
      'pdf_path' => $path,
      'issued_at' => now(),
      'issued_by_user_id' => $actor?->id,
    ]);
  }

  private function render(CourseOrder $order, string $number, string $label): string
  {
    $learner = e((string) ($order->user?->name ?? 'Learner'));
    $course = e((string) ($order->course?->title ?? 'Course'));
    $amount = e($order->currency.' '.number_format((float) $order->amount, 2));
    $orderNo = e($order->order_number);
    $date = e(($order->paid_at ?? now())->toDateString());
    $coupon = $order->coupon_code ? '<p>Coupon: '.e($order->coupon_code).'</p>' : '';

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$label} {$number}</title></head>
<body>
  <h1>{$label}</h1>
  <p>Number: {$number}</p>
  <p>Order: {$orderNo}</p>
  <p>Learner: {$learner}</p>
  <p>Course: {$course}</p>
  <p>Amount: {$amount}</p>
  {$coupon}
  <p>Date: {$date}</p>
  <p>Marketplace Ministers — Course commerce</p>
</body></html>
HTML;
  }
}
