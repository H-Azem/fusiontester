<?php

namespace App\Services\Telegram;

use App\Http\Resources\RunArtifacts;
use App\Models\Run;
use App\Models\RunStep;
use App\Repositories\Settings\AiConnectionRepository;
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
        private AiConnectionRepository $ai = new AiConnectionRepository,
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

        $ai = $this->aiReport($run);
        if ($ai !== null) {
            $lines[] = $ai;
        }

        $lines[] = $this->link($run);

        return $this->clip(implode("\n", $lines), self::MAX_LENGTH);
    }

    /**
     * The AI lane's own verdict, when the lane produced one and the switch to share
     * it is on. Absent for runs that never reached the lane, so nothing changes for
     * the ordinary Maestro reports.
     */
    private function aiReport(Run $run): ?string
    {
        $connection = $this->ai->row();

        if ($connection === null || ! $connection->getShareReportToTelegram()) {
            return null;
        }

        $path = RunArtifacts::path((string) $run->getId(), RunArtifacts::AI_REPORT);

        if (! is_file($path)) {
            return null;
        }

        $report = json_decode((string) file_get_contents($path), true);

        if (! is_array($report)) {
            return null;
        }

        $goals = is_array($report['goals'] ?? null) ? $report['goals'] : [];
        $summary = trim((string) ($report['summary'] ?? ''));

        if ($goals === [] && $summary === '') {
            return null;
        }

        $lines = ['', '<b>AI report</b>'];

        if ($summary !== '') {
            $lines[] = htmlspecialchars($summary, ENT_QUOTES);
        }

        foreach ($goals as $goal) {
            if (! is_array($goal)) {
                continue;
            }

            $mark = strtolower((string) ($goal['status'] ?? '')) === 'pass' ? '✅' : '❌';
            $lines[] = $mark.' '.htmlspecialchars((string) ($goal['text'] ?? ''), ENT_QUOTES);
        }

        $usage = $report['usage'] ?? null;

        if (is_array($usage)) {
            $tokens = (int) ($usage['inputTokens'] ?? 0) + (int) ($usage['outputTokens'] ?? 0);

            if ($tokens > 0) {
                $lines[] = 'Tokens: '.number_format($tokens);
            }
        }

        return implode("\n", $lines);
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
