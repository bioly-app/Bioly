<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Bioly\Module\Validator;
use Bioly\Service\LegalText;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Imprint and privacy: own text or a link to an external page. Changes go live with "Publish";
 * Bioly ships no templates for them.
 */
final class LegalController extends Controller
{
    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $url = $this->externalUrl($args['type']);

        return $this->page($response, $args['type'], $url !== '' ? 'link' : 'text', $url, null);
    }

    /** Preview for the editor: exactly what the page will show. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $markdown = LegalText::normalize($this->body($request)['content'] ?? '');
        $html = $this->services->legal()->toHtml($markdown);

        return $this->json($response, ['html' => $html !== '' ? $html : '<p>' . htmlspecialchars($this->t('legal.empty')) . '</p>']);
    }

    /**
     * Saves text and choice together; "Publish" in the editor sends both. With an external
     * link the footer and /impressum or /datenschutz point there; the text stays for later.
     *
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $args['type'];
        $markdown = LegalText::normalize($this->body($request)['content'] ?? '');
        $mode = $this->input($request, 'mode') === 'link' ? 'link' : 'text';
        $url = trim($this->input($request, 'url'));

        if ($mode === 'link' && !Validator::isWebUrl($url)) {
            $page = $this->services->pages()->byType($type) ?? [];

            return $this->page($response->withStatus(422), $type, $mode, $url, $this->t('legal.link.invalid'), ['content_md' => $markdown] + $page);
        }

        $this->services->pages()->save($type, $markdown, $this->services->legal()->toHtml($markdown));
        $legal = $this->services->settingsRepository()->getArray('site')['legal'] ?? [];
        $legal = is_array($legal) ? $legal : [];

        if ($mode === 'link') {
            $legal[$type] = $url;
        } else {
            unset($legal[$type]);
        }

        $this->services->settingsRepository()->merge('site', ['legal' => $legal]);
        // The footer of the page links to the legal pages, so the page is rebuilt.
        $this->services->publisher()->publish();
        $this->services->session()->flash('success', $this->t('legal.published'));

        return $this->redirect($response, '/admin/legal/' . $type);
    }

    private function externalUrl(string $type): string
    {
        return LegalText::externalLinks($this->services->settingsRepository()->getArray('site')['legal'] ?? null)[$type] ?? '';
    }

    /** @param array<string, mixed>|null $page */
    private function page(ResponseInterface $response, string $type, string $mode, string $url, ?string $error, ?array $page = null): ResponseInterface
    {
        return $this->render($response, 'backend/legal.twig', [
            'type' => $type,
            'page' => $page ?? $this->services->pages()->byType($type),
            'mode' => $mode,
            'url' => $url,
            'url_error' => $error,
        ]);
    }
}
