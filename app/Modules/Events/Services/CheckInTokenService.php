<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CheckInTokenService implements ServiceContract
{
  /**
   * Issue a new token for a registration.
   *
   * @return array{token: string, model: EventCheckInToken}
   */
  public function issue(EventRegistration $registration, ?Carbon $expiresAt = null, ?User $actor = null): array
  {
    return DB::transaction(function () use ($registration, $expiresAt, $actor): array {
      EventCheckInToken::query()->where('registration_id', $registration->id)->delete();

      $plaintext = $this->generatePlaintext();

      $model = EventCheckInToken::query()->create([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'token_hash' => hash('sha256', $plaintext),
        'issued_at' => now(),
        'expires_at' => $expiresAt,
        'metadata' => [
          ...($actor !== null ? ['issued_by_user_id' => $actor->id] : []),
          'token_cipher' => Crypt::encryptString($plaintext),
        ],
      ]);

      $model->plaintextToken = $plaintext;

      return ['token' => $plaintext, 'model' => $model];
    });
  }

  /**
   * Revoke existing token (if any) and issue a fresh one.
   *
   * @return array{token: string, model: EventCheckInToken}
   */
  public function regenerate(EventRegistration $registration, ?Carbon $expiresAt = null, ?User $actor = null): array
  {
    return $this->issue($registration, $expiresAt, $actor);
  }

  /**
   * Return the existing plaintext token when it can be decrypted; otherwise issue a new one.
   * Portal/email display must not silently rotate a token that was already delivered.
   *
   * @return array{token: string, model: EventCheckInToken, rotated: bool}
   */
  public function reveal(EventRegistration $registration, ?User $actor = null): array
  {
    $existing = EventCheckInToken::query()
      ->where('registration_id', $registration->id)
      ->whereNull('revoked_at')
      ->latest('id')
      ->first();

    if ($existing !== null) {
      $plaintext = $this->decryptCipher($existing);
      if ($plaintext !== null) {
        $existing->plaintextToken = $plaintext;

        return ['token' => $plaintext, 'model' => $existing, 'rotated' => false];
      }
    }

    $issued = $this->issue($registration, null, $actor);

    return ['token' => $issued['token'], 'model' => $issued['model'], 'rotated' => true];
  }

  public function validate(string $plaintext): EventCheckInToken
  {
    $hash = hash('sha256', trim($plaintext));

    $token = EventCheckInToken::query()->where('token_hash', $hash)->first();

    if ($token === null) {
      throw ValidationException::withMessages(['token' => ['Invalid check-in token.']]);
    }

    if ($token->revoked_at !== null) {
      throw ValidationException::withMessages(['token' => ['This check-in token has been revoked.']]);
    }

    if ($token->expires_at !== null && now()->gt($token->expires_at)) {
      throw ValidationException::withMessages(['token' => ['This check-in token has expired.']]);
    }

    return $token;
  }

  public function revoke(EventCheckInToken $token, User $actor): EventCheckInToken
  {
    $token->revoked_at = now();
    $token->revoked_by_user_id = $actor->id;
    $token->save();

    return $token->fresh();
  }

  private function generatePlaintext(): string
  {
    return Str::upper(Str::random(24));
  }

  private function decryptCipher(EventCheckInToken $token): ?string
  {
    $cipher = $token->metadata['token_cipher'] ?? null;
    if (! is_string($cipher) || $cipher === '') {
      return null;
    }

    try {
      $plaintext = Crypt::decryptString($cipher);
    } catch (\Throwable) {
      return null;
    }

    if (hash('sha256', $plaintext) !== $token->token_hash) {
      return null;
    }

    return $plaintext;
  }
}
