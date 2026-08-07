<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Enums\AuthAuditEventType;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordService implements ServiceContract
{
  public function __construct(
    private readonly AuthAuditService $auditService,
  ) {}

  public function sendResetLink(string $email): string
  {
    $status = Password::sendResetLink(['email' => $email]);

    if ($status !== Password::RESET_LINK_SENT) {
      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        __($status),
        null,
        422,
      );
    }

    $user = User::query()->where('email', $email)->first();
    $this->auditService->record(
      AuthAuditEventType::PasswordResetRequested,
      user: $user,
      email: $email,
    );

    return __($status);
  }

  /**
   * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
   */
  public function resetPassword(array $credentials): string
  {
    $status = Password::reset(
      $credentials,
      function (User $user, string $password): void {
        $user->forceFill([
          'password' => $password,
          'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));

        $this->auditService->record(
          AuthAuditEventType::PasswordResetCompleted,
          user: $user,
        );

        $this->invalidateUserSessions($user);
      },
    );

    if ($status !== Password::PASSWORD_RESET) {
      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        __($status),
        null,
        422,
      );
    }

    return __($status);
  }

  public function changePassword(User $user, string $currentPassword, string $newPassword): void
  {
    if (! Hash::check($currentPassword, $user->password)) {
      throw new ApiException(
        ApiErrorCode::Unauthorized,
        'The current password is incorrect.',
        ['current_password' => ['The current password is incorrect.']],
        401,
      );
    }

    $webGuard = Auth::guard('web');
    if ($webGuard->check() && method_exists($webGuard, 'logoutOtherDevices')) {
      $webGuard->logoutOtherDevices($currentPassword);
    } else {
      $this->invalidateUserSessions($user);
    }

    $user->forceFill([
      'password' => $newPassword,
      'remember_token' => Str::random(60),
      'must_change_password' => false,
      'activation_token' => null,
      'activated_at' => $user->activated_at ?? now(),
    ])->save();

    $user->tokens()->delete();

    $this->auditService->record(AuthAuditEventType::PasswordChanged, user: $user);
  }

  private function invalidateUserSessions(User $user): void
  {
    $user->tokens()->delete();

    if (class_exists(\Illuminate\Support\Facades\DB::class)) {
      \Illuminate\Support\Facades\DB::table('sessions')
        ->where('user_id', $user->id)
        ->delete();
    }
  }
}
