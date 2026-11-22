<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Bioly\Service\MediaUsage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Media library: images (alt text) and own icons (name, color).
 * Changes apply right away; files that are in use cannot be deleted.
 */
final class MediaController extends Controller
{
    private const MAX_FILES = 20;

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tab = ($request->getQueryParams()['tab'] ?? '') === 'icons' ? 'icons' : 'images';
        $media = $this->services->media();
        $usage = $this->usage();
        $rows = $media->library($tab === 'icons' ? 'icon' : 'content');
        $icons = $this->services->icons();

        $items = array_map(function (array $row) use ($usage, $icons, $tab): array {
            $uses = array_map(fn(array $use): string => $this->t($use['key'], ['n' => $use['count']]), $usage->of($row));

            return [
                ...$row,
                'url' => '/media/' . $row['filename'],
                'uses' => $uses,
                'svg' => $tab === 'icons' ? $icons->html(MediaUsage::iconId((string) $row['filename'])) : '',
            ];
        }, $rows);

        return $this->render($response, 'backend/media.twig', [
            'tab' => $tab,
            'media_items' => $items,
            'palette' => $icons->palette(),
            'max_mb' => round($this->services->settings['upload_max_bytes'] / 1048576, 1),
        ]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tab = $this->input($request, 'tab') === 'icons' ? 'icons' : 'images';
        $files = $request->getUploadedFiles()['files'] ?? [];
        $files = array_slice(array_filter(is_array($files) ? $files : [$files], static fn($f): bool => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE), 0, self::MAX_FILES);
        $color = $this->color($this->input($request, 'color'));
        $session = $this->services->session();
        $done = 0;

        foreach ($files as $file) {
            $result = $tab === 'icons'
                ? $this->services->media()->storeIcon($file, $color)
                : $this->services->media()->storeImage($file);

            if (isset($result['error'])) {
                $session->flash('error', ($file->getClientFilename() ?? '?') . ': ' . $this->t($result['error']));
            } else {
                $done++;
            }
        }

        if ($files === []) {
            $session->flash('error', $this->t('media.upload.none'));
        } elseif ($done > 0) {
            $session->flash('success', $this->t('media.upload.done', ['n' => $done]));
        }

        return $this->redirect($response, '/admin/media?tab=' . $tab);
    }

    /** @param array<string, string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $repository = $this->services->mediaRepository();
        $row = $repository->find((int) $args['id']);

        if ($row === null) {
            return $this->redirect($response, '/admin/media');
        }

        $label = mb_substr($this->input($request, 'label'), 0, 60);

        if ($row['usage'] === 'icon') {
            $repository->change((int) $row['id'], [
                'label' => $label !== '' ? $label : 'Icon',
                'color' => $this->color($this->input($request, 'color')),
            ]);
            $this->services->icons()->forgetOwn();
        } else {
            $repository->change((int) $row['id'], [
                'label' => $label,
                'alt_text' => mb_substr($this->input($request, 'alt_text'), 0, 250),
            ]);
        }

        // The page shows alt texts and icon colors, so it is rebuilt right away.
        $this->services->publisher()->publish();
        $this->services->session()->flash('success', $this->t('media.saved', ['name' => $label !== '' ? $label : $row['filename']]));

        return $this->redirect($response, '/admin/media?tab=' . ($row['usage'] === 'icon' ? 'icons' : 'images') . '#m' . $row['id']);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->services->mediaRepository()->find((int) $args['id']);
        $session = $this->services->session();

        if ($row === null) {
            return $this->redirect($response, '/admin/media');
        }

        $tab = $row['usage'] === 'icon' ? 'icons' : 'images';
        $uses = $this->usage()->of($row);

        if ($uses !== []) {
            $session->flash('error', $this->t('media.delete.in_use', [
                'uses' => implode(', ', array_map(fn(array $use): string => $this->t($use['key'], ['n' => $use['count']]), $uses)),
            ]));

            return $this->redirect($response, '/admin/media?tab=' . $tab . '#m' . $row['id']);
        }

        $this->services->media()->delete((int) $row['id']);
        $this->services->icons()->forgetOwn();
        $session->flash('success', $this->t('media.deleted'));

        return $this->redirect($response, '/admin/media?tab=' . $tab);
    }

    /** Palette color name, or null for "keep own colors". */
    private function color(string $value): ?string
    {
        return isset($this->services->icons()->palette()[$value]) ? $value : null;
    }

    private function usage(): MediaUsage
    {
        return new MediaUsage($this->services->blocks(), $this->services->settingsRepository());
    }
}
