<?php

namespace App\Services\Ai;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the repository's Maestro flows into a plain-language goal list.
 *
 * The flows already say what a test is supposed to do — which screens it reaches
 * and which elements it touches — so the "what should this test do" question is
 * answered here, for free. What is left for the lane is "how to do it on the
 * screens it actually finds", which is what the model is for.
 *
 * Goals are derived from the commands alone; no model is consulted.
 */
class MaestroGoals
{
    /** A runFlow cycle between shared flows must not recurse forever. */
    const MAX_DEPTH = 8;

    /** An id names the widget as well as the thing; the goal should name the thing. */
    const ID_SUFFIXES = ['_tab', '_button', '_btn', '_icon', '_link'];

    /** Suffixes that mean "moving to a place", phrased as open rather than tap. */
    const NAV_SUFFIXES = ['_tab', '_nav', '_menu'];

    const MAX_LABEL_LENGTH = 60;

    /**
     * @param  array<int, string>  $files  absolute paths to flow files, in run order
     * @return array<int, string>
     */
    public function fromFlowFiles(array $files): array
    {
        $goals = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $goals = array_merge(
                $goals,
                $this->fromYaml((string) file_get_contents($file), dirname($file))
            );
        }

        return $goals;
    }

    /**
     * A flow file is a config document plus a command list, split by `---`, and
     * Symfony's parser refuses more than one document — so the list is found by
     * parsing each document and keeping the one that is a list.
     *
     * @param  array<string, true>  $visited  files already inlined, to break cycles
     * @return array<int, string>
     */
    public function fromYaml(string $yaml, string $baseDir = '', int $depth = 0, array $visited = []): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        return $this->goalsFromSteps($this->steps($yaml), $baseDir, $depth, $visited);
    }

    /** The mission the lane works through: one numbered goal per line. */
    public function toText(array $goals): string
    {
        if ($goals === []) {
            return 'Explore the app and verify its main journey still works.';
        }

        $lines = [];

        foreach (array_values($goals) as $index => $goal) {
            $lines[] = ($index + 1).'. '.$goal;
        }

        return implode("\n", $lines);
    }

    /** @return array<int, mixed> */
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

    /**
     * @param  array<int, mixed>  $steps
     * @param  array<string, true>  $visited
     * @return array<int, string>
     */
    private function goalsFromSteps(array $steps, string $baseDir, int $depth, array $visited): array
    {
        $goals = [];

        foreach ($steps as $step) {
            foreach ($this->goalsForStep($step, $baseDir, $depth, $visited) as $goal) {
                if ($goal === '') {
                    continue;
                }

                // A flow that retries or waits repeatedly should not repeat itself.
                if ($goals !== [] && end($goals) === $goal) {
                    continue;
                }

                $goals[] = $goal;
            }
        }

        return $goals;
    }

    /** @param array<string, true> $visited @return array<int, string> */
    private function goalsForStep(mixed $step, string $baseDir, int $depth, array $visited): array
    {
        if (is_string($step)) {
            return $this->goalFor($step, null, $baseDir, $depth, $visited);
        }

        if (! is_array($step) || $step === []) {
            return [];
        }

        $command = (string) array_key_first($step);

        return $this->goalFor($command, $step[$command], $baseDir, $depth, $visited);
    }

    /** @param array<string, true> $visited @return array<int, string> */
    private function goalFor(string $command, mixed $value, string $baseDir, int $depth, array $visited): array
    {
        switch ($command) {
            case 'launchApp':
                return ['reach the home screen'];

            case 'stopApp':
                return ['close the app'];

            case 'back':
                return ['go back'];

            case 'eraseText':
                return ['clear the field'];

            case 'swipe':
                $direction = is_array($value) ? (string) ($value['direction'] ?? '') : '';

                return [$direction === '' ? 'swipe the screen' : 'swipe '.$direction];

            case 'pressKey':
                return [is_string($value) && $value !== '' ? 'press '.$value : 'press a key'];

            case 'waitForAnimationToEnd':
                return ['wait for the screen to settle'];

            case 'extendedWaitUntil':
                $label = $this->labelFor(is_array($value) ? ($value['visible'] ?? null) : null);

                return [$label === null ? 'wait until the screen is ready' : 'wait until '.$label.' is visible'];

            case 'tapOn':
            case 'longPressOn':
            case 'doubleTapOn':
                $label = $this->labelFor($value);

                if ($label === null) {
                    return ['tap an element'];
                }

                if ($command === 'longPressOn') {
                    return ['long-press '.$label];
                }

                if ($command === 'doubleTapOn') {
                    return ['double-tap '.$label];
                }

                return [$this->isNavigation($value) ? 'open '.$label : 'tap '.$label];

            case 'inputText':
                $text = is_string($value) ? trim($value) : '';

                return [$text === '' ? 'type into the field' : 'type "'.$this->shorten($text).'"'];

            case 'assertVisible':
                $label = $this->labelFor($value);

                return [$label === null ? 'the expected element is visible' : $label.' is visible'];

            case 'assertNotVisible':
                $label = $this->labelFor($value);

                return [$label === null ? 'the unwanted element is gone' : $label.' is not visible'];

            case 'scroll':
                return ['scroll the screen'];

            case 'scrollUntilVisible':
                $label = $this->labelFor(is_array($value) ? ($value['element'] ?? null) : null);

                return [$label === null ? 'scroll the screen' : 'scroll until '.$label.' is visible'];

            case 'runFlow':
                return $this->goalsFromRunFlow($value, $baseDir, $depth, $visited);
        }

        return [];
    }

    /** @param array<string, true> $visited @return array<int, string> */
    private function goalsFromRunFlow(mixed $value, string $baseDir, int $depth, array $visited): array
    {
        if (is_string($value) && $value !== '') {
            return $this->goalsFromFile($value, $baseDir, $depth, $visited);
        }

        if (! is_array($value)) {
            return [];
        }

        if (isset($value['commands']) && is_array($value['commands'])) {
            return $this->goalsFromSteps($value['commands'], $baseDir, $depth + 1, $visited);
        }

        foreach (['file', 'flow'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                return $this->goalsFromFile($value[$key], $baseDir, $depth, $visited);
            }
        }

        return [];
    }

    /** @param array<string, true> $visited @return array<int, string> */
    private function goalsFromFile(string $reference, string $baseDir, int $depth, array $visited): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $path = $baseDir === '' ? $reference : rtrim($baseDir, '/').'/'.$reference;
        $real = realpath($path) ?: $path;

        if (isset($visited[$real])) {
            return [];
        }

        // A flow the run was not handed is still a step the test expects; name it
        // rather than dropping it silently.
        if (! is_file($real)) {
            return ['complete the '.$this->flowName($reference).' flow'];
        }

        $visited[$real] = true;

        return $this->fromYaml((string) file_get_contents($real), dirname($real), $depth + 1, $visited);
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

        if (is_string($target) && trim($target) !== '') {
            return $this->humanize($target, false);
        }

        return null;
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
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return $this->shorten(trim($value));
    }

    private function flowName(string $reference): string
    {
        $name = basename($reference);
        $name = (string) preg_replace('/\.ya?ml$/i', '', $name);

        return $this->humanize($name, false);
    }

    private function shorten(string $value): string
    {
        return mb_strlen($value) > self::MAX_LABEL_LENGTH
            ? mb_substr($value, 0, self::MAX_LABEL_LENGTH).'…'
            : $value;
    }
}
