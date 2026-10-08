<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\CaptchaController;
use App\Http\Controllers\Auth\SettingsPasswordController;
use App\Http\Controllers\GitlabController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Hooks\GitlabWebhookController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ProjectSettingsController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\AutomationTriggerController;
use App\Http\Controllers\Settings\GitlabSettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use Illuminate\Support\Facades\Route;

// Public: the login flow itself, plus the health probe the container checks.
Route::get('health', HealthController::class);
Route::get('auth/captcha', [CaptchaController::class, 'show']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout']);

// Public by design: GitLab has no session, it carries the rule's own token.
Route::post('hooks/gitlab/{token}', GitlabWebhookController::class);

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

        Route::get('automation/triggers', [AutomationTriggerController::class, 'index']);
        Route::post('automation/triggers', [AutomationTriggerController::class, 'store']);
        Route::patch('automation/triggers/{id}', [AutomationTriggerController::class, 'update'])->whereNumber('id');
        Route::delete('automation/triggers/{id}', [AutomationTriggerController::class, 'destroy'])->whereNumber('id');

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
    Route::post('runs/{id}/cancel', [RunController::class, 'cancel']);
    Route::get('runs/{id}/screenshot', [RunController::class, 'screenshot']);
    Route::get('runs/{id}/live', [RunController::class, 'live']);
    Route::get('runs/{id}/maestro-screenshot', [RunController::class, 'maestroScreenshot']);
    Route::get('runs/{id}/ai-screenshot', [RunController::class, 'aiScreenshot']);
    Route::get('runs/{id}/ai-report', [RunController::class, 'aiReport']);
    Route::get('runs/{id}/ai-mission', [RunController::class, 'aiMission']);
    Route::get('runs/{id}/ai-transcript', [RunController::class, 'aiTranscript']);
    Route::get('runs/{id}/ai-shot/{file}', [RunController::class, 'aiShot'])
        ->where('file', '[A-Za-z0-9._-]+');
});
