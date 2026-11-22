<?php

declare(strict_types=1);

namespace Bioly\Support;

use Normalizer;

/**
 * Password rules after NIST SP 800-63B-4 (password as the only factor):
 * at least 15 characters, no rules about character kinds, and nothing easy to guess.
 *
 * "Easy to guess" works offline: common password words, keyboard and number runs,
 * repeated characters and words from the context (username, e-mail, site name) are
 * taken out; what remains must still be long enough. Passphrases pass, "Passwort2024!!!!" does not.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 15;
    public const MAX_LENGTH = 128;

    /** What is left after removing guessable parts must have at least this many characters. */
    private const MIN_REST = 8;

    /** Base words of the most used passwords (German and English), lower case. */
    private const COMMON_WORDS = [
        'passwort', 'password', 'passw0rd', 'kennwort', 'geheim', 'secret', 'letmein', 'welcome', 'willkommen',
        'admin', 'administrator', 'root', 'login', 'master', 'iloveyou', 'ichliebedich', 'liebe', 'love', 'schatz',
        'hallo', 'hello', 'test', 'sommer', 'summer', 'winter', 'fruehling', 'herbst', 'spring', 'autumn', 'fussball',
        'football', 'baseball', 'soccer', 'dragon', 'monkey', 'sunshine', 'princess', 'shadow', 'superman', 'batman',
        'pokemon', 'starwars', 'qwerty', 'qwertz', 'asdf', 'abc', 'default', 'changeme', 'trustno', 'freedom',
        'whatever', 'computer', 'internet', 'samsung', 'apple', 'google', 'facebook', 'instagram', 'tiktok',
        'bayern', 'borussia', 'schalke', 'dortmund', 'deutschland', 'germany', 'berlin', 'hamburg', 'muenchen',
        'januar', 'februar', 'maerz', 'april', 'juni', 'juli', 'august', 'september', 'oktober', 'november',
        'dezember', 'january', 'february', 'march', 'october', 'december', 'montag', 'freitag', 'sonntag',
        'katze', 'hund', 'maus', 'baby', 'familie', 'family', 'mama', 'papa', 'engel', 'angel', 'blume', 'flower',
        'bioly', 'linkpage', 'linktree',
    ];

    /** Runs on the keyboard, in the alphabet and in numbers (both directions are checked). */
    private const RUNS = ['abcdefghijklmnopqrstuvwxyz', '01234567890', 'qwertzuiopü', 'asdfghjklöä', 'yxcvbnm', 'qwertyuiop', 'zxcvbnm'];

    private const LEET = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i'];

    /**
     * Which rules are met.
     *
     * @param list<string> $context words the password must not be made of (username, e-mail, site name)
     * @return array{length: bool, guessable: bool}
     */
    public static function rules(string $password, array $context = []): array
    {
        $length = mb_strlen(self::normalize($password));

        return [
            'length' => $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH,
            'guessable' => !self::isGuessable($password, $context),
        ];
    }

    /** @param list<string> $context */
    public static function isGuessable(string $password, array $context = []): bool
    {
        $plain = mb_strtolower(self::normalize($password));

        if (count(array_unique(mb_str_split($plain))) < 5) {
            return true;
        }

        $rest = self::strip($plain, $context);
        $leet = self::strip(strtr($plain, self::LEET), $context);

        return min(self::letters($rest), self::letters($leet)) < self::MIN_REST;
    }

    private static function normalize(string $password): string
    {
        return class_exists(Normalizer::class) ? (string) Normalizer::normalize($password, Normalizer::FORM_C) : $password;
    }

    /** @param list<string> $context */
    private static function strip(string $value, array $context): string
    {
        $words = array_merge(self::COMMON_WORDS, self::contextWords($context));
        usort($words, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($words as $word) {
            $value = str_replace($word, ' ', $value);
        }

        // Runs of 4 or more along the keyboard, the alphabet or the digits.
        foreach (self::RUNS as $run) {
            foreach ([$run, self::reverse($run)] as $line) {
                for ($size = mb_strlen($line); $size >= 4; $size--) {
                    for ($start = 0; $start + $size <= mb_strlen($line); $start++) {
                        $value = str_replace(mb_substr($line, $start, $size), ' ', $value);
                    }
                }
            }
        }

        // A short part repeated ("abcabcabc", "2024 2024") counts once.
        return (string) preg_replace('/(.{1,6}?)\1+/u', '$1', $value);
    }

    /**
     * @param list<string> $context
     * @return list<string>
     */
    private static function contextWords(array $context): array
    {
        $words = [];

        foreach ($context as $value) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $words[] = $part;
                }
            }
        }

        return $words;
    }

    /** Letters and digits left; spaces and symbols that only fill up do not count. */
    private static function letters(string $value): int
    {
        return mb_strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $value));
    }

    private static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }
}
