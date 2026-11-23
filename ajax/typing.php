<?php

/**
 * Typing Indicator plugin for GLPI - AJAX endpoint.
 *
 * GET  ?action=poll&itemtype=Ticket&items_id=12
 * POST action=ping&itemtype=Ticket&items_id=12&kind=followup&is_private=0
 * POST action=stop&itemtype=Ticket&items_id=12
 *
 * Security:
 *  - login required; item must be readable by the current user (GLPI ACL)
 *  - itemtype / kind are whitelisted, ids are cast to int
 *  - state-changing calls are POST only and CSRF-checked by GLPI core
 *    (GLPI 10/11: X-Glpi-Csrf-Token header; GLPI 12+: Sec-Fetch-Site/Origin headers)
 *  - a user can only write/delete their own typing status
 *  - responses are JSON with nosniff and HTML-significant chars escaped
 *
 * @license GPLv3+
 */

use GlpiPlugin\Typingindicator\Settings;
use GlpiPlugin\Typingindicator\Typing;

// GLPI 11+ boots the kernel before legacy scripts; GLPI 10 needs includes.php.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
Html::header_nocache();

Session::checkLoginUser();

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode(
        $payload,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
};

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'POST') {
    $respond(['error' => 'Method not allowed'], 405);
    return;
}

// Read parameters only from the channel matching the method.
$params   = $method === 'POST' ? $_POST : $_GET;
$action   = (string) ($params['action'] ?? 'poll');
$itemtype = (string) ($params['itemtype'] ?? '');
$items_id = (int) ($params['items_id'] ?? 0);

if (!in_array($action, ['poll', 'ping', 'stop'], true)) {
    $respond(['error' => 'Unknown action'], 400);
    return;
}
if ($action === 'poll' && $method !== 'GET') {
    $respond(['error' => 'GET required'], 405);
    return;
}
if ($action !== 'poll' && $method !== 'POST') {
    $respond(['error' => 'POST required'], 405);
    return;
}
if (!Typing::isAllowedItemtype($itemtype) || $items_id <= 0) {
    $respond(['error' => 'Invalid item'], 400);
    return;
}

$users_id      = (int) Session::getLoginUserID();
$can_see       = Settings::canSee();
$can_broadcast = Settings::canBroadcast();

// "stop" is always allowed: it only deletes the caller's own status.
if ($action !== 'stop' && !$can_see && !$can_broadcast) {
    $respond(['error' => 'Access denied'], 403);
    return;
}

$item = getItemForItemtype($itemtype);
if (!$item || !$item->can($items_id, READ)) {
    $respond(['error' => 'Access denied'], 403);
    return;
}

// What the viewer is allowed to know about, per kind of content.
$visibility = [
    'followup' => [
        'public'  => Session::haveRight('followup', ITILFollowup::SEEPUBLIC),
        'private' => Session::haveRight('followup', ITILFollowup::SEEPRIVATE),
    ],
    'task' => [
        'public'  => Session::haveRight('task', CommonITILTask::SEEPUBLIC),
        'private' => Session::haveRight('task', CommonITILTask::SEEPRIVATE),
    ],
    'solution' => [
        'public'  => true,  // solutions are visible to anyone who can read the item
        'private' => false,
    ],
];

// Everything needed from the session is read; release the lock so these
// frequent requests never block other tabs of the same user.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

switch ($action) {
    case 'ping':
        if (!$can_broadcast) {
            $respond(['error' => 'Access denied'], 403);
            return;
        }
        Typing::ping(
            $itemtype,
            $items_id,
            $users_id,
            Typing::normalizeKind((string) ($params['kind'] ?? 'followup')),
            !empty($params['is_private'])
        );
        $respond(['ok' => true]);
        return;

    case 'stop':
        Typing::stop($itemtype, $items_id, $users_id);
        $respond(['ok' => true]);
        return;

    case 'poll':
    default:
        $typers = $can_see
            ? Typing::getTypers($itemtype, $items_id, $users_id, $visibility)
            : [];

        $respond([
            'can_see'       => $can_see,
            'can_broadcast' => $can_broadcast,
            'interval'      => Typing::POLL_INTERVAL,
            // Only names are exposed: no user ids, logins or emails.
            'typers'        => array_map(static function (array $t): array {
                return ['name' => $t['name'], 'kind' => $t['kind']];
            }, $typers),
            'message'       => Typing::buildMessage($typers),
        ]);
        return;
}
