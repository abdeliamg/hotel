<?php
require_once __DIR__ . '/../includes/auth.php';

$master_group = trim((string)($_COOKIE['master_group'] ?? ''));
$user = get_authenticated_user();

if ($master_group === '' && !$user) {
    http_response_code(403);
    exit();
}

// Get the search term from the AJAX request
$term = isset($_GET['q']) ? $_GET['q'] : '';

// Prepare and execute the query to search for barcode, name, or group in the pilgrim table
$stmt = $pdo->prepare("
    SELECT barcode, app_id as passport, name, `group`
    FROM pilgrim
    WHERE (barcode LIKE :term OR app_id LIKE :term OR name LIKE :term OR `group` LIKE :term)
      AND NOT EXISTS (
          SELECT 1
          FROM pilgrim_flight pf
          WHERE pf.barcode = pilgrim.barcode
      )
      AND NOT EXISTS (
          SELECT 1
          FROM hotel_pilgrim hp
          WHERE hp.barcode = pilgrim.barcode
      )
    ORDER BY name COLLATE NOCASE ASC
    LIMIT 10
");
$searchTerm = "%$term%";
$stmt->bindParam(':term', $searchTerm);
$stmt->execute();

// Fetch the results and return them as JSON
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($results);
