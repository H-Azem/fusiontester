<?php

use App\Models\Contracts\UserInterface;

return [
    /*
     * Origin the dashboard is served from. Login origin checks compare against
     * this value, so in production it must match the public URL exactly.
     */
    'web_origin' => env('WEB_ORIGIN', 'http://localhost:1999'),

    'session' => [
        'cookie' => 'ft_session',
        'ttl_hours' => (int) env('SESSION_TTL_HOURS', 12),
    ],

    /*
     * Failed attempts before an IP is blocked, the window they are counted in,
     * and how long the block lasts. Captcha mistakes are deliberately excluded
     * from this count.
     */
    'lockout' => [
        'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 3),
        'failed_attempt_window_minutes' => (int) env('AUTH_FAILED_WINDOW_MINUTES', 60),
        'block_hours' => (int) env('AUTH_BLOCK_HOURS', 6),
    ],

    'captcha' => [
        'ttl_minutes' => (int) env('CAPTCHA_TTL_MINUTES', 10),
        'length' => 5,
        // Characters a human reads unambiguously; also keeps the answer space
        // free of glyphs that OCR-style solving tends to confuse.
        'ignore_chars' => '0oO1ilI',
    ],

    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD', 'admin'),
        'role' => UserInterface::ROLE_ADMIN,
    ],

    /*
     * The Android lane drives a real device (the redroid container) instead of a
     * browser build, so it needs adb and the device's address. `live` captures a
     * frame every interval while such a run is in flight.
     */
    'android' => [
        'device' => env('FUSION_ANDROID_DEVICE', '127.0.0.1:5555'),
        'adb' => env('FUSION_ADB', 'adb'),
        'live_interval_ms' => (int) env('FUSION_LIVE_INTERVAL_MS', 2500),
        'live_width' => (int) env('FUSION_LIVE_WIDTH', 480),
    ],

    /*
     * Reading a Flutter web app's runtime needs the browser's accessibility tree
     * over CDP, so the browse boot check and the AI lane live in the runner
     * package and this service shells out to them. `dir` is that package.
     */
    'sidecar' => [
        'node' => env('SIDECAR_NODE', 'node'),
        'dir' => env('SIDECAR_DIR', base_path('../runner')),
        'scripts' => env('SIDECAR_SCRIPTS', base_path('../runner/src/scripts')),
        'timeout_seconds' => (int) env('SIDECAR_TIMEOUT', 1200),
    ],
];
