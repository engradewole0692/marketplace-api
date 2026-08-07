<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Enums\AuthAuditEventType;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AuthService implements ServiceContract
{
  public function __construct(
    private readonly AuthAuditService $auditService,
  ) {}

  /**
   * @return array{user: User, guard: string}
   */
  public function login(
    string $email,
    string $password,
    bool $remember = false,
    string $guard = 'web',
  ): array {
    $user = User::query()->where('email', $email)->first();

    if ($user === null || ! Hash::check($password, $user->password)) {
      $this->auditService->record(
        AuthAuditEventType::LoginFailed,
        email: $email,
        metadata: ['guard' => $guard],
      );

      throw new ApiException(
        ApiErrorCode::Unauthorized,
        'The provided credentials are incorrect.',
        null,
        401,
      );
    }

    if (! $user->canAuthenticate()) {
      $this->auditService->record(
        AuthAuditEventType::LoginFailed,
        user: $user,
        metadata: ['reason' => 'inactive_status', 'guard' => $guard],
      );

      throw new BusinessException(
        'Your account is not active. Please contact support.',
        ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    Auth::guard($guard)->login($user, $remember);

    $this->recordLoginActivity($user, $guard);

    return ['user' => $user->fresh(), 'guard' => $guard];
  }

  public function logout(string $guard = 'web'): void
  {
    $user = Auth::guard($guard)->user();

    if ($user instanceof User) {
      $this->auditService->record(AuthAuditEventType::Logout, user: $user, metadata: ['guard' => $guard]);
    }

    Auth::guard($guard)->logout();

    if (request()->hasSession()) {
      request()->session()->invalidate();
      request()->session()->regenerateToken();
    }

    Auth::forgetGuards();
  }

  public function recordLoginActivity(User $user, string $guard = 'web'): void
  {
    /** @var Request $request */
    $request = request();

    $user->forceFill([
      'last_login_at' => now(),
      'last_login_ip' => $request->ip(),
      'last_login_user_agent' => (string) $request->userAgent(),
    ])->save();

    $this->auditService->record(
      AuthAuditEventType::LoginSucceeded,
      user: $user,
      metadata: ['guard' => $guard, 'remember' => $request->boolean('remember')],
    );
  }

  public function invalidateOtherSessions(User $user, string $currentPassword): void
  {
    Auth::logoutOtherDevices($currentPassword);
  }
}
