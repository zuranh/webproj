<?php
/**
 * Admin Dashboard API
 * Returns high level stats for admin landing page
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Firebase-UID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $db = require __DIR__ . '/../db.php';
    require_once __DIR__ . '/../auth.php';

    $auth = new Auth($db);
    $currentUser = $auth->requireAdmin();

    // User counts
    $usersStmt = $db->query("SELECT role, is_active, COUNT(*) as total FROM users GROUP BY role, is_active");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalUsers = 0;
    $admins = 0;
    $owners = 0;
    $activeUsers = 0;

    foreach ($users as $row) {
        $totalUsers += $row['total'];
        if ($row['is_active']) {
            $activeUsers += $row['total'];
        }
        if ($row['role'] === 'admin') {
            $admins += $row['total'];
        } elseif ($row['role'] === 'owner') {
            $owners += $row['total'];
        }
    }

    // Event counts
    $eventStmt = $db->query("SELECT status, COUNT(*) as total FROM events GROUP BY status");
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
    $eventTotal = 0;
    $published = 0;
    $draft = 0;

    foreach ($events as $row) {
        $eventTotal += $row['total'];
        if ($row['status'] === 'published') {
            $published += $row['total'];
        }
        if ($row['status'] === 'draft') {
            $draft += $row['total'];
        }
    }

    // Registration counts
    $regStmt = $db->query("SELECT status, COUNT(*) as total FROM event_registrations GROUP BY status");
    $regs = $regStmt->fetchAll(PDO::FETCH_ASSOC);
    $registrationTotal = 0;
    $upcomingRegistrations = 0;
    $pastRegistrations = 0;

    foreach ($regs as $row) {
        $registrationTotal += $row['total'];
        if ($row['status'] === 'registered') {
            $upcomingRegistrations += $row['total'];
        } else {
            $pastRegistrations += $row['total'];
        }
    }

    // Recent events
    $recentStmt = $db->prepare("SELECT e.id, e.name, e.date, e.status, u.name AS owner_name FROM events e LEFT JOIN users u ON e.owner_id = u.id ORDER BY e.created_at DESC LIMIT 5");
    $recentStmt->execute();
    $recentEvents = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_users' => (int) $totalUsers,
            'admins' => (int) $admins,
            'owners' => (int) $owners,
            'active_users' => (int) $activeUsers,
            'events' => (int) $eventTotal,
            'published_events' => (int) $published,
            'draft_events' => (int) $draft,
            'registrations' => (int) $registrationTotal,
            'upcoming_registrations' => (int) $upcomingRegistrations,
            'past_registrations' => (int) $pastRegistrations,
        ],
        'recent_events' => $recentEvents,
    ]);
    exit;
} catch (Exception $e) {
    error_log('Admin Dashboard API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
