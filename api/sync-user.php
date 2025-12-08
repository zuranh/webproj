<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get Firebase ID token from Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'No token provided']);
    exit;
}

$idToken = $matches[1];

// Verify token with Firebase
$firebaseUser = verifyFirebaseToken($idToken);
if (!$firebaseUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);
$uid = $firebaseUser['localId'];
$email = $firebaseUser['email'] ?? '';
$name = $data['name'] ?? '';
$age = $data['age'] ?? null;

try {
    $db = require __DIR__ . '/db.php';
    
    // Check if user exists by Firebase UID
    $stmt = $db->prepare('SELECT * FROM users WHERE firebase_uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found by UID, try matching by email (helps claim the seeded owner account)
    if (!$existing) {
        $emailStmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $emailStmt->execute([':email' => $email]);
        $existing = $emailStmt->fetch(PDO::FETCH_ASSOC);

        // If the email is already tied to another Firebase UID, block to avoid hijacking
        if ($existing && !empty($existing['firebase_uid']) && $existing['firebase_uid'] !== $uid) {
            http_response_code(409);
            echo json_encode(['error' => 'Account email already linked to a different user']);
            exit;
        }
    }

    if ($existing) {
        // Update existing user and attach the Firebase UID if missing
        $stmt = $db->prepare('UPDATE users SET name = :name, email = :email, age = :age, firebase_uid = COALESCE(firebase_uid, :uid) WHERE id = :id');
        $stmt->execute([
            ':id' => $existing['id'],
            ':uid' => $uid,
            ':name' => $name,
            ':email' => $email,
            ':age' => $age
        ]);
        $userId = $existing['id'];
    } else {
        // Insert new user
        $stmt = $db->prepare('INSERT INTO users (name, email, age, firebase_uid, role, joined_at) VALUES (:name, :email, :age, :uid, :role, :joined)');
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':age' => $age,
            ':uid' => $uid,
            ':role' => 'user',
            ':joined' => date('c')
        ]);
        $userId = $db->lastInsertId();
    }
    
    echo json_encode(['success' => true, 'user_id' => $userId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function verifyFirebaseToken($idToken) {
    // Get your Firebase Web API Key from Firebase Console
    $apiKey = "AIzaSyAfWmO5Ye-ILmVcWbwN4cVOuP3_e-8ckD8"; // TODO: Replace this
    
    $url = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=" . $apiKey;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['idToken' => $idToken]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['users'][0] ?? null;
}