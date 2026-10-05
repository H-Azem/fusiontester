<?php

namespace App\Services\Telegram;

use App\Models\Run;
use App\Models\RunStep;
use App\Repositories\Settings\TelegramConnectionRepository;

/**
 * Posts one message per finished run: what ran, how it ended, how long it took, and
 * where to look. A notification that cannot be delivered is recorded and swallowed,
 * because the run's own outcome is what matters.
 */
class TelegramNotifier
{
    /** Telegram rejects messages beyond 4096 characters. */
    const MAX_LENGTH = 3800;

    public function __construct(
        private TelegramConnectionRepository $connections = new TelegramConnectionRepository,
        private TelegramClient $client = new TelegramClient,
    ) {}

    public function report(Run $run): void
    {
        $connection = $this->connections->data();

        if ($connection === null || ! $connection->enabled) {
            return;
        }

        $passed = $run->getStatus() === Run::STATUS_PASSED;

        if ($passed && ! $connection->notifyOnPass) {
            return;
        }

        $result = $this->client->sendMessage($connection->token, $connection->chatId, $this->message($run, $passed), $connection->threadId);

        $this->connections->recordVerification($result['ok'], $result['ok'] ? null : $result['detail']);
    }

    /** The message a person reads on a phone: outcome first, then the specifics. */
    public function message(Run $run, bool $passed): string
    {
        $icon = $passed ? '✅' : '❌';
        $headline = $passed ? 'Test passed' : 'Test failed';

        $lines = [
            $icon.' <b>'.$headline.'</b>',
            '<code>'.htmlspecialchars($run->getProjectPath(), ENT_QUOTES).'</code> on <code>'
                .htmlspecialchars($run->getBranch(), ENT_QUOTES).'</code>',
        ];

        $tests = $run->getTests() ?? [];
        $lines[] = 'Tests: '.($tests === [] ? 'all flows' : htmlspecialchars(implode(', ', $tests), ENT_QUOTES));

        $lines[] = 'Lane: '.($run->getPlatform() === Run::PLATFORM_ANDROID ? 'Android device' : 'Web')
            .' · '.$run->getOrientation().' · '.implode(' + ', $run->getEnvironments() ?? []);

        $duration = $this->duration($run);
        if ($duration !== null) {
            $lines[] = 'Took: '.$duration;
        }

        if (! $passed) {
            $step = $this->firstFailedStep($run);
            if ($step !== null) {
                $lines[] = 'Stopped at: <b>'.$step->getLabel().'</b>';
            }

            $error = trim((string) $run->getErrorMessage());
            if ($error !== '') {
                $lines[] = '<pre>'.htmlspecialchars($this->clip($error, 700), ENT_QUOTES).'</pre>';
            }
        }

        $lines[] = $this->link($run);

        return $this->clip(implode("\n", $lines), self::MAX_LENGTH);
    }

    private function firstFailedStep(Run $run): ?RunStep
    {
        return RunStep::query()
            ->where(RunStep::RUN_ID, $run->getId())
            ->where(RunStep::STATUS, RunStep::STATUS_FAILED)
            ->orderBy(RunStep::POSITION)
            ->first();
    }

    private function duration(Run $run): ?string
    {
        $started = $run->getStartedAt();
        $finished = $run->getFinishedAt();

        if ($started === null || $finished === null) {
            return null;
        }

        $seconds = max(0, $finished->getTimestamp() - $started->getTimestamp());

        return $seconds < 60
            ? $seconds.'s'
            : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }

    private function link(Run $run): string
    {
        $origin = rtrim((string) config('fusion.web_origin'), '/');

        return '<a href="'.$origin.'/runs/'.$run->getId().'">Open the run</a>';
    }

    private function clip(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).'…';
    }
}
