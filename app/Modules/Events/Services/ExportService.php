<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\ExportStatus;
use App\Modules\Events\Models\EventExportJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportService implements ServiceContract
{
  public function __construct(
    private readonly RegistrationExportGenerator $generator,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    return EventExportJob::query()
      ->with('event')
      ->orderByDesc('created_at')
      ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function queue(array $data, User $actor): EventExportJob
  {
    return DB::transaction(function () use ($data, $actor): EventExportJob {
      $job = EventExportJob::query()->create([
        'event_id' => $data['event_id'] ?? null,
        'export_type' => $data['export_type'],
        'format' => $data['format'],
        'filters' => $data['filters'] ?? null,
        'status' => ExportStatus::Pending,
        'requested_by_user_id' => $actor->id,
      ]);

      return $this->process($job);
    });
  }

  public function process(EventExportJob $job): EventExportJob
  {
    $job->update([
      'status' => ExportStatus::Processing,
      'started_at' => now(),
    ]);

    try {
      $job->load('requester');
      $result = $this->generator->generate($job);

      $job->update([
        'status' => ExportStatus::Completed,
        'file_path' => $result['path'],
        'disk' => $result['disk'],
        'completed_at' => now(),
        'metadata' => ['filename' => $result['filename']],
      ]);
    } catch (\Throwable $exception) {
      $job->update([
        'status' => ExportStatus::Failed,
        'failed_at' => now(),
        'failure_reason' => $exception->getMessage(),
      ]);
    }

    return $job->fresh(['event']);
  }

  public function download(EventExportJob $export): StreamedResponse
  {
    if ($export->status !== ExportStatus::Completed || empty($export->file_path)) {
      abort(404, 'Export file is not available.');
    }

    $filename = $export->metadata['filename'] ?? basename($export->file_path);
    $mime = match ($export->format) {
      'csv' => 'text/csv',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      default => 'application/octet-stream',
    };

    return response()->streamDownload(function () use ($export): void {
      echo Storage::disk($export->disk ?? 'public')->get($export->file_path);
    }, $filename, ['Content-Type' => $mime]);
  }
}
