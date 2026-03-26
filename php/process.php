<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';


function jsonResponse($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getUserId(): int
{
    $sessionId = session_id();
    
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare('SELECT id FROM users WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $user = $stmt->fetch();
        
        if ($user) {
            return (int)$user['id'];
        }
        
        $stmt = $pdo->prepare('INSERT INTO users (session_id) VALUES (?)');
        $stmt->execute([$sessionId]);
        
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        return 1;
    }
}

function getConversionHistory(int $limit = 50): array
{
    try {
        $pdo = getDbConnection();
        $userId = getUserId();
        
        $stmt = $pdo->prepare('
            SELECT id, conversion_type, original_filename, file_size, status, created_at, completed_at 
            FROM conversions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        return [];
    }
}

function logConversion(string $type, string $originalFile, string $outputPath, int $fileSize, string $status = 'pending', ?string $error = null): ?int
{
    try {
        $pdo = getDbConnection();
        $userId = getUserId();
        
        $stmt = $pdo->prepare('
            INSERT INTO conversions (user_id, conversion_type, original_filename, original_path, output_path, file_size, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $userId,
            $type,
            $originalFile,
            UPLOAD_DIR . $originalFile,
            $outputPath,
            $fileSize,
            $status,
            $error
        ]);
        
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        return null;
    }
}

function updateConversionStatus(?int $id, string $status, ?string $outputPath = null, ?string $error = null): bool
{
    if ($id === null) {
        return false;
    }

    try {
        $pdo = getDbConnection();
        
        if ($status === 'completed') {
            $stmt = $pdo->prepare('UPDATE conversions SET status = ?, output_path = ?, completed_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $outputPath, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE conversions SET status = ?, error_message = ? WHERE id = ?');
            $stmt->execute([$status, $error, $id]);
        }

        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        return false;
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'history':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            jsonResponse([
                'success' => true,
                'data' => getConversionHistory($limit)
            ]);
            break;

        case 'status':
            if (!isset($_GET['id'])) {
                jsonResponse(['success' => false, 'error' => 'Missing conversion ID'], 400);
            }
            
            try {
                $pdo = getDbConnection();
                $userId = getUserId();
                
                $stmt = $pdo->prepare('
                    SELECT id, conversion_type, status, output_path, error_message, completed_at 
                    FROM conversions 
                    WHERE id = ? AND user_id = ?
                ');
                $stmt->execute([(int)$_GET['id'], $userId]);
                $conversion = $stmt->fetch();
                
                if (!$conversion) {
                    jsonResponse(['success' => false, 'error' => 'Conversion not found'], 404);
                }
                
                jsonResponse([
                    'success' => true,
                    'data' => $conversion
                ]);
            } catch (PDOException $e) {
                jsonResponse(['success' => false, 'error' => 'Database error'], 500);
            }
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
}
