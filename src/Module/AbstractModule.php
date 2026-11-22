<?php

declare(strict_types=1);

namespace Bioly\Module;

abstract class AbstractModule implements Module
{
    public function isTile(): bool
    {
        return false;
    }

    public function acceptsChildren(): bool
    {
        return false;
    }

    /**
     * Place in the "Add module" list: lower comes first. Structure modules
     * like the divider sit at the end, content modules before them.
     */
    public function sortOrder(): int
    {
        return 50;
    }

    /**
     * Values a new block starts with.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            $defaults[$field['name']] = $field['default'] ?? ($field['type'] === 'toggle' ? false : '');
        }

        return $defaults;
    }
}
