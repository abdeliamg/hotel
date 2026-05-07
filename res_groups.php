<?php
require_once __DIR__ . '/check.php';
// Ensure database connection is set up
require_once __DIR__ . '/includes/db.php';

// Get the search term and page number from the request
$searchTerm = isset($_GET['q']) ? $_GET['q'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;  // Default page is 1
$pageSize = 30;  // Number of results per page
$offset = ($page - 1) * $pageSize;  // Calculate the offset based on the page number

// Prepare the SQL query to fetch group data based on the search term with pagination
$query = "SELECT `master_group` as id, (`group` || ' | ' || `master_group`) as text 
          FROM `group` 
          WHERE `group` LIKE :searchTerm OR `master_group` LIKE :searchTerm
          ORDER BY master_group
          LIMIT :limit OFFSET :offset";

// Prepare the statement and bind the search term and pagination values
$stmt = $pdo->prepare($query);
$searchTermLike = '%' . $searchTerm . '%';  // Add wildcards for LIKE search
$stmt->bindValue(':searchTerm', $searchTermLike);
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

// Fetch the results
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count the total number of matching records (for pagination)
$countQuery = "SELECT COUNT(*) as total 
               FROM `group` 
               WHERE `group` LIKE :searchTerm OR `master_group` LIKE :searchTerm";
$countStmt = $pdo->prepare($countQuery);
$countStmt->bindValue(':searchTerm', $searchTermLike);
$countStmt->execute();
$totalCount = $countStmt->fetchColumn();

// Prepare the response in the format Select2 expects
$response = [
    'results' => [],
    'pagination' => [
        'more' => ($offset + $pageSize) < $totalCount  // If there are more pages
    ]
];

// Format the response with group data
foreach ($groups as $group) {
    $response['results'][] = [
        'id' => $group['id'],  // Group ID
        'text' => $group['text']  // Group and master group information to display in dropdown
    ];
}

// Return the JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
