<?php
/**
 * Admin Actions Log API
 * GET /api/admin/actions.php
 * Returns recent admin actions for auditing.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $db = require __DIR__ . '/../db.php';
    require_once __DIR__ . '/../auth.php';

    $auth = new Auth($db);
    $auth->requireOwner();

    // Ensure admin_actions table exists on legacy DBs
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

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit < 1) {
        $limit = 50;
    } elseif ($limit > 300) {
        $limit = 300;
    }

    $stmt = $db->prepare(
        "SELECT a.id, a.action, a.target_type, a.target_id, a.details, a.ip_address, a.created_at,
                admin.name AS admin_name, admin.email AS admin_email,
                target.name AS target_name, target.email AS target_email
         FROM admin_actions a
         LEFT JOIN users admin ON admin.id = a.admin_id
         LEFT JOIN users target ON target.id = a.target_id
         ORDER BY a.created_at DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'actions' => $actions,
    ]);
} catch (Throwable $e) {
    error_log('Admin actions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load admin actions']);
}
