<?php
/**
 * YouTube Transcript API
 * POST { url: "https://youtube.com/watch?v=..." }
 * → Extracts video ID, fetches transcript, returns plain text
 */
header('Content-Type: application/json');
require_once __DIR__ . '/auth_middleware.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = trim($input['url'] ?? '');

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required']);
    exit;
}

// Extract video ID from various YouTube URL formats
$videoId = null;
$patterns = [
    '/(?:youtube\.com\/watch\?.*v=)([a-zA-Z0-9_-]{11})/',
    '/(?:youtu\.be\/)([a-zA-Z0-9_-]{11})/',
    '/(?:youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
    '/(?:youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
    '/(?:youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/',
];

foreach ($patterns as $pattern) {
    if (preg_match($pattern, $url, $matches)) {
        $videoId = $matches[1];
        break;
    }
}

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not extract video ID from URL. Please use a valid YouTube URL.']);
    exit;
}

try {
    $transcript = null;
    $title = null;

    // Method 1: Fetch the YouTube page and extract captions data
    $pageUrl = "https://www.youtube.com/watch?v=" . urlencode($videoId);
    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                        "Accept-Language: en-US,en;q=0.9\r\n",
            'timeout' => 15,
        ]
    ]);

    $pageHtml = @file_get_contents($pageUrl, false, $ctx);

    if ($pageHtml) {
        // Extract video title
        if (preg_match('/<title>([^<]+)<\/title>/', $pageHtml, $titleMatch)) {
            $title = html_entity_decode(preg_replace('/\s*-\s*YouTube$/', '', $titleMatch[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract captions track URL from page data
        if (preg_match('/"captionTracks":\s*(\[.*?\])/', $pageHtml, $captionMatch)) {
            $captionTracks = json_decode($captionMatch[1], true);

            if (!empty($captionTracks)) {
                // Prefer English, then auto-generated English, then first available
                $trackUrl = null;
                foreach ($captionTracks as $track) {
                    $lang = $track['languageCode'] ?? '';
                    if ($lang === 'en' && strpos($track['name']['simpleText'] ?? '', 'auto') === false) {
                        $trackUrl = $track['baseUrl'] ?? null;
                        break;
                    }
                }
                if (!$trackUrl) {
                    foreach ($captionTracks as $track) {
                        if (($track['languageCode'] ?? '') === 'en') {
                            $trackUrl = $track['baseUrl'] ?? null;
                            break;
                        }
                    }
                }
                if (!$trackUrl && !empty($captionTracks[0]['baseUrl'])) {
                    $trackUrl = $captionTracks[0]['baseUrl'];
                }

                if ($trackUrl) {
                    // Fetch the XML caption data
                    $xmlData = @file_get_contents($trackUrl, false, $ctx);
                    if ($xmlData) {
                        $transcript = parseTimedTextXml($xmlData);
                    }
                }
            }
        }
    }

    // Method 2: Try yt-dlp if available and Method 1 failed
    if (!$transcript) {
        $ytdlp = findYtDlp();
        if ($ytdlp) {
            $transcript = fetchWithYtDlp($ytdlp, $videoId, $title);
        }
    }

    if (!$transcript) {
        http_response_code(422);
        echo json_encode([
            'error' => 'Could not fetch transcript. The video may not have captions available, or it might be private/restricted.',
            'video_id' => $videoId,
            'title' => $title,
        ]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'transcript' => $transcript,
        'title'      => $title ?: "YouTube Video ($videoId)",
        'video_id'   => $videoId,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch transcript: ' . $e->getMessage()]);
}

// ─── Helper Functions ─────────────────────────────────

function parseTimedTextXml($xml) {
    // Suppress XML errors
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();

    if (!$doc) return null;

    $lines = [];
    foreach ($doc->text as $node) {
        $text = trim(html_entity_decode((string)$node, ENT_QUOTES, 'UTF-8'));
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        if ($text !== '') {
            $lines[] = $text;
        }
    }

    if (empty($lines)) return null;

    // Join into paragraphs (group every ~5 lines for readability)
    $paragraphs = [];
    $chunk = [];
    foreach ($lines as $i => $line) {
        $chunk[] = $line;
        if (count($chunk) >= 5 || $i === count($lines) - 1) {
            $paragraphs[] = implode(' ', $chunk);
            $chunk = [];
        }
    }

    return implode("\n\n", $paragraphs);
}

function findYtDlp() {
    // Check common locations
    $paths = ['yt-dlp', 'yt-dlp.exe'];
    foreach ($paths as $path) {
        $check = shell_exec("where $path 2>NUL") ?: shell_exec("which $path 2>/dev/null");
        if ($check && trim($check)) return trim($check);
    }
    return null;
}

function fetchWithYtDlp($ytdlp, $videoId, &$title) {
    $tmpDir = sys_get_temp_dir() . '/yt_' . $videoId;
    @mkdir($tmpDir, 0777, true);

    $url = "https://www.youtube.com/watch?v=" . escapeshellarg($videoId);

    // Get subtitles
    $cmd = escapeshellarg($ytdlp) . " --write-auto-sub --sub-lang en --sub-format vtt --skip-download --no-playlist -o " .
           escapeshellarg($tmpDir . '/%(id)s') . " " . $url . " 2>&1";

    shell_exec($cmd);

    // Find the VTT file
    $vttFiles = glob($tmpDir . '/*.vtt');
    if (empty($vttFiles)) {
        // Cleanup
        array_map('unlink', glob($tmpDir . '/*'));
        @rmdir($tmpDir);
        return null;
    }

    $vttContent = file_get_contents($vttFiles[0]);

    // Parse VTT
    $lines = [];
    $vttLines = explode("\n", $vttContent);
    foreach ($vttLines as $line) {
        $line = trim($line);
        // Skip headers, timestamps, and empty lines
        if ($line === '' || $line === 'WEBVTT' || preg_match('/^\d{2}:\d{2}/', $line) || preg_match('/^Kind:/', $line) || preg_match('/^Language:/', $line)) {
            continue;
        }
        // Remove VTT tags
        $line = preg_replace('/<[^>]+>/', '', $line);
        $line = trim($line);
        if ($line !== '' && !in_array($line, $lines)) {
            $lines[] = $line;
        }
    }

    // Cleanup temp files
    array_map('unlink', glob($tmpDir . '/*'));
    @rmdir($tmpDir);

    if (empty($lines)) return null;

    // Join into paragraphs
    $paragraphs = [];
    $chunk = [];
    foreach ($lines as $i => $line) {
        $chunk[] = $line;
        if (count($chunk) >= 5 || $i === count($lines) - 1) {
            $paragraphs[] = implode(' ', $chunk);
            $chunk = [];
        }
    }

    return implode("\n\n", $paragraphs);
}
