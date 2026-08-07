<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Iam\IamAuditLogController;
use App\Http\Controllers\Api\V1\Iam\PermissionController;
use App\Http\Controllers\Api\V1\Iam\RoleController;
use App\Http\Controllers\Api\V1\Iam\UserAvatarController;
use App\Http\Controllers\Api\V1\Iam\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
  ->prefix('iam')
  ->name('iam.')
  ->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::post('users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
    Route::post('users/{userId}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/avatar', [UserAvatarController::class, 'upload'])->name('users.avatar.upload');
    Route::put('users/{user}/avatar', [UserAvatarController::class, 'attach'])->name('users.avatar.attach');
    Route::delete('users/{user}/avatar', [UserAvatarController::class, 'destroy'])->name('users.avatar.destroy');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::post('roles/{role}/clone', [RoleController::class, 'clone'])->name('roles.clone');
    Route::get('roles/{role}/users', [RoleController::class, 'users'])->name('roles.users');

    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::get('permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show');

    Route::get('audit-logs', [IamAuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{auditLog}', [IamAuditLogController::class, 'show'])->name('audit-logs.show');
  });
