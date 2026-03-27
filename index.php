<?php
// ==========================================
// CONFIGURATION
// ==========================================
define('REMOVE_BG_API_KEY', 'K6j7D9dKTDQA1DUF72cgG2KQ');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/output/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB

// ==========================================
// DIRECTORIES SETUP
// ==========================================
if (!is_dir(UPLOAD_DIR)) { mkdir(UPLOAD_DIR, 0777, true); }
if (!is_dir(OUTPUT_DIR)) { mkdir(OUTPUT_DIR, 0777, true); }

// ==========================================
// PDF GENERATOR CLASS
// ==========================================
class PdfGenerator
{
    private $fpdf;

    public function __construct()
    {
        require_once __DIR__ . '/fpdf/fpdf.php';
        $this->fpdf = new FPDF();
        $this->fpdf->SetAutoPageBreak(false);
    }

    private function convertToPng(string $imagePath): string
    {
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo === false || $imageInfo[2] !== IMAGETYPE_WEBP) {
            return $imagePath;
        }

        if (!function_exists('imagecreatefromwebp')) {
            throw new Exception("Your server's PHP installation does not support WebP image conversion. Please upload JPG or PNG images instead.");
        }

        $img = @imagecreatefromwebp($imagePath);
        if ($img === false) {
            return $imagePath;
        }

        $tmpPng = sys_get_temp_dir() . '/' . uniqid('fpdf_', true) . '.png';
        imagepng($img, $tmpPng);
        imagedestroy($img);
        return $tmpPng;
    }

    public function generateFromImages(array $imagePaths, string $outputPath, float $margin = 10): bool
    {
        $pageWidth = $this->fpdf->GetPageWidth();
        $pageHeight = $this->fpdf->GetPageHeight();
        $availableWidth = $pageWidth - ($margin * 2);
        $availableHeight = $pageHeight - ($margin * 2);

        foreach ($imagePaths as $originalImagePath) {
            $imagePath = $this->convertToPng($originalImagePath);
            $this->addImagePage($imagePath, $availableWidth, $availableHeight, $margin);

            if ($imagePath !== $originalImagePath && file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }
        $this->fpdf->Output('F', $outputPath);
        return true;
    }

    private function addImagePage(string $imagePath, float $availableWidth, float $availableHeight, float $margin)
    {
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo === false) {
            return;
        }

        $originalWidthPx = $imageInfo[0];
        $originalHeightPx = $imageInfo[1];
        
        // Approximate pixel to mm conversion
        $originalWidthMm = $originalWidthPx * 0.264583;
        $originalHeightMm = $originalHeightPx * 0.264583;

        $widthRatio = $availableWidth / $originalWidthMm;
        $heightRatio = $availableHeight / $originalHeightMm;

        $scaleFactor = min($widthRatio, $heightRatio);
        if ($scaleFactor > 1) {
            $scaleFactor = 1;
        }

        $scaledWidth = $originalWidthMm * $scaleFactor;
        $scaledHeight = $originalHeightMm * $scaleFactor;

        $x = $margin + ($availableWidth - $scaledWidth) / 2;
        $y = $margin + ($availableHeight - $scaledHeight) / 2;

        $imageType = '';
        if ($imageInfo[2] === IMAGETYPE_JPEG) $imageType = 'JPEG';
        elseif ($imageInfo[2] === IMAGETYPE_PNG) $imageType = 'PNG';
        elseif ($imageInfo[2] === IMAGETYPE_GIF) $imageType = 'GIF';

        $this->fpdf->AddPage();
        $this->fpdf->Image($imagePath, $x, $y, $scaledWidth, $scaledHeight, $imageType);
    }
}

