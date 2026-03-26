<?php
// Show all errors directly in browser
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>PHP Version: " . phpversion() . "</h2>";
echo "<h3>Testing require chain...</h3>";

try {
    echo "Loading config.php... ";
    require_once __DIR__ . '/config.php';
    echo "OK<br>";

    echo "Loading db.php... ";
    require_once __DIR__ . '/db.php';
    echo "OK<br>";

    echo "Loading process.php... ";
    require_once __DIR__ . '/process.php';
    echo "OK<br>";

    echo "Loading PdfGenerator.php... ";
    require_once __DIR__ . '/PdfGenerator.php';
    echo "OK<br>";

    echo "<br><strong style='color:green'>All files loaded successfully!</strong>";
} catch (Throwable $e) {
    echo "<strong style='color:red'>FATAL: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "</strong>";
}
