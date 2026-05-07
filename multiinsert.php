<?php
require_once __DIR__ . '/check.php';
// Set the path to the SQLite database file located in the same folder
$db_path = 'hajj_data.db';

// Check if the form is submitted via AJAX
if (isset($_POST['sql_query'])) {
    $sql_query = $_POST['sql_query'];

    try {
        // Create a new PDO instance to connect to the SQLite database
        $pdo = new PDO("sqlite:" . $db_path);
        
        // Set error mode to exceptions to catch any issues
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Execute the SQL query
        $pdo->exec($sql_query);
        
        // Return a success message
        echo "SQL query executed successfully!";
    } catch (PDOException $e) {
        // Handle any errors that occur during the connection or query execution
        echo "Error: " . $e->getMessage();
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Execute SQL</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        textarea {
            width: 100%;
            height: 300px;
            margin-bottom: 10px;
            font-family: monospace;
        }
        button {
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <h1>Execute SQL Query</h1>
  <h2><a href="https://tableconvert.com/excel-to-sql" target="_blank">open converter</a></h2>
    
    <form id="sql-form">
        <textarea id="sql-query" name="sql_query" placeholder="Enter your SQL query here..."></textarea><br>
        <button type="button" id="execute-btn">Execute SQL</button>
    </form>

    <div id="result-message" style="margin-top: 20px;"></div>

    <script>
        $(document).ready(function() {
            $('#execute-btn').click(function() {
                // Get the SQL query from the textarea
                var sqlQuery = $('#sql-query').val();

                // Send the SQL query via AJAX
                $.ajax({
                    url: '', // Same page
                    type: 'POST',
                    data: { sql_query: sqlQuery },
                    success: function(response) {
                        $('#result-message').text(response); // Display success message
                    },
                    error: function(xhr, status, error) {
                        $('#result-message').text("Error: " + error); // Display error message
                    }
                });
            });
        });
    </script>
</body>
</html>
