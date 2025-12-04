<?php
/**
 * Admin Events API - Protected endpoint for event management
 * POST   /api/admin/events.php      Create new event
 * PUT    /api/admin/events.php?id=X Update event
 * DELETE /api/admin/events.php?id=X Delete event
 * GET    /api/admin/events.php      List all events (including drafts)
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db = require __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($db);
$currentUser = $auth->requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];

// Utility: normalize title/name input
function event_name(array $data): string
{
    return trim($data['title'] ?? $data['name'] ?? '');
}

// Utility: determine primary genre id
function primary_genre_id(array $data): ?int
{
    if (!empty($data['genre_id'])) {
        return (int) $data['genre_id'];
    }

    if (!empty($data['genres']) && is_array($data['genres'])) {
        $first = reset($data['genres']);
        return $first ? (int) $first : null;
    }

    return null;
}

// GET - Fetch all events (including drafts) for admin
if ($method === 'GET') {
    $stmt = $db->query(
        "SELECT e.*, u.name as owner_name
         FROM events e
         LEFT JOIN users u ON e.owner_id = u.id
         ORDER BY e.created_at DESC"
    );

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'events' => $events]);
    exit;
}

// POST - Create new event
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $name = event_name($data);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Title is required']);
        exit;
    }

    $genreId = primary_genre_id($data);
    $status = $data['status'] ?? 'published';

    $stmt = $db->prepare(
        "INSERT INTO events
        (name, description, location, lat, lng, date, time, age_restriction, price, image_url, status, genre_id, owner_id)
        VALUES
        (:name, :description, :location, :lat, :lng, :date, :time, :age_restriction, :price, :image_url, :status, :genre_id, :owner_id)"
    );

    $stmt->execute([
        ':name' => $name,
        ':description' => $data['description'] ?? null,
        ':location' => $data['location'] ?? null,
        ':lat' => $data['lat'] ?? null,
        ':lng' => $data['lng'] ?? null,
        ':date' => $data['date'] ?? null,
        ':time' => $data['time'] ?? null,
        ':age_restriction' => $data['age_restriction'] ?? null,
        ':price' => $data['price'] ?? 0,
        ':image_url' => $data['image_url'] ?? null,
        ':status' => $status,
        ':genre_id' => $genreId,
        ':owner_id' => $currentUser['id'],
    ]);

    $eventId = (int) $db->lastInsertId();

    // Add additional genre links if provided
    if (!empty($data['genres']) && is_array($data['genres'])) {
        $genreStmt = $db->prepare("INSERT IGNORE INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)");
        foreach ($data['genres'] as $genreId) {
            $genreStmt->execute([':event_id' => $eventId, ':genre_id' => (int) $genreId]);
        }
    }

    $auth->logAction($currentUser['id'], 'create_event', 'event', $eventId, json_encode(['name' => $name]), $_SERVER['REMOTE_ADDR'] ?? null);

    echo json_encode(['success' => true, 'event_id' => $eventId, 'message' => 'Event created successfully']);
    exit;
}

// PUT - Update existing event
if ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Event ID required']);
        exit;
    }

    $eventId = intval($_GET['id']);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $checkStmt = $db->prepare("SELECT * FROM events WHERE id = :id");
    $checkStmt->execute([':id' => $eventId]);
    $existingEvent = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingEvent) {
        http_response_code(404);
        echo json_encode(['error' => 'Event not found']);
        exit;
    }

    $name = event_name($data) ?: $existingEvent['name'];
    $genreId = primary_genre_id($data);

    $stmt = $db->prepare(
        "UPDATE events SET
            name = :name,
            description = :description,
            location = :location,
            lat = :lat,
            lng = :lng,
            date = :date,
            time = :time,
            age_restriction = :age_restriction,
            price = :price,
            image_url = :image_url,
            status = :status,
            genre_id = :genre_id
        WHERE id = :id"
    );

    $stmt->execute([
        ':name' => $name,
        ':description' => $data['description'] ?? $existingEvent['description'],
        ':location' => $data['location'] ?? $existingEvent['location'],
        ':lat' => $data['lat'] ?? $existingEvent['lat'],
        ':lng' => $data['lng'] ?? $existingEvent['lng'],
        ':date' => $data['date'] ?? $existingEvent['date'],
        ':time' => $data['time'] ?? $existingEvent['time'],
        ':age_restriction' => $data['age_restriction'] ?? $existingEvent['age_restriction'],
        ':price' => $data['price'] ?? $existingEvent['price'],
        ':image_url' => $data['image_url'] ?? $existingEvent['image_url'],
        ':status' => $data['status'] ?? $existingEvent['status'],
        ':genre_id' => $genreId ?? $existingEvent['genre_id'],
        ':id' => $eventId,
    ]);

    if (isset($data['genres']) && is_array($data['genres'])) {
        $db->prepare("DELETE FROM event_genres WHERE event_id = :event_id")->execute([':event_id' => $eventId]);
        $genreStmt = $db->prepare("INSERT IGNORE INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)");
        foreach ($data['genres'] as $genreId) {
            $genreStmt->execute([':event_id' => $eventId, ':genre_id' => (int) $genreId]);
        }
    }

    $auth->logAction($currentUser['id'], 'update_event', 'event', $eventId, json_encode($data), $_SERVER['REMOTE_ADDR'] ?? null);

    echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
    exit;
}

// DELETE - Delete event
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

    $auth->logAction($currentUser['id'], 'delete_event', 'event', $eventId, json_encode(['name' => $event['name']]), $_SERVER['REMOTE_ADDR'] ?? null);

    echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>
