<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function distance_km($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

try {
    $db = require __DIR__ . '/db.php';

    if (isset($_GET['id'])) {
        $stmt = $db->prepare('
            SELECT e.*, g. name as genre_name, g. slug as genre_slug, g. icon as genre_icon, u. name as owner_name
            FROM events e
            LEFT JOIN genres g ON e.genre_id = g.id
            LEFT JOIN users u ON e.owner_id = u.id
            WHERE e.id = :id AND e.status = "published"
        ');
        $stmt->execute([':id' => $_GET['id']]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$event) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'event' => $event]);
        exit;
    }
    
    $where = ["e.status = 'published'"];
    $params = [];
    
    if (! empty($_GET['search'])) {
        $where[] = "(e.name LIKE :search OR e.location LIKE :search OR e.description LIKE :search)";
        $params[':search'] = '%' . $_GET['search'] . '%';
    }
    
    if (!empty($_GET['genre'])) {
        if (is_numeric($_GET['genre'])) {
            $where[] = "e.genre_id = :genre";
            $params[':genre'] = $_GET['genre'];
        } else {
            $where[] = "g.slug = :genre";
            $params[':genre'] = $_GET['genre'];
        }
    }
    
    if (!empty($_GET['date_from'])) {
        $where[] = "e.date >= :date_from";
        $params[':date_from'] = $_GET['date_from'];
    }
    
    if (!empty($_GET['date_to'])) {
        $where[] = "e.date <= :date_to";
        $params[':date_to'] = $_GET['date_to'];
    }
    
    if (isset($_GET['price_min'])) {
        $where[] = "e.price >= :price_min";
        $params[':price_min'] = $_GET['price_min'];
    }
    
    if (isset($_GET['price_max'])) {
        $where[] = "e. price <= :price_max";
        $params[':price_max'] = $_GET['price_max'];
    }
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 12;
    $offset = ($page - 1) * $limit;
    
    $sortField = $_GET['sort'] ?? 'date';
    $sortOrder = ($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $allowedSorts = ['date', 'price', 'name', 'created_at'];
    
    if (!in_array($sortField, $allowedSorts)) {
        $sortField = 'date';
    }
    
    $whereClause = implode(' AND ', $where);
    
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM events e
        LEFT JOIN genres g ON e. genre_id = g.id
        WHERE $whereClause
    ");
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $db->prepare("
        SELECT e.*, g.name as genre_name, g.slug as genre_slug, g.icon as genre_icon, u.name as owner_name
        FROM events e
        LEFT JOIN genres g ON e. genre_id = g.id
        LEFT JOIN users u ON e. owner_id = u.id
        WHERE $whereClause
        ORDER BY e.$sortField $sortOrder
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lat = isset($_GET['lat']) ?  floatval($_GET['lat']) : null;
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
    $radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 50;
    
    if ($lat !== null && $lng !== null) {
        $filtered = [];
        foreach ($events as $e) {
            if (isset($e['lat']) && isset($e['lng']) && $e['lat'] !== null && $e['lng'] !== null) {
                $d = distance_km($lat, $lng, floatval($e['lat']), floatval($e['lng']));
                if ($d <= $radius) {
                    $e['distance_km'] = round($d, 2);
                    $filtered[] = $e;
                }
            }
        }
        $events = $filtered;
        $total = count($filtered);
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>