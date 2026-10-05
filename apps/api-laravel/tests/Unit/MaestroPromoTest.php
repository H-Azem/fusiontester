<?php

namespace Tests\Unit;

use App\Services\Run\ExecuteRunAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Maestro ends its output with an advertisement for its cloud service, inside a box.
 * It is the last thing a person reads in the panel, and it says nothing about the run.
 */
class MaestroPromoTest extends TestCase
{
    private function banner(): string
    {
        return implode("\n", [
            'Waiting for flows to complete...',
            '[Passed] full_test (2m 3s)',
            "[Failed] full_test (1m 46s) (Element not found: Text matching regex: Can't Find Customer)",
            '',
            '1/2 Flow Failed',
            '',
            '╭────────────────────────────────────────────────╮',
            '│                                                │',
            '│   Debug tests faster by easy access to test recordings, maestro logs, │',
            '│   Run your flows on Maestro Cloud:             │',
            '│   maestro cloud app_file flows_folder/         │',
            '│                                                │',
            '╰────────────────────────────────────────────────╯',
        ]);
    }

    #[Test]
    public function the_cloud_advertisement_is_dropped_whole(): void
    {
        $clean = ExecuteRunAction::stripMaestroPromo($this->banner());

        $this->assertStringNotContainsString('maestro cloud', $clean);
        $this->assertStringNotContainsString('Maestro Cloud', $clean);
        $this->assertStringNotContainsString('Debug tests faster', $clean);
        $this->assertStringNotContainsString('╭', $clean);
        $this->assertStringNotContainsString('│', $clean);

        $this->assertStringContainsString('[Passed] full_test (2m 3s)', $clean);
        $this->assertStringContainsString('1/2 Flow Failed', $clean);
        $this->assertStringContainsString("Can't Find Customer", $clean);
    }

    #[Test]
    public function a_pitch_printed_without_a_box_also_goes(): void
    {
        $clean = ExecuteRunAction::stripMaestroPromo("1/1 Flow Failed\nRun your flows on Maestro Cloud:\nmaestro cloud app_file\n");

        $this->assertStringNotContainsString('Maestro Cloud', $clean);
        $this->assertStringContainsString('1/1 Flow Failed', $clean);
    }

    #[Test]
    public function ordinary_maestro_output_is_left_alone(): void
    {
        $out = "Waiting for flows to complete...\n[Passed] full_test (2m 3s)\n\n1/1 Flow Passed";

        $this->assertSame($out, ExecuteRunAction::stripMaestroPromo($out));
    }
}
