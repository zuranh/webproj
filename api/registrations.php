<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['Authorization'])) $headers['Authorization'] = $_SERVER['Authorization'];
        return $headers;
    }
}

try {
    $db = require __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';

    ensureRegistrationSchema($db);
    ensureEventCapacityColumns($db);

    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $firebaseUid = $headers['x-firebase-uid'] ?? '';

    if (!$firebaseUid) {
        $authHeader = $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $idToken = $matches[1];
            $firebaseUser = verifyFirebaseToken($idToken);
            if ($firebaseUser && isset($firebaseUser['localId'])) {
                $firebaseUid = $firebaseUser['localId'];
            }
        }
    }

    if (!$firebaseUid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $auth = new Auth($db);
    $user = $auth->getUserByFirebaseUid($firebaseUid);

    if (!$user || !$auth->isActive($user)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

        if ($eventId > 0) {
            $status = getRegistrationStatus($db, $user['id'], $eventId);
            $spots = getEventAvailability($db, $eventId);

            echo json_encode([
                'success' => true,
                'registered' => $status === 'registered',
                'status' => $status,
                'spots_remaining' => $spots,
            ]);
            exit;
        }

        // List all registrations for the user
        $stmt = $db->prepare(
            'SELECT r.id as registration_id, r.event_id, r.status, r.created_at as registered_at, r.updated_at,
                    e.name, e.location, e.date, e.time, e.image_url, e.price, e.status as event_status
             FROM registrations r
             JOIN events e ON r.event_id = e.id
             WHERE r.user_id = :uid AND r.status = "registered"
             ORDER BY r.created_at DESC'
        );
        $stmt->execute([':uid' => $user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'registrations' => $rows]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $eventId = isset($payload['event_id']) ? (int) $payload['event_id'] : 0;

        if ($eventId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Event ID is required']);
            exit;
        }

        registerForEvent($db, $user['id'], $eventId);
        $spots = getEventAvailability($db, $eventId);

        echo json_encode([
            'success' => true,
            'message' => 'Registration confirmed',
            'spots_remaining' => $spots,
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

        if ($eventId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Event ID is required']);
            exit;
        }

        cancelRegistration($db, $user['id'], $eventId);
        $spots = getEventAvailability($db, $eventId);

        echo json_encode([
            'success' => true,
            'message' => 'Registration canceled',
            'spots_remaining' => $spots,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
} catch (Exception $e) {
    error_log('Registration API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while processing registration']);
    exit;
}

function ensureRegistrationSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS registrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        event_id INT NOT NULL,
        status ENUM("registered", "cancelled") DEFAULT "registered",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_registration (user_id, event_id),
        INDEX idx_user (user_id),
        INDEX idx_event (event_id),
        CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
}

function ensureEventCapacityColumns(PDO $db): void
{
    $missing = [];
    if (!columnExists($db, 'events', 'capacity')) {
        $missing[] = 'ADD COLUMN `capacity` INT DEFAULT 0';
    }
    if (!columnExists($db, 'events', 'available_spots')) {
        $missing[] = 'ADD COLUMN `available_spots` INT DEFAULT 0';
    }

    if (!empty($missing)) {
        $sql = 'ALTER TABLE events ' . implode(', ', $missing);
        $db->exec($sql);
    }
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool) $stmt->fetchColumn();
}

function getEventAvailability(PDO $db, int $eventId): ?int
{
    $stmt = $db->prepare('SELECT capacity, available_spots FROM events WHERE id = :id');
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return null;

    if ((int) $event['capacity'] <= 0) {
        return null; // unlimited or not enforced
    }
    return (int) $event['available_spots'];
}

function getRegistrationStatus(PDO $db, int $userId, int $eventId): ?string
{
    $stmt = $db->prepare('SELECT status FROM registrations WHERE user_id = :uid AND event_id = :eid');
    $stmt->execute([':uid' => $userId, ':eid' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['status'] ?? null;
}

function registerForEvent(PDO $db, int $userId, int $eventId): void
{
    $db->beginTransaction();
    try {
        $eventStmt = $db->prepare('SELECT id, capacity, available_spots, status FROM events WHERE id = :id FOR UPDATE');
        $eventStmt->execute([':id' => $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event || $event['status'] !== 'published') {
            http_response_code(404);
            throw new RuntimeException('Event not found');
        }

        $existingStatus = getRegistrationStatus($db, $userId, $eventId);
        if ($existingStatus === 'registered') {
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Already registered']);
            exit;
        }

        // Capacity check (only when capacity is positive)
        if ((int) $event['capacity'] > 0) {
            if ((int) $event['available_spots'] <= 0) {
                http_response_code(409);
                throw new RuntimeException('Event is full');
            }
        }

        if ($existingStatus === 'cancelled') {
            $stmt = $db->prepare('UPDATE registrations SET status = "registered" WHERE user_id = :uid AND event_id = :eid');
            $stmt->execute([':uid' => $userId, ':eid' => $eventId]);
        } else {
            $stmt = $db->prepare('INSERT INTO registrations (user_id, event_id, status) VALUES (:uid, :eid, "registered")');
            $stmt->execute([':uid' => $userId, ':eid' => $eventId]);
        }

        if ((int) $event['capacity'] > 0) {
            $update = $db->prepare('UPDATE events SET available_spots = GREATEST(available_spots - 1, 0) WHERE id = :id');
            $update->execute([':id' => $eventId]);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function cancelRegistration(PDO $db, int $userId, int $eventId): void
{
    $db->beginTransaction();
    try {
        $eventStmt = $db->prepare('SELECT id, capacity, available_spots, status FROM events WHERE id = :id FOR UPDATE');
        $eventStmt->execute([':id' => $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            http_response_code(404);
            throw new RuntimeException('Event not found');
        }

        $existingStatus = getRegistrationStatus($db, $userId, $eventId);
        if ($existingStatus !== 'registered') {
            $db->commit();
            return;
        }

        $stmt = $db->prepare('UPDATE registrations SET status = "cancelled" WHERE user_id = :uid AND event_id = :eid');
        $stmt->execute([':uid' => $userId, ':eid' => $eventId]);

        if ((int) $event['capacity'] > 0) {
            $update = $db->prepare('UPDATE events SET available_spots = LEAST(available_spots + 1, capacity) WHERE id = :id');
            $update->execute([':id' => $eventId]);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function verifyFirebaseToken($idToken)
{
    $apiKey = getenv('FIREBASE_API_KEY') ?: 'AIzaSyAfWmO5Ye-ILmVcWbwN4cVOuP3_e-8ckD8';
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . $apiKey;

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
    return is_array($data) ? ($data['users'][0] ?? null) : null;
}
