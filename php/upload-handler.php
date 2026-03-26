<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    file_put_contents(__DIR__ . '/error.txt', "PHP Error: $errstr in $errfile on line $errline\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => "PHP Error: $errstr"]);
    exit;
});

set_exception_handler(function($exception) {
    file_put_contents(__DIR__ . '/error.txt', "PHP Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString(), FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => "PHP Exception: " . $exception->getMessage()]);
    exit;
});

// Auto-heal the broken FPDF library
$fpdfPath = __DIR__ . '/../fpdf/fpdf.php';
if (file_exists($fpdfPath) && strpos(file_get_contents($fpdfPath), 'Minimal Image Support') !== false) {
    $realFpdf = file_get_contents('https://raw.githubusercontent.com/Setasign/FPDF/master/fpdf.php');
    if ($realFpdf) {
        file_put_contents($fpdfPath, $realFpdf);
    }
}


require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process.php';

header('Content-Type: application/json');

function validateImageUpload(array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Upload failed with error code: ' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['valid' => false, 'error' => 'File size exceeds maximum limit of 10MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return ['valid' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF'];
    }

    return ['valid' => true, 'mimeType' => $mimeType];
}

function generateUniqueFilename(string $originalName, string $prefix = ''): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
    
    return ($prefix ? $prefix . '_' : '') . $safeBaseName . '_' . uniqid() . '.' . $extension;
}

function ensureDirectories(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(OUTPUT_DIR)) {
        mkdir(OUTPUT_DIR, 0755, true);
    }
}

function removeBackgroundWithAPI(string $imagePath, string $outputPath): bool
{
    if (empty(REMOVE_BG_API_KEY)) {
        error_log('Remove.bg API key not configured');
        return false;
    }

    $imageData = file_get_contents($imagePath);
    if ($imageData === false) {
        return false;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.remove.bg/v1.0/removebg',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image_file' => new CURLFile($imagePath),
            'size' => 'auto',
            'format' => 'png'
        ],
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . REMOVE_BG_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log('Remove.bg API error: ' . $error . ' (HTTP ' . $httpCode . ')');
        return false;
    }

    return file_put_contents($outputPath, $response) !== false;
}

function processBackgroundRemoval(array $file): array
{
    $validation = validateImageUpload($file);
    if (!$validation['valid']) {
        return ['success' => false, 'error' => $validation['error']];
    }

    ensureDirectories();

    $filename = generateUniqueFilename($file['name'], 'bg');
    $uploadPath = UPLOAD_DIR . $filename;
    $outputFilename = pathinfo($filename, PATHINFO_FILENAME) . '.png';
    $outputPath = OUTPUT_DIR . $outputFilename;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file'];
    }

    $conversionId = logConversion('background_remove', $filename, $outputPath, $file['size'], 'processing');

    if (!removeBackgroundWithAPI($uploadPath, $outputPath)) {
        updateConversionStatus($conversionId, 'failed', null, 'Background removal failed');
        @unlink($uploadPath);
        
        if (empty(REMOVE_BG_API_KEY)) {
            return ['success' => false, 'error' => 'Remove.bg API key is missing. Please configure it in php/config.php'];
        }
        return ['success' => false, 'error' => 'Background removal API call failed.'];
    }
    
    updateConversionStatus($conversionId, 'completed', $outputPath);

    @unlink($uploadPath);

    return [
        'success' => true,
        'conversion_id' => $conversionId,
        'output_file' => $outputFilename,
        'download_url' => 'output/' . $outputFilename
    ];
}

function processPdfConversion(array $files): array
{
    if (empty($files) || count($files) === 0) {
        return ['success' => false, 'error' => 'No files uploaded'];
    }

    ensureDirectories();

    require_once __DIR__ . '/PdfGenerator.php';

    $uploadedFiles = [];
    $tempPaths = [];

    foreach ($files['tmp_name'] as $index => $tmpName) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $validation = validateImageUpload([
            'name' => $files['name'][$index],
            'tmp_name' => $tmpName,
            'size' => $files['size'][$index],
            'error' => $files['error'][$index]
        ]);

        if (!$validation['valid']) {
            continue;
        }

        $filename = generateUniqueFilename($files['name'][$index], 'pdf');
        $uploadPath = UPLOAD_DIR . $filename;

        if (move_uploaded_file($tmpName, $uploadPath)) {
            $uploadedFiles[] = [
                'name' => $filename,
                'path' => $uploadPath,
                'size' => $files['size'][$index]
            ];
            $tempPaths[] = $uploadPath;
        }
    }

    if (empty($uploadedFiles)) {
        return ['success' => false, 'error' => 'No valid files were uploaded'];
    }

    $totalSize = array_sum(array_column($uploadedFiles, 'size'));
    $originalNames = implode(', ', array_column($uploadedFiles, 'name'));

    $outputFilename = 'converted_' . date('Ymd_His') . '.pdf';
    $outputPath = OUTPUT_DIR . $outputFilename;

    $conversionId = logConversion('pdf_convert', $originalNames, $outputPath, $totalSize, 'processing');

    $imagePaths = array_column($uploadedFiles, 'path');
    $pdfGenerator = new PdfGenerator();
    $success = $pdfGenerator->generateFromImages($imagePaths, $outputPath);

    foreach ($tempPaths as $path) {
        @unlink($path);
    }

    if (!$success) {
        updateConversionStatus($conversionId, 'failed', null, 'PDF generation failed');
        return ['success' => false, 'error' => 'Failed to generate PDF'];
    }

    updateConversionStatus($conversionId, 'completed', $outputPath);

    return [
        'success' => true,
        'conversion_id' => $conversionId,
        'output_file' => $outputFilename,
        'download_url' => 'output/' . $outputFilename
    ];
}

$type = $_POST['type'] ?? '';

switch ($type) {
    case 'bg_remove':
        if (empty($_FILES['image'])) {
            jsonResponse(['success' => false, 'error' => 'No image file provided'], 400);
        }
        $result = processBackgroundRemoval($_FILES['image']);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    case 'pdf_convert':
        if (empty($_FILES['images'])) {
            jsonResponse(['success' => false, 'error' => 'No image files provided'], 400);
        }
        $result = processPdfConversion($_FILES['images']);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Invalid conversion type'], 400);
}
