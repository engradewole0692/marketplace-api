<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsGallerySource;
use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Services\YoutubeChannelFeedService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class PublicSiteContinuityTest extends IamTestCase
{
  public function test_contact_whatsapp_setting_is_public_on_site_bootstrap(): void
  {
    Cache::flush();

    CmsSetting::query()->updateOrCreate(
      ['key' => 'contact.whatsapp'],
      [
        'group' => 'contact',
        'value' => '+11434567897',
        'type' => 'string',
        'is_public' => true,
      ],
    );

    $settings = $this->getJson('/api/v1/public/site')->assertOk()->json('data.settings');
    $this->assertSame('+11434567897', $settings['contact.whatsapp'] ?? null);
  }

  public function test_vlog_feed_resolves_youtube_handle_without_hardcoded_videos(): void
  {
    Cache::flush();

    CmsSetting::query()->updateOrCreate(
      ['key' => 'vlog_youtube_channel_url'],
      [
        'group' => 'vlog',
        'value' => 'https://www.youtube.com/@themarketplaceministers',
        'type' => 'string',
        'is_public' => true,
      ],
    );
    CmsSetting::query()->where('key', 'vlog_youtube_channel_id')->delete();

    Http::fake([
      'www.youtube.com/@themarketplaceministers' => Http::response(
        '<html><link rel="canonical" href="https://www.youtube.com/channel/UCD7mq-tuAbI-_D-iDp5I2HA"><script>"channelId":"UCD7mq-tuAbI-_D-iDp5I2HA"</script></html>',
        200,
      ),
      'www.youtube.com/feeds/videos.xml*' => Http::response(
        <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>yt:video:handleVid01</id>
    <yt:videoId>handleVid01</yt:videoId>
    <title>Handle Episode</title>
    <published>2026-08-20T12:00:00+00:00</published>
  </entry>
</feed>
XML,
        200,
        ['Content-Type' => 'application/atom+xml'],
      ),
    ]);

    $this->getJson('/api/v1/public/vlog/feed')
      ->assertOk()
      ->assertJsonPath('data.channel_id', 'UCD7mq-tuAbI-_D-iDp5I2HA')
      ->assertJsonPath('data.items.0.metadata.youtube_id', 'handleVid01')
      ->assertJsonPath('data.items.0.title', 'Handle Episode');
  }

  public function test_feed_service_extracts_channel_id_from_handle_url(): void
  {
    Http::fake([
      'www.youtube.com/@themarketplaceministers' => Http::response(
        '<html><script>"channelId":"UCD7mq-tuAbI-_D-iDp5I2HA"</script></html>',
        200,
      ),
    ]);

    $service = app(YoutubeChannelFeedService::class);

    $this->assertSame(
      'UCD7mq-tuAbI-_D-iDp5I2HA',
      $service->extractChannelId('https://www.youtube.com/@themarketplaceministers'),
    );
  }

  public function test_gallery_sources_require_iam_and_drive_sync_imports_into_catalog(): void
  {
    Storage::fake('public');
    Cache::flush();

    $source = CmsGallerySource::query()->create([
      'name' => 'Drive folder',
      'provider' => 'google_drive',
      'source_url' => 'https://drive.google.com/drive/folders/1T9Ef3sQkTwL5iIYiADK4j1By8-17AEyg',
      'remote_id' => '1T9Ef3sQkTwL5iIYiADK4j1By8-17AEyg',
      'access_status' => 'pending_credentials',
      'is_enabled' => true,
    ]);

    Sanctum::actingAs($this->memberUser());
    $this->getJson('/api/v1/cms/gallery-sources')->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/cms/gallery-sources')
      ->assertOk()
      ->assertJsonPath('data.0.provider', 'google_drive');

    config(['services.google.drive_api_key' => 'test-drive-key']);

    Http::fake([
      'www.googleapis.com/drive/v3/files*' => function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'alt=media')) {
          return Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response([
          'files' => [
            ['id' => 'file-aaa', 'name' => 'Summit.jpg', 'mimeType' => 'image/jpeg'],
          ],
        ], 200);
      },
    ]);

    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/cms/gallery-sources/'.$source->uuid.'/sync')
      ->assertOk()
      ->assertJsonPath('data.result.status', 'ready')
      ->assertJsonPath('data.result.imported', 1);

    $this->assertSame(1, CmsCatalogItem::query()->where('type', CatalogItemType::Gallery)->count());
    $item = CmsCatalogItem::query()->where('type', CatalogItemType::Gallery)->first();
    $this->assertSame('file-aaa', $item?->metadata['source_file_id'] ?? null);

    $this->getJson('/api/v1/public/catalog/gallery')
      ->assertOk()
      ->assertJsonPath('data.0.title', 'Summit.jpg');
  }

  public function test_google_photos_source_is_blocked_without_oauth_album_id(): void
  {
    $source = CmsGallerySource::query()->create([
      'name' => 'Photos album',
      'provider' => 'google_photos',
      'source_url' => 'https://photos.app.goo.gl/mhF5hvrQS3mqEygk6',
      'remote_id' => 'mhF5hvrQS3mqEygk6',
      'access_status' => 'needs_oauth',
      'is_enabled' => true,
    ]);

    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/cms/gallery-sources/'.$source->uuid.'/sync')
      ->assertOk()
      ->assertJsonPath('data.result.status', 'needs_oauth')
      ->assertJsonPath('data.result.imported', 0);

    $this->assertSame(0, CmsCatalogItem::query()->where('type', CatalogItemType::Gallery)->count());
  }
}
