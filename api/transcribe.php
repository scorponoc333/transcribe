<?php
/**
 * Whisper CLI Transcription Backend
 * Receives audio file upload, runs Whisper locally, returns transcript
 */

set_time_limit(600);
header('Content-Type: application/json');
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

$uploadDir = __DIR__ . '/../uploads/';
$maxSize = 200 * 1024 * 1024;
$allowedExts = ['mp3', 'm4a', 'mp4', 'wav'];

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'No audio file provided';
    if (isset($_FILES['audio'])) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit. Increase upload_max_filesize in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
        ];
        $code = $_FILES['audio']['error'];
        $errorMsg = $uploadErrors[$code] ?? "Upload error code: $code";
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['audio'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)]);
    exit;
}

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum: 200MB']);
    exit;
}

$model = isset($_POST['model']) ? $_POST['model'] : 'turbo';
$validModels = ['tiny', 'base', 'small', 'medium', 'large', 'turbo'];
if (!in_array($model, $validModels)) {
    $model = 'turbo';
}

$uniqueName = uniqid('audio_', true) . '.' . $ext;
$filePath = $uploadDir . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

// Paths to dependencies
$pythonPath = 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python313\\python.exe';
$whisperPath = 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python313\\Scripts\\whisper.EXE';
$ffmpegDir = 'C:\\Users\\User\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-8.0.1-full_build\\bin';

// Check if whisper is available
if (!file_exists($whisperPath)) {
    @unlink($filePath);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Whisper CLI is not installed. Install with: pip install openai-whisper',
        'setup_required' => true
    ]);
    exit;
}

// Build and execute Whisper command with ffmpeg in PATH
$escapedPath = escapeshellarg($filePath);
$escapedOutput = escapeshellarg(rtrim($uploadDir, '/\\'));
$escapedWhisper = escapeshellarg($whisperPath);
$command = "set PATH=$ffmpegDir;%PATH% && $escapedWhisper $escapedPath --model $model --output_format txt --output_dir $escapedOutput --fp16 False 2>&1";

$startTime = microtime(true);
exec($command, $output, $returnCode);
$duration = round(microtime(true) - $startTime, 2);

// The output file has the same name as input but with .txt extension
$baseName = pathinfo($uniqueName, PATHINFO_FILENAME);
$outputFile = $uploadDir . $baseName . '.txt';

if ($returnCode !== 0 || !file_exists($outputFile)) {
    @unlink($filePath);
    @unlink($outputFile);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Transcription failed',
        'details' => implode("\n", $output),
        'return_code' => $returnCode
    ]);
    exit;
}

$transcript = trim(file_get_contents($outputFile));

// Clean up all generated files
@unlink($filePath);
@unlink($outputFile);
// Whisper might also generate .srt, .vtt, .json, .tsv files
foreach (['srt', 'vtt', 'json', 'tsv'] as $format) {
    @unlink($uploadDir . $baseName . '.' . $format);
}

echo json_encode([
    'success' => true,
    'transcript' => $transcript,
    'model' => $model,
    'filename' => $file['name'],
    'processing_time' => $duration,
    'word_count' => str_word_count($transcript),
    'char_count' => strlen($transcript)
]);
