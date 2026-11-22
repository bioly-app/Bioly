<?php

declare(strict_types=1);

namespace Bioly\Module;

/**
 * A content module on the link page. Each module lives in its own folder
 * under src/Modules/<Name>/ with its class, template.twig, icon.svg and lang/.
 */
interface Module
{
    /** Stored in blocks.type, e.g. "link". */
    public function type(): string;

    /**
     * Field description for the backend form.
     * Types: text, textarea, url, icon, select, toggle, images, date, time.
     * group: fields with the same group stand together; the form draws a line with the
     * group's name (editor.group.<group>) where a new group starts.
     *
     * @return list<array{name: string, type: string, required?: bool, max?: int, options?: list<string>, default?: mixed, group?: string}>
     */
    public function fields(): array;

    /** Grouped with links into one grid on the page. */
    public function isTile(): bool;

    /** Only the multilink holds child blocks (links). */
    public function acceptsChildren(): bool;

    /**
     * Values for template.twig.
     *
     * @param array<string, mixed> $data validated data
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    public function view(array $data, array $children, RenderContext $context): array;
}
