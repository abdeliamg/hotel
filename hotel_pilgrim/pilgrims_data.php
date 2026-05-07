<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get the search term from the AJAX request
$term = isset($_GET['q']) ? $_GET['q'] : '';

// Prepare and execute the query to search for barcode, name, or group in the pilgrim table
$stmt = $pdo->prepare("SELECT barcode, app_id as passport, name, `group` FROM pilgrim WHERE app_id LIKE :term OR name LIKE :term OR `group` LIKE :term LIMIT 10");
$searchTerm = "%$term%";
$stmt->bindParam(':term', $searchTerm);
$stmt->execute();

// Fetch the results and return them as JSON
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results);
