<?php

declare(strict_types=1);

// Creates the Ed25519 key pair for signing releases. Run once: make keys
// The private key stays on your computer (~/.bioly/signing.key), never in the repo.

$root = dirname(__DIR__);
$home = getenv('HOME') ?: '/root';
$dir = $home . '/.bioly';
$file = $dir . '/signing.key';

if (is_file($file)) {
    fwrite(STDERR, "A key already exists at {$file}. Delete it first if you really want a new one.\n");
    fwrite(STDERR, "Warning: installations only accept updates signed with the key they know.\n");
    exit(1);
}

require $root . '/src/Update/PublicKey.php';

// A new key is almost never right: installations only accept updates signed with the key they know.
// So two confirmations, and a louder warning when the code already has a key.
$ask = static function (string $question): string {
    fwrite(STDOUT, $question);

    return trim((string) fgets(STDIN));
};

echo "This creates the key that signs every Bioly release. Run it only once, ever.\n";

if (Bioly\Update\PublicKey::VALUE !== '') {
    echo "\nWARNING: src/Update/PublicKey.php already holds a key. With a new one, every existing\n";
    echo "installation stops accepting updates. Only go on if the old key is lost or stolen.\n";
}

if ($ask("\nType NEW KEY to go on: ") !== 'NEW KEY' || strtolower($ask('Really create a new signing key? [y/N] ')) !== 'y') {
    fwrite(STDERR, "Aborted. Nothing was changed.\n");
    exit(1);
}

$pair = sodium_crypto_sign_keypair();
@mkdir($dir, 0700, true);
file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
chmod($file, 0600);

$public = base64_encode(sodium_crypto_sign_publickey($pair));
$class = $root . '/src/Update/PublicKey.php';
$code = (string) file_get_contents($class);
file_put_contents($class, (string) preg_replace("/VALUE = '[^']*'/", "VALUE = '{$public}'", $code));

echo "Private key: {$file}\n";
echo "Public key written to src/Update/PublicKey.php. Commit that change.\n";
echo "Back up the private key, e.g. in your password manager.\n";
