<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared certificate PDF renderer reused by Events and LMS.
 * Extracted from Events CertificateService — do not duplicate DomPDF logic.
 */
final class CertificatePdfEngine
{
  /**
   * @param  array<string, string>  $placeholders
   * @param  array{
   *   background_url?: string|null,
   *   logo_url?: string|null,
   *   watermark_url?: string|null,
   *   instructor_signature_url?: string|null,
   *   director_signature_url?: string|null
   * }  $assets
   */
  public function renderToMedia(
    string $htmlBody,
    array $placeholders,
    string $certificateNumber,
    ?User $actor = null,
    array $assets = [],
    string $storagePrefix = 'certificates',
  ): ?CmsMedia {
    $html = $this->composeHtml($htmlBody, $placeholders, $assets);
    $binary = $this->renderPdf($html);
    if ($binary === null) {
      return null;
    }

    // DomPDF may return HTML fallback string when PDF libs unavailable.
    $isPdf = str_starts_with($binary, '%PDF');
    $ext = $isPdf ? 'pdf' : 'html';
    $mime = $isPdf ? 'application/pdf' : 'text/html';
    $filename = sprintf('certificate-%s.%s', Str::lower(Str::slug($certificateNumber)), $ext);
    $relativePath = trim($storagePrefix, '/').'/'.$filename;

    Storage::disk('public')->put($relativePath, $binary);

    return CmsMedia::query()->create([
      'name' => sprintf('Certificate %s', $certificateNumber),
      'file_name' => $filename,
      'disk' => 'public',
      'path' => $relativePath,
      'mime_type' => $mime,
      'size' => strlen($binary),
      'created_by' => $actor?->id,
      'updated_by' => $actor?->id,
    ]);
  }

  /**
   * @param  array<string, string>  $placeholders
   * @param  array<string, string|null>  $assets
   */
  public function composeHtml(string $htmlBody, array $placeholders, array $assets = []): string
  {
    $verificationUrl = html_entity_decode(strip_tags($placeholders['{{verification_url}}'] ?? ''), ENT_QUOTES);
    $qrImage = $this->qrDataUri($verificationUrl);
    $placeholders['{{qr}}'] = $qrImage !== ''
      ? '<img src="'.$qrImage.'" alt="QR" width="120" height="120" />'
      : e($verificationUrl);
    $placeholders['{{qr_url}}'] = e($verificationUrl);

    if ($htmlBody !== '' && stripos($htmlBody, '<html') !== false) {
      return strtr($htmlBody, $placeholders);
    }

    $background = $assets['background_url'] ?? null;
    $logo = $assets['logo_url'] ?? null;
    $watermark = $assets['watermark_url'] ?? null;
    $instructorSig = $assets['instructor_signature_url'] ?? null;
    $directorSig = $assets['director_signature_url'] ?? null;

    $shellOpen = '<html><head><meta charset="utf-8"><style>'
      .'body{font-family:DejaVu Sans,sans-serif;margin:0;padding:0;}'
      .'.cert-shell{position:relative;min-height:100%;padding:48px;text-align:center;}'
      .($background ? '.cert-shell{background:url('.e($background).') center/cover no-repeat;}' : '')
      .($watermark ? '.cert-shell:after{content:"";position:absolute;inset:10%;background:url('.e($watermark).') center/contain no-repeat;opacity:.08;pointer-events:none;}' : '')
      .'.logo{max-height:72px;margin-bottom:16px;}'
      .'.sig{max-height:64px;margin-top:8px;}'
      .'.sigs{display:flex;justify-content:space-around;margin-top:40px;}'
      .'</style></head><body><div class="cert-shell">';

    if ($logo) {
      $shellOpen .= '<img class="logo" src="'.e($logo).'" alt="Logo" />';
    }

    $body = $htmlBody !== ''
      ? strtr($htmlBody, $placeholders)
      : strtr(
        '<h1>Certificate of Completion</h1><p>Awarded to</p><h2>{{name}}</h2>'
        .'<p>For completing <strong>{{course}}</strong> on {{date}}.</p>'
        .'<p>Certificate: {{certificate_number}}</p>'
        .'<div style="margin-top:24px">{{qr}}</div>'
        .'<p style="font-size:12px;margin-top:16px">Verify: {{verification_url}}</p>',
        $placeholders,
      );

    $sigs = '';
    if ($instructorSig || $directorSig) {
      $sigs = '<div class="sigs">';
      if ($instructorSig) {
        $sigs .= '<div><img class="sig" src="'.e($instructorSig).'" alt="Instructor signature" /><p>Instructor</p></div>';
      }
      if ($directorSig) {
        $sigs .= '<div><img class="sig" src="'.e($directorSig).'" alt="Director signature" /><p>Director</p></div>';
      }
      $sigs .= '</div>';
    }

    return $shellOpen.$body.$sigs.'</div></body></html>';
  }

  public function renderPdf(string $html): ?string
  {
    if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
      return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    if (class_exists(\Dompdf\Dompdf::class)) {
      $dompdf = new \Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();

      return $dompdf->output();
    }

    return $html;
  }

  private function qrDataUri(string $data): string
  {
    if ($data === '') {
      return '';
    }

    // Prefer local QR packages when present; otherwise use a remote PNG data fetch.
    if (class_exists(\Endroid\QrCode\QrCode::class) && class_exists(\Endroid\QrCode\Writer\PngWriter::class)) {
      try {
        $qr = new \Endroid\QrCode\QrCode($data);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qr);

        return 'data:image/png;base64,'.base64_encode($result->getString());
      } catch (\Throwable) {
        // fall through
      }
    }

    try {
      $url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data='.rawurlencode($data);
      $png = @file_get_contents($url);
      if (is_string($png) && $png !== '') {
        return 'data:image/png;base64,'.base64_encode($png);
      }
    } catch (\Throwable) {
      // ignore
    }

    return '';
  }
}
