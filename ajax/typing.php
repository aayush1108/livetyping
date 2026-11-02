<?php

declare(strict_types=1);

/**
 * AJAX endpoint of the live typing indicator (JSON only).
 *
 * Security: login required; the plugin must be enabled for the user's active profile; GLPI enforces the
 * CSRF token on POST (X-Glpi-Csrf-Token header); the user must be able to view the ticket; inputs are
 * strictly validated; requests are rate-limited; responses are never cached and never contain user ids.
 *
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Livetyping\Api;
use Symfony\Component\HttpFoundation\JsonResponse;

Session::checkLoginUser();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

[$status, $payload, $headers] = Api::handle(
    $method,
    $method === 'POST' ? ($_POST['ticket'] ?? null) : ($_GET['ticket'] ?? null),
    $method === 'POST' ? ($_POST['action'] ?? null) : null
);

return new JsonResponse($payload, $status, $headers + [
    'Cache-Control'          => 'no-store, no-cache, must-revalidate',
    'X-Content-Type-Options' => 'nosniff',
]);
