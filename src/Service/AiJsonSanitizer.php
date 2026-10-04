<?php

namespace App\Service;

/**
 * Repairs LLM-generated JSON text before json_decode().
 *
 * AI responses that look perfectly valid can fail to parse for several
 * independent reasons, all of which surface as "failed to parse response":
 *
 *  1. Raw invalid UTF-8 bytes (e.g. a multibyte character truncated by a
 *     byte-level substr()) -> fixed at decode time with
 *     json_decode(..., JSON_INVALID_UTF8_SUBSTITUTE).
 *
 *  2. Unpaired UTF-16 surrogate escapes (\uD83D or \uDE00 without their
 *     counterpart) -> json_decode() rejects these as "Single unpaired UTF-16
 *     surrogate in unicode escape", and NO json_decode flag can repair it.
 *
 *  3. Malformed structure the model emits in free-text fields — most commonly
 *     an unescaped double quote inside a string value (the model mixes
 *     typographic „ with an ASCII " closer), plus raw control characters and
 *     trailing commas. json_decode() reports these as "Syntax error".
 *
 * repair() applies (1) and (2) always, and (3) only when the text does not
 * already parse strictly — valid JSON is never touched.
 */
final class AiJsonSanitizer
{
    /**
     * A high surrogate not followed by a low surrogate, or a low surrogate not
     * preceded by a high surrogate. Matched without the /u modifier so it also
     * works on strings containing raw invalid UTF-8.
     */
    private const UNPAIRED_SURROGATE_PATTERNS = [
        '/\\\\uD[89ABab][0-9A-Fa-f]{2}(?!\\\\uD[C-Fc-f][0-9A-Fa-f]{2})/',
        '/(?<!\\\\uD[89ABab][0-9A-Fa-f]{2})\\\\uD[C-Fc-f][0-9A-Fa-f]{2}/',
    ];

    /**
     * Replace unpaired UTF-16 surrogate escapes with U+FFFD.
     */
    public static function sanitize(string $json): string
    {
        return preg_replace(self::UNPAIRED_SURROGATE_PATTERNS, "\u{FFFD}", $json) ?? $json;
    }

    /**
     * Full repair pass. Returns $json unchanged if it already parses.
     */
    public static function repair(string $json): string
    {
        $json = self::sanitize($json);

        if (json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE) !== null) {
            return $json;
        }

        return self::repairStructure($json);
    }

    /**
     * Re-escape unescaped double quotes inside string values, escape raw
     * control characters, and drop trailing commas before } / ].
     *
     * A quote inside a string is treated as the real terminator only when the
     * next non-whitespace character is a structural one (, } ] or :) — anything
     * else means it was a stray quote the model forgot to escape. Ambiguous
     * input still fails to decode, so a bad guess never yields valid-but-wrong
     * JSON; it just stays unparseable.
     *
     * Only the outermost {...} / [...] span is rewritten, so any prose around
     * the JSON (which the raw-extraction tier tolerates) is left untouched.
     */
    private static function repairStructure(string $json): string
    {
        $start = strcspn($json, '{[');
        if ($start >= strlen($json)) {
            return $json; // no JSON structure found
        }

        $close = $json[$start] === '{' ? '}' : ']';
        $end = strrpos($json, $close);
        if ($end === false || $end < $start) {
            return $json;
        }

        return substr($json, 0, $start)
            . self::repairSpan(substr($json, $start, $end - $start + 1))
            . substr($json, $end + 1);
    }

    private static function repairSpan(string $json): string
    {
        $out = '';
        $len = strlen($json);
        $inString = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if (!$inString) {
                if ($ch === ',') {
                    $j = $i + 1;
                    while ($j < $len && strpos(" \t\r\n", $json[$j]) !== false) {
                        $j++;
                    }
                    if ($j < $len && ($json[$j] === '}' || $json[$j] === ']')) {
                        continue; // trailing comma -> drop it
                    }
                }
                $out .= $ch;
                if ($ch === '"') {
                    $inString = true;
                }
                continue;
            }

            // --- inside a string value/key ---

            if ($ch === '\\') {
                // Preserve an existing escape sequence verbatim.
                $out .= $ch;
                if ($i + 1 < $len) {
                    $out .= $json[++$i];
                }
                continue;
            }

            if ($ch === '"') {
                $j = $i + 1;
                while ($j < $len && strpos(" \t\r\n", $json[$j]) !== false) {
                    $j++;
                }
                $next = $j < $len ? $json[$j] : '';
                if ($next === ',' || $next === '}' || $next === ']' || $next === ':') {
                    $out .= $ch;
                    $inString = false;
                } else {
                    $out .= '\\"';
                }
                continue;
            }

            $ord = ord($ch);
            if ($ord < 0x20) {
                $out .= match ($ch) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\f" => '\\f',
                    "\b" => '\\b',
                    default => sprintf('\\u%04x', $ord),
                };
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }
}
