<?php

declare(strict_types=1);

// make publish: puts the release that "make release" built on GitHub.
//   1. checks: VERSION is a real version, its signed zip is in dist/, the changelog has its
//      section, everything is committed on main, and GitHub does not have this release yet
//   2. asks for confirmation
//   3. tags vX.Y.Z (if not done yet) and pushes main and the tag to GitHub
//   4. creates the GitHub release with the changelog section and attaches zip and checksum
// From then on installations find the update. The token comes from .env (GITHUB_TOKEN),
// which is never committed. Nothing else in the repository is changed.

$rootPath = dirname(__DIR__);
require $rootPath . '/src/Project.php';

const API = 'https://api.github.com/repos/';

function fail(string $message): never
{
    fwrite(STDERR, "\n✗ " . $message . "\n");
    exit(1);
}

function ask(string $question): string
{
    fwrite(STDOUT, $question);

    return trim((string) fgets(STDIN));
}

/** Runs git in the project folder and returns its output (stops on an error). */
function git(string $rootPath, string $arguments, bool $quiet = false): string
{
    $command = 'git -c safe.directory=' . escapeshellarg($rootPath) . ' -C ' . escapeshellarg($rootPath) . ' ' . $arguments . ($quiet ? ' 2>/dev/null' : '');
    exec($command, $output, $code);

    if ($code !== 0) {
        fail('git failed: git ' . $arguments);
    }

    return trim(implode("\n", $output));
}

/** The token from the environment or from .env (GITHUB_TOKEN=...). */
function token(string $rootPath): string
{
    $token = (string) getenv('GITHUB_TOKEN');

    if ($token === '' && is_file($rootPath . '/.env')) {
        foreach (file($rootPath . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*GITHUB_TOKEN\s*=\s*"?([^"\s]+)"?\s*$/', $line, $match) === 1) {
                $token = $match[1];
            }
        }
    }

    return $token !== '' ? $token : fail('No GitHub token. Put GITHUB_TOKEN=... into .env (see docs/10-arbeitsablauf.md).');
}

/**
 * @param array<string, mixed>|string|null $body JSON data, or raw bytes for an upload
 *
 * @return array{status: int, data: mixed}
 */
function github(string $method, string $url, string $token, array|string|null $body = null, string $type = 'application/json'): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: bioly-publish',
            'Content-Type: ' . $type,
        ],
        CURLOPT_POSTFIELDS => is_array($body) ? json_encode($body) : $body,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        fail('GitHub could not be reached: ' . $error);
    }

    return ['status' => $status, 'data' => json_decode((string) $response, true)];
}

// 1. Checks ------------------------------------------------------------------------------

$repo = Bioly\Project::GITHUB_REPO;
$version = trim((string) file_get_contents($rootPath . '/VERSION'));
$tag = 'v' . $version;

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fail("VERSION is \"{$version}\". Build the release with \"make release\" first.");
}

$zipPath = "{$rootPath}/dist/bioly-{$version}.zip";
$sumPath = $zipPath . '.sha256';

if (!is_file($zipPath) || !is_file($sumPath)) {
    fail("dist/bioly-{$version}.zip is missing. Run \"make release\" first.");
}

$zip = new ZipArchive();

if ($zip->open($zipPath) !== true || trim((string) $zip->getFromName('bioly/VERSION')) !== $version || $zip->locateName('bioly/manifest.sig') === false) {
    fail("dist/bioly-{$version}.zip is not the signed release of {$version}. Run \"make release\" again.");
}

$zip->close();

if (!str_starts_with((string) file_get_contents($sumPath), hash_file('sha256', $zipPath) . ' ')) {
    fail("The checksum in {$sumPath} does not match the zip.");
}

$changelog = (string) file_get_contents($rootPath . '/CHANGELOG.md');

if (preg_match('/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $changelog, $match) !== 1) {
    fail("CHANGELOG.md has no section for {$version}.");
}

$notes = trim($match[1]);

if (git($rootPath, 'rev-parse --abbrev-ref HEAD') !== 'main') {
    fail('Publish from the branch main.');
}

if (git($rootPath, 'status --porcelain') !== '') {
    fail('There are uncommitted changes. Commit VERSION and CHANGELOG.md (and everything else) first.');
}

if (trim(git($rootPath, 'show HEAD:VERSION')) !== $version) {
    fail("The last commit does not contain VERSION {$version}. Commit it first.");
}

$localTag = git($rootPath, 'tag --list ' . escapeshellarg($tag));

if ($localTag !== '' && git($rootPath, 'rev-list -n 1 ' . escapeshellarg($tag)) !== git($rootPath, 'rev-parse HEAD')) {
    fail("The tag {$tag} exists but points to another commit.");
}

$token = token($rootPath);
$existing = github('GET', API . $repo . '/releases/tags/' . $tag, $token);

if ($existing['status'] === 200) {
    fail("{$tag} is already published on GitHub. Nothing to do.");
}

if ($existing['status'] === 401 || $existing['status'] === 403) {
    fail('GitHub refused the token. Check GITHUB_TOKEN in .env (Contents: Read and write for ' . $repo . ').');
}

// 2. Confirm -----------------------------------------------------------------------------

echo "Bioly {$version} is ready:\n";
echo "  - push main and {$tag} to github.com/{$repo}\n";
echo "  - create the release with the changelog and attach bioly-{$version}.zip\n";
echo "  Installations will offer the update right after.\n\n";

if (strtolower(ask("Publish Bioly {$version}? [y/N] ")) !== 'y') {
    fail('Aborted. Nothing was published.');
}

// 3. Tag and push ------------------------------------------------------------------------

if ($localTag === '') {
    git($rootPath, 'tag ' . escapeshellarg($tag));
    echo "→ Tagged {$tag}\n";
}

echo "→ Pushing main and {$tag} to GitHub\n";
// The token goes in a header, so it never appears in an address or in git's output.
$header = 'Authorization: Basic ' . base64_encode('x-access-token:' . $token);
git($rootPath, '-c http.extraHeader=' . escapeshellarg($header) . ' push ' . escapeshellarg('https://github.com/' . $repo . '.git') . ' HEAD:refs/heads/main ' . escapeshellarg('refs/tags/' . $tag));

// 4. Release with files --------------------------------------------------------------------

echo "→ Creating the release\n";
$release = github('POST', API . $repo . '/releases', $token, [
    'tag_name' => $tag,
    'name' => 'Bioly ' . $version,
    'body' => $notes . "\n\n**Install:** download `bioly-{$version}.zip`, see the README. **Update:** in the backend under Updates.",
    'draft' => false,
    'prerelease' => false,
    'make_latest' => 'true',
]);

if ($release['status'] !== 201 || !is_array($release['data'])) {
    fail('GitHub did not create the release (HTTP ' . $release['status'] . '). The tag is pushed; run make publish again.');
}

$uploadUrl = preg_replace('/\{.*$/', '', (string) $release['data']['upload_url']);

foreach ([[$zipPath, 'application/zip'], [$sumPath, 'text/plain']] as [$file, $type]) {
    echo '→ Attaching ' . basename($file) . "\n";
    $upload = github('POST', $uploadUrl . '?name=' . rawurlencode(basename($file)), $token, (string) file_get_contents($file), $type);

    if ($upload['status'] !== 201) {
        fail('Uploading ' . basename($file) . ' failed (HTTP ' . $upload['status'] . '). Attach it by hand on the release page.');
    }
}

echo "\n✓ Bioly {$version} is published: " . $release['data']['html_url'] . "\n";
echo "  Also push the tag to Forgejo: git push origin {$tag}\n";
