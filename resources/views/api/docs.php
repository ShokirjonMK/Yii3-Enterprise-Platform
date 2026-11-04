<?php

declare(strict_types=1);

/**
 * Swagger UI page for REST API v1 (standalone, no layout, no inline script/style — strict CSP).
 *
 * @var string $specUrl
 */
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API v1 — Yii3 Enterprise Platform</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/static/swagger-ui/swagger-ui.css?v=5.17.14">
</head>
<body data-spec-url="<?= $e($specUrl) ?>">
<noscript>Swagger UI requires JavaScript. The raw OpenAPI document is available at <a href="<?= $e($specUrl) ?>"><?= $e($specUrl) ?></a>.</noscript>
<div id="swagger-ui"></div>
<footer class="api-docs-footer"><p>Built by <a href="https://github.com/ShokirjonMK" rel="noopener">ShokirjonMK</a></p></footer>
<script src="/static/swagger-ui/swagger-ui-bundle.js?v=5.17.14"></script>
<script src="/static/api-docs.js?v=1"></script>
</body>
</html>
