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

        // A failure is what a person wants to *see*, so the frames the lane kept go
        // with the text. An upload that fails is not worth failing the notify over.
        foreach ($this->aiFrames($run) as $index => $path) {
            $this->client->sendPhoto(
                $connection->token,
                $connection->chatId,
                $path,
                $index === 0 ? '<b>AI report</b> · the failing screen' : '',
                $connection->threadId,
            );
        }
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
        $report = $this->aiReportData($run);

        if ($report === null) {
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

        $failed = 0;

        foreach ($goals as $goal) {
            if (! is_array($goal)) {
                continue;
            }

            $ok = strtolower((string) ($goal['status'] ?? '')) === 'pass';
            $failed += $ok ? 0 : 1;
            $lines[] = ($ok ? '✅' : '❌').' <b>'.htmlspecialchars((string) ($goal['text'] ?? ''), ENT_QUOTES).'</b>';

            // The evidence is what makes the verdict checkable rather than a claim.
            $evidence = trim((string) ($goal['evidence'] ?? ''));

            if ($evidence !== '') {
                $lines[] = '<i>'.htmlspecialchars($this->clip($evidence, 200), ENT_QUOTES).'</i>';
            }
        }

        if ($goals !== []) {
            $lines[] = count($goals).' checked · '.$failed.' failed';
        }

        $usage = $report['usage'] ?? null;

        if (is_array($usage)) {
            $in = (int) ($usage['inputTokens'] ?? 0);
            $out = (int) ($usage['outputTokens'] ?? 0);

            if ($in + $out > 0) {
                $lines[] = 'Tokens: '.number_format($in + $out)
                    .' ('.number_format($in).' in · '.number_format($out).' out)';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The lane's report, but only when it produced one and the switch to share it is
     * on. Null keeps the ordinary Maestro report unchanged.
     *
     * @return array<string, mixed>|null
     */
    private function aiReportData(Run $run): ?array
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

        return is_array($report) ? $report : null;
    }

    /**
     * The frames the lane kept when something failed, newest last. Only sent when
     * the AI report itself is shared, so a text-only setup stays text-only.
     *
     * @return array<int, string>
     */
    private function aiFrames(Run $run): array
    {
        if ($this->aiReportData($run) === null) {
            return [];
        }

        $directory = RunArtifacts::path((string) $run->getId(), RunArtifacts::AI_SHOTS);
        $frames = array_merge(
            glob($directory.'/*.png') ?: [],
            glob($directory.'/*.jpg') ?: [],
        );

        sort($frames);

        return array_slice($frames, 0, 3);
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
