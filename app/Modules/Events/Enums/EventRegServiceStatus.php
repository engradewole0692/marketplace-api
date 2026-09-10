<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventRegServiceStatus: string
{
    case Requested = 'requested';
    case UnderReview = 'under_review';
    case PaymentVerified = 'payment_verified';
    case QuoteProvided = 'quote_provided';
    case AwaitingPayment = 'awaiting_payment';
    case BookingInProgress = 'booking_in_progress';
    case Confirmed = 'confirmed';
    case Booked = 'booked';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function isConfirmed(): bool
    {
        return in_array($this, [self::Confirmed, self::Booked], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::UnderReview => 'Under Review',
            self::PaymentVerified => 'Payment Verified',
            self::QuoteProvided => 'Quote Provided',
            self::AwaitingPayment => 'Awaiting Payment',
            self::BookingInProgress => 'Booking In Progress',
            self::Confirmed => 'Confirmed',
            self::Booked => 'Booked',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }
}
