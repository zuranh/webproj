<?php
// api/admin/event-get.php
// GET /api/admin/event-get.php?id=123

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid event id']);
        exit;
    }

    // Auth (similar to profile-update.php)
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

    $db = require __DIR__ . '/../db.php';

    // check role
    $stmt = $db->prepare('SELECT role FROM users WHERE firebase_uid = :uid');
    $stmt->execute([':uid' => $firebaseUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !in_array($user['role'], ['admin', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        exit;
    }

    // fetch event
    $stmt2 = $db->prepare('SELECT id, name, description, date, time, location, image_url FROM events WHERE id = :id');
    $stmt2->execute([':id' => $id]);
    $event = $stmt2->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'event'   => $event,
    ]);
} catch (Exception $e) {
    error_log('event-get error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}

/**
 * Same helper as in profile-update.php
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
