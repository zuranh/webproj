<?php
/**
 * Admin Events API - manage events with role checks
 * GET    /api/admin/events.php          -> list all events (any status)
 * POST   /api/admin/events.php          -> create event
 * PUT    /api/admin/events.php?id={id}  -> update event
 * DELETE /api/admin/events.php?id={id}  -> delete event
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $db = require __DIR__ . '/../db.php';
    require_once __DIR__ . '/../auth.php';

    $auth = new Auth($db);
    $currentUser = $auth->requireAdmin();
    $method = $_SERVER['REQUEST_METHOD'];

    // Helper to normalize event payload
    $parseEventPayload = function ($data) use ($currentUser) {
        $name = trim($data['name'] ?? $data['title'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Event name is required');
        }

        $capacity = isset($data['capacity']) ? max(0, intval($data['capacity'])) : 0;
        $availableSpots = isset($data['available_spots']) ? max(0, intval($data['available_spots'])) : $capacity;

        $lat = isset($data['lat']) ? $data['lat'] : null;
        $lng = isset($data['lng']) ? $data['lng'] : null;

        return [
            'name' => $name,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'lat' => ($lat === '' ? null : $lat),
            'lng' => ($lng === '' ? null : $lng),
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
            'age_restriction' => $data['age_restriction'] ?? null,
            'price' => $data['price'] ?? 0,
            'image_url' => $data['image_url'] ?? null,
            'status' => $data['status'] ?? 'published',
            'genre_id' => isset($data['genres'][0]) ? intval($data['genres'][0]) : ($data['genre_id'] ?? null),
            'capacity' => $capacity,
            'available_spots' => $availableSpots,
            'owner_id' => $currentUser['id'],
            'genres' => isset($data['genres']) && is_array($data['genres']) ? $data['genres'] : [],
        ];
    };

    if ($method === 'GET') {
        $stmt = $db->query("
            SELECT e.*, g.name AS genre_name, g.id AS primary_genre_id, u.name AS owner_name,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'registered') AS registration_count
            FROM events e
            LEFT JOIN genres g ON e.genre_id = g.id
            LEFT JOIN users u ON e.owner_id = u.id
            ORDER BY e.created_at DESC
        ");
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as &$event) {
            $event['title'] = $event['name'];

            // Fetch additional genres from pivot table
            $genreStmt = $db->prepare("SELECT g.id, g.name, g.icon FROM event_genres eg JOIN genres g ON eg.genre_id = g.id WHERE eg.event_id = :event_id");
            $genreStmt->execute([':event_id' => $event['id']]);
            $genres = $genreStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$genres && $event['genre_name']) {
                $genres = [['id' => $event['primary_genre_id'], 'name' => $event['genre_name']]];
            }
            $event['genres'] = array_column($genres, 'name');
            $event['genre_ids'] = array_column($genres, 'id');
        }

        echo json_encode(['success' => true, 'events' => $events]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $payload = $parseEventPayload($data);

        $stmt = $db->prepare("INSERT INTO events (name, description, location, lat, lng, date, time, age_restriction, price, image_url, status, genre_id, capacity, available_spots, owner_id) VALUES (:name, :description, :location, :lat, :lng, :date, :time, :age_restriction, :price, :image_url, :status, :genre_id, :capacity, :available_spots, :owner_id)");
        $stmt->execute([
            ':name' => $payload['name'],
            ':description' => $payload['description'],
            ':location' => $payload['location'],
            ':lat' => $payload['lat'],
            ':lng' => $payload['lng'],
            ':date' => $payload['date'],
            ':time' => $payload['time'],
            ':age_restriction' => $payload['age_restriction'],
            ':price' => $payload['price'],
            ':image_url' => $payload['image_url'],
            ':status' => $payload['status'],
            ':genre_id' => $payload['genre_id'],
            ':capacity' => $payload['capacity'],
            ':available_spots' => $payload['available_spots'],
            ':owner_id' => $payload['owner_id'],
        ]);

        $eventId = $db->lastInsertId();

        if (!empty($payload['genres'])) {
            $genreStmt = $db->prepare("INSERT IGNORE INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)");
            foreach ($payload['genres'] as $genreId) {
                $genreStmt->execute([':event_id' => $eventId, ':genre_id' => $genreId]);
            }
        }

        $auth->logAction($currentUser['id'], 'create_event', 'event', $eventId, json_encode(['name' => $payload['name']]), $_SERVER['REMOTE_ADDR'] ?? null);

        echo json_encode(['success' => true, 'event_id' => (int)$eventId, 'message' => 'Event created successfully']);
        exit;
    }

    if ($method === 'PUT') {
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID required']);
            exit;
        }

        $eventId = intval($_GET['id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $payload = $parseEventPayload($data);

        $checkStmt = $db->prepare("SELECT * FROM events WHERE id = :id");
        $checkStmt->execute([':id' => $eventId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            exit;
        }

        $stmt = $db->prepare("UPDATE events SET name = :name, description = :description, location = :location, lat = :lat, lng = :lng, date = :date, time = :time, age_restriction = :age_restriction, price = :price, image_url = :image_url, status = :status, genre_id = :genre_id, capacity = :capacity, available_spots = :available_spots WHERE id = :id");
        $stmt->execute([
            ':name' => $payload['name'],
            ':description' => $payload['description'],
            ':location' => $payload['location'],
            ':lat' => $payload['lat'],
            ':lng' => $payload['lng'],
            ':date' => $payload['date'],
            ':time' => $payload['time'],
            ':age_restriction' => $payload['age_restriction'],
            ':price' => $payload['price'],
            ':image_url' => $payload['image_url'],
            ':status' => $payload['status'],
            ':genre_id' => $payload['genre_id'],
            ':capacity' => $payload['capacity'],
            ':available_spots' => $payload['available_spots'],
            ':id' => $eventId,
        ]);

        if (isset($data['genres']) && is_array($data['genres'])) {
            $db->prepare("DELETE FROM event_genres WHERE event_id = :event_id")->execute([':event_id' => $eventId]);
            $genreStmt = $db->prepare("INSERT IGNORE INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)");
            foreach ($payload['genres'] as $genreId) {
                $genreStmt->execute([':event_id' => $eventId, ':genre_id' => $genreId]);
            }
        }

        $auth->logAction($currentUser['id'], 'update_event', 'event', $eventId, json_encode($data), $_SERVER['REMOTE_ADDR'] ?? null);

        echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
        exit;
    }

    if ($method === 'DELETE') {
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID required']);
            exit;
        }

        $eventId = intval($_GET['id']);
        $checkStmt = $db->prepare("SELECT name FROM events WHERE id = :id");
        $checkStmt->execute([':id' => $eventId]);
        $event = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            exit;
        }

        $db->prepare("DELETE FROM events WHERE id = :id")->execute([':id' => $eventId]);
        $auth->logAction($currentUser['id'], 'delete_event', 'event', $eventId, json_encode(['name' => $event['name'] ?? null]), $_SERVER['REMOTE_ADDR'] ?? null);

        echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Admin Events API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
