<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Contracts\ServiceContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

/**
 * Read-only production readiness checks. Never modifies data or prints secrets.
 */
final class ProductionPreflightService implements ServiceContract
{
  /** @var list<array{category: string, check: string, status: string, message: string}> */
  private array $checks = [];

  /**
   * @return list<array{category: string, check: string, status: string, message: string}>
   */
  public function run(): array
  {
    $this->checks = [];

    $this->checkApplication();
    $this->checkDatabase();
    $this->checkAuthentication();
    $this->checkMail();
    $this->checkQueue();
    $this->checkScheduler();

    return $this->checks;
  }

  /** @return array{pass: int, warn: int, fail: int} */
  public function summarize(array $checks): array
  {
    $pass = $warn = $fail = 0;
    foreach ($checks as $check) {
      match ($check['status']) {
        'PASS' => $pass++,
        'WARN' => $warn++,
        default => $fail++,
      };
    }

    return compact('pass', 'warn', 'fail');
  }

  private function checkApplication(): void
  {
    $appKey = (string) config('app.key', '');
    $this->record('Application', 'APP_KEY', $appKey !== '' ? 'PASS' : 'FAIL', $appKey !== '' ? 'Application key is set.' : 'APP_KEY is missing.');

    $env = (string) config('app.env', '');
    $this->record('Application', 'APP_ENV', $env !== '' ? 'PASS' : 'WARN', 'Environment: '.$env);

    if ($env === 'production') {
      $this->record(
        'Application',
        'APP_DEBUG',
        config('app.debug') ? 'FAIL' : 'PASS',
        config('app.debug') ? 'APP_DEBUG must be false in production.' : 'Debug mode is disabled.',
      );
    } else {
      $this->record(
        'Application',
        'APP_DEBUG',
        'WARN',
        'Not production (APP_DEBUG check skipped for strict enforcement).',
      );
    }

    $appUrl = (string) config('app.url', '');
    $this->record(
      'Application',
      'APP_URL',
      $this->isHttpUrl($appUrl) ? 'PASS' : 'WARN',
      $appUrl !== '' ? 'APP_URL configured.' : 'APP_URL is empty.',
    );

    $frontendUrl = (string) config('app-frontend.url', env('FRONTEND_URL', ''));
    $this->record(
      'Application',
      'FRONTEND_URL',
      $this->isHttpUrl($frontendUrl) ? 'PASS' : 'WARN',
      $frontendUrl !== '' ? 'Frontend URL configured.' : 'FRONTEND_URL / app-frontend.url is empty.',
    );
  }

  private function checkDatabase(): void
  {
    $driver = (string) config('database.default', '');
    $this->record('Database', 'DB_CONNECTION', $driver !== '' ? 'PASS' : 'FAIL', 'Driver: '.$driver);

    try {
      DB::connection()->getPdo();
      $this->record('Database', 'Connectivity', 'PASS', 'Database connection successful.');
    } catch (\Throwable $e) {
      $this->record('Database', 'Connectivity', 'FAIL', 'Cannot connect to database.');

      return;
    }

    try {
      $pending = $this->pendingMigrationCount();
      if ($pending > 0) {
        $this->record('Database', 'Migrations', 'WARN', "{$pending} pending migration(s).");
      } else {
        $this->record('Database', 'Migrations', 'PASS', 'All migrations applied.');
      }
    } catch (\Throwable) {
      $this->record('Database', 'Migrations', 'WARN', 'Migration status check failed.');
    }

    $requiredTables = [
      'communication_settings',
      'communication_routes',
      'communication_templates',
      'communication_email_logs',
      'communication_idempotency_keys',
      'lms_program_modules',
      'lms_courses',
      'lms_schools',
      'lms_school_orders',
      'lms_course_orders',
      'lms_school_enrollments',
      'permissions',
      'roles',
    ];

    $missing = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
    $this->record(
      'Database',
      'Required tables',
      $missing === [] ? 'PASS' : 'FAIL',
      $missing === [] ? 'All required LMS/Communications tables exist.' : 'Missing: '.implode(', ', $missing),
    );
  }

