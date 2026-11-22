<?php

declare(strict_types=1);

namespace Bioly\Update;

use Bioly\Project;

/**
 * Asks GitHub for the newest published Bioly release. Drafts and pre-releases are never
 * "latest" on GitHub, so they never reach an installation.
 */
final class ReleaseFeed
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * @return array{version: string, notes: string, page: string, zip: string, size: int}|null null: no release yet
     */
    public function latest(): ?array
    {
        $url = 'https://api.github.com/repos/' . Project::GITHUB_REPO . '/releases/latest';

        try {
            $body = $this->http->get($url, ['Accept' => 'application/vnd.github+json'], null, 2 * 1024 * 1024, 10);
        } catch (\RuntimeException $exception) {
            // 404: the repository has no release yet
            if (str_contains($exception->getMessage(), '404')) {
                return null;
            }

            throw new UpdateException('update.error.check', 0, $exception);
        }

        $data = json_decode($body, true);
        $tag = is_array($data) ? (string) ($data['tag_name'] ?? '') : '';

        if (preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $match) !== 1) {
            throw new UpdateException('update.error.check');
        }

        $version = $match[1];
        $zipName = 'bioly-' . $version . '.zip';

        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $zipName && is_string($asset['browser_download_url'] ?? null)) {
                return [
                    'version' => $version,
                    'notes' => is_string($data['body'] ?? null) ? $data['body'] : '',
                    'page' => is_string($data['html_url'] ?? null) ? $data['html_url'] : Project::RELEASES_URL,
                    'zip' => $asset['browser_download_url'],
                    'size' => (int) ($asset['size'] ?? 0),
                ];
            }
        }

        throw new UpdateException('update.error.check');
    }
}
