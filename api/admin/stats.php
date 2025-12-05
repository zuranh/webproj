<?php
/**
 * Admin Dashboard Stats API
 * GET /api/admin/stats.php
 * Returns aggregate counts and recent registrations for dashboard widgets.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db = require __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($db);
$auth->requireAdmin();

try {
    // Ensure registrations table exists for legacy databases
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `registrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `event_id` INT NOT NULL,
            `status` ENUM('registered','canceled') DEFAULT 'registered',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_event` (`user_id`, `event_id`),
            INDEX `idx_event` (`event_id`),
            INDEX `idx_user` (`user_id`),
            CONSTRAINT `fk_reg_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_reg_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $counts = [
        'events' => (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn(),
        'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'registrations' => (int) $db->query("SELECT COUNT(*) FROM registrations WHERE status = 'registered'")->fetchColumn(),
        'cancellations' => (int) $db->query("SELECT COUNT(*) FROM registrations WHERE status = 'canceled'")->fetchColumn(),
    ];

    $upcomingStmt = $db->query(
        "SELECT COUNT(*) FROM events WHERE (status IS NULL OR status NOT IN ('archived')) AND (date IS NULL OR date >= CURDATE())"
    );
    $counts['upcoming'] = (int) $upcomingStmt->fetchColumn();

    $recentRegistrations = $db
        ->query(
            "SELECT r.id, r.status, r.created_at, e.title AS event_title, u.name AS user_name
             FROM registrations r
             LEFT JOIN events e ON e.id = r.event_id
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.created_at DESC
             LIMIT 8"
        )
        ->fetchAll();

    $recentEvents = $db
        ->query(
            "SELECT e.id, e.title, e.status, e.date, u.name AS creator_name
             FROM events e
             LEFT JOIN users u ON u.id = e.created_by
             ORDER BY e.created_at DESC
             LIMIT 5"
        )
        ->fetchAll();

    echo json_encode([
        'success' => true,
        'counts' => $counts,
        'recentRegistrations' => $recentRegistrations,
        'recentEvents' => $recentEvents,
    ]);
} catch (Throwable $e) {
    error_log('Admin stats error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load dashboard stats']);
}
