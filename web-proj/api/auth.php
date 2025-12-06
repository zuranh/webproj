<?php
/**
 * Authentication and Authorization Helper
 * Handles user role checks and permissions for Event Finder
 */

class Auth {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Get user by Firebase UID
     */
    public function getUserByFirebaseUid($firebaseUid) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE firebase_uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $firebaseUid]);
        return $stmt->fetch();
    }

    /**
     * Get user by ID
     */
    public function getUserById($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch();
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole($user, $role) {
        if (!$user || !isset($user['role'])) {
            return false;
        }
        return $user['role'] === $role;
    }

    /**
     * Check if user is owner
     */
    public function isOwner($user) {
        return $this->hasRole($user, 'owner');
    }

    /**
     * Check if user is admin or owner
     */
    public function isAdmin($user) {
        return $this->hasRole($user, 'admin') || $this->hasRole($user, 'owner');
    }

    /**
     * Check if user is active
     */
    public function isActive($user) {
        return $user && isset($user['is_active']) && $user['is_active'] == 1;
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission($userId, $permission) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_permissions WHERE user_id = :user_id AND permission = :permission");
        $stmt->execute([':user_id' => $userId, ':permission' => $permission]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Grant permission to a user
     */
    public function grantPermission($userId, $permission, $grantedBy) {
        $stmt = $this->db->prepare("INSERT IGNORE INTO admin_permissions (user_id, permission, granted_by) VALUES (:user_id, :permission, :granted_by)");
        return $stmt->execute([
            ':user_id' => $userId,
            ':permission' => $permission,
            ':granted_by' => $grantedBy
        ]);
    }

    /**
     * Revoke permission from a user
     */
    public function revokePermission($userId, $permission) {
        $stmt = $this->db->prepare("DELETE FROM admin_permissions WHERE user_id = :user_id AND permission = :permission");
        return $stmt->execute([':user_id' => $userId, ':permission' => $permission]);
    }

    /**
     * Update user role (only owner can do this)
     */
    public function updateUserRole($userId, $newRole) {
        if (!in_array($newRole, ['owner', 'admin', 'user'])) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE users SET role = :role WHERE id = :id");
        return $stmt->execute([':role' => $newRole, ':id' => $userId]);
    }

    /**
     * Log admin action for audit trail
     */
    public function logAction($adminId, $action, $targetType = null, $targetId = null, $details = null, $ipAddress = null) {
        $stmt = $this->db->prepare("INSERT INTO admin_actions (admin_id, action, target_type, target_id, details, ip_address) VALUES (:admin_id, :action, :target_type, :target_id, :details, :ip_address)");
        return $stmt->execute([
            ':admin_id' => $adminId,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':details' => $details,
            ':ip_address' => $ipAddress
        ]);
    }

    /**
     * Require authentication - returns user or exits with 401
     */
    public function requireAuth() {
        // Get Firebase UID from request header or session
        $firebaseUid = $_SERVER['HTTP_X_FIREBASE_UID'] ?? $_SESSION['firebase_uid'] ?? null;
        
        if (!$firebaseUid) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }

        $user = $this->getUserByFirebaseUid($firebaseUid);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        if (!$this->isActive($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Account is inactive']);
            exit;
        }

        return $user;
    }

    /**
     * Require admin role - returns user or exits with 403
     */
    public function requireAdmin() {
        $user = $this->requireAuth();
        
        if (!$this->isAdmin($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }

        return $user;
    }

    /**
     * Require owner role - returns user or exits with 403
     */
    public function requireOwner() {
        $user = $this->requireAuth();
        
        if (!$this->isOwner($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Owner access required']);
            exit;
        }

        return $user;
    }
}
?>