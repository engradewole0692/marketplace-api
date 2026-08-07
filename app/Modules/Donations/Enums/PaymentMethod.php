<?php

declare(strict_types=1);

namespace App\Modules\Donations\Enums;

enum PaymentMethod: string
{
  case BankAccount = 'bank_account';
  case Card = 'card';
  case Flutterwave = 'flutterwave';
  case Paystack = 'paystack';
  case Stripe = 'stripe';
  case PayPal = 'paypal';
  case Offline = 'offline';
  case Wire = 'wire';
  case Crypto = 'crypto';
}
