<?php
/**
 * API: the signed-in PORTAL user's notifications - the portal's bell
 * (discussion #62: a manager hears about a new ticket from their team).
 *
 *   GET  ?action=list[&count_only=1]          same shape as api/notifications/get_notifications.php
 *   POST ?action=mark_read  {ids:[..]}|{all}   same shape as api/notifications/mark_read.php
 *   POST ?action=clear      {ids:[..]}|{all, include_unread}   same as api/notifications/clear.php
 *
 * The same shapes on purpose: the bell (assets/js/notification-bell.js) is one
 * script for both, told only which URLs to call. And the same service,
 * NotificationsService, handed a portal user rather than an analyst - so the
 * coalescing, the "unread survives Clear all" catch and the scoping (somebody
 * else's ids simply match nothing) are the analyst bell's, not a copy of them.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/notifications.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn   = connectToDatabase();
    $me     = NotificationsService::portalUser((int)$_SESSION['ss_user_id']);
    $action = (string)($_GET['action'] ?? 'list');

    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $unread = NotificationsService::unreadCount($conn, $me);
        if (!empty($_GET['count_only'])) {
            echo json_encode(['success' => true, 'unread' => $unread]);
            exit;
        }
        echo json_encode(['success' => true, 'unread' => $unread, 'notifications' => NotificationsService::listFor($conn, $me)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'mark_read') {
        if (!empty($in['all']))                                  $n = NotificationsService::markAllRead($conn, $me);
        elseif (isset($in['ids']) && is_array($in['ids']))       $n = NotificationsService::markRead($conn, $me, $in['ids']);
        else throw new Exception("Either 'ids' or 'all' is required.");
        echo json_encode(['success' => true, 'marked' => $n, 'unread' => NotificationsService::unreadCount($conn, $me)]);
        exit;
    }

    if ($action === 'clear') {
        if (!empty($in['all']))                                  $n = NotificationsService::clearAll($conn, $me, !empty($in['include_unread']));
        elseif (isset($in['ids']) && is_array($in['ids']))       $n = NotificationsService::clear($conn, $me, $in['ids']);
        else throw new Exception("Either 'ids' or 'all' is required.");
        echo json_encode(['success' => true, 'cleared' => $n, 'unread' => NotificationsService::unreadCount($conn, $me)]);
        exit;
    }

    throw new Exception('Unknown action');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
