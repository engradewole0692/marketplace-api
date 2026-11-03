<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

/**
 * Splits pasted email lists on commas, semicolons, tabs and line breaks.
 *
 * Plain spaces are not treated as separators on their own: a token containing spaces is only split
 * when every space-separated part is a valid address, and "Name <address>" entries keep the address.
 * Anything else is reported as invalid so the administrator can correct it.
 */
final class EmailListParser
{
  public const MAX_LENGTH = 254;

  /**
   * @param  iterable<mixed>|string  $input
   * @return array{
   *   total: int,
   *   valid: list<string>,
   *   invalid: list<string>,
   *   duplicates: list<string>
   * }
   */
  public static function parse(iterable|string $input): array
  {
    $tokens = [];
    foreach (is_string($input) ? [$input] : $input as $chunk) {
      if (! is_string($chunk)) {
        continue;
      }
      foreach (preg_split('/[,;\r\n\t]+/u', $chunk) ?: [] as $token) {
        foreach (self::expand($token) as $entry) {
          $tokens[] = $entry;
        }
      }
    }

    $valid = [];
    $invalid = [];
    $duplicates = [];
    $seen = [];
    foreach ($tokens as $token) {
      $email = self::normalize($token);
      if ($email === null) {
        $invalid[] = $token;

        continue;
      }
      if (isset($seen[$email])) {
        $duplicates[] = $email;

        continue;
      }
      $seen[$email] = true;
      $valid[] = $email;
    }

    return [
      'total' => count($tokens),
      'valid' => $valid,
      'invalid' => $invalid,
      'duplicates' => $duplicates,
    ];
  }

  /** Lowercased, trimmed address, or null when it is not a valid email. */
  public static function normalize(string $value): ?string
  {
    $email = strtolower(trim($value));
    if ($email === '' || strlen($email) > self::MAX_LENGTH) {
      return null;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
  }

  /**
   * @return list<string>
   */
  private static function expand(string $token): array
  {
    $token = trim($token, " \u{00A0}\"'");
    if ($token === '') {
      return [];
    }
    if (preg_match('/<([^<>]+)>\s*$/u', $token, $match) === 1) {
      $token = trim($match[1]);
    }
    if (stripos($token, 'mailto:') === 0) {
      $token = substr($token, 7);
    }
    if (! preg_match('/\s/u', $token)) {
      return [$token];
    }

    $parts = preg_split('/\s+/u', $token) ?: [];
    foreach ($parts as $part) {
      if (self::normalize($part) === null) {
        return [$token];
      }
    }

    return array_values($parts);
  }
}
