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
$currentUser = $auth->requireAdmin();

try {
    // Ensure supporting tables exist for legacy databases
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

    $db->exec(
        "CREATE TABLE IF NOT EXISTS `event_genres` (
            `event_id` INT NOT NULL,
            `genre_id` INT NOT NULL,
            PRIMARY KEY (`event_id`, `genre_id`),
            CONSTRAINT `fk_eg_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_eg_genre` FOREIGN KEY (`genre_id`) REFERENCES `genres`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS `admin_actions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `admin_id` INT NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(50),
            `target_id` INT,
            `details` TEXT,
            `ip_address` VARCHAR(45),
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_admin` (`admin_id`),
            INDEX `idx_created` (`created_at`),
            CONSTRAINT `fk_actions_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
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

    $capacityStmt = $db->query(
        "SELECT
            COALESCE(SUM(e.capacity), 0) AS total_capacity,
            COALESCE(SUM(e.available_spots), 0) AS total_available
         FROM events e"
    );
    $capacity = $capacityStmt->fetch();
    $counts['capacity'] = (int) ($capacity['total_capacity'] ?? 0);
    $counts['available'] = (int) ($capacity['total_available'] ?? 0);

    $genreStmt = $db->query(
        "SELECT g.name, g.slug, COUNT(DISTINCT eg.event_id) AS event_count
         FROM genres g
         LEFT JOIN event_genres eg ON eg.genre_id = g.id
         GROUP BY g.id
         HAVING event_count > 0
         ORDER BY event_count DESC, g.name ASC"
    );
    $genreBreakdown = $genreStmt->fetchAll();

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

    $recentActions = [];
    if ($auth->isOwner($currentUser)) {
        $recentActions = $db
            ->query(
                "SELECT a.id, a.action, a.target_type, a.target_id, a.details, a.created_at,
                        admin.name AS admin_name
                 FROM admin_actions a
                 LEFT JOIN users admin ON admin.id = a.admin_id
                 ORDER BY a.created_at DESC
                 LIMIT 5"
            )
            ->fetchAll();
    }

    echo json_encode([
        'success' => true,
        'counts' => $counts,
        'recentRegistrations' => $recentRegistrations,
        'recentEvents' => $recentEvents,
        'genreBreakdown' => $genreBreakdown,
        'recentActions' => $recentActions,
        'role' => $currentUser['role'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('Admin stats error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load dashboard stats']);
}
