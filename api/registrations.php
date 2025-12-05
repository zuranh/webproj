<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function json_response(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    $db = require __DIR__ . '/db.php';

    // Ensure required tables/columns exist for older databases
    $db->exec('CREATE TABLE IF NOT EXISTS `registrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `event_id` INT NOT NULL,
        `status` ENUM("registered","cancelled") DEFAULT "registered",
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_registration` (`user_id`, `event_id`),
        INDEX `idx_reg_user` (`user_id`),
        INDEX `idx_reg_event` (`event_id`),
        CONSTRAINT `fk_reg_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reg_event` FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

    // Backfill event capacity columns when missing (older schemas)
    $columnsStmt = $db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "events"');
    $columnsStmt->execute();
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasCapacity = in_array('capacity', $columns, true);
    $hasAvailable = in_array('available_spots', $columns, true);

    if (!$hasCapacity) {
        $db->exec('ALTER TABLE `events` ADD COLUMN `capacity` INT DEFAULT 0');
    }

    if (!$hasAvailable) {
        $db->exec('ALTER TABLE `events` ADD COLUMN `available_spots` INT DEFAULT 0');
    }

    if (!$hasAvailable) {
        // Initialize available_spots for existing rows where capacity is set
        $db->exec('UPDATE `events` SET `available_spots` = COALESCE(`capacity`, 0) WHERE `available_spots` IS NULL');
    }
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $firebaseUid = $headers['X-Firebase-UID'] ?? ($headers['x-firebase-uid'] ?? null);

    if (!$firebaseUid) {
        json_response(401, ['success' => false, 'error' => 'Missing X-Firebase-UID header']);
    }

    $userStmt = $db->prepare('SELECT id, name, role, is_active FROM users WHERE firebase_uid = :uid');
    $userStmt->execute([':uid' => $firebaseUid]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_response(404, ['success' => false, 'error' => 'User not found']);
    }

    if ((int)$user['is_active'] === 0) {
        json_response(403, ['success' => false, 'error' => 'Account is inactive']);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : null;

        if ($eventId) {
            $check = $db->prepare('SELECT id, status, created_at FROM registrations WHERE user_id = :uid AND event_id = :event_id');
            $check->execute([':uid' => $user['id'], ':event_id' => $eventId]);
            $registration = $check->fetch(PDO::FETCH_ASSOC);

            json_response(200, [
                'success' => true,
                'registered' => (bool) $registration,
                'registration' => $registration ?: null,
            ]);
        }

        $list = $db->prepare('SELECT r.*, e.name AS event_name, e.date AS event_date, e.location AS event_location FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = :uid ORDER BY r.created_at DESC');
        $list->execute([':uid' => $user['id']]);
        $registrations = $list->fetchAll(PDO::FETCH_ASSOC);

        json_response(200, ['success' => true, 'registrations' => $registrations]);
    }

    if ($method !== 'POST') {
        json_response(405, ['success' => false, 'error' => 'Method not allowed']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response(400, ['success' => false, 'error' => 'Invalid JSON body']);
    }

    $eventId = isset($data['event_id']) ? (int) $data['event_id'] : 0;
    if ($eventId <= 0) {
        json_response(422, ['success' => false, 'error' => 'event_id is required']);
    }

    $db->beginTransaction();

    $eventStmt = $db->prepare('SELECT id, name, status, capacity, available_spots FROM events WHERE id = :id FOR UPDATE');
    $eventStmt->execute([':id' => $eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event || $event['status'] !== 'published') {
        $db->rollBack();
        json_response(404, ['success' => false, 'error' => 'Event not found']);
    }

    $existing = $db->prepare('SELECT id, status FROM registrations WHERE user_id = :uid AND event_id = :event_id');
    $existing->execute([':uid' => $user['id'], ':event_id' => $eventId]);
    $registration = $existing->fetch(PDO::FETCH_ASSOC);

    if ($registration) {
        $db->rollBack();
        json_response(200, [
            'success' => true,
            'message' => 'Already registered for this event',
            'registration' => $registration,
        ]);
    }

    $capacity = $event['capacity'] !== null ? (int) $event['capacity'] : 0;
    $spots = $event['available_spots'] !== null ? (int) $event['available_spots'] : $capacity;

    if ($capacity > 0) {
        if ($spots <= 0) {
            $db->rollBack();
            json_response(409, ['success' => false, 'error' => 'Event is full']);
        }
    }

    $insert = $db->prepare('INSERT INTO registrations (user_id, event_id, status) VALUES (:uid, :event_id, "registered")');
    $insert->execute([':uid' => $user['id'], ':event_id' => $eventId]);

    if ($capacity > 0) {
        $update = $db->prepare('UPDATE events SET available_spots = GREATEST(0, available_spots - 1) WHERE id = :id');
        $update->execute([':id' => $eventId]);
    }

    $db->commit();

    json_response(201, [
        'success' => true,
        'message' => 'Registration confirmed',
        'registration' => [
            'user_id' => $user['id'],
            'event_id' => $eventId,
            'status' => 'registered',
        ],
    ]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Registration error: ' . $e->getMessage());
    json_response(500, ['success' => false, 'error' => 'Server error while processing registration']);
}
