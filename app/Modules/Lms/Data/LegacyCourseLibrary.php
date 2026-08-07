<?php

declare(strict_types=1);

namespace App\Modules\Lms\Data;

/**
 * Canonical legacy YouTube / vlog library mirrored from the public frontend catalog.
 * Migration imports this into LMS without rebuilding courses that already exist by slug.
 */
final class LegacyCourseLibrary
{
  public static function channelUrl(): string
  {
    $fromEnv = trim((string) (env('YOUTUBE_CHANNEL_URL') ?: env('VITE_YOUTUBE_URL') ?: ''));

    return $fromEnv !== '' ? $fromEnv : 'https://www.youtube.com';
  }

  /**
   * @return list<string>
   */
  public static function categories(): array
  {
    return [
      'Teachings',
      'Masterclasses',
      'Conversations',
      'Events',
      'Prayer',
      'Leadership',
      'Formation',
      'Kingdom Economics',
      'Professional Excellence',
      'Outreach',
      'Family & Life',
      'Resources',
    ];
  }

  /**
   * @return list<array{
   *   id: string,
   *   slug: string,
   *   title: string,
   *   description: string,
   *   duration: string,
   *   duration_seconds: int,
   *   published_at: string,
   *   youtube_id: string,
   *   category: string,
   *   playlist_ids: list<string>,
   *   view_count: int,
   *   featured?: bool,
   *   popular?: bool,
   *   cover_asset: string
   * }>
   */
  public static function videos(): array
  {
    return [
      self::video('v1', 'The Mandate of the Marketplace Minister', 'A foundational message on the divine assignment of marketplace ministers — called to transform industries with biblical conviction.', '32:14', 1934, '2026-06-15T10:00:00Z', '', 'Teachings', ['foundations'], 18400, 'event-prayer', true, true),
      self::video('v2', 'Kingdom Capital — A Conversation', 'Kingdom funders and executives discuss deploying capital for eternal impact without compromising biblical stewardship.', '48:02', 2882, '2026-05-28T14:00:00Z', '', 'Conversations', ['kingdom-capital'], 12300, 'marketplace-professionals', false, true),
      self::video('v3', 'Why We Pray Before We Build', 'Prayer Ministry leaders explain the altar-first rhythm that anchors every executive decision.', '21:47', 1307, '2026-05-14T09:00:00Z', '', 'Prayer', ['prayer-watch'], 9800, 'event-masterclass', false, true),
      self::video('v4', 'Forerunners Cohort Highlights', 'Highlights from the latest Forerunners cohort — next-generation marketplace leaders in formation.', '12:09', 729, '2026-04-22T16:00:00Z', '', 'Events', ['forerunners'], 7600, 'event-summit'),
      self::video('v5', 'Excellence as Worship', 'Faith & Works masterclass on pursuing professional excellence as an act of worship.', '26:33', 1593, '2026-04-08T11:00:00Z', '', 'Masterclasses', ['faith-and-works'], 11200, 'about-movement', false, true),
      self::video('v6', 'The Tribe at Work', 'Documentary-style look at marketplace ministers carrying the mandate across cities and industries.', '18:55', 1135, '2026-03-19T10:00:00Z', '', 'Events', ['global-summit'], 15400, 'hero-summit'),
      self::video('v7', 'Executive Prayer Forum — Lagos', 'Full session from the monthly executive prayer forum covering Nigeria\'s marketplace leaders.', '41:20', 2480, '2026-03-05T08:00:00Z', '', 'Prayer', ['prayer-watch'], 6200, 'event-prayer'),
      self::video('v8', 'Leading with Integrity in Finance', 'Banking and fintech leaders share how biblical integrity shapes high-stakes financial decisions.', '35:48', 2148, '2026-02-18T13:00:00Z', '', 'Leadership', ['foundations'], 8900, 'marketplace-professionals'),
      self::video('v9', 'Global Summit 2026 — Opening Session', 'Opening plenary from the annual Global Summit — one Tribe, many nations, one mandate.', '54:12', 3252, '2026-02-01T09:00:00Z', '', 'Events', ['global-summit'], 22100, 'hero-summit', false, true),
      self::video('v10', 'Women Rising — Panel Discussion', 'Daughters of the Kingdom discuss influence, mentorship, and excellence in the marketplace.', '38:05', 2285, '2026-01-20T15:00:00Z', '', 'Conversations', ['foundations'], 10500, 'about-movement'),
      self::video('v11', 'Outreach as Overflow — Field Report', 'Outreach teams share stories from mercy initiatives and city engagement campaigns.', '16:44', 1004, '2026-01-08T12:00:00Z', '', 'Teachings', ['forerunners'], 5400, 'event-summit'),
      self::video('v12', 'Marketplace Masterclass — Negotiation & Conviction', 'Practical masterclass on negotiating with excellence while holding biblical conviction.', '29:18', 1758, '2025-12-15T10:00:00Z', '', 'Masterclasses', ['faith-and-works'], 7100, 'event-masterclass'),
    ];
  }

