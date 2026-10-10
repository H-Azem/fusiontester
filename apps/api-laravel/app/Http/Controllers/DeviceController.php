<?php

namespace App\Http\Controllers;

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
