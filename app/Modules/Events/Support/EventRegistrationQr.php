<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Models\Event;

final class EventRegistrationQr
{
    public static function publicUrl(Event $event): string
    {
        $base = rtrim((string) config('app-frontend.url', env('FRONTEND_URL', 'http://localhost:8081')), '/');
        $slug = $event->slug ?: $event->uuid;

        return $base.'/events/'.$slug;
    }

    public static function imageUrl(string $url, int $size = 400): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size.'&data='.rawurlencode($url);
    }

    /**
     * @return array{url: string, qr_image_url: string, slug: string|null}
     */
    public static function payload(Event $event): array
    {
        $url = self::publicUrl($event);

        return [
            'url' => $url,
            'qr_image_url' => self::imageUrl($url),
            'slug' => $event->slug,
        ];
    }
}
