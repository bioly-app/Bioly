<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class PageRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function byType(string $type): ?array
    {
        return $this->one('SELECT * FROM pages WHERE type = ?', [$type]);
    }

    public function ensure(string $type, string $slug, string $title): void
    {
        $this->run(
            'INSERT OR IGNORE INTO pages (type, slug, title, updated_at) VALUES (?, ?, ?, ?)',
            [$type, $slug, $title, Clock::now()],
        );
    }

    public function save(string $type, string $markdown, string $html): void
    {
        $this->run(
            'UPDATE pages SET content_md = ?, content_html = ?, updated_at = ? WHERE type = ?',
            [$markdown, $html, Clock::now(), $type],
        );
    }
}
