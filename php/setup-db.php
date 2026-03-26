<?php

require_once __DIR__ . '/config.php';

try {
    // Connect to MySQL server without specifying a database
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Read the SQL setup file
    $sqlFilePath = __DIR__ . '/setup.sql';
    if (!file_exists($sqlFilePath)) {
        die("Error: setup.sql file not found.");
    }
    
    $sql = file_get_contents($sqlFilePath);

    // Execute the SQL queries
    $pdo->exec($sql);

    echo "<h1>Database Setup Successful!</h1>";
    echo "<p>The SQL database 'project_tls' and all tables have been created successfully.</p>";
    echo "<p>You can now close this tab and continue using the PDF Converter.</p>";

} catch (PDOException $e) {
    echo "<h1>Database Setup Failed</h1>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Are you sure MySQL is running in your control panel?</p>";
}
