<?php

declare(strict_types=1);

/**
 * Sign-in area entry point (listed in config/configuration.php). Routes: src/Web/Auth/routes.php
 * (login, 2FA, SSO, password change, 2FA enrolment, step-up re-authentication, Telegram webhook).
 */
return require dirname(__DIR__, 2) . '/src/Web/Auth/routes.php';
