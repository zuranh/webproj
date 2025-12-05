<?php
/**
 * Admin Events API - Protected endpoint for event management
 * POST /api/admin/events.php - Create new event
 * PUT /api/admin/events.php?id=X - Update event
 * DELETE /api/admin/events.php?id=X - Delete event
 * GET /api/admin/events.php - Get all events (including drafts)
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

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function sanitizeFloat($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    return (float) $value;
}

function sanitizeInt($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    return (int) $value;
}

if ($method === 'GET') {
    $stmt = $db->query(
        "SELECT e.*, u.name AS owner_name,
                GROUP_CONCAT(g.name) AS genres,
                GROUP_CONCAT(g.slug) AS genre_slugs
         FROM events e
         LEFT JOIN users u ON e.owner_id = u.id
         LEFT JOIN event_genres eg ON e.id = eg.event_id
         LEFT JOIN genres g ON eg.genre_id = g.id
         GROUP BY e.id
         ORDER BY e.created_at DESC"
    );
    $events = $stmt->fetchAll();

    foreach ($events as &$event) {
        $event['genres'] = $event['genres'] ? explode(',', $event['genres']) : [];
        $event['genre_slugs'] = $event['genre_slugs'] ? explode(',', $event['genre_slugs']) : [];
    }

    respond(200, ['success' => true, 'events' => $events]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
    $required = ['name', 'description', 'date', 'time', 'location'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            respond(422, ['success' => false, 'error' => ucfirst($field) . ' is required']);
        }
    }

    $status = $input['status'] ?? 'published';
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        respond(422, ['success' => false, 'error' => 'Invalid status']);
    }

    $genres = isset($input['genres']) && is_array($input['genres']) ? $input['genres'] : [];
    if (count($genres) === 0) {
        respond(422, ['success' => false, 'error' => 'At least one genre is required']);
    }

    $primaryGenre = $genres[0];
    $capacity = sanitizeInt($input['capacity'] ?? 0);
    $available = $capacity !== null ? $capacity : 0;

    $stmt = $db->prepare(
        "INSERT INTO events
            (name, description, location, lat, lng, date, time, age_restriction, price, image_url, status, genre_id, owner_id, capacity, available_spots)
         VALUES
            (:name, :description, :location, :lat, :lng, :date, :time, :age_restriction, :price, :image_url, :status, :genre_id, :owner_id, :capacity, :available_spots)"
    );

    $stmt->execute([
        ':name' => $input['name'],
        ':description' => $input['description'],
        ':location' => $input['location'],
        ':lat' => sanitizeFloat($input['lat'] ?? null),
        ':lng' => sanitizeFloat($input['lng'] ?? null),
        ':date' => $input['date'],
        ':time' => $input['time'],
        ':age_restriction' => sanitizeInt($input['age_restriction'] ?? null),
        ':price' => sanitizeFloat($input['price'] ?? 0),
        ':image_url' => $input['image_url'] ?? null,
        ':status' => $status,
        ':genre_id' => $primaryGenre,
        ':owner_id' => $currentUser['id'],
        ':capacity' => $capacity ?? 0,
        ':available_spots' => $available,
    ]);

    $eventId = $db->lastInsertId();

    $genreStmt = $db->prepare('INSERT INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)');
    foreach ($genres as $genreId) {
        $genreStmt->execute([':event_id' => $eventId, ':genre_id' => $genreId]);
    }

    if (method_exists($auth, 'logAction')) {
        $auth->logAction($currentUser['id'], 'create_event', 'event', $eventId, json_encode(['name' => $input['name']]), $_SERVER['REMOTE_ADDR'] ?? null);
    }

    respond(201, ['success' => true, 'event_id' => (int) $eventId, 'message' => 'Event created successfully']);
}

if ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        respond(400, ['success' => false, 'error' => 'Event ID required']);
    }
    $eventId = (int) $_GET['id'];

    $check = $db->prepare('SELECT * FROM events WHERE id = :id');
    $check->execute([':id' => $eventId]);
    $existing = $check->fetch();

    if (!$existing) {
        respond(404, ['success' => false, 'error' => 'Event not found']);
    }

    $status = $input['status'] ?? $existing['status'];
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        respond(422, ['success' => false, 'error' => 'Invalid status']);
    }

    $capacity = array_key_exists('capacity', $input) ? sanitizeInt($input['capacity']) : $existing['capacity'];
    $available = $capacity;

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
            genre_id = :genre_id,
            capacity = :capacity,
            available_spots = :available_spots
         WHERE id = :id"
    );

    $stmt->execute([
        ':name' => $input['name'] ?? $existing['name'],
        ':description' => $input['description'] ?? $existing['description'],
        ':location' => $input['location'] ?? $existing['location'],
        ':lat' => array_key_exists('lat', $input) ? sanitizeFloat($input['lat']) : $existing['lat'],
        ':lng' => array_key_exists('lng', $input) ? sanitizeFloat($input['lng']) : $existing['lng'],
        ':date' => $input['date'] ?? $existing['date'],
        ':time' => $input['time'] ?? $existing['time'],
        ':age_restriction' => array_key_exists('age_restriction', $input) ? sanitizeInt($input['age_restriction']) : $existing['age_restriction'],
        ':price' => array_key_exists('price', $input) ? sanitizeFloat($input['price']) : $existing['price'],
        ':image_url' => array_key_exists('image_url', $input) ? $input['image_url'] : $existing['image_url'],
        ':status' => $status,
        ':genre_id' => isset($input['genres'][0]) ? $input['genres'][0] : $existing['genre_id'],
        ':capacity' => $capacity,
        ':available_spots' => $available,
        ':id' => $eventId,
    ]);

    if (isset($input['genres']) && is_array($input['genres'])) {
        $db->prepare('DELETE FROM event_genres WHERE event_id = :event_id')->execute([':event_id' => $eventId]);
        $genreStmt = $db->prepare('INSERT INTO event_genres (event_id, genre_id) VALUES (:event_id, :genre_id)');
        foreach ($input['genres'] as $genreId) {
            $genreStmt->execute([':event_id' => $eventId, ':genre_id' => $genreId]);
        }
    }

    if (method_exists($auth, 'logAction')) {
        $auth->logAction($currentUser['id'], 'update_event', 'event', $eventId, json_encode($input), $_SERVER['REMOTE_ADDR'] ?? null);
    }

    respond(200, ['success' => true, 'message' => 'Event updated successfully']);
}

if ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        respond(400, ['success' => false, 'error' => 'Event ID required']);
    }
    $eventId = (int) $_GET['id'];

    $check = $db->prepare('SELECT name FROM events WHERE id = :id');
    $check->execute([':id' => $eventId]);
    $event = $check->fetch();

    if (!$event) {
        respond(404, ['success' => false, 'error' => 'Event not found']);
    }

    $db->prepare('DELETE FROM event_genres WHERE event_id = :event_id')->execute([':event_id' => $eventId]);
    $db->prepare('DELETE FROM events WHERE id = :id')->execute([':id' => $eventId]);

    if (method_exists($auth, 'logAction')) {
        $auth->logAction($currentUser['id'], 'delete_event', 'event', $eventId, json_encode(['name' => $event['name']]), $_SERVER['REMOTE_ADDR'] ?? null);
    }

    respond(200, ['success' => true, 'message' => 'Event deleted successfully']);
}

respond(405, ['success' => false, 'error' => 'Method not allowed']);
