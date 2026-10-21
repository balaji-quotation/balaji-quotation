<?php
declare(strict_types=1);

/**
 * Store optional company logos outside source control.
 * Returns a web path under /quotation/uploads/company-logos/.
 */
function storeCompanyLogoUpload(array $file): ?string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The company logo upload failed.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 2 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Company logo must be a valid image under 2 MB.');
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        throw new RuntimeException('Choose a valid PNG, JPEG, or WebP image.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    $extensions = [
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
        'image/webp' => '.webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Choose a valid PNG, JPEG, or WebP image.');
    }

    $directory = __DIR__ . '/../uploads/company-logos';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Company logo storage is unavailable.');
    }

    $filename = 'company-' . bin2hex(random_bytes(16)) . $extensions[$mime];
    if (!move_uploaded_file($tmpPath, $directory . '/' . $filename)) {
        throw new RuntimeException('The company logo could not be stored.');
    }

    return '/quotation/uploads/company-logos/' . $filename;
}

function deleteCompanyLogo(?string $path): void
{
    if (!$path || !preg_match('#^/quotation/uploads/company-logos/[A-Za-z0-9._-]+$#D', $path)) {
        return;
    }
    $filename = basename($path);
    $fullPath = __DIR__ . '/../uploads/company-logos/' . $filename;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
