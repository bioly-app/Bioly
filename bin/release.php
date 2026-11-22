<?php

declare(strict_types=1);

// Builds a release zip.
//   make release   signed release: asks for a higher version, confirms, then sets VERSION and CHANGELOG
//                  (needs the private key from make keys)
//   make zip       test zip (--test): no signature, no questions, VERSION and CHANGELOG stay as they are.
//                  Its version is the next patch with "-dev" and a time stamp, e.g. 0.1.6-dev.202611212330,
//                  so it counts as newer than the installed version but older than the real 0.1.6.

$rootPath = dirname(__DIR__);
$versionFile = $rootPath . '/VERSION';

// Paths (relative to the project root) that never go into a release.
const EXCLUDE = [
    '.git', '.github', '.forgejo', '.idea', '.vscode', '.phpunit.cache',
    'dist', 'docker', 'docs', '.intern', 'tests', 'vendor', 'node_modules',
    'Dockerfile', 'docker-compose.yml', 'Makefile',
    'phpunit.xml', 'phpstan.neon', '.php-cs-fixer.dist.php', '.php-cs-fixer.cache',
    '.editorconfig', '.gitignore', '.gitattributes', '.env',
    'CONTRIBUTING.md', 'bin/release.php', 'bin/seed.php', 'bin/keys.php', 'bin/import-brand-icons.php',
];

// Inside data/ only the .htaccess is shipped: it keeps the folder in the zip and blocks web access to it.
const DATA_KEEP = ['data/.htaccess'];

function fail(string $message): never
{
    fwrite(STDERR, "\n✗ " . $message . "\n");
    exit(1);
}

/**
 * Turns the entries under "## [Unreleased]" into a section for the new version.
 * Returns null when there is nothing under "## [Unreleased]".
 */
function releaseChangelog(string $changelog, string $version, string $date): ?string
{
    if (preg_match('/^## \[Unreleased\]\n(.*?)(?=^## \[|\z)/ms', $changelog, $match) !== 1 || trim($match[1]) === '') {
        return null;
    }

    return str_replace('## [Unreleased]', "## [Unreleased]\n\n## [{$version}] - {$date}", $changelog);
}

function ask(string $question): string
{
    fwrite(STDOUT, $question);

    return trim((string) fgets(STDIN));
}

function isExcluded(string $relativePath): bool
{
    if (str_starts_with($relativePath, 'data/')) {
        return !in_array($relativePath, DATA_KEEP, true);
    }

    foreach (EXCLUDE as $excluded) {
        if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
            return true;
        }
    }

    return false;
}

function copyProject(string $from, string $to): void
{
    $directory = new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS);

    // Skip excluded paths early, so folders like vendor/ or .git/ are never walked.
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static fn(SplFileInfo $file): bool => !isExcluded(substr($file->getPathname(), strlen($from) + 1)),
    );

    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target = $to . '/' . substr($file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($target, 0775, true) : copy($file->getPathname(), $target);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}

