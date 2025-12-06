<?php
header('Content-Type: application/json');
$results = ['ok' => false, 'messages' => []];

try {
    // Adjust path if your db.php is in a parent folder: require __DIR__ . '/../db.php';
    $pdo = require __DIR__ . '/db.php';
    if (!($pdo instanceof PDO)) {
        $results['messages'][] = 'db.php did not return a PDO instance.';
        echo json_encode($results, JSON_PRETTY_PRINT);
        exit;
    }
    $results['messages'][] = 'Successfully required db.php and got a PDO instance.';

    // MySQL server version
    try {
        $stmt = $pdo->query('SELECT VERSION() as v');
        $ver = $stmt->fetch(PDO::FETCH_ASSOC);
        $results['mysql_version'] = $ver['v'] ?? '(unknown)';
    } catch (Throwable $e) {
        $results['messages'][] = 'Could not read MySQL version: ' . $e->getMessage();
    }

    // Check events table rows
    try {
        $stmt = $pdo->query('SELECT COUNT(*) AS c FROM events');
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        $results['events_count'] = isset($c['c']) ? (int)$c['c'] : null;
        $results['messages'][] = 'events table checked.';
    } catch (Throwable $te) {
        $results['messages'][] = 'Could not SELECT from events table: ' . $te->getMessage();
    }

    $results['ok'] = true;
} catch (Throwable $e) {
    $results['messages'][] = 'Connection or require failed: ' . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);