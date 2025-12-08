<?php
/**
 * Event registrations API
 * GET    /api/registrations.php              -> list current user's registrations
 * GET    /api/registrations.php?event_id=ID  -> status for a specific event
 * POST   /api/registrations.php              -> register for an event {event_id}
 * DELETE /api/registrations.php?event_id=ID  -> cancel a registration
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $db = require __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';

    $auth = new Auth($db);
    $currentUser = $auth->requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Status for a single event
        if (!empty($_GET['event_id'])) {
            $eventId = intval($_GET['event_id']);
            $stmt = $db->prepare("SELECT er.*, e.name AS event_name, e.date, e.time, e.location, e.available_spots, e.capacity, e.status AS event_status
                                   FROM event_registrations er
                                   JOIN events e ON er.event_id = e.id
                                   WHERE er.user_id = :uid AND er.event_id = :event_id
                                   LIMIT 1");
            $stmt->execute([':uid' => $currentUser['id'], ':event_id' => $eventId]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'registered' => (bool) $registration,
                'registration' => $registration
            ]);
            exit;
        }

        // Full list for the user
        $stmt = $db->prepare("SELECT er.*, e.name AS event_name, e.location, e.date, e.time, e.image_url, e.price, e.available_spots, e.capacity, e.status AS event_status
                               FROM event_registrations er
                               JOIN events e ON er.event_id = e.id
                               WHERE er.user_id = :uid
                               ORDER BY e.date ASC, er.created_at DESC");
        $stmt->execute([':uid' => $currentUser['id']]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTime('today');
        foreach ($registrations as &$reg) {
            $eventDate = $reg['date'] ? new DateTime($reg['date']) : null;
            $reg['is_upcoming'] = $eventDate ? $eventDate >= $today : false;
            $reg['can_cancel'] = $reg['status'] === 'registered' && $reg['event_status'] === 'published' && $reg['is_upcoming'];
        }

        echo json_encode([
            'success' => true,
            'registrations' => $registrations,
            'counts' => [
                'total' => count($registrations),
                'upcoming' => count(array_filter($registrations, fn($r) => $r['is_upcoming'] && $r['status'] === 'registered')),
                'past' => count(array_filter($registrations, fn($r) => !$r['is_upcoming'])),
                'cancelled' => count(array_filter($registrations, fn($r) => $r['status'] === 'cancelled')),
            ],
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $eventId = isset($payload['event_id']) ? intval($payload['event_id']) : 0;

        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID is required']);
            exit;
        }

        $db->beginTransaction();

        // Lock the event row to safely update available spots
        $eventStmt = $db->prepare("SELECT id, name, date, time, capacity, available_spots, status FROM events WHERE id = :id FOR UPDATE");
        $eventStmt->execute([':id' => $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            exit;
        }

        if ($event['status'] !== 'published') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Event is not open for registration']);
            exit;
        }

        $hasCapacity = intval($event['capacity']) > 0;
        $spots = intval($event['available_spots']);

        // Check existing registration
        $regStmt = $db->prepare("SELECT id, status FROM event_registrations WHERE user_id = :uid AND event_id = :event_id FOR UPDATE");
        $regStmt->execute([':uid' => $currentUser['id'], ':event_id' => $eventId]);
        $existing = $regStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['status'] === 'registered') {
            $db->rollBack();
            echo json_encode([
                'success' => true,
                'message' => 'You are already registered for this event.',
                'registration_id' => $existing['id'],
                'status' => 'registered',
                'remaining_spots' => $spots,
            ]);
            exit;
        }

        if ($hasCapacity && $spots <= 0) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'This event is sold out.']);
            exit;
        }

        if ($existing) {
            $updateStmt = $db->prepare("UPDATE event_registrations SET status = 'registered', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $updateStmt->execute([':id' => $existing['id']]);
            $registrationId = $existing['id'];
        } else {
            $createStmt = $db->prepare("INSERT INTO event_registrations (user_id, event_id, status) VALUES (:uid, :event_id, 'registered')");
            $createStmt->execute([':uid' => $currentUser['id'], ':event_id' => $eventId]);
            $registrationId = $db->lastInsertId();
        }

        if ($hasCapacity) {
            $updateEvent = $db->prepare("UPDATE events SET available_spots = GREATEST(available_spots - 1, 0) WHERE id = :id");
            $updateEvent->execute([':id' => $eventId]);
            $spots = max(0, $spots - 1);
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Registration confirmed',
            'registration_id' => intval($registrationId),
            'status' => 'registered',
            'event' => $event,
            'remaining_spots' => $spots,
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID is required']);
            exit;
        }

        $db->beginTransaction();

        $regStmt = $db->prepare("SELECT * FROM event_registrations WHERE user_id = :uid AND event_id = :event_id FOR UPDATE");
        $regStmt->execute([':uid' => $currentUser['id'], ':event_id' => $eventId]);
        $registration = $regStmt->fetch(PDO::FETCH_ASSOC);

        if (!$registration) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Registration not found']);
            exit;
        }

        if ($registration['status'] === 'cancelled') {
            $db->rollBack();
            echo json_encode(['success' => true, 'message' => 'Registration already cancelled']);
            exit;
        }

        $eventStmt = $db->prepare("SELECT id, capacity, available_spots, status, date FROM events WHERE id = :id FOR UPDATE");
        $eventStmt->execute([':id' => $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        $hasCapacity = intval($event['capacity']) > 0;
        $spots = intval($event['available_spots']);

        $cancelStmt = $db->prepare("UPDATE event_registrations SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $cancelStmt->execute([':id' => $registration['id']]);

        // Only free up a spot for future events that were actively registered
        if ($hasCapacity && $event['status'] === 'published') {
            $eventDate = $event['date'] ? new DateTime($event['date']) : null;
            $today = new DateTime('today');
            if (!$eventDate || $eventDate >= $today) {
                $updateEvent = $db->prepare("UPDATE events SET available_spots = LEAST(capacity, available_spots + 1) WHERE id = :id");
                $updateEvent->execute([':id' => $eventId]);
                $spots = min($spots + 1, intval($event['capacity']));
            }
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Registration cancelled',
            'status' => 'cancelled',
            'remaining_spots' => $spots,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Registrations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
