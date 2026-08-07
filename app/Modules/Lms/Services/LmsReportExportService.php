<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LMS report exports — CSV / Excel (PhpSpreadsheet, Events pattern) / PDF (DomPDF).
 */
final class LmsReportExportService implements ServiceContract
{
  public const FORMATS = ['csv', 'xlsx', 'excel', 'pdf'];

  /**
   * @param  array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}  $report
   * @return array{path: string, disk: string, filename: string, mime: string, url: string}
   */
  public function export(array $report, string $format): array
  {
    $format = strtolower($format);
    if ($format === 'excel') {
      $format = 'xlsx';
    }
    if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
      throw ValidationException::withMessages(['format' => ['Format must be csv, xlsx/excel, or pdf.']]);
    }

    $type = (string) ($report['type'] ?? 'report');
    $stamp = now()->format('Ymd-His');
    $base = 'lms/reports/'.$type.'-'.$stamp.'-'.Str::lower(Str::random(4));

    return match ($format) {
      'csv' => $this->writeCsv($report, $base.'.csv'),
      'xlsx' => $this->writeXlsx($report, $base.'.xlsx'),
      'pdf' => $this->writePdf($report, $base.'.pdf'),
    };
  }

  /**
   * @param  array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}  $report
   * @return array{path: string, disk: string, filename: string, mime: string, url: string}
   */
  private function writeCsv(array $report, string $relativePath): array
  {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
      throw new \RuntimeException('Unable to open CSV stream.');
    }

    fputcsv($handle, ['LMS Report', strtoupper((string) $report['type'])]);
    fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
    foreach ($report['summary'] as $key => $value) {
      fputcsv($handle, [(string) $key, is_scalar($value) ? (string) $value : json_encode($value)]);
    }
    fputcsv($handle, []);
    fputcsv($handle, $report['columns']);
    foreach ($report['rows'] as $row) {
      $line = [];
      foreach ($report['columns'] as $col) {
        $val = $row[$col] ?? '';
        $line[] = is_scalar($val) || $val === null ? (string) ($val ?? '') : json_encode($val);
      }
      fputcsv($handle, $line);
    }

    rewind($handle);
    Storage::disk('public')->put($relativePath, stream_get_contents($handle) ?: '');
    fclose($handle);

    return $this->meta($relativePath, 'text/csv');
  }

  /**
   * @param  array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}  $report
   * @return array{path: string, disk: string, filename: string, mime: string, url: string}
   */
  private function writeXlsx(array $report, string $relativePath): array
  {
    if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
      return $this->writeCsv($report, preg_replace('/\.xlsx$/', '.csv', $relativePath) ?: $relativePath.'.csv');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(Str::limit((string) $report['type'], 28, ''));

    $rowIndex = 1;
    $sheet->setCellValue([1, $rowIndex], 'LMS Report');
    $sheet->setCellValue([2, $rowIndex], strtoupper((string) $report['type']));
    $rowIndex++;
    $sheet->setCellValue([1, $rowIndex], 'Generated At');
    $sheet->setCellValue([2, $rowIndex], now()->toDateTimeString());
    $rowIndex++;

    foreach ($report['summary'] as $key => $value) {
      $sheet->setCellValue([1, $rowIndex], (string) $key);
      $sheet->setCellValue([2, $rowIndex], is_scalar($value) ? (string) $value : json_encode($value));
      $rowIndex++;
    }
    $rowIndex++;

    foreach ($report['columns'] as $i => $header) {
      $sheet->setCellValue([$i + 1, $rowIndex], $header);
    }
    $rowIndex++;

    foreach ($report['rows'] as $row) {
      foreach ($report['columns'] as $i => $header) {
        $val = $row[$header] ?? '';
        $sheet->setCellValue([$i + 1, $rowIndex], is_scalar($val) || $val === null ? (string) ($val ?? '') : json_encode($val));
      }
      $rowIndex++;
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $temp = tempnam(sys_get_temp_dir(), 'lmsxlsx');
    $writer->save($temp);
    Storage::disk('public')->put($relativePath, file_get_contents($temp) ?: '');
    @unlink($temp);

    return $this->meta($relativePath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  }

  /**
   * @param  array{type: string, summary: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>}  $report
   * @return array{path: string, disk: string, filename: string, mime: string, url: string}
   */
  private function writePdf(array $report, string $relativePath): array
  {
    $title = e(strtoupper((string) $report['type']).' Report');
    $generated = e(now()->toDateTimeString());
    $summaryHtml = '';
    foreach ($report['summary'] as $key => $value) {
      $display = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
      $summaryHtml .= '<tr><td>'.e((string) $key).'</td><td>'.e($display).'</td></tr>';
    }

    $headerHtml = '';
    foreach ($report['columns'] as $col) {
      $headerHtml .= '<th>'.e(str_replace('_', ' ', $col)).'</th>';
    }

    $bodyHtml = '';
    foreach (array_slice($report['rows'], 0, 200) as $row) {
      $bodyHtml .= '<tr>';
      foreach ($report['columns'] as $col) {
        $val = $row[$col] ?? '';
        $display = (is_scalar($val) || $val === null) ? (string) ($val ?? '') : (json_encode($val) ?: '');
        $bodyHtml .= '<td>'.e($display).'</td>';
      }
      $bodyHtml .= '</tr>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}
h1{font-size:18px;margin-bottom:4px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
th{background:#f3f3f3}
.summary td{border:none;padding:2px 6px}
</style></head><body>
<h1>{$title}</h1>
<p>Generated: {$generated}</p>
<table class="summary">{$summaryHtml}</table>
<table><thead><tr>{$headerHtml}</tr></thead><tbody>{$bodyHtml}</tbody></table>
</body></html>
HTML;

    $binary = $this->renderPdf($html);
    $isPdf = is_string($binary) && str_starts_with($binary, '%PDF');
    if (! $isPdf) {
      $relativePath = preg_replace('/\.pdf$/', '.html', $relativePath) ?: $relativePath.'.html';
      Storage::disk('public')->put($relativePath, $binary ?: $html);

      return $this->meta($relativePath, 'text/html');
    }

    Storage::disk('public')->put($relativePath, $binary);

    return $this->meta($relativePath, 'application/pdf');
  }

  private function renderPdf(string $html): string
  {
    if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
      return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }
    if (class_exists(\Dompdf\Dompdf::class)) {
      $dompdf = new \Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();

      return $dompdf->output() ?: $html;
    }

    return $html;
  }

  /**
   * @return array{path: string, disk: string, filename: string, mime: string, url: string}
   */
  private function meta(string $relativePath, string $mime): array
  {
    return [
      'path' => $relativePath,
      'disk' => 'public',
      'filename' => basename($relativePath),
      'mime' => $mime,
      'url' => Storage::disk('public')->url($relativePath),
    ];
  }
}
