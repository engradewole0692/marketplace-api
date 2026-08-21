<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Services\YoutubeChannelFeedService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Iam\IamTestCase;

final class YoutubeVlogFeedTest extends IamTestCase
{
  public function test_public_vlog_feed_returns_channel_videos_from_cms_settings(): void
  {
    CmsSetting::query()->updateOrCreate(
      ['key' => 'vlog_youtube_channel_id'],
      [
        'group' => 'vlog',
        'value' => 'UCD7mq-tuAbI-_D-iDp5I2HA',
        'type' => 'string',
        'is_public' => true,
      ],
    );
    CmsSetting::query()->updateOrCreate(
      ['key' => 'vlog_youtube_channel_url'],
      [
        'group' => 'vlog',
        'value' => 'https://www.youtube.com/channel/UCD7mq-tuAbI-_D-iDp5I2HA',
        'type' => 'string',
        'is_public' => true,
      ],
    );

    Http::fake([
      'www.youtube.com/feeds/videos.xml*' => Http::response(
        <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
  <title>Marketplace Ministers Podcast</title>
  <entry>
    <id>yt:video:abc123XYZ01</id>
    <yt:videoId>abc123XYZ01</yt:videoId>
    <title>Episode One</title>
    <published>2026-08-01T12:00:00+00:00</published>
    <media:group>
      <media:description>First podcast episode</media:description>
    </media:group>
  </entry>
  <entry>
    <id>yt:video:def456UVW02</id>
    <yt:videoId>def456UVW02</yt:videoId>
    <title>Episode Two</title>
    <published>2026-08-10T12:00:00+00:00</published>
  </entry>
</feed>
XML,
        200,
        ['Content-Type' => 'application/atom+xml'],
      ),
    ]);

    $response = $this->getJson('/api/v1/public/vlog/feed')
      ->assertOk()
      ->assertJsonPath('data.channel_id', 'UCD7mq-tuAbI-_D-iDp5I2HA')
      ->assertJsonPath('data.items.0.metadata.youtube_id', 'abc123XYZ01')
      ->assertJsonPath('data.items.0.title', 'Episode One')
      ->assertJsonPath('data.items.1.metadata.youtube_id', 'def456UVW02');

    $this->assertSame('yt-abc123XYZ01', $response->json('data.items.0.id'));
  }

  public function test_feed_service_extracts_channel_id_from_url(): void
  {
    $service = app(YoutubeChannelFeedService::class);

    $this->assertSame(
      'UCD7mq-tuAbI-_D-iDp5I2HA',
      $service->extractChannelId('https://www.youtube.com/channel/UCD7mq-tuAbI-_D-iDp5I2HA'),
    );
  }
}
