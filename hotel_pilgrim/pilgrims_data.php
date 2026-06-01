<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/mg_cookie.php';

$master_group = mg_cookie_get();
$user         = get_authenticated_user();
$isAdmin      = $user && role_meets_requirement($user['role'] ?? '', 'admin');

// Gate: admins are allowed; otherwise a master_group cookie is required.
if (!$isAdmin && $master_group === '') {
    http_response_code(403);
    exit();
}

$term = isset($_GET['q']) ? (string)$_GET['q'] : '';
// Smart fuzzy search: collapse any internal whitespace and turn it into
// SQL wildcards, so typing "عبد المنعم" becomes the LIKE pattern
// "%عبد%المنعم%" and matches any row that contains those tokens in order
// across the same column (name / barcode / app_id / group).
$normalized = preg_replace('/\s+/u', '%', trim($term));
$searchTerm = '%' . $normalized . '%';

// Pagination (Select2 sends `page` starting at 1; serve 10 rows per page).
$pageSize = 10;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $pageSize;

// Build the scoping clause: admins see all; non-admins are limited to pilgrims
// whose `group` belongs to the logged-in master_group (one master_group → many groups).
$scopeSql = '';
$params   = [':term' => $searchTerm];
if (!$isAdmin) {
    $scopeSql = ' AND pilgrim."group" IN (SELECT "group" FROM "group" WHERE master_group = :mg) ';
    $params[':mg'] = $master_group;
}

$sql = '
    SELECT barcode, app_id AS passport, name, "group"
    FROM pilgrim
    WHERE (barcode LIKE :term OR app_id LIKE :term OR name LIKE :term OR "group" LIKE :term)
      ' . $scopeSql . '
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
    LIMIT :limit OFFSET :offset
';

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($results, JSON_UNESCAPED_UNICODE);
