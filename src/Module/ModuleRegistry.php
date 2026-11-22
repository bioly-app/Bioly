<?php

declare(strict_types=1);

namespace Bioly\Module;

use Bioly\Icon\IconRegistry;

/**
 * Finds modules on its own: every folder src/Modules/<Name>/ with a class
 * Bioly\Modules\<Name>\<Name>Module is a module. Remove the folder, the module is gone.
 */
final class ModuleRegistry
{
    /** @var array<string, AbstractModule>|null */
    private ?array $modules = null;

    /** @var array<string, string> type => folder */
    private array $folders = [];

    public function __construct(private readonly string $modulesPath) {}

    /** @return array<string, AbstractModule> type => module */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $this->modules = [];

        foreach (glob($this->modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $name = basename($folder);
            $class = 'Bioly\\Modules\\' . $name . '\\' . $name . 'Module';

            if (class_exists($class) && is_subclass_of($class, AbstractModule::class)) {
                $module = new $class();
                $this->modules[$module->type()] = $module;
                $this->folders[$module->type()] = $folder;
            }
        }

        // Order of the "Add module" list: by sortOrder(), then by folder name.
        uasort($this->modules, static fn(AbstractModule $a, AbstractModule $b): int => [$a->sortOrder(), $a->type()] <=> [$b->sortOrder(), $b->type()]);

        return $this->modules;
    }

    public function get(string $type): ?AbstractModule
    {
        return $this->all()[$type] ?? null;
    }

    /** @return array<string, string> Twig namespace => folder, e.g. module_link */
    public function templateNamespaces(): array
    {
        $this->all();
        $namespaces = [];

        foreach ($this->folders as $type => $folder) {
            $namespaces['module_' . $type] = $folder;
        }

        return $namespaces;
    }

    /** @return list<string> lang folders of all modules */
    public function langPaths(): array
    {
        $this->all();

        return array_values(array_filter(
            array_map(static fn(string $folder): string => $folder . '/lang', $this->folders),
            'is_dir',
        ));
    }

    public function iconSvg(string $type): string
    {
        $this->all();
        $file = ($this->folders[$type] ?? '') . '/icon.svg';

        return is_file($file) ? (IconRegistry::clean((string) file_get_contents($file)) ?? '') : '';
    }
}
