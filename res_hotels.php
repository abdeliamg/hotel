<?php
require_once __DIR__ . '/check.php';
// Database connection setup
require_once __DIR__ . '/includes/db.php';

// Get search query from the request (q) and page number for pagination
$searchTerm = isset($_GET['q']) ? $_GET['q'] : '';  // The search term entered by the user
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;  // Page number for pagination
$limit = 30;  // Limit number of results per page
$offset = ($page - 1) * $limit;  // Calculate offset based on page

// Prepare SQL query to search for hotel names in the database
$query = "SELECT id, hotel_name FROM hotel WHERE hotel_name LIKE :searchTerm LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
$stmt->bindValue(':searchTerm', '%' . $searchTerm . '%');
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

// Execute query
$stmt->execute();

// Fetch results from database
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare response for Select2
$response = [
    'results' => [],  // This will contain the items (hotel names)
    'pagination' => [
        'more' => count($hotels) === $limit  // Determine if there are more results
    ]
];

// Loop through fetched data and format it for Select2
foreach ($hotels as $hotel) {
    $response['results'][] = [
        'id' => $hotel['hotel_name'],  // Unique identifier for the option
        'text' => $hotel['hotel_name'],  // Text to display in the dropdown
    ];
}

// Return the JSON response to Select2
header('Content-Type: application/json');
echo json_encode($response);
?>
