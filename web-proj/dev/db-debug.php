<?php
// Dev-only DB debug page: lists users and events
// Access restricted to localhost for safety
$allowed = ['127.0.0.1', '::1'];
$addr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($addr, $allowed, true)) {
    http_response_code(403);
    echo "<h2>403 Forbidden</h2><p>Debug page accessible from localhost only.</p>";
    exit;
}

require_once __DIR__ . '/../api/db.php';
$db = require __DIR__ . '/../api/db.php';

// Optional JSON output
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    $out = [];
    $out['users'] = $db->query('SELECT id,name,email,age,role,joined_at FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $out['events'] = $db->query('SELECT * FROM events')->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

function safeHtml($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>DB Debug — Dev Only</title>
  <style>
    body{font-family: Arial,Helvetica,sans-serif;padding:20px}
    table{border-collapse:collapse;width:100%;margin-bottom:24px}
    th,td{padding:8px;border:1px solid #ddd;text-align:left}
    h1{margin-bottom:10px}
    .note{color:#555;margin-bottom:14px}
    .actions{margin-bottom:14px}
    .actions a{margin-right:10px}
  </style>
</head>
<body>
  <h1>Database Debug (dev-only)</h1>
  <p class="note">This page is for local development only. It is accessible only from <strong>localhost</strong>.</p>
  <div class="actions">
    <a href="?format=json">View JSON</a>
    <a href="/">Back to site</a>
  </div>

  <h2>Users</h2>
  <?php
    $users = $db->query('SELECT id,name,email,age,role,joined_at FROM users')->fetchAll(PDO::FETCH_ASSOC);
    if (!$users) {
        echo "<p>No users found.</p>";
    } else {
        echo "<table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Age</th><th>Role</th><th>Joined</th></tr></thead><tbody>";
        foreach ($users as $u) {
            echo '<tr>';
            echo '<td>'.safeHtml($u['id']).'</td>';
            echo '<td>'.safeHtml($u['name']).'</td>';
            echo '<td>'.safeHtml($u['email']).'</td>';
            echo '<td>'.safeHtml($u['age']).'</td>';
            echo '<td>'.safeHtml($u['role']).'</td>';
            echo '<td>'.safeHtml($u['joined_at']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
  ?>

  <h2>Events</h2>
  <?php
    $events = $db->query('SELECT id,title,location,lat,lng,date,time,age_restriction,price,created_at FROM events')->fetchAll(PDO::FETCH_ASSOC);
    if (!$events) {
        echo "<p>No events found.</p>";
    } else {
        echo "<table><thead><tr><th>ID</th><th>Title</th><th>Location</th><th>Lat</th><th>Lng</th><th>Date</th><th>Time</th><th>Age</th><th>Price</th><th>Created</th></tr></thead><tbody>";
        foreach ($events as $e) {
            echo '<tr>';
            echo '<td>'.safeHtml($e['id']).'</td>';
            echo '<td>'.safeHtml($e['title']).'</td>';
            echo '<td>'.safeHtml($e['location']).'</td>';
            echo '<td>'.safeHtml($e['lat']).'</td>';
            echo '<td>'.safeHtml($e['lng']).'</td>';
            echo '<td>'.safeHtml($e['date']).'</td>';
            echo '<td>'.safeHtml($e['time']).'</td>';
            echo '<td>'.safeHtml($e['age_restriction']).'</td>';
            echo '<td>'.safeHtml($e['price']).'</td>';
            echo '<td>'.safeHtml($e['created_at']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
  ?>

</body>
</html>
