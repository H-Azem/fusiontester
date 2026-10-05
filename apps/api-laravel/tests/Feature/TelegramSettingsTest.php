<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Run reports go to a Telegram channel. The bot token is a credential, so it sits
 * behind the settings password and is never returned to the dashboard.
 */
class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    private function unlockAndSave(string $token): void
    {
        $this->unlockSettings($token);

        $this->asSession($token)->putJson('/settings/telegram', [
            'botToken' => '123456:AAHsecret-token',
            'chatId' => '@fusion_reports',
            'notifyOnPass' => true,
        ])->assertSuccessful();
    }

    #[Test]
    public function the_settings_password_guards_the_bot_token(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->getJson('/settings/telegram')->assertStatus(403);

        $this->unlockAndSave($token);

        $this->asSession($token)->getJson('/settings/telegram')
            ->assertSuccessful()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('chatId', '@fusion_reports')
            ->assertJsonPath('botTokenHint', '••••oken')
            ->assertJsonMissingPath('botToken');
    }

    #[Test]
    public function the_token_is_stored_encrypted_and_can_be_decrypted_for_use(): void
    {
        $token = $this->loginToken();
        $this->unlockAndSave($token);

        $row = TelegramConnection::query()->firstOrFail();

        $this->assertStringNotContainsString('123456:AAHsecret-token', (string) $row->getBotTokenCiphertext());
        $this->assertSame('123456:AAHsecret-token', (new \App\Repositories\Settings\TelegramConnectionRepository)->data()?->token);
    }

    #[Test]
    public function testing_it_verifies_the_bot_and_sends_a_message(): void
    {
        $token = $this->loginToken();
        $this->unlockAndSave($token);

        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'fusion_bot']]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->asSession($token)->postJson('/settings/telegram/test')
            ->assertSuccessful()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bot', 'fusion_bot');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '@fusion_reports');
    }

    #[Test]
    public function a_rejected_token_is_reported_rather_than_thrown(): void
    {
        $token = $this->loginToken();
        $this->unlockAndSave($token);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->asSession($token)->postJson('/settings/telegram/test')
            ->assertSuccessful()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Unauthorized');
    }

    #[Test]
    public function a_finished_run_produces_a_readable_report(): void
    {
        $notifier = new TelegramNotifier;

        $run = new Run([
            Run::PROJECT_PATH => 'fusion-food-tech/mobile-apps/pos',
            Run::BRANCH => 'stage',
            Run::TESTS => ['reports'],
            Run::PLATFORM => Run::PLATFORM_ANDROID,
            Run::ORIENTATION => 'vertical',
            Run::ENVIRONMENTS => ['development'],
            Run::STATUS => Run::STATUS_FAILED,
            Run::ERROR_MESSAGE => 'Assertion is false: id: main_screen is visible',
        ]);

        $message = $notifier->message($run, false);

        $this->assertStringContainsString('Test failed', $message);
        $this->assertStringContainsString('fusion-food-tech/mobile-apps/pos', $message);
        $this->assertStringContainsString('reports', $message);
        $this->assertStringContainsString('main_screen', $message);
        $this->assertStringContainsString('/runs/', $message);
    }

    #[Test]
    public function the_notifier_stays_quiet_when_nothing_is_configured(): void
    {
        Http::fake();

        (new TelegramNotifier)->report(new Run);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_passing_run_can_be_kept_out_of_the_channel(): void
    {
        $this->loginToken();
        $repository = new \App\Repositories\Settings\TelegramConnectionRepository;
        $repository->save('123456:AAH', '@channel', false);

        $run = new Run([Run::STATUS => Run::STATUS_PASSED]);
        $run->setAttribute('id', '01900000-0000-7000-8000-000000000000');

        $notifier = new TelegramNotifier;
        $this->assertStringContainsString('Test passed', $notifier->message($run, true));

        Http::fake();
        $notifier->report($run);
        Http::assertNothingSent();
    }

    #[Test]
    public function reports_can_be_switched_off_entirely(): void
    {
        $token = $this->loginToken();
        $this->unlockAndSave($token);

        $this->asSession($token)->putJson('/settings/telegram', [
            'chatId' => '@fusion_reports',
            'enabled' => false,
        ])->assertSuccessful()->assertJsonPath('enabled', false);

        Http::fake();

        (new TelegramNotifier)->report(new Run([Run::STATUS => Run::STATUS_PASSED]));

        // Off means off, whatever the other switch says.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_report_can_be_sent_into_one_topic(): void
    {
        $token = $this->loginToken();
        $this->unlockAndSave($token);

        $this->asSession($token)->putJson('/settings/telegram', [
            'chatId' => '@fusion_reports',
            'messageThreadId' => '42',
        ])->assertSuccessful()->assertJsonPath('messageThreadId', '42');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        (new TelegramNotifier)->report(new Run([Run::STATUS => Run::STATUS_PASSED]));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['message_thread_id'] === '42');
    }
}
