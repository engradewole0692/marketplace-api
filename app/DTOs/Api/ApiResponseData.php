<?php

declare(strict_types=1);

namespace App\DTOs\Api;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ApiResponseData implements Arrayable
{
  /**
   * @param  array<string, mixed>|null  $meta
   * @param  array<string, mixed>|null  $errors
   */
  public function __construct(
    public bool $success,
    public mixed $data = null,
    public ?string $message = null,
    public ?string $code = null,
    public ?array $meta = null,
    public ?array $errors = null,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    $payload = [
      'success' => $this->success,
    ];

    if ($this->data !== null) {
      $payload['data'] = $this->data;
    }

    if ($this->message !== null) {
      $payload['message'] = $this->message;
    }

    if ($this->code !== null) {
      $payload['code'] = $this->code;
    }

    if ($this->meta !== null) {
      $payload['meta'] = $this->meta;
    }

    if ($this->errors !== null) {
      $payload['errors'] = $this->errors;
    }

    return $payload;
  }
}
