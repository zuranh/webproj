<?php
// api/admin/event-update.php
// POST /api/admin/event-update.php
// Body: { id, name, description, date, time, location, image_url }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // -------- AUTH --------
    $headers = getallheaders();
    $normalized = [];
    foreach ($headers as $k => $v) {
        $normalized[strtolower($k)] = $v;
    }

    $firebaseUid = $normalized['x-firebase-uid'] ?? null;
    if (!$firebaseUid && isset($normalized['authorization'])) {
        $firebaseUser = verifyFirebaseTokenFromHeader($normalized['authorization']);
        $firebaseUid = $firebaseUser['localId'] ?? null;
    }

    if (!$firebaseUid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing authentication']);
        exit;
    }

    /** @var PDO $db */
    $db = require __DIR__ . '/../db.php';

    // role check
    $stmt = $db->prepare('SELECT role FROM users WHERE firebase_uid = :uid');
    $stmt->execute([':uid' => $firebaseUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !in_array($user['role'], ['admin', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        exit;
    }

    // -------- BODY --------
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $id          = isset($data['id']) ? (int) $data['id'] : 0;
    $name        = isset($data['name']) ? trim($data['name']) : '';
    $description = isset($data['description']) ? trim($data['description']) : '';
    $date        = isset($data['date']) ? trim($data['date']) : '';
    $time        = isset($data['time']) ? trim($data['time']) : '';
    $location    = isset($data['location']) ? trim($data['location']) : '';
    $imageUrl    = isset($data['image_url']) ? trim((string) $data['image_url']) : null;

    $errors = [];

    if ($id <= 0) {
        $errors[] = 'Invalid event id';
    }
    if ($name === '') {
        $errors[] = 'Name is required';
    }
    if ($description === '') {
        $errors[] = 'Description is required';
    }
    if ($date === '') {
        $errors[] = 'Date is required';
    }
    if ($time === '') {
        $errors[] = 'Time is required';
    }
    if ($location === '') {
        $errors[] = 'Location is required';
    }

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => implode('. ', $errors)]);
        exit;
    }

    // ensure event exists
    $check = $db->prepare('SELECT id FROM events WHERE id = :id');
    $check->execute([':id' => $id]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }

    // -------- UPDATE EVENT --------
    $stmt2 = $db->prepare('
        UPDATE events
        SET name = :name,
            description = :description,
            date = :date,
            time = :time,
            location = :location,
            image_url = :image_url
        WHERE id = :id
    ');

    $stmt2->execute([
        ':name'       => $name,
        ':description'=> $description,
        ':date'       => $date,
        ':time'       => $time,
        ':location'   => $location,
        ':image_url'  => $imageUrl ?: null,
        ':id'         => $id,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Event updated successfully',
    ]);
} catch (Exception $e) {
    error_log('event-update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while updating event']);
    exit;
}

/**
 * Same helper style as in profile-update.php
 */
function verifyFirebaseTokenFromHeader(string $authHeader): ?array
{
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return null;
    }

    $idToken = $matches[1];
    $apiKey = getenv('FIREBASE_API_KEY') ?: 'AIzaSyAfWmO5Ye-ILmVcWbwN4cVOuP3_e-8ckD8';
    $url = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['idToken' => $idToken]));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    return $data['users'][0] ?? null;
}
