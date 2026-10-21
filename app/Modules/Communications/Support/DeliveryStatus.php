<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

/**
 * Recipient-level delivery states shared by campaigns, single sends and provider callbacks.
 *
 * "accepted" means the provider (or SMTP server) accepted the message; it is not proof of delivery.
 * "delivered" and "read" are only set from provider delivery evidence.
 */
final class DeliveryStatus
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const ACCEPTED = 'accepted';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const BOUNCED = 'bounced';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    /** Legacy value written before accepted/delivered were separated. Treated as accepted. */
    public const LEGACY_SENT = 'sent';

    private const RANK = [
        self::PENDING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::ACCEPTED => 3,
        self::LEGACY_SENT => 3,
        self::FAILED => 4,
        self::BOUNCED => 4,
        self::DELIVERED => 5,
        self::READ => 6,
    ];

    /**
     * A callback may only move a message forward. Final delivery evidence is never downgraded,
     * and cancelled/skipped rows were never handed to a provider.
     */
    public static function canTransition(?string $from, string $to): bool
    {
        $from = $from ?: self::PENDING;
        if (in_array($from, [self::SKIPPED, self::CANCELLED], true)) {
            return false;
        }
        if (! isset(self::RANK[$to], self::RANK[$from])) {
            return false;
        }

        return self::RANK[$to] > self::RANK[$from];
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::DELIVERED, self::READ, self::FAILED, self::BOUNCED, self::SKIPPED, self::CANCELLED], true);
    }

    /**
     * @return list<string>
     */
    public static function acceptedLike(): array
    {
        return [self::ACCEPTED, self::LEGACY_SENT, self::DELIVERED, self::READ];
    }

    /**
     * @return list<string>
     */
    public static function failureLike(): array
    {
        return [self::FAILED, self::BOUNCED];
    }
}
