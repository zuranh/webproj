<?php
/**
 * Admin Users API - Protected endpoint for user management
 * GET /api/admin/users.php - Get all users (admin can view, owner can manage)
 * PUT /api/admin/users.php? id=X - Update user role (owner only)
 * POST /api/admin/users.php - Create/sync user from Firebase
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $db = require __DIR__ . '/../db.php';
    require_once __DIR__ . '/../auth.php';

    $auth = new Auth($db);
    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure admin_actions table exists for logging on legacy databases
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `admin_actions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `admin_id` INT NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(50),
            `target_id` INT,
            `details` TEXT,
            `ip_address` VARCHAR(45),
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_admin` (`admin_id`),
            INDEX `idx_created` (`created_at`),
            CONSTRAINT `fk_actions_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // GET - View all users (requires admin)
    if ($method === 'GET') {
        $currentUser = $auth->requireAdmin();
        
        $stmt = $db->query("
            SELECT id, name, email, firebase_uid, age, role, is_active, joined_at, updated_at
            FROM users
            ORDER BY 
                CASE role 
                    WHEN 'owner' THEN 1 
                    WHEN 'admin' THEN 2 
                    ELSE 3 
                END,
                joined_at DESC
        ");
        $users = $stmt->fetchAll();
        
        // Add permission counts for each user
        foreach ($users as &$user) {
            $permStmt = $db->prepare("SELECT COUNT(*) FROM admin_permissions WHERE user_id = :user_id");
            $permStmt->execute([':user_id' => $user['id']]);
            $user['permission_count'] = $permStmt->fetchColumn();
        }
        
        echo json_encode(['success' => true, 'users' => $users, 'count' => count($users)]);
        exit;
    }

    // POST - Create or sync user from Firebase
    if ($method === 'POST') {
        // Allow unauthenticated for initial user creation
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['firebase_uid']) || empty($data['email'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Firebase UID and email are required']);
            exit;
        }
        
        // Check if user exists
        $checkStmt = $db->prepare("SELECT * FROM users WHERE firebase_uid = :uid");
        $checkStmt->execute([':uid' => $data['firebase_uid']]);
        $existingUser = $checkStmt->fetch();
        
        if ($existingUser) {
            // User exists, return their data
            echo json_encode(['success' => true, 'user' => $existingUser, 'message' => 'User already exists']);
            exit;
        }
        
        // Create new user
        $stmt = $db->prepare("
            INSERT INTO users (name, email, firebase_uid, age, role, is_active)
            VALUES (:name, :email, :firebase_uid, :age, 'user', 1)
        ");
        
        $stmt->execute([
            ':name' => $data['name'] ?? 'User',
            ':email' => $data['email'],
            ':firebase_uid' => $data['firebase_uid'],
            ':age' => $data['age'] ?? null
        ]);
        
        $userId = $db->lastInsertId();
        
        // Fetch created user
        $newUser = $auth->getUserById($userId);
        
        echo json_encode(['success' => true, 'user' => $newUser, 'message' => 'User created successfully']);
        exit;
    }

    // PUT - Update user role or status (owner only)
    if ($method === 'PUT') {
        $currentUser = $auth->requireOwner();
        
        if (! isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            exit;
        }
        
        $userId = intval($_GET['id']);
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Check if target user exists
        $targetUser = $auth->getUserById($userId);
        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }
        
        // Prevent owner from demoting themselves
        if ($userId == $currentUser['id'] && isset($data['role']) && $data['role'] !== 'owner') {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot change your own role']);
            exit;
        }
        
        $updates = [];
        $params = [':id' => $userId];
        
        // Update role
        if (isset($data['role'])) {
            if (! in_array($data['role'], ['owner', 'admin', 'user'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid role']);
                exit;
            }
            $updates[] = 'role = :role';
            $params[':role'] = $data['role'];
        }
        
        // Update active status
        if (isset($data['is_active'])) {
            $updates[] = 'is_active = :is_active';
            $params[':is_active'] = $data['is_active'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit;
        }
        
        $sql = "UPDATE users SET " . implode(', ', $updates) .  " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Log action
        $auth->logAction(
            $currentUser['id'], 
            'update_user_role', 
            'user', 
            $userId, 
            json_encode($data), 
            $_SERVER['REMOTE_ADDR']
        );
        
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    error_log('Admin Users API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>