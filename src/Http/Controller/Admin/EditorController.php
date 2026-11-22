<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Bioly\Module\AbstractModule;
use Bioly\Module\Validator;
use Bioly\Page\Draft;
use Bioly\Page\Quicklinks;
use Bioly\Service\MediaService;
use Bioly\Service\MediaUsage;
use Bioly\Theme\Theme;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * The page editor. The draft lives in the browser; the preview gets a copy
 * in the session; only "publish" writes to the database.
 */
/**
 * @phpstan-import-type DraftData from Draft
 */
final class EditorController extends Controller
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->services->session()->remove('draft');

        return $this->render($response, 'backend/editor.twig', ['mode' => 'page']);
    }

    /** "Allgemein": design and favicon, same draft and publish as the page editor. */
    public function general(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->services->session()->remove('draft');

        return $this->render($response, 'backend/editor.twig', ['mode' => 'general']);
    }

    /**
     * Stores an uploaded image or own icon in the media library right away.
     * It only shows up on the page after publishing, because the draft just holds the file name.
      *
      * @param array<string, string> $args
     */
    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return $this->jsonError($response, 'upload', 422, ['message' => $this->t('upload.failed')]);
        }

        $media = $this->services->media();
        $color = $this->input($request, 'color');
        $result = match ($args['kind']) {
            'image' => $media->storeImage($file),
            default => $media->storeIcon($file, isset($this->services->icons()->palette()[$color]) ? $color : null),
        };

        if (isset($result['error'])) {
            return $this->jsonError($response, 'upload', 422, ['message' => $this->t($result['error'])]);
        }

        $answer = ['file' => $result['file'], 'url' => '/media/' . $result['file']];

        if ($args['kind'] === 'icon') {
            $this->services->icons()->forgetOwn();
            $answer['icon'] = MediaUsage::iconId($result['file']);
            $answer['icons'] = $this->services->icons()->listing($this->services->translator());
        }

        if ($args['kind'] === 'image') {
            $answer['images'] = $this->libraryImages();
        }

        return $this->json($response, $answer);
    }

    public function state(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->currentState());
    }

    public function storePreview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $draft = $this->services->draft()->sanitize($this->body($request));
        $this->services->session()->set('draft', $draft);

        return $this->json($response, [
            'warnings' => $this->services->theme()->warnings($draft['theme']),
            'look' => $this->services->theme()->iconLook($draft['theme']),
        ]);
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $draft = $this->draft();
        $html = $this->services->publisher()->render($draft['profile'], $draft['theme'], $draft['blocks'], [
            'theme_url' => '/admin/preview/theme.css?t=' . microtime(true),
            'multilink_endpoint' => '/admin/preview/multilink/',
        ], true);

        return $this->text($response, $html, 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public function previewTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $css = $this->services->theme()->css($this->draft()['theme'], '/assets/patterns');

        return $this->text($response, $css, 'text/css; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $args */
    public function previewMultilink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $draft = $this->draft();

        foreach ($draft['blocks'] as $block) {
            if ($block['key'] === $args['key'] && $block['type'] === 'multilink') {
                return $this->multilinkAnswer($request, $response, $block['data'], $block['children'], $draft['theme']['icon_style']);
            }
        }

        throw new HttpNotFoundException($request);
    }

    public function publish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $draft = $this->services->draft()->sanitize($this->body($request));
        $errors = $this->services->draft()->validate($draft);

        if ($errors !== []) {
            return $this->jsonError($response, 'invalid', 422, ['fields' => $errors]);
        }

        $pdo = $this->services->pdo();
        $settings = $this->services->settingsRepository();
        $pdo->beginTransaction();

        try {
            $this->services->blocks()->replaceAll($draft['blocks']);
            $settings->merge('site', [
                'name' => $draft['profile']['name'],
                'bio' => $draft['profile']['bio'],
                'avatar' => $draft['profile']['avatar'],
                // Only complete quicklinks go live; unfinished rows stay in the draft.
                'quicklinks' => Quicklinks::clean($draft['profile']['quicklinks'], $this->services->icons(), true),
            ]);
            $settings->set('theme', $draft['theme']);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->services->publisher()->publish();
        $this->services->session()->remove('draft');

        return $this->json($response, ['state' => $this->currentState()]);
    }

    /** @return array<string, mixed> everything the editor needs */
    private function currentState(): array
    {
        $translator = $this->services->translator();
        $settings = $this->services->settingsRepository();
        $site = $settings->getArray('site');
        $theme = $this->services->publisher()->currentTheme();
        $modules = [];

        foreach ($this->services->modules()->all() as $type => $module) {
            $modules[$type] = $this->describe($module);
        }

        return [
            'modules' => $modules,
            'icons' => $this->services->icons()->listing($translator),
            'palette' => $this->services->icons()->palette(),
            'patterns' => $this->services->patterns(),
            // Steps of the pattern settings, so the tiles in the editor look like the page.
            'patternLevels' => ['strength' => Theme::PATTERN_STRENGTH, 'size' => Theme::PATTERN_SIZE, 'fade' => Theme::PATTERN_FADE],
            'profile' => [
                'name' => (string) ($site['name'] ?? ''),
                'bio' => (string) ($site['bio'] ?? ''),
                'avatar' => $this->services->media()->exists((string) ($site['avatar'] ?? '')) ? (string) $site['avatar'] : '',
                'quicklinks' => Quicklinks::clean($site['quicklinks'] ?? [], $this->services->icons(), false),
            ],
            'images' => $this->libraryImages(),
            'uploadLimits' => ['image' => (int) $this->services->settings['upload_max_bytes'], 'svg' => MediaService::SVG_MAX_BYTES],
            // Lengths and counts the server checks, so the form uses the same numbers.
            'limits' => [
                'name' => Draft::NAME_MAX,
                'bio' => Draft::BIO_MAX,
                'quicklinks' => Quicklinks::MAX,
                'quicklinkLabel' => Quicklinks::LABEL_MAX,
                'url' => Validator::MAX_URL,
            ],
            'theme' => $theme,
            'defaultTheme' => $this->services->theme()->normalize(Theme::DEFAULTS),
            'warnings' => $this->services->theme()->warnings($theme),
            'look' => $this->services->theme()->iconLook($theme),
            'blocks' => $this->withKeys($this->services->blocks()->tree()),
            'texts' => $translator->all(['editor.', 'validation.', 'module.', 'design.', 'common.', 'upload.', 'favicon.', 'general.', 'media.', 'color.']),
        ];
    }

    /**
     * Library images for the header image chooser.
     *
     * @return list<array{file: string, url: string, label: string, alt: string, color: string, transparent: bool, width: int, height: int}>
     */
    private function libraryImages(): array
    {
        return array_map(static fn(array $row): array => [
            'file' => (string) $row['filename'],
            'url' => '/media/' . $row['filename'],
            'label' => (string) ($row['label'] ?? ''),
            'alt' => (string) ($row['alt_text'] ?? ''),
            'color' => (string) ($row['color'] ?? ''),
            'transparent' => (bool) ($row['has_alpha'] ?? false),
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
        ], $this->services->media()->library('content'));
    }

    /** @return array<string, mixed> */
    private function describe(AbstractModule $module): array
    {
        $t = $this->services->translator();
        $type = $module->type();
        $fields = array_map(static function (array $field) use ($t, $type): array {
            $help = 'module.' . $type . '.' . $field['name'] . '.help';

            $prefix = 'module.' . $type . '.' . $field['name'];
            $described = [...$field, 'label' => $t->translate($prefix), 'help' => $t->has($help) ? $t->translate($help) : ''];

            // Own option labels for selects other than the icon style.
            if ($field['type'] === 'select' && $field['name'] !== 'icon_style') {
                $options = $field['options'] ?? [];
                $described['optionLabels'] = array_combine($options, array_map(static fn(string $o): string => $t->translate($prefix . '.' . $o), $options));
            }

            return $described;
        }, $module->fields());

        return [
            'type' => $type,
            'name' => $t->translate('module.' . $type . '.name'),
            'description' => $t->translate('module.' . $type . '.description'),
            'icon' => $this->services->modules()->iconSvg($type),
            'fields' => $fields,
            'defaults' => $module->defaults(),
            'tile' => $module->isTile(),
            'acceptsChildren' => $module->acceptsChildren(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function withKeys(array $blocks): array
    {
        return array_map(fn(array $block): array => [
            'key' => 'b' . $block['id'],
            'type' => $block['type'],
            'is_active' => $block['is_active'],
            'data' => $block['data'],
            'children' => $this->withKeys($block['children']),
        ], $blocks);
    }

    /** @return DraftData */
    private function draft(): array
    {
        $draft = $this->services->session()->get('draft');

        // Stored by storePreview() after sanitize(); sanitizing again keeps the shape guaranteed.
        if (is_array($draft)) {
            return $this->services->draft()->sanitize($draft);
        }

        $state = $this->currentState();

        return ['profile' => $state['profile'], 'theme' => $state['theme'], 'blocks' => $state['blocks']];
    }
}