/** Version, minimum PHP and the SHA-256 of every file; this is what gets signed. */
function manifest(string $path, string $version): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($path) + 1)] = hash_file('sha256', $file->getPathname());
    }

    ksort($files);

    return json_encode(['name' => 'bioly', 'version' => $version, 'php' => '8.3.0', 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function zipDirectory(string $source, string $zipPath, string $prefix): void
{
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('Could not create ' . $zipPath);
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($files as $file) {
        $name = $prefix . '/' . substr($file->getPathname(), strlen($source) + 1);
        $file->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($file->getPathname(), $name);
    }

    $zip->close();
}

$test = in_array('--test', $argv, true);
$current = trim((string) file_get_contents($versionFile));
$changelogFile = $rootPath . '/CHANGELOG.md';

if ($test) {
    [$major, $minor, $patch] = array_map('intval', explode('.', $current) + [0, 0, 0]);
    $new = sprintf('%d.%d.%d-dev.%s', $major, $minor, $patch + 1, date('YmdHi'));
    $released = (string) file_get_contents($changelogFile);
    $secret = null;
    echo "Bioly test zip {$new} (not signed, only for testing)\n";
} else {
    [$new, $secret, $released] = askRelease($rootPath, $current, $changelogFile);
}

/** @return array{string, string, string} new version, secret key, changelog with the new section */
function askRelease(string $rootPath, string $current, string $changelogFile): array
{
    // 0. The signing key must exist and match the public key in the code
    $keyFile = (getenv('HOME') ?: '/root') . '/.bioly/signing.key';
    $secret = is_file($keyFile) ? base64_decode(trim((string) file_get_contents($keyFile)), true) : false;
    require $rootPath . '/src/Update/PublicKey.php';

    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('No signing key found. Run "make keys" once before the first release.');
    }

    if (Bioly\Update\PublicKey::VALUE !== base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))) {
        fail('The signing key does not match src/Update/PublicKey.php.');
    }

    // 1. Ask for the new version
    echo "Bioly release\n";
    echo "Current version: {$current}\n";

    $new = ask('New version: ');

    if (preg_match('/^\d+\.\d+\.\d+$/', $new) !== 1) {
        fail('Use the format MAJOR.MINOR.PATCH, e.g. 1.2.0');
    }

    if (version_compare($new, $current, '<=')) {
        fail("The new version must be higher than {$current}.");
    }

    $released = releaseChangelog((string) file_get_contents($changelogFile), $new, date('Y-m-d'));

    if ($released === null) {
        fail('CHANGELOG.md has no entries under "## [Unreleased]". Write down what changed first.');
    }

    // 2. Confirm
    if (strtolower(ask("Build Bioly {$new}? [y/N] ")) !== 'y') {
        fail('Aborted. Nothing was changed.');
    }

    return [$new, $secret, $released];
}

// 3. Build in a temporary folder
$buildRoot = sys_get_temp_dir() . '/bioly-build-' . bin2hex(random_bytes(4));
$buildPath = $buildRoot . '/bioly';
mkdir($buildPath, 0775, true);

try {
    echo "\n→ Copying files\n";
    copyProject($rootPath, $buildPath);
    file_put_contents($buildPath . '/VERSION', $new . "\n");
    file_put_contents($buildPath . '/CHANGELOG.md', $released);

    echo "→ Installing production dependencies\n";
    passthru(
        'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir='
        . escapeshellarg($buildPath),
        $exitCode,
    );

    if ($exitCode !== 0) {
        fail('composer install failed.');
    }

    $manifest = manifest($buildPath, $new);
    file_put_contents($buildPath . '/manifest.json', $manifest);

    if ($secret !== null) {
        echo "→ Signing\n";
        file_put_contents($buildPath . '/manifest.sig', base64_encode(sodium_crypto_sign_detached($manifest, $secret)) . "\n");
    }

    echo "→ Creating zip\n";
    $distPath = $rootPath . '/dist';
    @mkdir($distPath, 0775, true);
    $zipPath = "{$distPath}/bioly-{$new}.zip";
    zipDirectory($buildPath, $zipPath, 'bioly');
    file_put_contents($zipPath . '.sha256', hash_file('sha256', $zipPath) . '  ' . basename($zipPath) . "\n");
} finally {
    removeDirectory($buildRoot);
}

if ($test) {
    echo "\n✓ Built dist/bioly-{$new}.zip (test, not signed)\n";
    echo "  Try it as an update with: make testsite\n";
    exit(0);
}

// 4. Only after a successful build: update VERSION and CHANGELOG
file_put_contents($versionFile, $new . "\n");

file_put_contents($changelogFile, $released);

echo "\n✓ Built dist/bioly-{$new}.zip (signed)\n";
echo "  VERSION and CHANGELOG.md were updated.\n";
echo "  Next: check the changelog, then commit and tag v{$new} yourself.\n";