  /**
   * @return list<array{
   *   id: string,
   *   slug: string,
   *   title: string,
   *   description: string,
   *   cover_asset: string,
   *   video_ids: list<string>
   * }>
   */
  public static function playlists(): array
  {
    return [
      [
        'id' => 'foundations',
        'slug' => 'foundations',
        'title' => 'Foundations',
        'description' => 'Core teachings for every marketplace minister.',
        'cover_asset' => 'event-prayer',
        'video_ids' => ['v1', 'v8', 'v10'],
      ],
      [
        'id' => 'prayer-watch',
        'slug' => 'prayer-watch',
        'title' => 'Prayer Watch',
        'description' => 'Intercession, altars, and executive prayer forums.',
        'cover_asset' => 'event-masterclass',
        'video_ids' => ['v3', 'v7'],
      ],
      [
        'id' => 'global-summit',
        'slug' => 'global-summit',
        'title' => 'Global Summit',
        'description' => 'Annual convergence highlights and plenary sessions.',
        'cover_asset' => 'hero-summit',
        'video_ids' => ['v6', 'v9'],
      ],
      [
        'id' => 'faith-and-works',
        'slug' => 'faith-and-works',
        'title' => 'Faith & Works',
        'description' => 'Masterclasses on professional excellence and conviction.',
        'cover_asset' => 'about-movement',
        'video_ids' => ['v5', 'v12'],
      ],
      [
        'id' => 'kingdom-capital',
        'slug' => 'kingdom-capital',
        'title' => 'Kingdom Capital',
        'description' => 'Conversations on stewardship and Kingdom economics.',
        'cover_asset' => 'marketplace-professionals',
        'video_ids' => ['v2'],
      ],
      [
        'id' => 'forerunners',
        'slug' => 'forerunners',
        'title' => 'Forerunners',
        'description' => 'Next-generation leaders and cohort highlights.',
        'cover_asset' => 'event-summit',
        'video_ids' => ['v4', 'v11'],
      ],
    ];
  }

  public static function resourcesCourseSlug(): string
  {
    return 'kingdom-resources-library';
  }

  /**
   * @return array{
   *   id: string,
   *   slug: string,
   *   title: string,
   *   description: string,
   *   duration: string,
   *   duration_seconds: int,
   *   published_at: string,
   *   youtube_id: string,
   *   category: string,
   *   playlist_ids: list<string>,
   *   view_count: int,
   *   featured: bool,
   *   popular: bool,
   *   cover_asset: string
   * }
   */
  private static function video(
    string $id,
    string $title,
    string $description,
    string $duration,
    int $durationSeconds,
    string $publishedAt,
    string $youtubeId,
    string $category,
    array $playlistIds,
    int $viewCount,
    string $coverAsset,
    bool $featured = false,
    bool $popular = false,
  ): array {
    return [
      'id' => $id,
      'slug' => self::slugify($title),
      'title' => $title,
      'description' => $description,
      'duration' => $duration,
      'duration_seconds' => $durationSeconds,
      'published_at' => $publishedAt,
      'youtube_id' => $youtubeId,
      'category' => $category,
      'playlist_ids' => $playlistIds,
      'view_count' => $viewCount,
      'featured' => $featured,
      'popular' => $popular,
      'cover_asset' => $coverAsset,
    ];
  }

  public static function slugify(string $title): string
  {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '');

    return trim($slug, '-');
  }
}
