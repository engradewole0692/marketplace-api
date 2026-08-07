<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthAuditEventType: string
{
  case LoginSucceeded = 'login_succeeded';
  case LoginFailed = 'login_failed';
  case Logout = 'logout';
  case PasswordResetRequested = 'password_reset_requested';
  case PasswordResetCompleted = 'password_reset_completed';
  case PasswordChanged = 'password_changed';
}