// ==========================================
// API HANDLER LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $type = $_POST['type'] ?? '';

    // BACKGROUND REMOVER API
    if ($type === 'bg_remove') {
        $file = $_FILES['image'] ?? [];
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Valid image file not supplied']);
            exit;
        }

        $filename = uniqid('bg_') . '_' . basename($file['name']);
        $uploadPath = UPLOAD_DIR . $filename;
        $outputFilename = pathinfo($filename, PATHINFO_FILENAME) . '.png';
        $outputPath = OUTPUT_DIR . $outputFilename;

        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
            exit;
        }

        // Call Remove.bg API
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.remove.bg/v1.0/removebg',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'image_file' => new CURLFile($uploadPath),
                'size' => 'auto',
                'format' => 'png'
            ],
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . REMOVE_BG_API_KEY],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            @unlink($uploadPath);
            if (empty(REMOVE_BG_API_KEY)) {
                echo json_encode(['success' => false, 'error' => 'Remove.bg API key is missing in PHP code.']);
                exit;
            }
            echo json_encode(['success' => false, 'error' => 'Background removal API call failed (HTTP '.$httpCode.'). Check your API key.']);
            exit;
        }

        file_put_contents($outputPath, $response);
        @unlink($uploadPath);

        echo json_encode(['success' => true, 'download_url' => 'output/' . $outputFilename]);
        exit;
    }

    // PDF CONVERTER API
    if ($type === 'pdf_convert') {
        $files = $_FILES['images'] ?? [];
        if (empty($files) || count($files['name']) === 0) {
            echo json_encode(['success' => false, 'error' => 'No files uploaded']);
            exit;
        }

        $imagePaths = [];
        $totalFiles = count($files['name']);
        for ($i = 0; $i < $totalFiles; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $filename = uniqid('pdf_img_') . '_' . basename($files['name'][$i]);
                $path = UPLOAD_DIR . $filename;
                if (move_uploaded_file($files['tmp_name'][$i], $path)) {
                    $imagePaths[] = $path;
                }
            }
        }

        if (empty($imagePaths)) {
            echo json_encode(['success' => false, 'error' => 'Failed to upload images']);
            exit;
        }

        $outputFilename = 'document_' . time() . '.pdf';
        $outputPath = OUTPUT_DIR . $outputFilename;

        try {
            $generator = new PdfGenerator();
            $generator->generateFromImages($imagePaths, $outputPath);

            // Cleanup temp images
            foreach ($imagePaths as $path) {
                @unlink($path);
            }

            echo json_encode(['success' => true, 'download_url' => 'output/' . $outputFilename]);
        } catch (Exception $e) {
            // Cleanup temp images
            foreach ($imagePaths as $path) {
                @unlink($path);
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid Request']);
    exit;
}

// ==========================================
// HTML FRONTEND RENDERING (ONLY IF NOT POST)
// ==========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <title>Project TLS</title>
    <style>
        .bg-remover-hero {
            background: linear-gradient(160deg, var(--md-sys-color-primary-container) 0%, var(--md-sys-color-surface) 100%);
            background-size: cover;
            background-position: center;
            height: 80vh;
            text-align: center;
            position: relative;
            isolation: isolate;
            overflow: visible;
            border-radius: var(--md-sys-shape-corner-extra-large);
        }
        .bg-remover-hero::before {
            content: "";
            position: absolute;
            inset: -18px;
            border-radius: 36px;
            background: radial-gradient(60% 60% at 15% 20%, rgba(103, 80, 164, 0.25), transparent 70%),
                        radial-gradient(65% 65% at 85% 80%, rgba(125, 82, 96, 0.2), transparent 72%);
            filter: blur(16px); opacity: 0.9; z-index: -1;
        }
        .bg-remover-hero h1 { font-size: 2.5rem; font-weight: 700; }
        .bg-remover-hero p { font-size: 1.125rem; max-width: 500px; margin: 0 auto; }
        .upload-box { height: 80vh; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .drag-over { border-color: var(--md-sys-color-primary) !important; background: var(--md-sys-color-primary-container) !important; }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-light navigation">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#" data-target="home">Project TLS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" data-target="home" href="#">Home</a></li>
                    <li class="nav-item"><a class="nav-link" data-target="bg-remove" href="#">Remove Background</a></li>
                    <li class="nav-item"><a class="nav-link" data-target="pdf-convert" href="#">Convert to PDF</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Section: Home -->
    <div id="section-home" class="container mt-5 pt-3">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-4 m-3">
                <div class="card h-100">
                    <div class="card-img-top p-1"><img src="images/bg remover.png" class="img-fluid rounded-2"></div>
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Image Background Remover</h5>
                        <p class="card-text mb-4">Remove the background from your images with ease.</p>
                        <button class="btn button mt-auto" data-target="bg-remove">Remove Background</button>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4 m-3">
                <div class="card h-100">
                    <div class="card-img-top p-1"><img src="images/img to pdf.png" class="img-fluid rounded-2"></div>
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Image to PDF converter</h5>
                        <p class="card-text mb-4">Convert your images to PDF format with ease.</p>
                        <button class="btn button mt-auto" data-target="pdf-convert">Convert to PDF</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section: Background Remover -->
    <div id="section-bg-remove" class="container pt-5 mt-4 d-none">
        <div class="row g-4">
            <div class="col-12 col-md-6 d-flex flex-column justify-content-center align-items-center bg-remover-hero">
                <h1 class="fw-bold">Remove Image Background Instantly</h1>
                <p class="mt-3">Upload your image and get a clean, transparent background in seconds. No design skills needed.</p>
            </div>
            <div class="col-12 col-md-6">
                <!-- IMPORTANT: Form action is now index.php since all POST logic is inside this file -->
                <form id="bgUploadForm" action="index.php" method="POST" enctype="multipart/form-data" class="w-100">
                    <input type="hidden" name="type" value="bg_remove">
                    <div id="bgUploadBox" class="upload-box text-center p-5 align-items-center d-flex flex-column justify-content-center">
                        <i class="bi bi-cloud-upload upload-icon"></i>
                        <p class="mt-3">Drag & Drop your image here</p>
                        <p class="text-muted small">or click to browse</p>
                        <input type="file" name="image" id="bgImageInput" accept="image/*" hidden>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Section: PDF Converter -->
    <div id="section-pdf-convert" class="d-none">
        <div class="container text-center mt-5 pt-4">
            <h1>Convert Images to PDF Instantly</h1>
            <p class="w-50 mx-auto">Easily turn your images into high-quality PDF files in seconds. Upload, arrange, and convert with a fast, secure experience.</p>
        </div>
        <div class="container">
            <!-- IMPORTANT: Form action is technically handled by fetch to index.php -->
            <form id="pdfUploadForm" class="row justify-content-center mt-4" action="index.php" method="POST">
                <div class="col-12 col-md-8">
                    <div id="pdfUploadBox" class="upload-box text-center p-5 align-items-center d-flex flex-column justify-content-center">
                        <i class="bi bi-cloud-upload upload-icon"></i>
                        <p class="mt-3">Drag & Drop your images here</p>
                        <p class="text-muted small">or click to browse</p>
                        <input type="file" name="images[]" id="pdfImageInput" accept="image/*" multiple hidden>
                    </div>
                </div>
                <div class="col-12 col-md-8 text-center" id="pdfStatusContainer" style="display: none;">
                    <p id="pdfStatusText" class="mt-3 text-muted"></p>
                    <div id="pdfDownloadContainer"></div>
                </div>
                <div class="col-12 col-md-8">
                    <button type="button" id="pdfConvertBtn" class="btn button w-100 mt-3" disabled>Convert to PDF</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.js"></script>

    <!-- Navigation Script -->
    <script>
        const navLinks = document.querySelectorAll('[data-target]');
        const sections = {
            'home': document.getElementById('section-home'),
            'bg-remove': document.getElementById('section-bg-remove'),
            'pdf-convert': document.getElementById('section-pdf-convert')
        };
        function showSection(sectionId) {
            Object.values(sections).forEach(sec => sec.classList.add('d-none'));
            if (sections[sectionId]) sections[sectionId].classList.remove('d-none');
            document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
            document.querySelectorAll(`.nav-link[data-target="${sectionId}"]`).forEach(link => link.classList.add('active'));
            const navbarCollapse = document.getElementById('navbarNav');
            if (navbarCollapse && navbarCollapse.classList.contains('show')) navbarCollapse.classList.remove('show');
        }
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                const target = e.currentTarget.getAttribute('data-target');
                if (target) { e.preventDefault(); showSection(target); }
            });
        });
    </script>

    <!-- Background Remover Script -->
    <script>
        const bgUploadBox = document.getElementById('bgUploadBox');
        const bgFileInput = document.getElementById('bgImageInput');
        
        bgUploadBox.addEventListener('click', () => bgFileInput.click());
        bgUploadBox.addEventListener('dragover', (e) => { e.preventDefault(); bgUploadBox.classList.add('drag-over'); });
        bgUploadBox.addEventListener('dragleave', () => bgUploadBox.classList.remove('drag-over'));
        bgUploadBox.addEventListener('drop', (e) => {
            e.preventDefault(); bgUploadBox.classList.remove('drag-over');
            if (e.dataTransfer.files.length) { bgFileInput.files = e.dataTransfer.files; handleBgFileUpload(); }
        });
        bgFileInput.addEventListener('change', handleBgFileUpload);

        function handleBgFileUpload() {
            if (bgFileInput.files.length === 0) return;
            const file = bgFileInput.files[0];
            const icon = bgUploadBox.querySelector('.upload-icon');
            const text = bgUploadBox.querySelector('p:not(.text-muted)');
            const subtext = bgUploadBox.querySelector('.text-muted');

            icon.className = 'bi bi-hourglass-split upload-icon';
            icon.style.animation = 'spin 1s linear infinite';
            text.textContent = 'Processing...';
            subtext.textContent = 'Please wait... Note: This might take a few seconds';

            const formData = new FormData();
            formData.append('type', 'bg_remove');
            formData.append('image', file);

            // CHANGED: Form submits directly to index.php
            fetch('index.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    icon.className = 'bi bi-check-circle upload-icon';
                    icon.style.animation = 'none';
                    if (data.success) {
                        text.textContent = 'Background Removed!';
                        subtext.innerHTML = `<a href="${data.download_url}" class="btn md3-filled-button mt-2" download onclick="event.stopPropagation()">Download Image</a>`;
                    } else {
                        text.textContent = 'Processing Failed';
                        subtext.textContent = data.error || 'An error occurred';
                        icon.className = 'bi bi-exclamation-circle upload-icon';
                    }
                })
                .catch(error => {
                    text.textContent = 'Error';
                    subtext.textContent = 'Network error occurred';
                    icon.className = 'bi bi-exclamation-circle upload-icon';
                    icon.style.animation = 'none';
                });
        }
    </script>

    <!-- PDF Converter Script -->
    <script>
        const pdfUploadBox = document.getElementById('pdfUploadBox');
        const pdfFileInput = document.getElementById('pdfImageInput');
        const pdfConvertBtn = document.getElementById('pdfConvertBtn');
        const pdfIcon = pdfUploadBox.querySelector('.upload-icon');
        const pdfText = pdfUploadBox.querySelector('p:not(.text-muted)');
        const pdfSubtext = pdfUploadBox.querySelector('.text-muted');
        const pdfStatusContainer = document.getElementById('pdfStatusContainer');
        const pdfStatusText = document.getElementById('pdfStatusText');
        const pdfDownloadContainer = document.getElementById('pdfDownloadContainer');

        let pdfSelectedFiles = [];

        pdfUploadBox.addEventListener('click', () => pdfFileInput.click());
        pdfUploadBox.addEventListener('dragover', (e) => { e.preventDefault(); pdfUploadBox.classList.add('drag-over'); });
        pdfUploadBox.addEventListener('dragleave', () => pdfUploadBox.classList.remove('drag-over'));
        pdfUploadBox.addEventListener('drop', (e) => {
            e.preventDefault(); pdfUploadBox.classList.remove('drag-over');
            if (e.dataTransfer.files.length) handlePdfFilesSelection(e.dataTransfer.files);
        });
        pdfFileInput.addEventListener('change', () => { if (pdfFileInput.files.length) handlePdfFilesSelection(pdfFileInput.files); });

        function handlePdfFilesSelection(files) {
            pdfSelectedFiles = Array.from(files);
            pdfConvertBtn.disabled = pdfSelectedFiles.length === 0;

            if (pdfSelectedFiles.length > 0) {
                pdfIcon.className = 'bi bi-images upload-icon';
                pdfText.textContent = `${pdfSelectedFiles.length} image(s) selected`;
                pdfSubtext.textContent = 'Click to Add or Change Images';
            } else {
                pdfIcon.className = 'bi bi-cloud-upload upload-icon';
                pdfText.textContent = 'Drag & Drop your images here';
                pdfSubtext.textContent = 'or click to browse';
            }
            pdfStatusContainer.style.display = 'none';
            pdfDownloadContainer.innerHTML = '';
        }

        pdfConvertBtn.addEventListener('click', () => {
            if (pdfSelectedFiles.length === 0) return;
            pdfConvertBtn.disabled = true;
            pdfConvertBtn.textContent = 'Converting...';
            pdfIcon.className = 'bi bi-hourglass-split upload-icon';
            pdfIcon.style.animation = 'spin 1s linear infinite';
            pdfStatusContainer.style.display = 'block';
            pdfStatusText.textContent = 'Uploading and generating PDF...';
            pdfDownloadContainer.innerHTML = '';

            const formData = new FormData();
            formData.append('type', 'pdf_convert');
            pdfSelectedFiles.forEach(file => formData.append('images[]', file));

            // CHANGED: Form submits directly to index.php
            fetch('index.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    pdfIcon.className = 'bi bi-check-circle upload-icon';
                    pdfIcon.style.animation = 'none';
                    pdfConvertBtn.textContent = 'Convert to PDF';
                    if (data.success) {
                        pdfText.textContent = 'Success!';
                        pdfSubtext.textContent = 'Your PDF is ready.';
                        pdfStatusText.textContent = 'Conversion Successful!';
                        pdfDownloadContainer.innerHTML = `<a href="${data.download_url}" class="btn md3-filled-button mt-2" download>Download PDF</a>`;
                    } else {
                        pdfText.textContent = 'Conversion Failed';
                        pdfSubtext.textContent = 'See the error message below.';
                        pdfStatusText.textContent = 'Error: ' + (data.error || 'An error occurred during conversion.');
                        pdfIcon.className = 'bi bi-exclamation-circle upload-icon';
                    }
                })
                .catch(error => {
                    pdfText.textContent = 'Error';
                    pdfSubtext.textContent = 'Network or server error occurred.';
                    pdfStatusText.textContent = 'Please check console for details.';
                    pdfIcon.className = 'bi bi-exclamation-circle upload-icon';
                    pdfIcon.style.animation = 'none';
                    pdfConvertBtn.textContent = 'Convert to PDF';
                })
                .finally(() => { pdfConvertBtn.disabled = false; });
        });
    </script>
</body>
</html>
