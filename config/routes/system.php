<?php

declare(strict_types=1);

/**
 * Operational endpoints entry point (listed in config/configuration.php). Routes: src/Web/System/routes.php
 * (health probes, metrics, theme stylesheets).
 */
return require dirname(__DIR__, 2) . '/src/Web/System/routes.php';
