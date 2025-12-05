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
