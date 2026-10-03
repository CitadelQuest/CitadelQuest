<?php

namespace App\Service;

/**
 * Repairs LLM-generated JSON text before json_decode().
 *
 * Two independent failure modes both surface as "failed to parse response"
 * even though the JSON looks perfectly valid:
 *
 *  1. Raw invalid UTF-8 bytes (e.g. a multibyte character truncated by a
 *     byte-level substr()) -> fixed at decode time with
 *     json_decode(..., JSON_INVALID_UTF8_SUBSTITUTE).
 *
 *  2. Unpaired UTF-16 surrogate escapes (\uD83D or \uDE00 without their
 *     counterpart) -> json_decode() rejects these as "Single unpaired UTF-16
 *     surrogate in unicode escape", and NO json_decode flag can repair it.
 *     We replace the lone escape with U+FFFD before decoding.
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

    public static function sanitize(string $json): string
    {
        return preg_replace(self::UNPAIRED_SURROGATE_PATTERNS, "\u{FFFD}", $json) ?? $json;
    }
}
