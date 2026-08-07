<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$version = config('api.version', 'v1');

Route::prefix($version)
  ->name("api.{$version}.")
  ->group(base_path('routes/api/v1.php'));
