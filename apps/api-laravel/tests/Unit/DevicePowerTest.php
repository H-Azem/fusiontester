<?php

namespace Tests\Unit;

use App\Services\Run\DevicePower;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DevicePowerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/fusion-control-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([DevicePower::PAUSE, DevicePower::RESUME] as $flag) {
            if (is_file($this->dir.'/'.$flag)) {
                unlink($this->dir.'/'.$flag);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_asks_the_host_to_pause_and_resume_the_device(): void
    {
        mkdir($this->dir);
        $power = new DevicePower($this->dir);

        $this->assertTrue($power->installed());
        $this->assertTrue($power->sleep());
        $this->assertFileExists($this->dir.'/'.DevicePower::PAUSE);

        $this->assertTrue($power->wake());
        $this->assertFileExists($this->dir.'/'.DevicePower::RESUME);
    }

    #[Test]
    public function it_does_nothing_when_no_watcher_is_installed(): void
    {
        // Without the host-side watcher the flags have nowhere to go, and a run has
        // to keep working — the device simply never sleeps.
        $power = new DevicePower($this->dir);

        $this->assertFalse($power->installed());
        $this->assertFalse($power->sleep());
        $this->assertFalse($power->wake());
        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
