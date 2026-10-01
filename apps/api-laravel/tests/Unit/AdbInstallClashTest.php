<?php

namespace Tests\Unit;

use App\Services\Run\ExecuteRunAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reported failure, verbatim: a build from an earlier run is still installed with
 * another signature, and Android says so in its own words. That package name is what
 * lets the pipeline clean up after itself.
 */
class AdbInstallClashTest extends TestCase
{
    #[Test]
    public function it_reads_the_package_android_refused_to_replace(): void
    {
        $output = "Performing Streamed Install\n"
            ."adb: failed to install /app/storage/app/fusion/runs/01a0f74f/repo/build/app/outputs/flutter-apk/app-generalapp-debug.apk: "
            .'Failure [INSTALL_FAILED_UPDATE_INCOMPATIBLE: Existing package com.fusionfoodtech.hubdev signatures do not match newer version; ignoring!]';

        $this->assertSame('com.fusionfoodtech.hubdev', ExecuteRunAction::clashingPackage($output));
    }

    #[Test]
    public function an_install_that_failed_for_another_reason_names_nothing(): void
    {
        $this->assertNull(ExecuteRunAction::clashingPackage('adb: failed to install app.apk: Failure [INSTALL_FAILED_INSUFFICIENT_STORAGE]'));
        $this->assertNull(ExecuteRunAction::clashingPackage('device offline'));
        $this->assertNull(ExecuteRunAction::clashingPackage(''));
    }

    #[Test]
    public function it_still_finds_the_package_when_only_the_reason_is_printed(): void
    {
        $this->assertSame(
            'com.example.app',
            ExecuteRunAction::clashingPackage('Failure [INSTALL_FAILED_UPDATE_INCOMPATIBLE: Package com.example.app was not installed]')
        );
    }
}
