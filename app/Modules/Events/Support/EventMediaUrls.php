<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Cms\Models\CmsMedia;

final class EventMediaUrls
{
    /**
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    public static function fromIds(array $ids): array
    {
        $numeric = [];
        $uuids = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $numeric[] = (int) $id;
            } elseif (is_string($id) && $id !== '') {
                $uuids[] = $id;
            }
        }

        if ($numeric === [] && $uuids === []) {
            return [];
        }

        return CmsMedia::query()
            ->where(function ($query) use ($numeric, $uuids): void {
                if ($numeric !== []) {
                    $query->orWhereIn('id', $numeric);
                }
                if ($uuids !== []) {
                    $query->orWhereIn('uuid', $uuids);
                }
            })
            ->get()
            ->map(fn (CmsMedia $media) => self::absolute($media->url()))
            ->filter()
            ->values()
            ->all();
    }

    public static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}