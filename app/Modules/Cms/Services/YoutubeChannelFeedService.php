<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Models\CmsSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fetch recent videos from a YouTube channel via the public Atom RSS feed.
 * Uses CMS settings for the Vlog channel (separate from the main YouTube link).
 */
final class YoutubeChannelFeedService implements ServiceContract
{
  private const CACHE_TTL_SECONDS = 900;

  private const MAX_ITEMS = 48;

  /**
   * @return list<array{
   *   id: string,
   *   type: string,
   *   title: string,
   *   slug: string,
   *   summary: string,
   *   body: null,
   *   metadata: array<string, mixed>,
   *   category: string,
   *   tags: list<string>,
   *   featured_media_id: null,
   *   featured_image_url: string,
   *   is_active: bool,
   *   is_featured: bool,
   *   sort_order: int,
   *   published_at: string|null
   * }>
   */
  public function vlogFeed(): array
  {
    $channelId = $this->resolveVlogChannelId();
    if ($channelId === null) {
      return [];
    }

    $cacheKey = 'cms:public:vlog-youtube-feed';

    return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($channelId): array {
      return $this->fetchChannelFeed($channelId);
    });
  }

  public function resolveVlogChannelId(): ?string
  {
    $id = $this->settingValue('vlog_youtube_channel_id');
    if (is_string($id) && $this->isChannelId($id)) {
      return trim($id);
    }

    $url = $this->settingValue('vlog_youtube_channel_url');
    if (! is_string($url) || trim($url) === '') {
      return null;
    }

    return $this->extractChannelId(trim($url));
  }

  public function extractChannelId(string $input): ?string
  {
    $input = trim($input);
    if ($this->isChannelId($input)) {
      return $input;
    }

    if (preg_match('#youtube\.com/channel/(UC[\w-]{20,})#i', $input, $m)) {
      return $m[1];
    }

    return null;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function fetchChannelFeed(string $channelId): array
  {
    $feedUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId;

    try {
      $response = Http::timeout(12)
        ->accept('application/atom+xml, application/xml, text/xml, */*')
        ->get($feedUrl);

      if (! $response->successful()) {
        return [];
      }

      return $this->parseAtomFeed($response->body(), $channelId);
    } catch (\Throwable) {
      return [];
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function parseAtomFeed(string $xml, string $channelId): array
  {
    $previous = libxml_use_internal_errors(true);
    try {
      $document = simplexml_load_string($xml);
      if ($document === false) {
        return [];
      }

      $document->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
      $document->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
      $document->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');

      $entries = $document->xpath('//atom:entry') ?: [];
      $items = [];
      $index = 0;

      foreach ($entries as $entry) {
        if ($index >= self::MAX_ITEMS) {
          break;
        }

        $entry->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
        $entry->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
        $entry->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');

        $videoIdNodes = $entry->xpath('yt:videoId') ?: $entry->xpath('.//yt:videoId');
        $videoId = isset($videoIdNodes[0]) ? trim((string) $videoIdNodes[0]) : '';
        if ($videoId === '') {
          $idNodes = $entry->xpath('atom:id') ?: [];
          $idAttr = isset($idNodes[0]) ? trim((string) $idNodes[0]) : '';
          if (str_contains($idAttr, ':')) {
            $videoId = (string) Str::afterLast($idAttr, ':');
          }
        }
        if ($videoId === '' || ! preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
          continue;
        }

        $titleNodes = $entry->xpath('atom:title') ?: [];
        $title = isset($titleNodes[0]) ? trim((string) $titleNodes[0]) : 'Untitled video';

        $summary = '';
        $summaryNodes = $entry->xpath('atom:summary') ?: [];
        if (isset($summaryNodes[0])) {
          $summary = trim((string) $summaryNodes[0]);
        }
        if ($summary === '') {
          $mediaGroup = $entry->xpath('media:group/media:description')
            ?: $entry->xpath('.//media:description');
          $summary = isset($mediaGroup[0]) ? trim((string) $mediaGroup[0]) : '';
        }

        $publishedNodes = $entry->xpath('atom:published') ?: $entry->xpath('atom:updated') ?: [];
        $published = isset($publishedNodes[0]) ? trim((string) $publishedNodes[0]) : '';
        $publishedIso = $published !== '' ? date('c', strtotime($published) ?: time()) : null;
        $thumbnail = 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg';
        $slug = Str::slug(Str::limit($title, 60, '')).'-'.$videoId;

        $items[] = [
          'id' => 'yt-'.$videoId,
          'type' => 'vlog',
          'title' => $title,
          'slug' => $slug,
          'summary' => Str::limit($summary, 500),
          'body' => null,
          'metadata' => [
            'youtube_id' => $videoId,
            'youtube_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'source' => 'youtube_channel_feed',
            'channel_id' => $channelId,
            'published_label' => $publishedIso
              ? date('F j, Y', strtotime($publishedIso) ?: time())
              : '',
            'duration' => '',
            'duration_seconds' => 0,
            'view_count' => 0,
            'view_label' => '',
          ],
          'category' => 'YouTube',
          'tags' => [],
          'featured_media_id' => null,
          'featured_image_url' => $thumbnail,
          'is_active' => true,
          'is_featured' => $index === 0,
          'sort_order' => $index,
          'published_at' => $publishedIso,
        ];
        $index++;
      }

      return $items;
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  private function settingValue(string $key): mixed
  {
    $setting = CmsSetting::query()->where('key', $key)->first();
    $value = $setting?->value;

    if (is_array($value) && array_key_exists('value', $value) && is_string($value['value'])) {
      return $value['value'];
    }

    return $value;
  }

  private function isChannelId(string $value): bool
  {
    return (bool) preg_match('/^UC[\w-]{20,}$/', trim($value));
  }
}
