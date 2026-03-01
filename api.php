<?php
/**
 * Jukebox API — Synology NAS backend
 * Compatible with PHP 7.2+
 *
 * SETUP: Edit MUSIC_DIR and API_KEYS below.
 */
// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('MUSIC_DIR',   '/volume1/music');
define('API_KEYS',    [
    '81fec16a75cfe311dbbb1266eaad74fbe50abab70975741c8f5c0f40cb44256e',  // user 1
    'd921474d3fb0e3bbd9877e071172d2fe92d10ad91a30490f7ad802ff18fe372c'   // user 2
]);
define('CACHE_FILE',  __DIR__ . '/.library-cache.json');
define('CACHE_TTL',   86400); // 24 hours
define('DEBUG',       false);
define('ART_CACHE_DIR', __DIR__ . '/artcache');
// Per-user files are derived at runtime from the API key — see userFile() below.
// HMAC_SECRET signs stream URLs — set to any long random string, never change it.
// Changing this invalidates all previously signed URLs.
define('HMAC_SECRET', 'dc74259ff20208260be142960d22a3e9d0ea69eae6fff066b162bfe2ec25f6d3');
// ──────────────────────────────────────────────────────────────────────────────
if (DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode(['error' => DEBUG ? $e->getMessage() : 'Server error']);
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
$allowedOrigins = [
    'https://music.jjjp.ca',
    'https://testmusic.jjjp.ca'
];
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
$action = isset($_GET['action']) ? $_GET['action'] : '';
// ── Auth — stream/art/album_stream use their own token validation ─────────────
// $userKey is set to the matched API key and used to derive per-user file paths.
$userKey = null;
if ($action !== 'stream' && $action !== 'art' && $action !== 'album_stream') {
    $key = '';
    if (isset($_SERVER['HTTP_X_API_KEY'])) $key = $_SERVER['HTTP_X_API_KEY'];
    elseif (isset($_GET['key']))           $key = $_GET['key'];
    if (!in_array($key, API_KEYS, true)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $userKey = $key;
}
// Returns a per-user file path by hashing the API key.
// Shared files (library cache, art) are not prefixed.
function userFile(string $name): string {
    global $userKey;
    $slug = substr(hash('crc32b', $userKey ?? 'shared'), 0, 8);
    return __DIR__ . '/.' . $name . '-' . $slug . '.json';
}
if      ($action === 'library')         handleLibrary();
elseif  ($action === 'sign')            handleSign();
elseif  ($action === 'sign_album')      handleSignAlbum();
elseif  ($action === 'stream')          handleStream();
elseif  ($action === 'album_stream')    handleAlbumStream();
elseif  ($action === 'art')             handleArt();
elseif  ($action === 'diag')            handleDiag();
elseif  ($action === 'get_userlib')     handleGetUserLib();
elseif  ($action === 'save_userlib')    handleSaveUserLib();
elseif  ($action === 'get_playlists')   handleGetPlaylists();
elseif  ($action === 'save_playlists')  handleSavePlaylists();
elseif  ($action === 'get_meta')        handleGetMeta();
elseif  ($action === 'save_meta')       handleSaveMeta();
elseif  ($action === 'nowplaying_get')  handleNowPlayingGet();
elseif  ($action === 'nowplaying_set')  handleNowPlayingSet();
elseif  ($action === 'albumart')        handleAlbumArt();
else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown action: ' . $action]);
}
// ─── DIAG ─────────────────────────────────────────────────────────────────────
function handleDiag() {
    header('Content-Type: application/json; charset=utf-8');
    $dir    = MUSIC_DIR;
    $report = [];
    $report['php_version']   = PHP_VERSION;
    $report['process_user']  = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : 'n/a';
    $report['process_uid']   = function_exists('posix_geteuid') ? posix_geteuid() : 'n/a';
    $report['path_tested']   = $dir;
    $report['path_exists']   = file_exists($dir);
    $report['is_dir']        = is_dir($dir);
    $report['is_readable']   = is_readable($dir);
    $report['is_executable'] = is_executable($dir);
    $stat = @stat($dir);
    if ($stat) {
        $report['stat_uid']  = $stat['uid'];
        $report['stat_gid']  = $stat['gid'];
        $report['stat_mode'] = substr(sprintf('%o', $stat['mode']), -4);
        if (function_exists('posix_getpwuid')) {
            $owner = posix_getpwuid($stat['uid']);
            $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid']) : null;
            $report['owner_name'] = $owner ? $owner['name'] : 'unknown';
            $report['group_name'] = $group ? $group['name'] : 'unknown';
        }
    } else {
        $report['stat'] = 'failed';
    }
    $dh = @opendir($dir);
    if ($dh) {
        $items = []; $count = 0;
        while (($entry = readdir($dh)) !== false && $count < 20) {
            if ($entry[0] === '.') continue;
            $full    = $dir . '/' . $entry;
            $items[] = ['name' => $entry, 'is_dir' => is_dir($full), 'readable' => is_readable($full)];
            $count++;
        }
        closedir($dh);
        $report['dir_listing'] = $items;
        $report['dir_open']    = true;
    } else {
        $report['dir_open']    = false;
        $report['dir_listing'] = [];
        $error = error_get_last();
        $report['dir_error']   = $error ? $error['message'] : 'unknown error';
    }
    $scan = @scandir($dir);
    $report['scandir_works'] = ($scan !== false);
    $report['scandir_count'] = $scan ? count($scan) - 2 : 0;
    $report['shell_exec_exists']   = function_exists('shell_exec');
    $disabledFns = array_map('trim', explode(',', ini_get('disable_functions')));
    $report['shell_exec_disabled'] = in_array('shell_exec', $disabledFns);
    $report['cache_dir_writable']  = is_writable(dirname(CACHE_FILE));
    $candidates = ['/usr/local/bin/ffprobe','/usr/bin/ffprobe','/opt/ffmpeg/bin/ffprobe','/opt/bin/ffprobe','/bin/ffprobe'];
    $foundAt = null;
    foreach ($candidates as $p) {
        if (@is_executable($p)) { $foundAt = $p; break; }
    }
    $whichOut = @shell_exec('which ffprobe 2>/dev/null');
    $report['ffprobe_found_at'] = $foundAt ?: ($whichOut ? trim($whichOut) : null);
    $fp = $report['ffprobe_found_at'];
    if ($fp) {
        $versionOut = @shell_exec(escapeshellcmd($fp) . ' -version 2>&1');
        $report['ffprobe_version'] = $versionOut ? substr(trim($versionOut), 0, 200) : 'no output';
        // Test on first audio file found
        $testFile  = null;
        $audioExts = ['mp3','m4a','aac','flac','ogg','wav'];
        try {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MUSIC_DIR, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if (in_array(strtolower($file->getExtension()), $audioExts)) { $testFile = $file->getPathname(); break; }
            }
        } catch (Exception $e) { $report['iterator_error'] = $e->getMessage(); }
        if ($testFile) {
            $report['ffprobe_test_file'] = $testFile;
            $out = ffprobeViaProcOpen($fp, $testFile);
            if ($out) {
                $parsed = json_decode($out, true);
                if ($parsed) {
                    $tags = isset($parsed['format']['tags']) ? array_change_key_case($parsed['format']['tags'], CASE_LOWER) : [];
                    $report['ffprobe_test_tags'] = [
                        'title'    => $tags['title']    ?? '(none)',
                        'artist'   => $tags['artist']   ?? '(none)',
                        'album'    => $tags['album']    ?? '(none)',
                        'duration' => $parsed['format']['duration'] ?? '(none)',
                    ];
                    $report['ffprobe_test_parsed'] = 'OK';
                } else {
                    $report['ffprobe_test_parsed'] = 'FAIL';
                }
            }
        } else {
            $report['ffprobe_test_file'] = 'no audio file found';
        }
        // Check for ffmpeg (needed for album_stream merge)
        $ffmpegCandidates = ['/usr/local/bin/ffmpeg','/usr/bin/ffmpeg','/opt/ffmpeg/bin/ffmpeg','/opt/bin/ffmpeg','/bin/ffmpeg'];
        $ffmpegFound = null;
        foreach ($ffmpegCandidates as $p) {
            if (@is_executable($p)) { $ffmpegFound = $p; break; }
        }
        if (!$ffmpegFound) {
            $whichFfmpeg = @shell_exec('which ffmpeg 2>/dev/null');
            if ($whichFfmpeg) $ffmpegFound = trim($whichFfmpeg);
        }
        $report['ffmpeg_found_at'] = $ffmpegFound;
    } else {
        $report['ffprobe_version'] = 'ffprobe not found';
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
// ─── SONG META ────────────────────────────────────────────────────────────────
function readMeta() {
    if (!file_exists(userFile('meta'))) return [];
    $raw = @file_get_contents(userFile('meta'));
    return $raw ? (json_decode($raw, true) ?: []) : [];
}
function writeMeta($data) {
    return @file_put_contents(userFile('meta'), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT, LOCK_EX));
}
function handleGetMeta() {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['meta' => readMeta()]);
}
function handleSaveMeta() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['meta'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }
    $existing = readMeta();
    foreach ($data['meta'] as $id => $m) {
        if (!isset($existing[$id])) $existing[$id] = [];
        foreach ($m as $k => $v) $existing[$id][$k] = $v;
    }
    if (writeMeta($existing) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write meta file']);
        return;
    }
    echo json_encode(['ok' => true]);
}
// ─── ALBUM ART (iTunes API proxy + disk cache) ────────────────────────────────
function handleAlbumArt() {
    header('Content-Type: application/json; charset=utf-8');
    $artist = isset($_GET['artist']) ? trim($_GET['artist']) : '';
    $album  = isset($_GET['album'])  ? trim($_GET['album'])  : '';
    if (!$artist && !$album) { echo json_encode(['url' => null]); return; }
    if (!is_dir(ART_CACHE_DIR)) @mkdir(ART_CACHE_DIR, 0775, true);
    $cacheKey  = preg_replace('/[^a-z0-9_-]/', '_', strtolower($artist . '_' . $album));
    $cachePath = ART_CACHE_DIR . '/' . $cacheKey . '.jpg';
    $metaPath  = ART_CACHE_DIR . '/' . $cacheKey . '.json';
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'];
    $basePath    = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $absoluteUrl = $protocol . '://' . $host . $basePath . '/artcache/' . $cacheKey . '.jpg';
    if (file_exists($cachePath) && file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        echo json_encode(['url' => $absoluteUrl, 'source' => 'cache', 'meta' => $meta]);
        return;
    }
    $apiUrl = 'https://itunes.apple.com/search?' . http_build_query([
        'term' => $artist . ' ' . $album, 'media' => 'music', 'entity' => 'album', 'limit' => 5, 'lang' => 'en_us',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Jukebox/1.0', 'ignore_errors' => true]]);
    $raw = @file_get_contents($apiUrl, false, $ctx);
    if (!$raw) { echo json_encode(['url' => null, 'error' => 'iTunes API unreachable']); return; }
    $results = json_decode($raw, true);
    if (!$results || empty($results['results'])) {
        @file_put_contents($metaPath, json_encode(['found' => false, 'ts' => time()]));
        echo json_encode(['url' => null, 'reason' => 'not found']);
        return;
    }
    $best = null;
    foreach ($results['results'] as $r) {
        if (!isset($r['artworkUrl100'])) continue;
        if (!$best) $best = $r;
        if ($album && stripos($r['collectionName'] ?? '', $album) !== false) { $best = $r; break; }
    }
    if (!$best || !isset($best['artworkUrl100'])) { echo json_encode(['url' => null, 'reason' => 'no artwork']); return; }
    $artUrl  = str_replace('100x100bb', '600x600bb', $best['artworkUrl100']);
    $imgData = @file_get_contents($artUrl, false, $ctx);
    if (!$imgData || strlen($imgData) < 1000) { echo json_encode(['url' => null, 'reason' => 'download failed']); return; }
    @file_put_contents($cachePath, $imgData);
    $meta = ['found' => true, 'ts' => time(), 'itunesArtist' => $best['artistName'] ?? '', 'itunesAlbum' => $best['collectionName'] ?? ''];
    @file_put_contents($metaPath, json_encode($meta));
    echo json_encode(['url' => $absoluteUrl, 'source' => 'itunes', 'meta' => $meta]);
}
// ─── PLAYLISTS ────────────────────────────────────────────────────────────────
// ─── USER LIBRARY ─────────────────────────────────────────────────────────────
// Stores a per-user set of song IDs with a metadata snapshot so songs remain
// visible (as "missing") even after they are removed from the catalog.
function handleGetUserLib() {
    header('Content-Type: application/json; charset=utf-8');
    $f = userFile('userlib');
    if (!file_exists($f)) { echo json_encode(['ids' => new stdClass()]); return; }
    $raw = @file_get_contents($f);
    $data = $raw ? (json_decode($raw, true) ?: ['ids' => []]) : ['ids' => []];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function handleSaveUserLib() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['ids']) || !is_array($data['ids'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }
    // Sanitise each entry
    $clean = [];
    foreach ($data['ids'] as $id => $snap) {
        if (!is_string($id) || !is_array($snap)) continue;
        $clean[(string)$id] = [
            'title'   => isset($snap['title'])   ? substr((string)$snap['title'],   0, 300) : '',
            'artist'  => isset($snap['artist'])  ? substr((string)$snap['artist'],  0, 200) : '',
            'album'   => isset($snap['album'])   ? substr((string)$snap['album'],   0, 200) : '',
            'added'   => isset($snap['added'])   ? (int)$snap['added']                      : time(),
        ];
    }
    $out = json_encode(['ids' => $clean, 'saved' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents(userFile('userlib'), $out, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write userlib file']);
        return;
    }
    echo json_encode(['ok' => true, 'count' => count($clean)]);
}
function handleGetPlaylists() {
    header('Content-Type: application/json; charset=utf-8');
    if (!file_exists(userFile('playlists'))) { echo json_encode(['playlists' => [], 'updated' => 0]); return; }
    $raw = @file_get_contents(userFile('playlists'));
    if ($raw === false) { http_response_code(500); echo json_encode(['error' => 'Could not read playlists file']); return; }
    $data = json_decode($raw, true) ?: ['playlists' => []];
    $data['updated'] = filemtime(userFile('playlists'));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function handleSavePlaylists() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['playlists']) || !is_array($data['playlists'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }
    $clean = [];
    foreach ($data['playlists'] as $pl) {
        if (!isset($pl['id']) || !isset($pl['name'])) continue;
        $clean[] = [
            'id'      => (string)$pl['id'],
            'name'    => substr(trim((string)$pl['name']), 0, 200),
            'sids'    => isset($pl['sids']) && is_array($pl['sids']) ? array_values(array_map('strval', $pl['sids'])) : [],
            'created' => isset($pl['created']) ? (int)$pl['created'] : time(),
            'updated' => time(),
        ];
    }
    $out = json_encode(['playlists' => $clean, 'saved' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents(userFile('playlists'), $out, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write playlists file']);
        return;
    }
    echo json_encode(['ok' => true, 'count' => count($clean)]);
}
// ─── SIGN ─────────────────────────────────────────────────────────────────────
function handleSign() {
    header('Content-Type: application/json');
    $path    = isset($_GET['path']) ? $_GET['path'] : '';
    $expires = time() + 14400;
    $token   = hash_hmac('sha256', $path . ':' . $expires, HMAC_SECRET);
    echo json_encode(['token' => $token, 'expires' => $expires]);
}
// ─── SIGN ALBUM (server-side merge) ──────────────────────────────────────────
// Signs a list of song paths for a single merged album stream.
// The client sends an array of paths; we return a token that authorises
// album_stream to concatenate and serve them as one continuous audio file.
function handleSignAlbum() {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['paths']) || !is_array($data['paths']) || !count($data['paths'])) {
        http_response_code(400);
        echo json_encode(['error' => 'paths array required']);
        return;
    }
    $paths   = array_values(array_filter(array_map('strval', $data['paths'])));
    $expires = time() + 14400;
    // Token covers the sorted canonical path list so order cannot be tampered with
    $payload = implode('|', $paths) . ':' . $expires;
    $token   = hash_hmac('sha256', $payload, HMAC_SECRET);
    echo json_encode(['token' => $token, 'expires' => $expires, 'paths' => $paths]);
}
// ─── ALBUM STREAM (ffmpeg concat, gapless) ────────────────────────────────────
// Streams multiple tracks as a single concatenated audio file via ffmpeg.
// Supports two modes:
//   format=aac  — copy M4A/AAC audio into a fragmented MP4 (no re-encode, gapless)
//   format=opus — re-encode to Opus in an OGG container
//   format=mp3  — re-encode to MP3 (wide device support, fallback)
//
// The client passes:
//   token, expires — HMAC from sign_album
//   paths[]        — ordered list of relative song paths (URL-encoded)
//   format         — "aac" (default), "opus", or "mp3"
//
// For MP3 inputs we use CBR + Accept-Ranges: bytes so that if Synology's nginx
// drops the connection mid-stream (timeout/buffer limit), Safari's retry Range
// request can seek ffmpeg to the correct album position instead of restarting
// from track 1. For AAC/M4A we keep Accept-Ranges: none (fragmented MP4 byte
// offsets don't map linearly to timestamps).
function handleAlbumStream() {
    // Allow this script to run as long as the album takes to stream
    set_time_limit(0);
    // Stop ffmpeg if client disconnects (saves CPU)
    ignore_user_abort(false);

    $token   = isset($_GET['token'])   ? $_GET['token']   : '';
    $expires = isset($_GET['expires']) ? (int)$_GET['expires'] : 0;
    if (time() > $expires) { http_response_code(403); echo 'Token expired'; exit; }

    // Collect paths[] array from query string
    $paths = isset($_GET['paths']) ? (array)$_GET['paths'] : [];
    if (!$paths) { http_response_code(400); echo 'paths required'; exit; }
    $paths = array_values(array_filter(array_map('strval', $paths)));

    // Verify HMAC
    $payload  = implode('|', $paths) . ':' . $expires;
    $expected = hash_hmac('sha256', $payload, HMAC_SECRET);
    if (!hash_equals($expected, $token)) { http_response_code(403); echo 'Invalid token'; exit; }

    $base = realpath(MUSIC_DIR);
    if (!$base) { http_response_code(500); echo 'Music dir not found'; exit; }

    // Resolve and validate every path
    $fullPaths = [];
    foreach ($paths as $p) {
        $full = realpath($base . '/' . ltrim($p, '/'));
        if (!$full || strpos($full, $base) !== 0 || !is_file($full)) {
            http_response_code(404);
            echo 'Not found: ' . $p;
            exit;
        }
        $fullPaths[] = $full;
    }

    // Find ffmpeg
    static $ffmpeg = null;
    if ($ffmpeg === null) {
        $ffmpeg = false;
        foreach (['/volume1/music/ffmpeg/ffmpeg','/usr/local/bin/ffmpeg','/usr/bin/ffmpeg','/opt/ffmpeg/bin/ffmpeg','/opt/bin/ffmpeg','/bin/ffmpeg'] as $c) {
            if (@is_executable($c)) { $ffmpeg = $c; break; }
        }
        if (!$ffmpeg) {
            $w = @shell_exec('which ffmpeg 2>/dev/null');
            if ($w) $ffmpeg = trim($w);
        }
    }
    if (!$ffmpeg) { http_response_code(500); echo 'ffmpeg not available'; exit; }

    // Find ffprobe (used on the MP3 path to sum track durations for Content-Length)
    static $ffprobe = null;
    if ($ffprobe === null) {
        $ffprobe = false;
        foreach (['/volume1/music/ffmpeg/ffprobe','/usr/local/bin/ffprobe','/usr/bin/ffprobe','/opt/ffmpeg/bin/ffprobe','/opt/bin/ffprobe','/bin/ffprobe'] as $c) {
            if (@is_executable($c)) { $ffprobe = $c; break; }
        }
        if (!$ffprobe) { $w = @shell_exec('which ffprobe 2>/dev/null'); if ($w) $ffprobe = trim($w); }
    }

    $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'aac';
    if (!in_array($format, ['aac','opus','mp3'])) $format = 'aac';

    // Build a temp concat list file
    $listFile = tempnam(sys_get_temp_dir(), 'jbcat_');
    $listContent = '';
    foreach ($fullPaths as $fp) {
        // ffmpeg concat demuxer requires "file 'path'" with single-quoted paths,
        // with internal single-quotes escaped as '\''
        $escaped = str_replace("'", "'\\''", $fp);
        $listContent .= "file '" . $escaped . "'\n";
    }
    file_put_contents($listFile, $listContent);

    // Auto-detect input format from first file extension
    $inputExt   = strtolower(pathinfo($fullPaths[0], PATHINFO_EXTENSION));
    $allSameExt = count(array_unique(array_map(function($p) {
        return strtolower(pathinfo($p, PATHINFO_EXTENSION));
    }, $fullPaths))) === 1;

    // ── Per-format encoding args + range support ─────────────────────────────
    $startByte    = 0;
    $seekSec      = 0.0;
    $totalBytes   = 0;
    $rangeSupport = false;

    if ($format === 'aac' && in_array($inputExt, ['m4a','aac']) && $allSameExt) {
        // Stream-copy into fragmented MP4 — Accept-Ranges: none because byte
        // offsets don't map predictably to timestamps in a fragmented MP4.
        $mime = 'audio/mp4';
        $args = [
            $ffmpeg,
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-map', '0:a', '-c', 'copy',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
            '-f', 'mp4', 'pipe:1',
        ];

    } elseif ($format === 'opus') {
        $mime = 'audio/ogg';
        $args = [
            $ffmpeg,
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-vn', '-c:a', 'libopus', '-b:a', '160k',
            '-application', 'audio', '-f', 'ogg', 'pipe:1',
        ];

    } else {
        // ── MP3: CBR + Accept-Ranges: bytes ──────────────────────────────────
        // CBR makes byte_offset ≈ time × (bitrate/8), so when Synology's nginx
        // drops the connection mid-stream and Safari retries with Range: bytes=N-,
        // we can seek ffmpeg to the right album position instead of track 1.
        $mime = 'audio/mpeg';
        $cbr  = '192k';
        $bps  = 192000; // must match $cbr

        // Sum track durations via ffprobe for Content-Length
        $totalDuration = 0.0;
        if ($ffprobe) {
            foreach ($fullPaths as $fp) {
                $out = @shell_exec(
                    escapeshellarg($ffprobe) .
                    ' -v quiet -select_streams a:0' .
                    ' -show_entries stream=duration' .
                    ' -of default=nw=1:nk=1 ' .
                    escapeshellarg($fp) . ' 2>/dev/null'
                );
                if (is_numeric(trim((string)$out))) {
                    $totalDuration += (float)trim($out);
                }
            }
        }

        // Parse Range header — byte offset → seek timestamp
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-/', $_SERVER['HTTP_RANGE'], $m)) {
            $startByte = (int)$m[1];
            $seekSec   = max(0.0, $startByte / ($bps / 8));
        }

        // CBR: total ≈ duration × bitrate/8  (accurate to ±0.1%)
        $totalBytes   = ($totalDuration > 0) ? (int)($totalDuration * $bps / 8) : 0;
        $rangeSupport = ($totalBytes > 0);

        // -ss before -f concat so ffmpeg seeks the concat demuxer directly
        // (jumps to the right file — no full re-decode from the beginning)
        $seekArgs = ($seekSec > 0) ? ['-ss', sprintf('%.3f', $seekSec)] : [];
        $args = array_merge(
            [$ffmpeg],
            $seekArgs,
            [
                '-f', 'concat', '-safe', '0', '-i', $listFile,
                '-vn',
                '-c:a', 'libmp3lame', '-b:a', $cbr,
                '-write_xing', '0',    // suppress VBR Xing header (avoids Safari
                '-id3v2_version', '0', //   miscalculating duration from header fields)
                '-f', 'mp3', 'pipe:1',
            ]
        );
    }

    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_clean();

    // Stream ffmpeg output directly to client
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'], // stderr captured for error reporting
    ];
    $proc = @proc_open($args, $desc, $pipes);
    if (!$proc) { @unlink($listFile); http_response_code(500); echo 'ffmpeg launch failed'; exit; }
    fclose($pipes[0]);

    // Read a small amount first — if ffmpeg fails immediately, stderr will have output
    // before stdout gets anything, so we can catch and report the error
    stream_set_blocking($pipes[2], false);
    stream_set_blocking($pipes[1], false);

    // Give ffmpeg a moment to fail (or start producing output)
    usleep(300000); // 300ms
    $errOut     = stream_get_contents($pipes[2]);
    $firstChunk = stream_get_contents($pipes[1]);

    if (($firstChunk === '' || $firstChunk === false) && $errOut) {
        // ffmpeg failed before producing any output — return error as plain text
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($listFile);
        header('Content-Type: text/plain');
        http_response_code(500);
        echo "ffmpeg error:\n" . $errOut;
        exit;
    }

    // ── Response headers ─────────────────────────────────────────────────────
    header("Content-Type: $mime");
    if ($rangeSupport) {
        // MP3/CBR: tell Safari it can make range requests. When Synology's nginx
        // drops the connection mid-stream, Safari retries with Range: bytes=N-
        // and we seek ffmpeg to the right position — not back to track 1.
        header('Accept-Ranges: bytes');
        $remaining = max(0, $totalBytes - $startByte);
        header("Content-Length: $remaining");
        if ($startByte > 0) {
            http_response_code(206);
            header("Content-Range: bytes $startByte-" . ($totalBytes - 1) . "/$totalBytes");
        }
    } else {
        header('Accept-Ranges: none');
    }

    if ($firstChunk) { echo $firstChunk; flush(); }

    stream_set_blocking($pipes[1], true);
    while (!feof($pipes[1]) && !connection_aborted()) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
    }

    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    @unlink($listFile);
}
// ─── LIBRARY — serve cache (TTL 24h), rebuild on expiry ──────────────────────
function handleLibrary() {
    header('Content-Type: application/json; charset=utf-8');
    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
        $etag = '"' . md5_file(CACHE_FILE) . '"';
        header('ETag: ' . $etag);
        if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '') === $etag) {
            http_response_code(304); exit;
        }
        readfile(CACHE_FILE);
        return;
    }
    if (!is_dir(MUSIC_DIR)) {
        http_response_code(500);
        echo json_encode(['error' => 'MUSIC_DIR not found: ' . MUSIC_DIR]);
        return;
    }
    @set_time_limit(300);
    $songs = doScanDir(MUSIC_DIR);
    usort($songs, function($a, $b) {
        return strcasecmp($a['artist'] . $a['title'], $b['artist'] . $b['title']);
    });
    foreach ($songs as &$s) {
        $s['id'] = hash('crc32b', $s['path']);
    }
    unset($s);
    $json = json_encode(
        ['songs' => $songs, 'count' => count($songs), 'generated' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    @file_put_contents(CACHE_FILE, $json);
    header('ETag: "' . md5($json) . '"');
    echo $json;
}
function doScanDir($dir, $base = '') {
    $exts  = ['mp3','m4a','aac','flac','ogg','wav','opus','aiff','wma'];
    $songs = [];
    $items = @scandir($dir);
    if (!$items) return $songs;
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $full = $dir . '/' . $item;
        $rel  = $base ? $base . '/' . $item : $item;
        if (is_dir($full)) {
            $songs = array_merge($songs, doScanDir($full, $rel));
            continue;
        }
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts)) continue;
        $songs[] = extractMeta($full, $rel);
    }
    return $songs;
}
function extractMeta($path, $rel) {
    $meta = tryFFprobe($path);
    if (!$meta) $meta = parseFilename(pathinfo($path, PATHINFO_FILENAME));
    $meta['path']     = $rel;
    $meta['ext']      = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $meta['size']     = (int)@filesize($path);
    $meta['modified'] = (int)@filemtime($path);
    $meta['hasArt']   = false;
    $meta['artFile']  = '';
    $dir = dirname($path);
    foreach (['cover.jpg','cover.png','folder.jpg','folder.png','artwork.jpg'] as $f) {
        if (file_exists($dir . '/' . $f)) {
            $meta['hasArt']  = true;
            $meta['artFile'] = dirname($rel) . '/' . $f;
            break;
        }
    }
    return $meta;
}
function tryFFprobe($path) {
    static $fp = null;
    if ($fp === null) {
        $fp = false;
        foreach (['/usr/local/bin/ffprobe','/usr/bin/ffprobe','/opt/ffmpeg/bin/ffprobe','/opt/bin/ffprobe','/bin/ffprobe'] as $p) {
            if (@is_executable($p)) { $fp = $p; break; }
        }
        if (!$fp) { $w = @shell_exec('which ffprobe 2>/dev/null'); if ($w) $fp = trim($w); }
    }
    if (!$fp) return null;
    // Write path to temp file to avoid shell escaping issues with unicode filenames
    $tmp = tempnam(sys_get_temp_dir(), 'jbprobe_');
    if (!$tmp) return null;
    file_put_contents($tmp, $path);
    $cmd = 'cat ' . escapeshellarg($tmp) . ' | xargs -d "\n" ' . escapeshellarg($fp) . ' -v quiet -print_format json -show_format -show_streams 2>/dev/null';
    $out = @shell_exec($cmd);
    @unlink($tmp);
    if (!$out || trim($out) === '' || trim($out) === '{}') {
        $out = ffprobeViaProcOpen($fp, $path);
    }
    if (!$out || trim($out) === '' || trim($out) === '{}') return null;
    $d = json_decode($out, true);
    if (!$d) return null;
    $tags = [];
    if (isset($d['format']['tags']))
        $tags = array_change_key_case($d['format']['tags'], CASE_LOWER);
    if (empty($tags) && isset($d['streams'])) {
        foreach ($d['streams'] as $stream) {
            if (isset($stream['tags'])) { $tags = array_change_key_case($stream['tags'], CASE_LOWER); break; }
        }
    }
    $duration = 0;
    if (isset($d['format']['duration']))         $duration = round((float)$d['format']['duration'], 2);
    elseif (isset($d['streams'][0]['duration'])) $duration = round((float)$d['streams'][0]['duration'], 2);
    $trackParts  = explode('/', isset($tags['track']) ? $tags['track'] : '0');
    $trackNum    = (int)$trackParts[0];
    $totalTracks = isset($trackParts[1]) ? (int)$trackParts[1] : 0;
    $discRaw    = isset($tags['disc']) ? $tags['disc'] : (isset($tags['discnumber']) ? $tags['discnumber'] : '1');
    $discParts  = explode('/', $discRaw);
    $discNum    = max(1, (int)$discParts[0]);
    $totalDiscs = isset($discParts[1]) ? (int)$discParts[1] : 1;
    $year    = 0;
    $dateRaw = isset($tags['date']) ? $tags['date'] : (isset($tags['year']) ? $tags['year'] : '');
    if ($dateRaw) $year = (int)substr(trim($dateRaw), 0, 4);
    $albumArtist = trim(isset($tags['album_artist'])   ? $tags['album_artist']
                 : (isset($tags['albumartist'])         ? $tags['albumartist']
                 : (isset($tags['album artist'])        ? $tags['album artist']
                 : (isset($tags['albumartist'])         ? $tags['albumartist'] : ''))));
    // ReplayGain
    $rgTrack = null; $rgAlbum = null;
    foreach (['rg_track' => 'replaygain_track_gain', 'rg_album' => 'replaygain_album_gain'] as $out => $key) {
        if (isset($tags[$key])) {
            $n = (float)preg_replace('/[^0-9.\-+]/', '', $tags[$key]);
            if ($n !== 0.0 || strpos($tags[$key], '0') !== false) $$out = $n;
        }
    }
    // Sample rate
    $sampleRate = 44100;
    $codecName  = '';
    $bitrate    = 0;
    $channels   = 2;
    if (isset($d['streams'])) {
        foreach ($d['streams'] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                if (isset($stream['sample_rate'])) $sampleRate = (int)$stream['sample_rate'];
                if (isset($stream['codec_name']))  $codecName  = strtolower($stream['codec_name']);
                if (isset($stream['bit_rate']))    $bitrate    = (int)$stream['bit_rate'];
                if (isset($stream['channels']))    $channels   = (int)$stream['channels'];
                break;
            }
        }
    }
    // Fall back to container bit_rate if stream didn't have one
    if (!$bitrate && isset($d['format']['bit_rate'])) {
        $bitrate = (int)$d['format']['bit_rate'];
    }
    // Encoder delay/padding — used for server-side gapless merge trimming
    $encoderDelay = null; $encoderPadding = null;
    $smpbRaw = isset($tags['itunsmpb']) ? $tags['itunsmpb'] : (isset($tags['itunes_gapless']) ? $tags['itunes_gapless'] : null);
    if ($smpbRaw !== null) {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($smpbRaw)), function($p) { return $p !== ''; }));
        if (count($parts) >= 3) {
            $delay   = hexdec($parts[1]);
            $padding = hexdec($parts[2]);
            if ($delay   > 0 && $delay   < 10000) $encoderDelay   = (int)$delay;
            if ($padding > 0 && $padding < 10000) $encoderPadding = (int)$padding;
        }
    }
    if ($encoderDelay === null && isset($d['streams'])) {
        foreach ($d['streams'] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                if (isset($stream['encoder_delay']))   { $v = (int)$stream['encoder_delay'];   if ($v > 0 && $v < 10000) $encoderDelay   = $v; }
                if (isset($stream['encoder_padding'])) { $v = (int)$stream['encoder_padding']; if ($v > 0 && $v < 10000) $encoderPadding = $v; }
                break;
            }
        }
    }
    // Derive library section from path prefix
    $section = 'albums';
    $pathLower = strtolower($path);
    if (strpos($pathLower, '/singles/') !== false || strpos($pathLower, '\\singles\\') !== false)             $section = 'singles';
    elseif (strpos($pathLower, '/compilations/') !== false || strpos($pathLower, '\\compilations\\') !== false) $section = 'compilations';
    return [
        'title'           => trim(isset($tags['title'])  ? $tags['title']  : ''),
        'artist'          => trim(isset($tags['artist']) ? $tags['artist'] : $albumArtist),
        'album_artist'    => $albumArtist,
        'section'         => $section,
        'album'           => trim(isset($tags['album'])  ? $tags['album']  : ''),
        'year'            => $year,
        'track'           => $trackNum,
        'total_tracks'    => $totalTracks,
        'disc'            => $discNum,
        'total_discs'     => $totalDiscs,
        'genre'           => trim(isset($tags['genre'])  ? $tags['genre']  : ''),
        'composer'        => trim(isset($tags['composer']) ? $tags['composer'] : ''),
        'bpm'             => isset($tags['bpm']) ? (int)$tags['bpm'] : (isset($tags['tbpm']) ? (int)$tags['tbpm'] : 0),
        'isrc'            => trim(isset($tags['isrc']) ? $tags['isrc'] : ''),
        'duration'        => $duration,
        'sample_rate'     => $sampleRate,
        'channels'        => $channels,
        'codec'           => $codecName,
        'bitrate'         => $bitrate,
        'rg_track'        => $rgTrack,
        'rg_album'        => $rgAlbum,
        'encoder_delay'   => $encoderDelay,
        'encoder_padding' => $encoderPadding,
    ];
}
function ffprobeViaProcOpen($ffprobe, $path) {
    $cmd  = [$ffprobe, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path];
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!$proc) return null;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return $out ?: null;
}
function parseFilename($name) {
    if (preg_match('/^(.+?)\s*[-\x{2013}]\s*(.+)$/u', $name, $m))
        return ['artist'=>trim($m[1]),'album_artist'=>'','title'=>trim($m[2]),'album'=>'','year'=>0,'track'=>0,'disc'=>1,'genre'=>'','duration'=>0,'sample_rate'=>44100,'codec'=>'','bitrate'=>0,'rg_track'=>null,'rg_album'=>null,'encoder_delay'=>null,'encoder_padding'=>null];
    $clean = preg_replace('/^\d+[\.\s\-]+/', '', $name);
    return ['artist'=>'','album_artist'=>'','title'=>trim($clean?:$name),'album'=>'','year'=>0,'track'=>0,'disc'=>1,'genre'=>'','duration'=>0,'sample_rate'=>44100,'codec'=>'','bitrate'=>0,'rg_track'=>null,'rg_album'=>null,'encoder_delay'=>null,'encoder_padding'=>null];
}
// ─── STREAM ───────────────────────────────────────────────────────────────────
function handleStream() {
    $token   = isset($_GET['token'])   ? $_GET['token']   : '';
    $path    = isset($_GET['path'])    ? $_GET['path']    : '';
    $expires = isset($_GET['expires']) ? (int)$_GET['expires'] : 0;
    if (time() > $expires) { http_response_code(403); echo 'Token expired'; exit; }
    $expected = hash_hmac('sha256', $path . ':' . $expires, HMAC_SECRET);
    if (!hash_equals($expected, $token)) { http_response_code(403); echo 'Invalid token'; exit; }
    $base = realpath(MUSIC_DIR);
    if (!$base) { http_response_code(500); echo 'Music dir not found'; exit; }
    $full = realpath($base . '/' . ltrim($path, '/'));
    if (!$full || strpos($full, $base) !== 0 || !is_file($full)) { http_response_code(404); echo 'Not found'; exit; }
    $ext     = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimeMap = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','aac'=>'audio/aac','flac'=>'audio/flac',
                'ogg'=>'audio/ogg','wav'=>'audio/wav','opus'=>'audio/ogg','aiff'=>'audio/aiff','wma'=>'audio/x-ms-wma'];
    $mime    = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
    $size    = filesize($full);
    $start   = 0;
    $end     = $size - 1;
    if (!empty($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
        $start = ($m[1] !== '') ? (int)$m[1] : 0;
        $end   = ($m[2] !== '') ? min((int)$m[2], $size - 1) : $size - 1;
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    }
    $length = $end - $start + 1;
    header("Content-Type: $mime");
    header("Content-Length: $length");
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    $fp = fopen($full, 'rb');
    fseek($fp, $start);
    $left = $length;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(65536, $left));
        echo $chunk;
        $left -= strlen($chunk);
        if (ob_get_level()) ob_flush();
        flush();
    }
    fclose($fp);
}
// ─── ART ──────────────────────────────────────────────────────────────────────
function handleArt() {
    $base = realpath(MUSIC_DIR);
    $full = realpath($base . '/' . ltrim(isset($_GET['path']) ? $_GET['path'] : '', '/'));
    if (!$full || strpos($full, $base) !== 0 || !is_file($full)) { http_response_code(404); exit; }
    $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
    header("Content-Type: $mime");
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($full));
    readfile($full);
}
