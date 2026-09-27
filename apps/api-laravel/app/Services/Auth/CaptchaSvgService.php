<?php

namespace App\Services\Auth;

/**
 * Draws the verification code the login form shows.
 *
 * The characters are emitted as separate <text> elements with individual fills
 * so the answer stays legible under the noise, and the noise itself is strokes
 * rather than filled shapes so it can never be mistaken for a glyph.
 */
class CaptchaSvgService
{
    const WIDTH = 180;

    const HEIGHT = 60;

    const FONT_SIZE = 34;

    private const COLORS = ['#6f6174', '#61646f', '#506c65', '#d8b03b', '#72697b'];

    private const NOISE = ['#a0e45c', '#7ced7c', '#6fc8e5'];

    public function generate(int $length, string $ignoreChars): string
    {
        $alphabet = str_split(preg_replace('/['.preg_quote($ignoreChars, '/').']/', '', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'));

        $characters = '';
        for ($index = 0; $index < $length; $index++) {
            $characters .= $alphabet[random_int(0, count($alphabet) - 1)];
        }

        return $characters;
    }

    public function render(string $characters): string
    {
        $count = strlen($characters);
        $slot = (int) floor((self::WIDTH - 20) / max($count, 1));
        $parts = [
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">',
                self::WIDTH,
                self::HEIGHT,
                self::WIDTH,
                self::HEIGHT
            ),
            sprintf('<rect width="100%%" height="100%%" fill="%s"/>', '#161b22'),
        ];

        for ($index = 0; $index < $count; $index++) {
            $parts[] = sprintf(
                '<text x="%d" y="%d" fill="%s" font-family="monospace" font-size="%d" font-weight="700" transform="rotate(%d %d %d)">%s</text>',
                14 + $index * $slot,
                (int) (self::HEIGHT / 2 + self::FONT_SIZE / 3 + random_int(-5, 5)),
                self::COLORS[$index % count(self::COLORS)],
                self::FONT_SIZE,
                random_int(-18, 18),
                14 + $index * $slot,
                self::HEIGHT / 2,
                htmlspecialchars($characters[$index], ENT_QUOTES | ENT_XML1)
            );
        }

        for ($line = 0; $line < 3; $line++) {
            $parts[] = sprintf(
                '<path d="M%d %d C%d %d,%d %d,%d %d" stroke="%s" fill="none" stroke-width="1"/>',
                random_int(0, 20),
                random_int(0, self::HEIGHT),
                random_int(40, 120),
                random_int(0, self::HEIGHT),
                random_int(60, 140),
                random_int(0, self::HEIGHT),
                self::WIDTH - random_int(0, 15),
                random_int(0, self::HEIGHT),
                self::NOISE[$line % count(self::NOISE)]
            );
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }
}
