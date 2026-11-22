<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Module\Validator;

/**
 * A small, safe Markdown subset for imprint and privacy pages:
 * ## and ### headings, paragraphs, - lists, --- lines, **bold**, *italic*, [links](https://…, mailto:, tel:).
 * Everything is escaped first, so no HTML from the user ever reaches the page.
 * (Planned: replace with league/commonmark together with the EasyMDE editor.)
 */
final class LegalText
{
    public const TYPES = ['imprint', 'privacy'];
    public const MAX_LENGTH = 50_000;

    /**
     * External imprint or privacy pages from the site settings, checked: type => address.
     * A missing type means Bioly's own page.
     *
     * @return array<string, string>
     */
    public static function externalLinks(mixed $legal): array
    {
        $links = [];

        foreach (self::TYPES as $type) {
            $url = is_array($legal) ? ($legal[$type] ?? null) : null;

            if (is_string($url) && Validator::isWebUrl($url)) {
                $links[$type] = $url;
            }
        }

        return $links;
    }

    /** Text from the form: one kind of line break, at most MAX_LENGTH characters. */
    public static function normalize(mixed $markdown): string
    {
        return mb_substr(str_replace("\r\n", "\n", is_string($markdown) ? $markdown : ''), 0, self::MAX_LENGTH);
    }

    public function toHtml(string $markdown): string
    {
        $html = [];
        $paragraph = [];
        $list = [];

        /**
         * @param list<string> $html
         * @param list<string> $paragraph
         * @param list<string> $list
         */
        $flush = static function (array &$html, array &$paragraph, array &$list): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }

            if ($list !== []) {
                $html[] = '<ul>' . implode('', array_map(static fn(string $item): string => "<li>{$item}</li>", $list)) . '</ul>';
                $list = [];
            }
        };

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '') {
                $flush($html, $paragraph, $list);
            } elseif (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m) === 1) {
                $flush($html, $paragraph, $list);
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . $this->inline($m[2]) . "</h{$level}>";
            } elseif (preg_match('/^-{3,}$/', $line) === 1) {
                $flush($html, $paragraph, $list);
                $html[] = '<hr>';
            } elseif (preg_match('/^[-*]\s+(.+)$/', $line, $m) === 1) {
                if ($paragraph !== []) {
                    $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
                    $paragraph = [];
                }
                $list[] = $this->inline($m[1]);
            } else {
                if ($list !== []) {
                    $flush($html, $paragraph, $list);
                }
                $paragraph[] = $this->inline($line);
            }
        }

        $flush($html, $paragraph, $list);

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!\*)\*(?!\*)(.+?)\*/', '<em>$1</em>', $text);

        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|tel:)[^\s)]+)\)/',
            static fn(array $m): string => '<a href="' . $m[2] . '" rel="noopener">' . $m[1] . '</a>',
            $text,
        );
    }
}