  private function checkAuthentication(): void
  {
    $stateful = config('sanctum.stateful', []);
    $this->record(
      'Authentication',
      'SANCTUM_STATEFUL_DOMAINS',
      is_array($stateful) && count($stateful) > 0 ? 'PASS' : 'WARN',
      is_array($stateful) && count($stateful) > 0
        ? count($stateful).' stateful domain(s) configured.'
        : 'No Sanctum stateful domains detected.',
    );

    $sessionDomain = env('SESSION_DOMAIN');
    $this->record(
      'Authentication',
      'SESSION_DOMAIN',
      'PASS',
      $sessionDomain === null || $sessionDomain === '' || $sessionDomain === 'null'
        ? 'SESSION_DOMAIN is null (API-host scoped cookie — expected for cross-origin SPA).'
        : 'SESSION_DOMAIN configured.',
    );

    $secure = env('SESSION_SECURE_COOKIE');
    $this->record(
      'Authentication',
      'SESSION_SECURE_COOKIE',
      $secure !== null && $secure !== '' ? 'PASS' : 'WARN',
      $secure !== null && $secure !== '' ? 'SESSION_SECURE_COOKIE is set.' : 'SESSION_SECURE_COOKIE not explicitly set.',
    );

    $sameSite = (string) config('session.same_site', env('SESSION_SAME_SITE', 'lax'));
    $this->record('Authentication', 'SESSION_SAME_SITE', 'PASS', 'SameSite: '.$sameSite);

    $frontendHost = parse_url((string) config('app-frontend.url', ''), PHP_URL_HOST);
    $apiHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
    $crossOriginSpa = is_string($frontendHost) && is_string($apiHost) && $frontendHost !== '' && $apiHost !== '' && $frontendHost !== $apiHost;
    if ($crossOriginSpa) {
      $topologyOk = in_array($sameSite, ['none', 'lax'], true);
      $this->record(
        'Authentication',
        'Cross-origin SPA topology',
        $topologyOk ? 'PASS' : 'WARN',
        $sameSite === 'none'
          ? 'Frontend and API hosts differ; SameSite=none is configured for direct cross-origin cookies.'
          : ($sameSite === 'lax'
            ? 'Frontend and API hosts differ; SameSite=lax is valid when the SPA uses a same-origin /api proxy.'
            : 'Frontend and API hosts differ. Prefer a same-origin /api proxy (SESSION_SAME_SITE=lax) or SESSION_SAME_SITE=none.'),
      );
      if ($sameSite === 'none') {
        $partitioned = filter_var(env('SESSION_PARTITIONED_COOKIE', false), FILTER_VALIDATE_BOOL);
        $this->record(
          'Authentication',
          'SESSION_PARTITIONED_COOKIE',
          $partitioned ? 'PASS' : 'WARN',
          $partitioned
            ? 'Partitioned session cookies enabled for third-party cookie compatibility.'
            : 'Consider SESSION_PARTITIONED_COOKIE=true when using direct cross-origin cookies without a frontend /api proxy.',
        );
      }
    }

    $origins = (string) env('FRONTEND_ORIGINS', '');
    $this->record(
      'Authentication',
      'FRONTEND_ORIGINS',
      $origins !== '' ? 'PASS' : 'WARN',
      $origins !== '' ? 'FRONTEND_ORIGINS configured.' : 'FRONTEND_ORIGINS not set (app-frontend may use FRONTEND_URL only).',
    );
  }

