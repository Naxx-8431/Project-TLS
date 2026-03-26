<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'project_tls');
define('DB_USER', 'root');
define('DB_PASS', '');
define('REMOVE_BG_API_KEY', 'K6j7D9dKTDQA1DUF72cgG2KQ');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('OUTPUT_DIR', __DIR__ . '/../output/');

define('MAX_FILE_SIZE', 10 * 1024 * 1024);

define('ALLOWED_IMAGE_TYPES', [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif'
]);
