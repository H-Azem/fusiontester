<?php

namespace App\Http\Controllers;

use App\Jobs\BuildManualAppJob;
use App\Models\ManualApp;
use App\Repositories\Manual\ManualAppRepository;
use App\Services\Device\ManualTestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\Response;

/**
 * The device, by hand: a frame to look at and the few inputs a person needs to poke
 * at the app themselves. It exists to judge whether the device is usable interactively
 * at all — every call is adb, so it is only as quick as adb is.
 */
class DeviceController extends Controller
{
    const TIMEOUT_SECONDS = 120;

    /** Wide enough to read, small enough to travel over the internet. */
    const FRAME_WIDTH = 720;

    public function frame()
    {
        $this->connect();

        $png = $this->adb(['exec-out', 'screencap', '-p'], raw: true);

        if ($png === null || $png === '') {
            return $this->legacyResponse(['error' => 'device_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response($this->shrink($png), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function tap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'integer', 'min:0', 'max:10000'],
            'y' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        return $this->performed(['shell', 'input', 'tap', (string) $validated['x'], (string) $validated['y']]);
    }

    public function swipe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x1' => ['required', 'integer', 'min:0', 'max:10000'],
            'y1' => ['required', 'integer', 'min:0', 'max:10000'],
            'x2' => ['required', 'integer', 'min:0', 'max:10000'],
            'y2' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        return $this->performed([
            'shell', 'input', 'swipe',
            (string) $validated['x1'], (string) $validated['y1'],
            (string) $validated['x2'], (string) $validated['y2'], '300',
        ]);
    }

    /** A space is `%s` to `input text`, which is the only way it types one. */
    public function text(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:200']]);

        return $this->performed(['shell', 'input', 'text', str_replace(' ', '%s', $validated['text'])]);
    }

    public function key(Request $request): JsonResponse
    {
        $validated = $request->validate(['key' => ['required', 'in:BACK,HOME,APP_SWITCH,ENTER,DEL']]);

        return $this->performed(['shell', 'input', 'keyevent', 'KEYCODE_'.$validated['key']]);
    }

    /** @param array<int, string> $args */
    private function performed(array $args): JsonResponse
    {
        $this->connect();

        if ($this->adb($args) === null) {
            return $this->legacyResponse(['error' => 'device_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->legacyResponse(['ok' => true]);
    }

    /**
     * Turns the device: the app lays itself out from the screen it is handed, so a
     * landscape app on a portrait screen puts its controls where nothing is.
     */
    public function orientation(Request $request): JsonResponse
    {
        $validated = $request->validate(['orientation' => ['required', 'in:horizontal,vertical']]);

        $vertical = $validated['orientation'] === 'vertical';

        $this->connect();
        $this->adb(['shell', 'settings', 'put', 'system', 'accelerometer_rotation', '0']);
        $this->adb(['shell', 'settings', 'put', 'system', 'user_rotation', $vertical ? '0' : '1']);

        if ($this->adb(['shell', 'wm', 'size', $vertical ? '1080x1920' : '1920x1080']) === null) {
            return $this->legacyResponse(['error' => 'device_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->legacyResponse(['ok' => true, 'orientation' => $validated['orientation']]);
    }

    /** Rebuilds one app's APK now, from this page. */
    public function rebuild(Request $request): JsonResponse
    {
        $validated = $request->validate(['appId' => ['required', 'integer', 'min:1']]);

        if ((new ManualAppRepository)->find((int) $validated['appId']) === null) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        BuildManualAppJob::dispatch((int) $validated['appId']);

        return $this->legacyResponse(['ok' => true]);
    }

    /**
     * The page is still open. An hour after the last of these, the apps it installed
     * are removed — the emulator is not a place to leave things lying around.
     */
    public function activity(): JsonResponse
    {
        (new ManualTestSession)->touch();

        return $this->legacyResponse(['ok' => true]);
    }

    /** Is the device there? A build puts it to sleep, and the page should say so. */
    public function state(): JsonResponse
    {
        $this->connect();

        return $this->legacyResponse([
            'reachable' => trim((string) $this->adb(['shell', 'getprop', 'sys.boot_completed'])) === '1',
        ]);
    }

    /** The apps that can be installed by hand, each with the build it would install. */
    public function apps(): JsonResponse
    {
        $apps = (new ManualAppRepository)->all()->map(function (ManualApp $app): array {
            $path = (string) ($app->getApkPath() ?? '');

            return [
                'id' => (int) $app->getId(),
                'title' => $app->getTitle(),
                'branch' => $app->getBranch(),
                'builtAt' => $app->getApkBuiltAt()?->toIso8601String(),
                'ready' => $path !== '' && is_file($path),
                'buildError' => $app->getBuildError(),
            ];
        })->all();

        return $this->legacyResponse(['apps' => $apps]);
    }

    /** Installs one app's built APK on the device. */
    public function install(Request $request): JsonResponse
    {
        $validated = $request->validate(['appId' => ['required', 'integer', 'min:1']]);
        $app = (new ManualAppRepository)->find((int) $validated['appId']);
        $path = (string) ($app?->getApkPath() ?? '');

        if ($app === null || $path === '' || ! is_file($path)) {
            return $this->legacyResponse(
                ['error' => 'no_build', 'message' => 'That app has no built APK yet.'],
                Response::HTTP_CONFLICT
            );
        }

        $this->connect();

        $session = new ManualTestSession;
        $before = $this->packages();

        $process = new Process(
            array_merge([(string) config('fusion.android.adb'), '-s', (string) config('fusion.android.device')], ['install', '-r', $path]),
            null,
            null,
            null,
            self::TIMEOUT_SECONDS
        );

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return $this->legacyResponse(['error' => 'device_unavailable', 'message' => $exception->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $process->isSuccessful()) {
            return $this->legacyResponse(
                ['error' => 'install_failed', 'message' => trim($process->getOutput().' '.$process->getErrorOutput())],
                Response::HTTP_BAD_REQUEST
            );
        }

        // What the install added is what this page owns; anything already on the device
        // belongs to something else and is left alone.
        foreach (array_diff($this->packages(), $before) as $package) {
            $session->rememberInstalled($package);
        }

        $session->touch();

        return $this->legacyResponse(['ok' => true, 'detail' => trim($process->getOutput())]);
    }

    /** @return array<int, string> */
    private function packages(): array
    {
        $packages = [];

        foreach (preg_split('/\R/', (string) $this->adb(['shell', 'pm', 'list', 'packages', '-3'])) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'package:')) {
                $packages[] = substr($line, strlen('package:'));
            }
        }

        return $packages;
    }

    /** adb holds one connection per device, and a restarted container needs a new one. */
    private function connect(): void
    {
        $this->adb(['connect', (string) config('fusion.android.device')]);
    }

    /** @param array<int, string> $args */
    private function adb(array $args, bool $raw = false): ?string
    {
        $process = new Process(
            array_merge(
                [(string) config('fusion.android.adb'), '-s', (string) config('fusion.android.device')],
                $args
            ),
            null,
            null,
            null,
            self::TIMEOUT_SECONDS
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? ($raw ? $process->getOutput() : trim($process->getOutput())) : null;
    }

    /** A 1920x1080 PNG is megabytes; the browser only needs it readable. */
    private function shrink(string $png): string
    {
        $source = tempnam(sys_get_temp_dir(), 'device');
        $target = $source.'.png';
        file_put_contents($source, $png);

        $process = new Process(['convert', $source, '-resize', self::FRAME_WIDTH.'x', $target], null, null, null, 30);

        try {
            $process->run();
        } catch (\Throwable) {
            // ImageMagick missing is not fatal: the full frame still works.
        }

        $out = is_file($target) ? (string) file_get_contents($target) : $png;

        @unlink($source);
        @unlink($target);

        return $out;
    }
}