  private function checkMail(): void
  {
    $mailer = (string) config('mail.default', '');
    $this->record('Mail', 'MAIL_MAILER', $mailer !== '' ? 'PASS' : 'WARN', 'Mailer: '.$mailer);

    if (in_array($mailer, ['smtp', 'ses', 'postmark', 'resend'], true)) {
      $host = (string) config('mail.mailers.smtp.host', env('MAIL_HOST', ''));
      $port = (string) config('mail.mailers.smtp.port', env('MAIL_PORT', ''));
      $username = env('MAIL_USERNAME');
      $password = env('MAIL_PASSWORD');

      $this->record('Mail', 'MAIL_HOST', $host !== '' ? 'PASS' : 'WARN', $host !== '' ? 'SMTP host present.' : 'MAIL_HOST missing.');
      $this->record('Mail', 'MAIL_PORT', $port !== '' ? 'PASS' : 'WARN', $port !== '' ? 'SMTP port present.' : 'MAIL_PORT missing.');
      $this->record('Mail', 'MAIL_USERNAME', $this->secretPresent($username), 'SMTP username '.($username ? 'present' : 'missing').'.');
      $this->record('Mail', 'MAIL_PASSWORD', $this->secretPresent($password), 'SMTP password '.($password ? 'present' : 'missing').'.');
    }

    $fromAddress = (string) config('mail.from.address', '');
    $fromName = (string) config('mail.from.name', '');
    $this->record('Mail', 'MAIL_FROM_ADDRESS', filter_var($fromAddress, FILTER_VALIDATE_EMAIL) ? 'PASS' : 'WARN', 'From address configured.');
    $this->record('Mail', 'MAIL_FROM_NAME', $fromName !== '' ? 'PASS' : 'WARN', 'From name configured.');
  }

  private function checkQueue(): void
  {
    $connection = (string) config('queue.default', 'sync');
    $this->record('Queue', 'QUEUE_CONNECTION', 'PASS', 'Queue driver: '.$connection);

    if (in_array($connection, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
      $this->record(
        'Queue',
        'Queue worker',
        'WARN',
        "QUEUE_CONNECTION={$connection} requires a running queue worker (cannot verify automatically).",
      );
    }
  }

  private function checkScheduler(): void
  {
    $events = Schedule::events();
    $commands = array_map(
      static fn ($event): string => (string) ($event->command ?? $event->description ?? ''),
      $events,
    );

    $hasReminders = $this->scheduleIncludes($commands, 'events:send-reminders');
    $hasPublish = $this->scheduleIncludes($commands, 'lms:publish-scheduled');

    $this->record(
      'Scheduler',
      'events:send-reminders',
      $hasReminders ? 'PASS' : 'FAIL',
      $hasReminders ? 'Event reminder command is scheduled.' : 'events:send-reminders is not registered in the scheduler.',
    );

    $this->record(
      'Scheduler',
      'lms:publish-scheduled',
      $hasPublish ? 'PASS' : 'WARN',
      $hasPublish ? 'Scheduled course publish command is registered.' : 'lms:publish-scheduled is not registered.',
    );

    $this->record(
      'Scheduler',
      'Forge cron',
      'WARN',
      'Ensure Forge runs: * * * * * php artisan schedule:run (cannot verify remotely).',
    );
  }

  /** @param list<string> $commands */
  private function scheduleIncludes(array $commands, string $needle): bool
  {
    foreach ($commands as $command) {
      if (str_contains($command, $needle)) {
        return true;
      }
    }

    return false;
  }

  private function pendingMigrationCount(): int
  {
    $ran = DB::table('migrations')->pluck('migration')->all();
    $files = glob(database_path('migrations/*.php')) ?: [];
    $pending = 0;
    foreach ($files as $file) {
      $name = pathinfo($file, PATHINFO_FILENAME);
      if (! in_array($name, $ran, true)) {
        $pending++;
      }
    }

    return $pending;
  }

  private function isHttpUrl(string $url): bool
  {
    return $url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'));
  }

  private function secretPresent(mixed $value): string
  {
    return is_string($value) && $value !== '' ? 'PASS' : 'WARN';
  }

  private function record(string $category, string $check, string $status, string $message): void
  {
    $this->checks[] = [
      'category' => $category,
      'check' => $check,
      'status' => strtoupper($status),
      'message' => $message,
    ];
  }
}
