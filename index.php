<?php

declare(strict_types=1);

// Shown only when the webroot was not moved to /public.
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Bioly: Webroot anpassen</title>
</head>
<body>
    <h1>Fast geschafft</h1>
    <p>Bitte stelle in deinem Hosting den Webroot (Document Root) auf den Ordner <code>/public</code> um.</p>
    <p lang="en">Please set your hosting document root to the <code>/public</code> folder.</p>
</body>
</html>
