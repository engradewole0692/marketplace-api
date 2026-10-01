<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Models\Event;

final class EventRegistrationQr
{
    public const KIND_PUBLIC_REGISTRATION = 'public_registration';

    public const KIND_ATTENDEE_CHECK_IN = 'attendee_check_in';

    public static function frontendBase(): string
    {
        $configured = rtrim((string) config('app-frontend.url', env('FRONTEND_URL', 'http://localhost:8081')), '/');
        $origins = array_map(
            static fn (string $origin): string => rtrim($origin, '/'),
            config('app-frontend.origins', []),
        );

        $candidate = request()?->headers->get('Origin')
            ?: request()?->headers->get('Referer');
        if (is_string($candidate) && $candidate !== '') {
            $parts = parse_url($candidate);
            if (isset($parts['scheme'], $parts['host'])) {
                $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                if ($origins === [] || in_array($origin, $origins, true)) {
                    return $origin;
                }
            }
        }

        return $configured !== '' ? $configured : 'http://localhost:8081';
    }

    public static function publicPath(Event $event): string
    {
        $identifier = trim((string) ($event->slug ?: $event->uuid));

        return '/events/'.$identifier;
    }

    public static function publicUrl(Event $event): string
    {
        return self::frontendBase().self::publicPath($event);
    }

    public static function imageUrl(string $payload, int $size = 400): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size.'&data='.rawurlencode($payload);
    }

    public static function attendeeImageUrl(string $token, int $size = 240): string
    {
        return self::imageUrl($token, $size);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Event $event): array
    {
        $url = self::publicUrl($event);
        $published = $event->published_at !== null;
        $visibility = $event->visibility instanceof \BackedEnum
            ? $event->visibility->value
            : (string) $event->visibility;

        return [
            'kind' => self::KIND_PUBLIC_REGISTRATION,
            'event_id' => $event->uuid,
            'event_title' => $event->title,
            'slug' => $event->slug,
            'url' => $url,
            'path' => self::publicPath($event),
            'qr_image_url' => self::imageUrl($url),
            'publicly_accessible' => $published && $visibility !== 'private',
            'status' => $event->status instanceof \BackedEnum ? $event->status->value : $event->status,
        ];
    }
}
