<?php

declare(strict_types=1);

namespace Bioly\Page;

use Bioly\I18n\Translator;
use Bioly\Icon\IconRegistry;
use Bioly\Module\AbstractModule;
use Bioly\Module\ModuleRegistry;
use Bioly\Module\Validator;
use Bioly\Service\MediaService;
use Bioly\Theme\Theme;

/**
 * The editor's draft: untrusted input from the browser. sanitize() brings it into a known
 * shape (for the preview), validate() checks it fully (before publishing).
 *
 * @phpstan-type Child array{key: string, type: string, is_active: bool, data: array<string, mixed>, children: list<mixed>}
 * @phpstan-type Block array{key: string, type: string, is_active: bool, data: array<string, mixed>, children: list<Child>}
 * @phpstan-type DraftData array{profile: array<string, mixed>, theme: array<string, mixed>, blocks: list<Block>}
 */
final class Draft
{
    public const MAX_BLOCKS = 200;
    public const NAME_MAX = 80;
    public const BIO_MAX = 300;

    public function __construct(
        private readonly IconRegistry $icons,
        private readonly MediaService $media,
        private readonly Theme $theme,
        private readonly ModuleRegistry $modules,
        private readonly Validator $validator,
        private readonly Translator $translator,
    ) {}

    /**
     * Brings untrusted editor input into a known shape. Validation happens on publish.
     *
     * @param array<mixed> $input
     * @return DraftData
     */
    public function sanitize(array $input): array
    {
        $profile = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $count = 0;

        return [
            'profile' => [
                'name' => is_string($profile['name'] ?? null) ? trim($profile['name']) : '',
                'bio' => is_string($profile['bio'] ?? null) ? trim($profile['bio']) : '',
                'avatar' => is_string($profile['avatar'] ?? null) && MediaService::validName($profile['avatar'], ['avatar', 'image']) && $this->media->exists($profile['avatar']) ? $profile['avatar'] : '',
                'quicklinks' => Quicklinks::clean($profile['quicklinks'] ?? [], $this->icons, false),
            ],
            'theme' => $this->theme->normalize(is_array($input['theme'] ?? null) ? $input['theme'] : []),
            'blocks' => $this->sanitizeBlocks($input['blocks'] ?? [], 0, $count),
        ];
    }

    /** @return list<Block> */
    private function sanitizeBlocks(mixed $blocks, int $depth, int &$count): array
    {
        $clean = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (!is_array($block) || $depth > 1 || ++$count > self::MAX_BLOCKS) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $clean[] = [
                'key' => is_string($block['key'] ?? null) ? substr($block['key'], 0, 40) : 'n' . $count,
                'type' => is_string($block['type'] ?? null) ? $block['type'] : '',
                'is_active' => !empty($block['is_active']),
                'data' => array_filter($data, static fn($v): bool => is_scalar($v) || $v === null),
                'children' => $this->sanitizeBlocks($block['children'] ?? [], $depth + 1, $count),
            ];
        }

        return $clean;
    }

    /**
     * @param DraftData $draft
     * @return list<array{key: string, field: string, message: string}>
     */
    public function validate(array &$draft): array
    {
        $errors = [];

        if (mb_strlen($draft['profile']['name']) > self::NAME_MAX) {
            $errors[] = ['key' => 'profile', 'field' => 'name', 'message' => $this->translator->translate('validation.max')];
        }

        if (mb_strlen($draft['profile']['bio']) > self::BIO_MAX) {
            $errors[] = ['key' => 'profile', 'field' => 'bio', 'message' => $this->translator->translate('validation.max')];
        }

        $draft['blocks'] = $this->validateBlocks($draft['blocks'], null, $errors);

        return $errors;
    }

    /**
     * @param list<Block>|list<Child> $blocks
     * @param list<array{key: string, field: string, message: string}> $errors
     * @return list<Block>
     */
    private function validateBlocks(array $blocks, ?AbstractModule $parent, array &$errors): array
    {
        $valid = [];

        foreach ($blocks as $block) {
            $module = $this->modules->get($block['type']);

            if ($module === null || ($parent !== null && $module->type() !== 'link')) {
                $errors[] = ['key' => (string) $block['key'], 'field' => '', 'message' => $this->translator->translate('validation.module')];
                continue;
            }

            $result = $this->validator->validate($module, $block['data']);

            foreach ($result['errors'] as $field => $message) {
                $errors[] = ['key' => (string) $block['key'], 'field' => (string) $field, 'message' => $this->translator->translate($message)];
            }

            $block['data'] = $result['data'];
            $block['children'] = $module->acceptsChildren() ? $this->validateBlocks($block['children'], $module, $errors) : [];
            $valid[] = $block;
        }

        return $valid;
    }
}
