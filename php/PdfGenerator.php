<?php
require_once __DIR__ . '/../fpdf/fpdf.php';

class PdfGenerator
{
    private $fpdf;
    private string $orientation;
    private string $unit;
    private string $format;

    public function __construct(string $orientation = 'P', string $unit = 'mm', string $format = 'A4')
    {
        $this->orientation = $orientation;
        $this->unit = $unit;
        $this->format = $format;
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
            return $imagePath; // fallback, will fail gracefully in FPDF
        }

        $tmpPng = sys_get_temp_dir() . '/' . uniqid('fpdf_', true) . '.png';
        imagepng($img, $tmpPng);
        imagedestroy($img);
        return $tmpPng;
    }

    public function generateFromImages(array $imagePaths, string $outputPath): bool
    {
        if (empty($imagePaths)) {
            return false;
        }

        if (!class_exists('FPDF')) {
            error_log('FPDF class not found. Please ensure fpdf.php is properly included.');
            return false;
        }

        $this->fpdf = new FPDF($this->orientation, $this->unit, $this->format);
        $convertedPaths = [];

        foreach ($imagePaths as $imagePath) {
            if (!file_exists($imagePath)) {
                error_log('Image not found: ' . $imagePath);
                continue;
            }

            $processPath = $this->convertToPng($imagePath);
            $convertedPaths[] = $processPath;
            $this->addImagePage($processPath);
        }

        // Clean up temp files
        foreach ($convertedPaths as $p) {
            if ($p !== $imagePaths[array_search($p, $convertedPaths) ?? -1] ?? '' && file_exists($p) && strpos($p, sys_get_temp_dir()) === 0) {
                @unlink($p);
            }
        }

        if (!isset($this->fpdf)) {
            return false;
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $result = $this->fpdf->Output('F', $outputPath);
        return $result !== false;
    }

    private function addImagePage(string $imagePath): void
    {
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo === false) {
            return;
        }

        $imageWidth = $imageInfo[0];
        $imageHeight = $imageInfo[1];

        $pageWidth = $this->format === 'A4' ? 210 : 297;
        $pageHeight = $this->format === 'A4' ? 297 : 210;
        $margin = 10;

        $availableWidth = $pageWidth - (2 * $margin);
        $availableHeight = $pageHeight - (2 * $margin);

        if ($this->orientation === 'L') {
            $temp = $availableWidth;
            $availableWidth = $availableHeight;
            $availableHeight = $temp;
        }

        $scale = min($availableWidth / $imageWidth, $availableHeight / $imageHeight);

        $scaledWidth = $imageWidth * $scale;
        $scaledHeight = $imageHeight * $scale;

        $x = $margin + ($availableWidth - $scaledWidth) / 2;
        $y = $margin + ($availableHeight - $scaledHeight) / 2;

        $imageType = '';
        if ($imageInfo[2] === IMAGETYPE_JPEG) $imageType = 'JPEG';
        elseif ($imageInfo[2] === IMAGETYPE_PNG) $imageType = 'PNG';
        elseif ($imageInfo[2] === IMAGETYPE_GIF) $imageType = 'GIF';

        $this->fpdf->AddPage();
        $this->fpdf->Image($imagePath, $x, $y, $scaledWidth, $scaledHeight, $imageType);
    }

    public function generateFromImagesCustomSize(array $imagePaths, string $outputPath, float $pageWidth, float $pageHeight, float $margin = 5): bool
    {
        if (empty($imagePaths)) {
            return false;
        }

        if (!class_exists('FPDF')) {
            error_log('FPDF class not found. Please ensure fpdf.php is properly included.');
            return false;
        }

        $this->fpdf = new FPDF('P', 'mm', [0, 0]);
        $this->fpdf->setAutoPageBreak(false);

        foreach ($imagePaths as $imagePath) {
            if (!file_exists($imagePath)) {
                continue;
            }

            $imageInfo = @getimagesize($imagePath);
            if ($imageInfo === false) {
                continue;
            }

            $imageWidth = $imageInfo[0];
            $imageHeight = $imageInfo[1];

            $availableWidth = $pageWidth - (2 * $margin);
            $availableHeight = $pageHeight - (2 * $margin);

            $scale = min($availableWidth / $imageWidth, $availableHeight / $imageHeight);

            $scaledWidth = $imageWidth * $scale;
            $scaledHeight = $imageHeight * $scale;

            $x = $margin + ($availableWidth - $scaledWidth) / 2;
            $y = $margin + ($availableHeight - $scaledHeight) / 2;

            $imageType = '';
            if ($imageInfo[2] === IMAGETYPE_JPEG) $imageType = 'JPEG';
            elseif ($imageInfo[2] === IMAGETYPE_PNG) $imageType = 'PNG';
            elseif ($imageInfo[2] === IMAGETYPE_GIF) $imageType = 'GIF';

            $this->fpdf->AddPage([$pageWidth, $pageHeight]);
            $this->fpdf->Image($imagePath, $x, $y, $scaledWidth, $scaledHeight, $imageType);
        }

        if (!isset($this->fpdf)) {
            return false;
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $result = $this->fpdf->Output('F', $outputPath);
        return $result !== false;
    }
}
