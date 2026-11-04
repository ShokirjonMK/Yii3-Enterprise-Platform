<?php

declare(strict_types=1);

/**
 * Rendered without session (fallback handler) — keep self-contained.
 *
 * @var string $correlationId
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Not found</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/static/error.css">
</head>
<body>
<main class="err">
    <h1>404</h1>
    <p>The page you are looking for does not exist. · Sahifa topilmadi. · Страница не найдена.</p>
    <p class="ref">Ref: <?= htmlspecialchars($correlationId, ENT_QUOTES) ?></p>
    <p><a href="/">← Home</a></p>
</main>
</body>
</html>
