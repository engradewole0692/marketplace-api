<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsGallerySource;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GoogleDriveGallerySyncService implements ServiceContract
{
  public function __construct(
    private readonly CmsCacheManager $cacheManager,
  ) {}

  /**
   * @return array{imported: int, skipped: int, duplicates: int, failed: int, status: string, error: ?string}
   */
  public function sync(CmsGallerySource $source): array
  {
    if ($source->provider === 'google_photos') {
      return $this->blockedPhotosResult($source);
    }

    if ($source->provider !== 'google_drive') {
      return $this->persistResult($source, 0, 0, 0, 0, 'failed', 'Unknown gallery provider.');
    }

    $folderId = $source->remote_id ?: $this->folderIdFromUrl((string) $source->source_url);
    if ($folderId === null) {
      return $this->persistResult($source, 0, 0, 0, 0, 'failed', 'Could not parse a Google Drive folder id from the source URL.');
    }

    $auth = $this->resolveDriveAuth();
    if ($auth === null) {
      return $this->persistResult(
        $source,
        0,
        0,
        0,
        0,
        'pending_credentials',
        'Google Drive sync requires GOOGLE_DRIVE_API_KEY (public folder) or GOOGLE_SERVICE_ACCOUNT_JSON / GOOGLE_SERVICE_ACCOUNT_PATH (private folder shared with the service-account email). Credentials stay in Laravel env — never in React.',
      );
    }

    try {
      $files = $this->listImages($folderId, $auth, 0);
    } catch (\Throwable $exception) {
      return $this->persistResult($source, 0, 0, 0, 1, 'failed', $exception->getMessage());
    }

    $imported = 0;
    $skipped = 0;
    $duplicates = 0;
    $failed = 0;

    foreach ($files as $file) {
      try {
        $outcome = $this->importImage($source, $file, $auth, $folderId);
        match ($outcome) {
          'imported' => $imported++,
          'duplicate' => $duplicates++,
          default => $skipped++,
        };
      } catch (\Throwable $exception) {
        $failed++;
        report($exception);
      }
    }

    $status = $failed > 0 && $imported === 0 ? 'failed' : 'ready';
    $error = $failed > 0
      ? "{$failed} file(s) failed. Imported {$imported}, skipped {$skipped}, duplicates {$duplicates}."
      : null;

    $this->cacheManager->flushCatalog(CatalogItemType::Gallery->value);

    return $this->persistResult($source, $imported, $skipped, $duplicates, $failed, $status, $error);
  }

  /**
   * @return array{imported: int, skipped: int, duplicates: int, failed: int, status: string, error: ?string}
   */
  private function blockedPhotosResult(CmsGallerySource $source): array
  {
    $message = 'Google Photos shared albums (photos.app.goo.gl) cannot be read with the Drive API or an API key. '
      .'They require OAuth 2.0 of the album owner with https://www.googleapis.com/auth/photoslibrary.readonly. '
      .'photos.app.goo.gl short links are not Photos Library album IDs, so they cannot be synced into cms_catalog_items until the owner grants OAuth and provides a library album id. '
      .'Public Gallery continues to use GET /api/v1/public/catalog/gallery only — the browser never calls Google.';

    if (is_string(config('services.google.photos_oauth_token')) && config('services.google.photos_oauth_token') !== '') {
      $message = 'GOOGLE_PHOTOS_OAUTH_ACCESS_TOKEN is set, but photos.app.goo.gl URLs are still not Photos Library album IDs. '
        .'Ask the album owner for a Photos Library album id. Public Gallery remains local-catalog-only.';
    }

    return $this->persistResult($source, 0, 0, 0, 0, 'needs_oauth', $message);
  }

  /**
   * @param  array{type: string, token: string}  $auth
   * @return list<array{id: string, name: string, mimeType: string}>
   */
  private function listImages(string $folderId, array $auth, int $depth): array
  {
    if ($depth > 3) {
      return [];
    }

    $out = [];
    $pageToken = null;

    do {
      $query = [
        'q' => sprintf("'%s' in parents and trashed = false", $folderId),
        'fields' => 'nextPageToken,files(id,name,mimeType)',
        'pageSize' => 100,
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
      ];
      if (is_string($pageToken) && $pageToken !== '') {
        $query['pageToken'] = $pageToken;
      }

      $response = $this->driveRequest('https://www.googleapis.com/drive/v3/files', $auth, $query, true);
      if (! $response['ok']) {
        throw new \RuntimeException('Google Drive list failed: '.($response['error'] ?? 'unknown error'));
      }

      $files = $response['json']['files'] ?? [];
      if (! is_array($files)) {
        $files = [];
      }

      foreach ($files as $file) {
        if (! is_array($file) || empty($file['id'])) {
          continue;
        }
        $mime = (string) ($file['mimeType'] ?? '');
        $id = (string) $file['id'];
        $name = (string) ($file['name'] ?? $id);
        if ($mime === 'application/vnd.google-apps.folder') {
          $out = array_merge($out, $this->listImages($id, $auth, $depth + 1));

          continue;
        }
        if (! str_starts_with($mime, 'image/')) {
          continue;
        }
        $out[] = ['id' => $id, 'name' => $name, 'mimeType' => $mime];
      }

      $pageToken = $response['json']['nextPageToken'] ?? null;
    } while (is_string($pageToken) && $pageToken !== '');

    return $out;
  }

  /**
   * @param  array{id: string, name: string, mimeType: string}  $file
   * @param  array{type: string, token: string}  $auth
   */
  private function importImage(CmsGallerySource $source, array $file, array $auth, string $folderId): string
  {
    $already = CmsCatalogItem::query()
      ->where('type', CatalogItemType::Gallery)
      ->where(function ($query) use ($source, $file, $folderId): void {
        $query->where(function ($inner) use ($source, $file, $folderId): void {
          $inner->where('metadata->source_provider', $source->provider)
            ->where('metadata->source_folder_id', $folderId)
            ->where('metadata->source_file_id', $file['id']);
        })->orWhere('metadata->source_file_id', $file['id']);
      })
      ->exists();
    if ($already) {
      return 'duplicate';
    }

    $binary = $this->download($file['id'], $auth);
    $hash = hash('sha256', $binary);

    $media = CmsMedia::query()->where('content_hash', $hash)->first();
    if ($media !== null) {
      $linked = CmsCatalogItem::query()
        ->where('type', CatalogItemType::Gallery)
        ->where('featured_media_id', $media->id)
        ->exists();
      if ($linked) {
        return 'duplicate';
      }
    } else {
      $media = $this->storeMedia($source, $file, $binary, $hash);
    }

    $slug = Str::slug(pathinfo($file['name'], PATHINFO_FILENAME) ?: 'gallery-'.$file['id']);
    if ($slug === '' || CmsCatalogItem::query()->where('slug', $slug)->exists()) {
      $slug = 'gallery-'.Str::lower(Str::random(10));
    }

    CmsCatalogItem::query()->create([
      'type' => CatalogItemType::Gallery,
      'title' => $file['name'],
      'slug' => $slug,
      'summary' => 'Imported from Google Drive.',
      'metadata' => [
        'source_provider' => 'google_drive',
        'source_id' => $source->uuid,
        'source_url' => $source->source_url,
        'source_folder_id' => $folderId,
        'source_file_id' => $file['id'],
        'image_url' => $media->url(),
        'photographer' => '',
        'location' => '',
        'event' => '',
        'aspect' => 'wide',
      ],
      'category' => 'Google Drive',
      'featured_media_id' => $media->id,
      'status' => 'published',
      'is_active' => true,
      'is_featured' => false,
      'sort_order' => 0,
      'published_at' => now(),
    ]);

    return 'imported';
  }

  /**
   * @param  array{id: string, name: string, mimeType: string}  $file
   */
  private function storeMedia(CmsGallerySource $source, array $file, string $binary, string $hash): CmsMedia
  {
    $extension = match ($file['mimeType']) {
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
      default => 'jpg',
    };
    $path = 'cms/gallery/google-drive/'.$file['id'].'.'.$extension;
    Storage::disk('public')->put($path, $binary);

    return CmsMedia::query()->create([
      'name' => pathinfo($file['name'], PATHINFO_FILENAME) ?: $file['id'],
      'file_name' => $file['name'],
      'disk' => 'public',
      'path' => $path,
      'content_hash' => $hash,
      'mime_type' => $file['mimeType'],
      'size' => strlen($binary),
      'alt_text' => $file['name'],
      'title' => $file['name'],
      'metadata' => [
        'source_provider' => 'google_drive',
        'source_id' => $source->uuid,
        'source_file_id' => $file['id'],
      ],
    ]);
  }

  /**
   * @param  array{type: string, token: string}  $auth
   */
  private function download(string $fileId, array $auth): string
  {
    $response = $this->driveRequest(
      'https://www.googleapis.com/drive/v3/files/'.$fileId,
      $auth,
      ['alt' => 'media', 'supportsAllDrives' => 'true'],
      false,
    );
    if (! $response['ok'] || $response['body'] === '') {
      throw new \RuntimeException('Google Drive download failed for '.$fileId.': '.($response['error'] ?? 'empty body'));
    }

    return $response['body'];
  }

  /**
   * @param  array{type: string, token: string}  $auth
   * @param  array<string, scalar>  $query
   * @return array{ok: bool, json: array<string, mixed>, body: string, error: ?string}
   */
  private function driveRequest(string $url, array $auth, array $query, bool $asJson): array
  {
    $request = Http::timeout(30)->accept($asJson ? 'application/json' : '*/*');
    if ($auth['type'] === 'bearer') {
      $request = $request->withToken($auth['token']);
    } else {
      $query['key'] = $auth['token'];
    }

    $httpResponse = $request->get($url, $query);
    $body = $httpResponse->body();
    $json = [];
    if ($asJson) {
      $decoded = $httpResponse->json();
      $json = is_array($decoded) ? $decoded : [];
    }

    if (! $httpResponse->successful()) {
      $error = is_string($json['error']['message'] ?? null)
        ? (string) $json['error']['message']
        : 'HTTP '.$httpResponse->status();

      return ['ok' => false, 'json' => $json, 'body' => $body, 'error' => $error];
    }

    return ['ok' => true, 'json' => $json, 'body' => $body, 'error' => null];
  }

  /**
   * @return array{type: string, token: string}|null
   */
  private function resolveDriveAuth(): ?array
  {
    $serviceToken = $this->serviceAccountToken();
    if (is_string($serviceToken) && $serviceToken !== '') {
      return ['type' => 'bearer', 'token' => $serviceToken];
    }

    $apiKey = config('services.google.drive_api_key');
    if (is_string($apiKey) && $apiKey !== '') {
      return ['type' => 'key', 'token' => $apiKey];
    }

    return null;
  }

  private function serviceAccountToken(): ?string
  {
    $credentials = $this->serviceAccountPayload();
    if ($credentials === null) {
      return null;
    }

    $now = time();
    $header = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $this->b64url((string) json_encode([
      'iss' => $credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/drive.readonly',
      'aud' => 'https://oauth2.googleapis.com/token',
      'exp' => $now + 3600,
      'iat' => $now,
    ]));
    $unsigned = $header.'.'.$claims;
    $signature = '';
    if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Unable to sign Google service-account JWT.');
    }

    $jwt = $unsigned.'.'.$this->b64url($signature);
    $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
      'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      'assertion' => $jwt,
    ])->json('access_token');

    return is_string($token) && $token !== '' ? $token : null;
  }

  /**
   * @return array{client_email: string, private_key: string}|null
   */
  private function serviceAccountPayload(): ?array
  {
    $json = config('services.google.service_account_json');
    $path = config('services.google.service_account_path');
    $raw = null;
    if (is_string($json) && $json !== '') {
      $raw = $json;
    } elseif (is_string($path) && $path !== '' && is_readable($path)) {
      $raw = file_get_contents($path) ?: null;
    }
    if (! is_string($raw) || $raw === '') {
      return null;
    }

    $decoded = json_decode($raw, true);
    if (! is_array($decoded)) {
      return null;
    }
    $email = $decoded['client_email'] ?? null;
    $key = $decoded['private_key'] ?? null;
    if (! is_string($email) || ! is_string($key) || $email === '' || $key === '') {
      return null;
    }

    return ['client_email' => $email, 'private_key' => $key];
  }

  private function folderIdFromUrl(string $url): ?string
  {
    if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $url, $match)) {
      return $match[1];
    }
    if (preg_match('#id=([a-zA-Z0-9_-]+)#', $url, $match)) {
      return $match[1];
    }
    $trimmed = trim($url);

    return preg_match('/^[a-zA-Z0-9_-]{10,}$/', $trimmed) ? $trimmed : null;
  }

  private function b64url(string $value): string
  {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  /**
   * @return array{imported: int, skipped: int, duplicates: int, failed: int, status: string, error: ?string}
   */
  private function persistResult(
    CmsGallerySource $source,
    int $imported,
    int $skipped,
    int $duplicates,
    int $failed,
    string $status,
    ?string $error,
  ): array {
    $source->fill([
      'imported_count' => $imported,
      'skipped_count' => $skipped,
      'duplicate_count' => $duplicates,
      'failed_count' => $failed,
      'access_status' => $status,
      'last_error' => $error,
      'last_synced_at' => now(),
    ])->save();

    return [
      'imported' => $imported,
      'skipped' => $skipped,
      'duplicates' => $duplicates,
      'failed' => $failed,
      'status' => $status,
      'error' => $error,
    ];
  }
}
