<?php
// db.php - PDO connection and simple env loader

if (file_exists(__DIR__.'/.env')) {
    $lines = file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if (!getenv($k)) putenv("$k=$v");
        }
    }
}

$dbHost = getenv('DB_HOST') ?: 'sql313.infinityfree.com';
$dbName = getenv('DB_NAME') ?: 'if0_39741603_dlink_network';
$dbUser = getenv('DB_USER') ?: 'if0_39741603';
$dbPass = getenv('DB_PASS') ?: 'mkala3771';

$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}
