<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\CaptchaController;
use App\Http\Controllers\Auth\SettingsPasswordController;
use App\Http\Controllers\GitlabController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ProjectSettingsController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\GitlabSettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use Illuminate\Support\Facades\Route;

// Public: the login flow itself, plus the health probe the container checks.
Route::get('health', HealthController::class);
Route::get('auth/captcha', [CaptchaController::class, 'show']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout']);

Route::middleware('auth.session')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // The settings password: asked for once per sign-in, before Settings opens.
    Route::get('auth/settings-status', [SettingsPasswordController::class, 'status']);
    Route::post('auth/settings-unlock', [SettingsPasswordController::class, 'unlock']);
    Route::post('auth/settings-password', [SettingsPasswordController::class, 'change']);

    // Everything that holds a credential sits behind that second password.
    Route::middleware('settings.unlocked')->group(function () {
        Route::get('settings/gitlab', [GitlabSettingsController::class, 'show']);
        Route::put('settings/gitlab', [GitlabSettingsController::class, 'update']);
        Route::post('settings/gitlab/test', [GitlabSettingsController::class, 'test']);

        Route::get('settings/ai', [AiSettingsController::class, 'show']);
        Route::put('settings/ai', [AiSettingsController::class, 'update']);
        Route::post('settings/ai/test', [AiSettingsController::class, 'test']);

        Route::get('settings/telegram', [TelegramSettingsController::class, 'show']);
        Route::put('settings/telegram', [TelegramSettingsController::class, 'update']);
        Route::post('settings/telegram/test', [TelegramSettingsController::class, 'test']);
    });

    Route::get('gitlab/projects', [GitlabController::class, 'projects']);
    Route::get('gitlab/projects/{projectId}/branches', [GitlabController::class, 'branches'])
        ->whereNumber('projectId');
    Route::get('gitlab/projects/{projectId}/branch-check', [GitlabController::class, 'branchCheck'])
        ->whereNumber('projectId');
    Route::get('gitlab/projects/{projectId}/tests', [GitlabController::class, 'tests'])
        ->whereNumber('projectId');

    Route::get('pins', [PinController::class, 'index']);
    Route::put('pins', [PinController::class, 'upsert']);
    Route::delete('pins', [PinController::class, 'destroy']);

    Route::get('projects/{projectId}/settings', [ProjectSettingsController::class, 'show'])
        ->whereNumber('projectId');
    Route::put('projects/{projectId}/settings', [ProjectSettingsController::class, 'update'])
        ->whereNumber('projectId');

    Route::get('runs', [RunController::class, 'index']);
    Route::post('runs', [RunController::class, 'store']);
    Route::get('runs/{id}', [RunController::class, 'show']);
    Route::get('runs/{id}/screenshot', [RunController::class, 'screenshot']);
    Route::get('runs/{id}/live', [RunController::class, 'live']);
    Route::get('runs/{id}/maestro-screenshot', [RunController::class, 'maestroScreenshot']);
    Route::get('runs/{id}/ai-screenshot', [RunController::class, 'aiScreenshot']);
});
