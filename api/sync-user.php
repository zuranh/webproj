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
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = $firebaseUser['localId'];
$email = $firebaseUser['email'] ?? '';
$hasName = array_key_exists('name', $data);
$hasAge = array_key_exists('age', $data);
$name = $hasName ? trim((string) $data['name']) : null;
$age = $hasAge ? $data['age'] : null;

$errors = [];
if ($hasName) {
    if ($name === '') {
        $errors[] = 'Full name is required';
    } else {
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $validParts = array_filter($parts, fn($part) => preg_match("/^[A-Za-z][A-Za-z'\\-]{1,}$/", $part));
        if (count($parts) < 2) {
            $errors[] = 'Please provide first and last name';
        } elseif (count($validParts) !== count($parts)) {
            $errors[] = 'Names should only include letters (plus optional hyphen/apostrophe)';
        }
    }
}
if ($hasAge) {
    if (!is_numeric($age)) {
        $errors[] = 'Age must be a number';
    } else {
        $age = (int) $age;
        if ($age < 13 || $age > 120) {
            $errors[] = 'Age must be between 13 and 120';
        }
    }
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['error' => implode('. ', $errors)]);
    exit;
}

try {
    $db = require __DIR__ . '/db.php';
    
    // Check if user exists
    $stmt = $db->prepare('SELECT id, name, email, age FROM users WHERE firebase_uid = :uid');
    $stmt->execute([':uid' => $uid]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $nameToSave = $hasName ? $name : $existing['name'];
        $ageToSave = $hasAge ? $age : $existing['age'];

        // Update existing user
        $stmt = $db->prepare('UPDATE users SET name = :name, email = :email, age = :age WHERE firebase_uid = :uid');
        $stmt->execute([
            ':uid' => $uid,
            ':name' => $nameToSave,
            ':email' => $email ?: $existing['email'],
            ':age' => $ageToSave
        ]);
        $userId = $existing['id'];
    } else {
        $nameToSave = $name ?? ($firebaseUser['displayName'] ?? 'User');
        $ageToSave = $hasAge ? $age : null;

        // Insert new user
        $stmt = $db->prepare('INSERT INTO users (name, email, age, firebase_uid, role, joined_at) VALUES (:name, :email, :age, :uid, :role, :joined)');
        $stmt->execute([
            ':name' => $nameToSave,
            ':email' => $email,
            ':age' => $ageToSave,
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