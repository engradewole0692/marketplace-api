<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use Illuminate\Support\Facades\Storage;

/**
 * GD-based transform pipeline for CMS media.
 */
final class CmsMediaImagePipeline implements ServiceContract
{
  /** @var list<int> */
  private const RESPONSIVE_WIDTHS = [320, 640, 960, 1280, 1920];

  /**
   * @return array{
   *   width: int|null,
   *   height: int|null,
   *   thumbnail_path: string|null,
   *   variants: array<string, mixed>,
   *   is_optimized: bool
   * }
   */
  public function process(string $disk, string $path, string $mimeType, ?float $focalX = null, ?float $focalY = null): array
  {
    if (! str_starts_with($mimeType, 'image/') || ! extension_loaded('gd')) {
      return [
        'width' => null,
        'height' => null,
        'thumbnail_path' => null,
        'variants' => [],
        'is_optimized' => false,
      ];
    }

    $absolute = Storage::disk($disk)->path($path);
    $source = $this->loadImage($absolute, $mimeType);
    if ($source === null) {
      return [
        'width' => null,
        'height' => null,
        'thumbnail_path' => null,
        'variants' => [],
        'is_optimized' => false,
      ];
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $basename = pathinfo($path, PATHINFO_FILENAME);
    $dir = 'cms/media/variants/'.$basename;

    $thumbnailPath = $this->writeResized($source, $disk, $dir.'/thumb.jpg', 480, 480, $focalX, $focalY, 'jpeg', 82);
    $variants = [
      'original' => ['path' => $path, 'width' => $width, 'height' => $height, 'format' => $this->formatFromMime($mimeType)],
      'thumb' => $thumbnailPath ? ['path' => $thumbnailPath, 'width' => 480, 'format' => 'jpeg'] : null,
      'responsive' => [],
      'webp' => [],
    ];

    foreach (self::RESPONSIVE_WIDTHS as $targetWidth) {
      if ($targetWidth >= $width) {
        continue;
      }

      $jpegPath = $this->writeResized($source, $disk, $dir."/w{$targetWidth}.jpg", $targetWidth, null, $focalX, $focalY, 'jpeg', 84);
      if ($jpegPath !== null) {
        $variants['responsive'][] = ['path' => $jpegPath, 'width' => $targetWidth, 'format' => 'jpeg'];
      }

      if (function_exists('imagewebp')) {
        $webpPath = $this->writeResized($source, $disk, $dir."/w{$targetWidth}.webp", $targetWidth, null, $focalX, $focalY, 'webp', 82);
        if ($webpPath !== null) {
          $variants['webp'][] = ['path' => $webpPath, 'width' => $targetWidth, 'format' => 'webp'];
        }
      }
    }

    // Full-size WebP when source is not already WebP.
    if (function_exists('imagewebp') && $mimeType !== 'image/webp') {
      $fullWebp = $this->writeResized($source, $disk, $dir.'/original.webp', $width, $height, $focalX, $focalY, 'webp', 80);
      if ($fullWebp !== null) {
        $variants['webp'][] = ['path' => $fullWebp, 'width' => $width, 'height' => $height, 'format' => 'webp'];
      }
    }

    imagedestroy($source);

    return [
      'width' => $width,
      'height' => $height,
      'thumbnail_path' => $thumbnailPath,
      'variants' => array_filter($variants, static fn ($value) => $value !== null),
      'is_optimized' => true,
    ];
  }

  /**
   * Crop a region and return a new stored path + mime.
   *
   * @param  array{x:int,y:int,width:int,height:int}  $crop
   * @return array{path: string, mime_type: string, size: int}|null
   */
  public function cropToNewFile(string $disk, string $path, string $mimeType, array $crop, int $outputWidth = 0): ?array
  {
    if (! extension_loaded('gd')) {
      return null;
    }

    $absolute = Storage::disk($disk)->path($path);
    $source = $this->loadImage($absolute, $mimeType);
    if ($source === null) {
      return null;
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $x = max(0, min($srcW - 1, (int) $crop['x']));
    $y = max(0, min($srcH - 1, (int) $crop['y']));
    $w = max(1, min($srcW - $x, (int) $crop['width']));
    $h = max(1, min($srcH - $y, (int) $crop['height']));

    $destW = $outputWidth > 0 ? $outputWidth : $w;
    $destH = (int) max(1, round($h * ($destW / $w)));

    $dest = imagecreatetruecolor($destW, $destH);
    if ($dest === false) {
      imagedestroy($source);

      return null;
    }

    imagecopyresampled($dest, $source, 0, 0, $x, $y, $destW, $destH, $w, $h);
    imagedestroy($source);

    $relative = 'cms/media/'.uniqid('crop_', true).'.jpg';
    $outAbsolute = Storage::disk($disk)->path($relative);
    $this->ensureDirectory(dirname($outAbsolute));
    $ok = imagejpeg($dest, $outAbsolute, 88);
    imagedestroy($dest);

    if (! $ok) {
      return null;
    }

    return [
      'path' => $relative,
      'mime_type' => 'image/jpeg',
      'size' => (int) filesize($outAbsolute),
    ];
  }

  /**
   * @return array{path: string, mime_type: string, size: int}|null
   */
  public function resizeToNewFile(string $disk, string $path, string $mimeType, int $maxWidth, int $maxHeight = 0): ?array
  {
    if (! extension_loaded('gd')) {
      return null;
    }

    $absolute = Storage::disk($disk)->path($path);
    $source = $this->loadImage($absolute, $mimeType);
    if ($source === null) {
      return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min($maxWidth / max(1, $width), $maxHeight > 0 ? $maxHeight / max(1, $height) : 1.0, 1.0);
    $destW = max(1, (int) round($width * $scale));
    $destH = max(1, (int) round($height * $scale));

    $written = $this->writeResized($source, $disk, 'cms/media/'.uniqid('resize_', true).'.jpg', $destW, $destH, null, null, 'jpeg', 88);
    imagedestroy($source);

    if ($written === null) {
      return null;
    }

    $outAbsolute = Storage::disk($disk)->path($written);

    return [
      'path' => $written,
      'mime_type' => 'image/jpeg',
      'size' => (int) filesize($outAbsolute),
    ];
  }

  /**
   * @return \GdImage|null
   */
  private function loadImage(string $absolute, string $mimeType)
  {
    if (! is_file($absolute)) {
      return null;
    }

    $source = match ($mimeType) {
      'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($absolute),
      'image/png' => @imagecreatefrompng($absolute),
      'image/gif' => @imagecreatefromgif($absolute),
      'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
      default => false,
    };

    return $source === false ? null : $source;
  }

  /**
   * @param  \GdImage  $source
   */
  private function writeResized(
    $source,
    string $disk,
    string $relativePath,
    int $targetWidth,
    ?int $targetHeight,
    ?float $focalX,
    ?float $focalY,
    string $format,
    int $quality,
  ): ?string {
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW < 1 || $srcH < 1) {
      return null;
    }

    if ($targetHeight === null) {
      $scale = min($targetWidth / $srcW, 1.0);
      $destW = max(1, (int) round($srcW * $scale));
      $destH = max(1, (int) round($srcH * $scale));
      $srcX = 0;
      $srcY = 0;
      $cropW = $srcW;
      $cropH = $srcH;
    } else {
      // Cover-style crop using focal point (0-1), default center.
      $fx = $focalX ?? 0.5;
      $fy = $focalY ?? 0.5;
      $scale = max($targetWidth / $srcW, $targetHeight / $srcH);
      $cropW = (int) round($targetWidth / $scale);
      $cropH = (int) round($targetHeight / $scale);
      $srcX = (int) round(($srcW - $cropW) * $fx);
      $srcY = (int) round(($srcH - $cropH) * $fy);
      $srcX = max(0, min($srcW - $cropW, $srcX));
      $srcY = max(0, min($srcH - $cropH, $srcY));
      $destW = $targetWidth;
      $destH = $targetHeight;
    }

    $dest = imagecreatetruecolor($destW, $destH);
    if ($dest === false) {
      return null;
    }

    if ($format === 'png') {
      imagealphablending($dest, false);
      imagesavealpha($dest, true);
    }

    imagecopyresampled($dest, $source, 0, 0, $srcX, $srcY, $destW, $destH, $cropW, $cropH);

    $absolute = Storage::disk($disk)->path($relativePath);
    $this->ensureDirectory(dirname($absolute));

    $ok = match ($format) {
      'webp' => function_exists('imagewebp') ? imagewebp($dest, $absolute, $quality) : false,
      'png' => imagepng($dest, $absolute, 6),
      default => imagejpeg($dest, $absolute, $quality),
    };

    imagedestroy($dest);

    return $ok ? $relativePath : null;
  }

  private function ensureDirectory(string $directory): void
  {
    if (! is_dir($directory)) {
      mkdir($directory, 0755, true);
    }
  }

  private function formatFromMime(string $mimeType): string
  {
    return match ($mimeType) {
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      default => 'jpeg',
    };
  }
}
