<?php

namespace App\Services\Ai;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A short list of landmarks from a test's Maestro flow.
 *
 * The AI lane is not a script runner: it should behave like a person using the
 * app. So the flow is read for *inspiration* only — the few beats a human would
 * name ("open customers", "add a customer", "it is visible") — and never replayed
 * step by step. Nested `runFlow`s are not followed: inlining a shared sign-in and
 * everything it pulls in turned two small tests into thousands of goals.
 *
 * No model is consulted; this is a read of the repository's own text.
 */
class MaestroGoals
{
    /** Enough to describe the journey; a longer list is a script again. */
    const MAX_GOALS = 8;

    /** An id names the widget as well as the thing; the landmark names the thing. */
    const ID_SUFFIXES = ['_tab', '_button', '_btn', '_icon', '_link'];

    /** Moving somewhere is "open"; these id suffixes mark navigation. */
    const NAV_SUFFIXES = ['_tab', '_nav', '_menu'];

    /** A landmark that creates something is worth naming as an action. */
    const CREATE_WORDS = ['add', 'create', 'new', 'save', 'submit', 'insert', 'register'];

    const MAX_LABEL_LENGTH = 60;

    /**
     * @param  array<int, string>  $files  absolute paths to a test's flow files
     * @return array<int, string>
     */
    public function fromFlowFiles(array $files): array
    {
        $goals = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            foreach ($this->landmarks($this->steps((string) file_get_contents($file))) as $goal) {
                if ($goal === '' || in_array($goal, $goals, true)) {
                    continue;
                }

                $goals[] = $goal;

                if (count($goals) >= self::MAX_GOALS) {
                    return $goals;
                }
            }
        }

        return $goals;
    }

    /**
     * A flow file is a config document plus a command list, split by `---`, and
     * Symfony's parser refuses more than one document — so the list is found by
     * parsing each document and keeping the one that is a list.
     *
     * @return array<int, mixed>
     */
    private function steps(string $yaml): array
    {
        foreach (preg_split('/^---\s*$/m', $yaml) ?: [] as $document) {
            try {
                $parsed = Yaml::parse($document);
            } catch (ParseException) {
                continue;
            }

            if (is_array($parsed) && $parsed !== [] && array_is_list($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    /** @return array<int, string> */
    private function landmarks(array $steps): array
    {
        $goals = [];

        foreach ($steps as $step) {
            // `- launchApp` has no value at all, so it arrives as a bare string.
            if (is_string($step)) {
                if ($step === 'launchApp') {
                    $goals[] = 'reach the home screen';
                }

                continue;
            }

            if (! is_array($step) || $step === []) {
                continue;
            }

            $command = (string) array_key_first($step);
            $value = $step[$command];

            $goal = match ($command) {
                'launchApp' => 'reach the home screen',
                'tapOn' => $this->tapLandmark($value),
                'assertVisible' => $this->visibleLandmark($value),
                'extendedWaitUntil' => $this->waitLandmark($value),
                default => null,
            };

            if ($goal !== null) {
                $goals[] = $goal;
            }
        }

        return $goals;
    }

    /** Navigation and creation are landmarks; an arbitrary tap is a step, not a goal. */
    private function tapLandmark(mixed $target): ?string
    {
        $label = $this->labelFor($target);

        if ($label === null) {
            return null;
        }

        if ($this->isNavigation($target)) {
            return 'open '.$label;
        }

        $first = strtolower(strtok($label, ' ') ?: '');

        return in_array($first, self::CREATE_WORDS, true) ? $label : null;
    }

    private function visibleLandmark(mixed $target): ?string
    {
        $label = $this->labelFor($target);

        return $label === null ? null : $label.' is visible';
    }

    private function waitLandmark(mixed $value): ?string
    {
        $label = $this->labelFor(is_array($value) ? ($value['visible'] ?? null) : null);

        return $label === null ? null : $label.' appears';
    }

    private function isNavigation(mixed $target): bool
    {
        $id = is_array($target) ? ($target['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            return false;
        }

        foreach (self::NAV_SUFFIXES as $suffix) {
            if (str_ends_with($id, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function labelFor(mixed $target): ?string
    {
        if (is_array($target)) {
            foreach (['id', 'label', 'text'] as $key) {
                if (isset($target[$key]) && is_string($target[$key]) && trim($target[$key]) !== '') {
                    return $this->humanize($target[$key], $key === 'id');
                }
            }

            return null;
        }

        return is_string($target) && trim($target) !== '' ? $this->humanize($target, false) : null;
    }

    private function humanize(string $value, bool $fromId): string
    {
        $value = trim($value);

        if ($fromId) {
            if (str_contains($value, '/')) {
                $value = substr($value, (int) strrpos($value, '/') + 1);
            }

            foreach (self::ID_SUFFIXES as $suffix) {
                if (str_ends_with($value, $suffix) && strlen($value) > strlen($suffix)) {
                    $value = substr($value, 0, -strlen($suffix));
                    break;
                }
            }
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > self::MAX_LABEL_LENGTH
            ? mb_substr($value, 0, self::MAX_LABEL_LENGTH).'…'
            : $value;
    }
}
