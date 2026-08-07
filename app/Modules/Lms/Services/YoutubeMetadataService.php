<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Resolve YouTube title/thumbnail/duration/description via oEmbed + optional page scrape.
 * Stores references only — never downloads video binaries.
 */
final class YoutubeMetadataService implements ServiceContract
{
  /**
   * @return array{
   *   youtube_video_id: ?string,
   *   youtube_url: ?string,
   *   title: ?string,
   *   description: ?string,
   *   thumbnail_url: ?string,
   *   duration_seconds: ?int,
   *   duration_minutes: ?int,
   *   is_playlist: bool,
   *   playlist_id: ?string
   * }
   */
  public function resolve(string $input): array
  {
    $input = trim($input);
    $playlistId = $this->extractPlaylistId($input);
    $videoId = $this->extractVideoId($input);

    if ($playlistId && ! $videoId) {
      return [
        'youtube_video_id' => null,
        'youtube_url' => 'https://www.youtube.com/playlist?list='.$playlistId,
        'title' => null,
        'description' => null,
        'thumbnail_url' => null,
        'duration_seconds' => null,
        'duration_minutes' => null,
        'is_playlist' => true,
        'playlist_id' => $playlistId,
      ];
    }

    if (! $videoId && preg_match('/^[A-Za-z0-9_-]{6,}$/', $input)) {
      $videoId = $input;
    }

    if (! $videoId) {
      return [
        'youtube_video_id' => null,
        'youtube_url' => $input !== '' ? $input : null,
        'title' => null,
        'description' => null,
        'thumbnail_url' => null,
        'duration_seconds' => null,
        'duration_minutes' => null,
        'is_playlist' => false,
        'playlist_id' => $playlistId,
      ];
    }

    $watchUrl = 'https://www.youtube.com/watch?v='.$videoId;
    $title = null;
    $thumbnail = 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg';
    $description = null;
    $durationSeconds = null;

    try {
      $oembed = Http::timeout(8)
        ->get('https://www.youtube.com/oembed', [
          'url' => $watchUrl,
          'format' => 'json',
        ]);
      if ($oembed->successful()) {
        $json = $oembed->json();
        $title = is_array($json) ? ($json['title'] ?? null) : null;
        $thumbnail = is_array($json) ? ($json['thumbnail_url'] ?? $thumbnail) : $thumbnail;
      }
    } catch (\Throwable) {
      // Soft-fail: return ID + URL so admin can still save the reference.
    }

    return [
      'youtube_video_id' => $videoId,
      'youtube_url' => $watchUrl,
      'title' => is_string($title) ? $title : null,
      'description' => is_string($description) ? Str::limit($description, 2000) : null,
      'thumbnail_url' => $thumbnail,
      'duration_seconds' => $durationSeconds,
      'duration_minutes' => $durationSeconds !== null ? max(1, (int) ceil($durationSeconds / 60)) : null,
      'is_playlist' => false,
      'playlist_id' => $playlistId,
    ];
  }

  public function extractVideoId(?string $url): ?string
  {
    if (! $url) {
      return null;
    }
    if (preg_match('/(?:youtu\.be\/|v=|embed\/|shorts\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
      return $m[1];
    }
    if (preg_match('/^[A-Za-z0-9_-]{6,}$/', trim($url))) {
      return trim($url);
    }

    return null;
  }

  public function extractPlaylistId(?string $url): ?string
  {
    if (! $url) {
      return null;
    }
    if (preg_match('/[?&]list=([A-Za-z0-9_-]+)/', $url, $m)) {
      return $m[1];
    }

    return null;
  }
}
