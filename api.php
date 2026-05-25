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
    'd921474d3fb0e3bbd9877e071172d2fe92d10ad91a30490f7ad802ff18fe372c',   // user 2
    'a48044e99683cf2c12d3d2b5a34170e9294e8cc8b0addf0348275ece0d9d6288',//jska
    '69584b6e2abaae294e2da00568edebfe9d03a12331a87aebb97b7290e21deb5c'//jesse
]);
define('AUTH_DB_PATH', '/volume3/web/jjjp.ca/src/posts.db');
define('CACHE_FILE',       __DIR__ . '/.library-cache.json');
define('FINGERPRINT_FILE', __DIR__ . '/.library-fingerprint');
define('SONG_CACHE_FILE',  __DIR__ . '/.song-meta-cache.json');
define('TAG_OVERRIDES_FILE', __DIR__ . '/.tag-overrides.json');
define('CACHE_TTL',        86400); // 24 hours
define('DEBUG',       false);
define('ART_CACHE_DIR', __DIR__ . '/artcache');
define('HLS_DIR',       __DIR__ . '/hls_sessions');
define('HLS_MAX_AGE',   3600); // clean up HLS sessions older than 1 hour
// Per-user files are derived at runtime from the API key — see userFile() below.
// HMAC_SECRET signs stream URLs — set to any long random string, never change it.
// Changing this invalidates all previously signed URLs.
define('HMAC_SECRET', 'dc74259ff20208260be142960d22a3e9d0ea69eae6fff066b162bfe2ec25f6d3');
// ── LAST.FM ──────────────────────────────────────────────────────────────────
// Server-side Last.fm API key. Powers the "Similar Artists" panel in the
// Catalogue and the "Play Songs Like This" queue builder for every user.
// Get a key at https://www.last.fm/api/account/create — paste it here.
// Leave as empty string to disable Last.fm features (clients fall back to
// the local heuristic for "Play Songs Like This").
define('LFM_API_KEY', '');
// ── AUDIT (per-song background fix) ──────────────────────────────────────────
// When a client plays an M4A, the stream handler kicks off a background ffmpeg
// audit of that file. Corrupt-but-fixable files are remuxed losslessly into a
// sidecar under AUDIT_FIXED_DIR; the stream handler then serves the sidecar on
// subsequent plays. Originals are NEVER modified. Set AUDIT_ENABLED=false to
// disable the per-play hook entirely (bulk CLI commands still work).
define('AUDIT_ENABLED',     true);
define('AUDIT_VERBOSE',     true);   // log every decision (TRIG_*/WORKER_*/OK); flip to false once stable
define('AUDIT_ALLOW_REENCODE', true); // if lossless remux can't reconcile container vs audio,
                                      // fall back to re-encoding AAC. Lossy (one generation),
                                      // but the only way to fix packet-level damage. Flip to
                                      // false to refuse re-encoding and let those files stay broken.
define('AUDIT_STATE_FILE',  __DIR__ . '/audit-state.json');
define('AUDIT_FIXED_DIR',   __DIR__ . '/audit-fixed');         // outside MUSIC_DIR so library scanner ignores it
define('AUDIT_BACKUP_DIR',  __DIR__ . '/audit-backup');         // for bulk --fix originals; outside MUSIC_DIR
define('AUDIT_LOCK_FILE',   __DIR__ . '/audit.lock');
define('AUDIT_LOG_FILE',    __DIR__ . '/audit-log.txt');
define('AUDIT_SPAWN_ERRLOG',__DIR__ . '/audit-spawn-stderr.log');
// Album-level replacement workflow:
//   - When any song in an album is detected damaged, write a human-readable
//     manifest into AUDIT_REPLACE_QUEUE_DIR for that album.
//   - User drops a fresh copy at AUDIT_STAGING_DIR/<album-rel-path>/.
//   - On the next audit run, the staged replacement is validated and, if
//     clean, swapped in. Original moves to AUDIT_REPLACED_DIR (kept forever).
define('AUDIT_REPLACE_QUEUE_DIR', __DIR__ . '/albums-needs-replacement');
define('AUDIT_STAGING_DIR',       __DIR__ . '/albums-staging');
define('AUDIT_REPLACED_DIR',      __DIR__ . '/albums-replaced');
// Auto-managed per-user playlists that surface the audit workflow in the UI.
// When a song in a user's library is detected damaged, it goes into the
// "Corrupt" playlist for that user. When the album containing it is
// successfully replaced, the entry migrates to "Replaced" — a running history
// the user can clear out manually whenever they like.
// Users can rename these playlists; the system will recreate freshly-named
// copies on the next event, so any renames effectively detach from auto-management.
define('AUDIT_PLAYLIST_CORRUPT',  'Corrupt');
define('AUDIT_PLAYLIST_REPLACED', 'Recently Replaced');
// ──────────────────────────────────────────────────────────────────────────────
// CLI maintenance commands — run before web/CORS setup.
//   php api.php audit                    # bulk: detect only, log to audit-log.txt
//   php api.php audit --fix              # bulk: detect + remux IN PLACE (originals moved to audit-backup/)
//   php api.php audit --resume           # bulk: skip files already in the log
//   php api.php audit --limit=50         # bulk: stop after N files (testing)
//   php api.php audit-one <rel/path>     # one file: writes sidecar to audit-fixed/, updates audit-state.json
//   php api.php reconcile                # manually check albums-staging/ for replacements and swap in any that validate
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    if ($argv[1] === 'audit')                        { handleAudit(array_slice($argv, 2)); exit; }
    if ($argv[1] === 'audit-one' && isset($argv[2])) { handleAuditOne($argv[2]);            exit; }
    if ($argv[1] === 'reconcile')                    { handleReconcile();                   exit; }
    if ($argv[1] === 'heal-userdata')                { handleHealUserData($argv[2] ?? '');  exit; }
}
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
if ($action !== 'stream' && $action !== 'art' && $action !== 'album_stream' && $action !== 'hls_serve') {
    $key = '';
    if (isset($_SERVER['HTTP_X_API_KEY'])) $key = $_SERVER['HTTP_X_API_KEY'];
    elseif (isset($_GET['key']))           $key = $_GET['key'];
    // if (!in_array($key, API_KEYS, true)) {
    //     http_response_code(401);
    //     header('Content-Type: application/json');
    //     echo json_encode(['error' => 'Unauthorized']);
    //     exit;
    // }
    $authUserId = validateAppToken($key);
    if ($authUserId === null && !in_array($key, API_KEYS, true)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $userKey = $key;
}
// Returns a per-user file path by hashing the API key.
// Shared files (library cache, art) are not prefixed.

/**
 * Validate a token against the APP_TOKENS table.
 * Returns the USER_ID if valid, null otherwise.
 */
function validateAppToken(string $token): ?int {
    if (empty($token)) return null;
    
    try {
        $db = new SQLite3(AUTH_DB_PATH);
        $stmt = $db->prepare(
            "SELECT USER_ID FROM APP_TOKENS WHERE APP = 'music' AND TOKEN = :token"
        );
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return $row ? (int) $row['USER_ID'] : null;
    } catch (Exception $e) {
        if (DEBUG) error_log('APP_TOKENS lookup failed: ' . $e->getMessage());
        return null;
    }
}


function userFile(string $name): string {
    global $userKey;
    $slug = substr(hash('crc32b', $userKey ?? 'shared'), 0, 8);
    return __DIR__ . '/.' . $name . '-' . $slug . '.json';
}
if      ($action === 'library')         handleLibrary();
elseif  ($action === 'library_check')  handleLibraryCheck();
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
elseif  ($action === 'get_tag_overrides')  handleGetTagOverrides();
elseif  ($action === 'save_tag_overrides') handleSaveTagOverrides();
elseif  ($action === 'nowplaying_get')    handleNowPlayingGet();
elseif  ($action === 'nowplaying_set')    handleNowPlayingSet();
elseif  ($action === 'albumart')          handleAlbumArt();
elseif  ($action === 'save_search_miss')  handleSaveSearchMiss();
elseif  ($action === 'get_search_misses') handleGetSearchMisses();
elseif  ($action === 'nowplaying_get')  handleNowPlayingGet();
elseif  ($action === 'nowplaying_set')  handleNowPlayingSet();
elseif  ($action === 'albumart')        handleAlbumArt();
elseif  ($action === 'hls_generate')    handleHlsGenerate();
elseif  ($action === 'hls_serve')       handleHlsServe();
elseif  ($action === 'whoami')          handleWhoAmI();
elseif  ($action === 'delete_song')     handleDeleteSong();
elseif  ($action === 'lfm_similar_tracks')  handleLfmSimilarTracks();
elseif  ($action === 'lfm_similar_artists') handleLfmSimilarArtists();
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


function handleWhoAmI(): void {
    global $userKey;
    
    $userId = validateAppToken($userKey);
    
    if ($userId !== null) {
        // Look up the user's display info from USERS table
        try {
            $db = new SQLite3(AUTH_DB_PATH);
            $stmt = $db->prepare(
                "SELECT ID, NAME, GIVENNAME, PICTURE FROM USERS WHERE ID = :id"
            );
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $db->close();
            
            header('Content-Type: application/json');
            echo json_encode([
                'authenticated' => true,
                'user_id'       => $userId,
                'name'          => $row['NAME'] ?? 'User',
                'given_name'    => $row['GIVENNAME'] ?? null,
                'picture'       => $row['PICTURE'] ?? null,
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['authenticated' => true, 'user_id' => $userId]);
        }
    } else {
        // Legacy key — no user profile available
        header('Content-Type: application/json');
        echo json_encode([
            'authenticated' => true,
            'user_id'       => null,
            'name'          => 'Legacy API User',
        ]);
    }
    exit;
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

// ─── GLOBAL TAG OVERRIDES ────────────────────────────────────────────────────
// Per-user meta (stars, plays, dates) lives in meta-{userhash}.json.
// Tag overrides (title, artist, album_artist, album, year, genre, track,
// disc) are SHARED across all users so a single correction applies
// everywhere. Stored keyed by song id with only the overridden fields.
// Applied to the library cache at scan time in handleLibrary().
const TAG_OVERRIDE_FIELDS = ['title','artist','album_artist','album','year','genre','track','disc'];

function readTagOverrides(): array {
    if (!file_exists(TAG_OVERRIDES_FILE)) return [];
    $raw = @file_get_contents(TAG_OVERRIDES_FILE);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function writeTagOverrides(array $data) {
    return @file_put_contents(
        TAG_OVERRIDES_FILE,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}
function handleGetTagOverrides() {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['overrides' => readTagOverrides()]);
}
function handleSaveTagOverrides() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['overrides'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }
    $existing = readTagOverrides();
    foreach ($data['overrides'] as $id => $patch) {
        if (!is_string($id) || !is_array($patch)) continue;
        if (!isset($existing[$id])) $existing[$id] = [];
        foreach ($patch as $k => $v) {
            if (!in_array($k, TAG_OVERRIDE_FIELDS, true)) continue;
            $isNum = ($k === 'year' || $k === 'track' || $k === 'disc');
            if ($v === '' || $v === null) {
                // Empty value clears the override for this field
                unset($existing[$id][$k]);
            } else {
                $existing[$id][$k] = $isNum ? (int)$v : substr((string)$v, 0, 300);
            }
        }
        // Drop empty entries so the file doesn't accumulate cruft
        if (empty($existing[$id])) unset($existing[$id]);
    }
    if (writeTagOverrides($existing) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write tag overrides file']);
        return;
    }
    // Invalidate the library cache so the next library/library_check reflects
    // the new overrides for every user.
    if (file_exists(CACHE_FILE)) @unlink(CACHE_FILE);
    if (file_exists(FINGERPRINT_FILE)) @unlink(FINGERPRINT_FILE);
    echo json_encode(['ok' => true]);
}

// Apply global overrides to a song record (modifies in-place via reference).
// Stashes each replaced value into $song['_orig'] so the frontend edit sheet
// can show what the file actually had and offer a "Reset" action.
function applyTagOverridesToSong(array &$song, array $overrides): void {
    $id = $song['id'] ?? '';
    if (!$id || !isset($overrides[$id])) return;
    $orig = [];
    foreach (TAG_OVERRIDE_FIELDS as $k) {
        if (isset($overrides[$id][$k]) && $overrides[$id][$k] !== '' && $overrides[$id][$k] !== null) {
            $orig[$k] = $song[$k] ?? null;
            $song[$k] = $overrides[$id][$k];
        }
    }
    if (!empty($orig)) $song['_orig'] = $orig;
}
// ─── SEARCH MISS LOG ─────────────────────────────────────────────────────────
// Shared across all users (not per-user) so the owner sees all requests.
define('SEARCH_MISSES_FILE', __DIR__ . '/search-misses.json');
function readSearchMisses() {
    if (!file_exists(SEARCH_MISSES_FILE)) return [];
    $raw = @file_get_contents(SEARCH_MISSES_FILE);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}
function writeSearchMisses($data) {
    return @file_put_contents(SEARCH_MISSES_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}
function handleSaveSearchMiss() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || empty($data['query'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }
    $query = trim($data['query']);
    $ts    = isset($data['ts']) ? (int)$data['ts'] : (int)(microtime(true) * 1000);
    if (!$query) { echo json_encode(['ok' => true]); return; }
    $misses = readSearchMisses();
    $lquery = mb_strtolower($query);
    $found  = false;
    foreach ($misses as &$m) {
        if (mb_strtolower($m['query']) === $lquery) {
            $m['count']++;
            $m['lastTs'] = $ts;
            $found = true;
            break;
        }
    }
    unset($m);
    if (!$found) $misses[] = ['query' => $query, 'count' => 1, 'lastTs' => $ts];
    usort($misses, fn($a, $b) => $b['count'] - $a['count']);
    writeSearchMisses($misses);
    echo json_encode(['ok' => true]);
}
function handleGetSearchMisses() {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['misses' => readSearchMisses()]);
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
// ─── DELETE SONG (move to trash) ─────────────────────────────────────────────
// Moves a song file from MUSIC_DIR into MUSIC_DIR/.trash, preserving its
// relative path so it can be restored manually. Used by the Duplicates view.
// Trashed files are invisible to the library scanner (filenames starting with '.').
function handleDeleteSong() {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    $path = is_array($data) && isset($data['path']) ? (string)$data['path'] : '';
    if ($path === '') { http_response_code(400); echo json_encode(['error' => 'Missing path']); return; }

    $base = realpath(MUSIC_DIR);
    if (!$base) { http_response_code(500); echo json_encode(['error' => 'MUSIC_DIR not found']); return; }
    $full = realpath($base . '/' . ltrim($path, '/'));
    if (!$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
        http_response_code(404); echo json_encode(['error' => 'File not found']); return;
    }

    $trashRoot = $base . '/.trash';
    if (!is_dir($trashRoot)) @mkdir($trashRoot, 0755, true);
    if (!is_dir($trashRoot)) { http_response_code(500); echo json_encode(['error' => 'Could not create trash dir']); return; }

    $rel = ltrim($path, '/');
    $ts  = date('Ymd-His');
    $dst = $trashRoot . '/' . $ts . '__' . str_replace('/', '_', $rel);
    // If a name collision somehow happens, append a counter.
    $i = 1;
    while (file_exists($dst)) {
        $dst = $trashRoot . '/' . $ts . '__' . $i . '__' . str_replace('/', '_', $rel);
        $i++;
    }
    if (!@rename($full, $dst)) {
        http_response_code(500); echo json_encode(['error' => 'Could not move file to trash']); return;
    }

    // Invalidate library cache so the song disappears from listings on next fetch.
    if (file_exists(CACHE_FILE))       @unlink(CACHE_FILE);
    if (file_exists(FINGERPRINT_FILE)) @unlink(FINGERPRINT_FILE);
    echo json_encode(['ok' => true, 'trashed' => basename($dst)]);
}

// ─── LAST.FM PROXY ───────────────────────────────────────────────────────────
// Thin pass-through so the key stays on the server. Clients call these
// instead of audioscrobbler.com directly. Returns 503 if no key is configured.
function _lfmCall(string $method, array $params): void {
    header('Content-Type: application/json; charset=utf-8');
    if (LFM_API_KEY === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Last.fm key not configured on server']);
        return;
    }
    $qs = http_build_query(array_merge($params, [
        'method'  => $method,
        'api_key' => LFM_API_KEY,
        'format'  => 'json',
    ]));
    $url = 'https://ws.audioscrobbler.com/2.0/?' . $qs;
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: MusicApp/1.0\r\n"]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Last.fm request failed']);
        return;
    }
    // Cache successful responses briefly — Last.fm rate-limits and these
    // results barely change. 1 day is plenty for similarity data.
    header('Cache-Control: private, max-age=86400');
    echo $resp;
}
function handleLfmSimilarTracks(): void {
    _lfmCall('track.getSimilar', [
        'track'  => isset($_GET['track'])  ? (string)$_GET['track']  : '',
        'artist' => isset($_GET['artist']) ? (string)$_GET['artist'] : '',
        'limit'  => isset($_GET['limit'])  ? (int)$_GET['limit']     : 50,
    ]);
}
function handleLfmSimilarArtists(): void {
    _lfmCall('artist.getSimilar', [
        'artist' => isset($_GET['artist']) ? (string)$_GET['artist'] : '',
        'limit'  => isset($_GET['limit'])  ? (int)$_GET['limit']     : 15,
    ]);
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
// Supports three output formats:
//   format=aac  — copy M4A/AAC audio into a fragmented MP4 (no re-encode, gapless)
//   format=opus — re-encode to Opus in an OGG container
//   format=mp3  — re-encode to MP3 CBR 192k (wide device support, fallback)
//
// The client passes:
//   token, expires  — HMAC from sign_album
//   paths[]         — ordered list of relative song paths (URL-encoded)
//   format          — "aac" (default), "opus", or "mp3"
//   total_sec       — sum of track durations (used for Content-Range total)
//
// HTTP range handling:
//   Safari/AVFoundation ALWAYS sends a Range: bytes=0-1 probe before loading
//   audio. We respond 206 + 2 dummy bytes (no ffmpeg). This tells AVFoundation
//   the server supports ranges. When nginx drops the connection mid-stream,
//   AVFoundation reconnects with Range: bytes=N- and we seek ffmpeg to N/bps
//   seconds. JS layer no longer needs to manage reconnects.
function handleAlbumStream() {
    set_time_limit(0);
    @ini_set('max_execution_time', '0');  // belt-and-suspenders for Synology php.ini
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

    //$ffmpeg = findFfmpeg();
    //if (!$ffmpeg) { http_response_code(500); echo 'ffmpeg not available'; exit; }

    $ffmpeg = '/volume1/music/ffmpeg/ffmpeg';
    if (!@is_executable($ffmpeg)) { http_response_code(500); echo 'ffmpeg not available'; exit; }

    $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'aac';
    if (!in_array($format, ['aac','opus','mp3'])) $format = 'aac';

    // Build a temp concat list file
    $listFile = tempnam(sys_get_temp_dir(), 'jbcat_');
    $listContent = '';
    foreach ($fullPaths as $fp) {
        $escaped = str_replace("'", "'\\''", $fp);
        $listContent .= "file '" . $escaped . "'\n";
    }
    file_put_contents($listFile, $listContent);

    // Auto-detect input format from first file extension
    $inputExt   = strtolower(pathinfo($fullPaths[0], PATHINFO_EXTENSION));
    $allSameExt = count(array_unique(array_map(function($p) {
        return strtolower(pathinfo($p, PATHINFO_EXTENSION));
    }, $fullPaths))) === 1;

    // Determine MIME type and CBR bytes-per-second for Range-based seeking.
    // AAC stream-copy has variable bitrate so byte-offset seeking isn't reliable.
    if ($format === 'aac' && in_array($inputExt, ['m4a','aac']) && $allSameExt) {
        $mime = 'audio/mp4';
        $bps  = 0; // variable — skip byte-offset seek
    } elseif ($format === 'opus') {
        $mime = 'audio/ogg';
        $bps  = (int)(160000 / 8); // 20000 bytes/sec CBR
    } else {
        $mime = 'audio/mpeg';
        $bps  = (int)(192000 / 8); // 24000 bytes/sec CBR
    }

    // JS passes total_sec (sum of track durations) so we can include an accurate
    // byte total in Content-Range headers, enabling AVFoundation to calculate
    // aud.duration and aud.currentTime correctly after a range-request reconnect.
    $totalSec   = isset($_GET['total_sec']) ? max(0.0, (float)$_GET['total_sec']) : 0.0;
    $totalBytes = ($bps > 0 && $totalSec > 0) ? (int)($totalSec * $bps) : 0;

    // ── HTTP Range handling ──────────────────────────────────────────────────────
    // Safari probe: Range: bytes=0-1 — respond 206 + 2 dummy bytes, no ffmpeg.
    // This confirms range support; AVFoundation then makes the real content request
    // (Range: bytes=0-) and handles reconnects itself via Range: bytes=N- requests.
    $startByte      = 0;
    $seekSec        = 0.0;
    $isRangeRequest = false;
    $rangeHeader    = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';
    if ($rangeHeader && preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $rm)) {
        $rb1 = (int)$rm[1];
        $rb2 = ($rm[2] !== '') ? (int)$rm[2] : -1;

        // Safari probe: bytes=0-1
        if ($rb1 === 0 && $rb2 >= 0 && $rb2 <= 1) {
            @unlink($listFile);
            while (ob_get_level()) ob_end_clean();
            http_response_code(206);
            header("Content-Type: $mime");
            header('Accept-Ranges: bytes');
            $rangeTotal = $totalBytes > 0 ? $totalBytes : '*';
            header("Content-Range: bytes 0-1/$rangeTotal");
            header('Content-Length: 2');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');
            echo "\xff\xfb"; // 2 dummy bytes — Safari discards probe content
            exit;
        }

        $startByte = $rb1;
        // Map byte offset → ffmpeg seek time (CBR formats only)
        if ($startByte > 0 && $bps > 0) {
            $seekSec = $startByte / $bps;
        }
        $isRangeRequest = true;
    }

    // -ss before -f concat seeks the concat demuxer directly (fast: jumps to
    // the right file without re-decoding everything from the beginning).
    $seekArgs = ($seekSec > 0) ? ['-ss', sprintf('%.3f', $seekSec)] : [];

    if ($format === 'aac' && in_array($inputExt, ['m4a','aac']) && $allSameExt) {
        $args = array_merge([$ffmpeg], $seekArgs, [
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-map', '0:a', '-c', 'copy',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
            '-f', 'mp4', 'pipe:1',
        ]);
    } elseif ($format === 'opus') {
        $args = array_merge([$ffmpeg], $seekArgs, [
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-vn', '-c:a', 'libopus', '-b:a', '160k',
            '-application', 'audio', '-f', 'ogg', 'pipe:1',
        ]);
    } else {
        // MP3 CBR — suppress Xing/ID3 headers so Safari doesn't miscalculate duration
        $args = array_merge([$ffmpeg], $seekArgs, [
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-vn', '-c:a', 'libmp3lame', '-b:a', '192k',
            '-write_xing', '0', '-id3v2_version', '0',
            '-f', 'mp3', 'pipe:1',
        ]);
    }

    while (ob_get_level()) ob_end_clean();

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($args, $desc, $pipes);
    if (!$proc) { @unlink($listFile); http_response_code(500); echo 'ffmpeg launch failed'; exit; }
    fclose($pipes[0]);

    stream_set_blocking($pipes[2], false);
    stream_set_blocking($pipes[1], false);
    usleep(300000); // 300ms initial wait
    $firstChunk = stream_get_contents($pipes[1]);

    // When seeking (-ss), ffmpeg may take longer to produce output because it
    // needs to probe input file durations and seek through the concat list.
    // ffmpeg ALWAYS writes its version banner to stderr, so stderr output alone
    // does NOT indicate an error.  Only treat it as a failure if the process
    // has exited AND produced no audio output.
    if (($firstChunk === '' || $firstChunk === false)) {
        for ($w = 0; $w < 50; $w++) {            // wait up to 5 more seconds
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            usleep(100000); // 100ms
            $firstChunk = stream_get_contents($pipes[1]);
            if ($firstChunk !== '' && $firstChunk !== false) break;
        }
    }

    if (($firstChunk === '' || $firstChunk === false)) {
        $errOut = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        @unlink($listFile);
        header('Content-Type: text/plain');
        http_response_code(500);
        echo "ffmpeg error:\n" . $errOut;
        exit;
    }

    // Accept-Ranges: bytes — tells Safari this server handles range requests,
    // so AVFoundation will reconnect with Range: bytes=N- after a dropped
    // connection instead of restarting from byte 0 (track 1).
    header("Content-Type: $mime");
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');

    // Content-Range lets AVFoundation calculate aud.currentTime and aud.duration.
    if ($totalBytes > 0) {
        $endByte = $totalBytes - 1;
        http_response_code(206);
        header("Content-Range: bytes $startByte-$endByte/$totalBytes");
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
// ─── HLS GENERATE — build HLS playlist + segments for album playback ─────────
// Creates an HLS playlist (.m3u8) and segment files (.ts) from album tracks.
// Returns a session ID; the client fetches playlist/segments via hls_serve.
// This avoids long-lived HTTP responses that cause server timeouts.
function handleHlsGenerate() {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $data = $body ? json_decode($body, true) : null;
    if (!$data || !isset($data['paths']) || !is_array($data['paths']) || !count($data['paths'])) {
        http_response_code(400);
        echo json_encode(['error' => 'paths array required']);
        return;
    }
    $paths = array_values(array_filter(array_map('strval', $data['paths'])));
    if (!$paths) {
        http_response_code(400);
        echo json_encode(['error' => 'empty paths']);
        return;
    }

    $base = realpath(MUSIC_DIR);
    if (!$base) { http_response_code(500); echo json_encode(['error' => 'Music dir not found']); return; }

    // Resolve and validate every path
    $fullPaths = [];
    foreach ($paths as $p) {
        $full = realpath($base . '/' . ltrim($p, '/'));
        if (!$full || strpos($full, $base) !== 0 || !is_file($full)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found: ' . $p]);
            return;
        }
        $fullPaths[] = $full;
    }

    $ffmpeg = findFfmpeg();
    if (!$ffmpeg) { http_response_code(500); echo json_encode(['error' => 'ffmpeg not available']); return; }

    // Clean up old HLS sessions before creating a new one
    hlsCleanup();

    // Kill any running HLS ffmpeg process from a previous request
    hlsKillPrevious();

    // Create session directory
    $sessionId = bin2hex(random_bytes(16));
    $sessionDir = HLS_DIR . '/' . $sessionId;
    if (!is_dir(HLS_DIR)) @mkdir(HLS_DIR, 0775, true);
    @mkdir($sessionDir, 0775, true);

    // Build ffmpeg concat list
    $listFile = $sessionDir . '/concat.txt';
    $listContent = '';
    foreach ($fullPaths as $fp) {
        $escaped = str_replace("'", "'\\''", $fp);
        $listContent .= "file '" . $escaped . "'\n";
    }
    file_put_contents($listFile, $listContent);

    // Sign the session so hls_serve can verify ownership
    $expires = time() + 14400; // 4 hours
    $token = hash_hmac('sha256', $sessionId . ':' . $expires, HMAC_SECRET);

    // Build ffmpeg command for HLS output
    // Always re-encode for gapless HLS. The concat demuxer decodes each track
    // (stripping per-track encoder delay/padding from iTunSMPB metadata) and
    // re-encodes as one continuous AAC stream. Using -c:a copy would preserve
    // each track's individual encoder priming samples, causing audible gaps.
    $playlistPath = $sessionDir . '/playlist.m3u8';
    $segmentFile = $sessionDir . '/seg.m4s';

    $args = [$ffmpeg, '-f', 'concat', '-safe', '0', '-i', $listFile,
        '-vn', '-c:a', 'aac', '-b:a', '256k',
    ];

    // HLS output — fMP4 single-file mode.
    // single_file writes all segments into one .m4s with byte-range addressing,
    // avoiding thousands of per-frame files (audio-only fMP4 treats every AAC
    // frame as a keyframe, so per-segment files are ~3KB each).
    $args = array_merge($args, [
        '-f', 'hls',
        '-hls_time', '6',
        '-hls_list_size', '0',
        '-hls_playlist_type', 'vod',
        '-hls_segment_type', 'fmp4',
        '-hls_fmp4_init_filename', 'init.mp4',
        '-hls_segment_filename', $segmentFile,
        '-hls_flags', 'single_file',
        $playlistPath,
    ]);

    // Run ffmpeg synchronously — for a typical album this takes a few seconds
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    @set_time_limit(300); // allow up to 5 min for long albums
    $proc = @proc_open($args, $desc, $pipes);
    if (!$proc) {
        @unlink($listFile);
        http_response_code(500);
        echo json_encode(['error' => 'ffmpeg launch failed']);
        return;
    }
    // Record PID so a subsequent request can kill this process
    $status = proc_get_status($proc);
    if ($status && $status['pid']) {
        @file_put_contents(HLS_DIR . '/.ffmpeg.pid', $status['pid']);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    // Clear PID file now that ffmpeg has finished
    @unlink(HLS_DIR . '/.ffmpeg.pid');

    // Clean up concat list (segments remain)
    @unlink($listFile);

    if ($exitCode !== 0 || !file_exists($playlistPath)) {
        // Clean up failed session
        hlsCleanupDir($sessionDir);
        http_response_code(500);
        echo json_encode(['error' => 'ffmpeg failed', 'detail' => substr($stderr, 0, 500)]);
        return;
    }

    // Write a timestamp file for cleanup tracking
    file_put_contents($sessionDir . '/.created', (string)time());

    // Return session info
    echo json_encode([
        'session' => $sessionId,
        'token'   => $token,
        'expires' => $expires,
        'playlist' => 'playlist.m3u8',
    ]);
}

// ─── HLS SERVE — serve playlist and segment files from HLS sessions ──────────
function handleHlsServe() {
    $session = isset($_GET['session']) ? $_GET['session'] : '';
    $file    = isset($_GET['file'])    ? $_GET['file']    : '';
    $token   = isset($_GET['token'])   ? $_GET['token']   : '';
    $expires = isset($_GET['expires']) ? (int)$_GET['expires'] : 0;

    // Validate token
    if (time() > $expires) { http_response_code(403); echo 'Token expired'; exit; }
    $expected = hash_hmac('sha256', $session . ':' . $expires, HMAC_SECRET);
    if (!hash_equals($expected, $token)) { http_response_code(403); echo 'Invalid token'; exit; }

    // Validate session ID (hex only) and file name (alphanumeric + dots)
    if (!preg_match('/^[0-9a-f]{32}$/', $session)) { http_response_code(400); echo 'Invalid session'; exit; }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $file))  { http_response_code(400); echo 'Invalid file'; exit; }

    $path = HLS_DIR . '/' . $session . '/' . $file;
    if (!file_exists($path)) { http_response_code(404); echo 'Not found'; exit; }

    // Ensure path stays within the session directory
    $realPath = realpath($path);
    $sessionDir = realpath(HLS_DIR . '/' . $session);
    if (!$realPath || !$sessionDir || strpos($realPath, $sessionDir) !== 0) {
        http_response_code(403); echo 'Forbidden'; exit;
    }

    // Determine MIME type
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'm3u8') {
        $mime = 'application/vnd.apple.mpegurl';
    } elseif ($ext === 'ts') {
        $mime = 'video/mp2t';
    } elseif ($ext === 'm4s') {
        $mime = 'audio/mp4';
    } elseif ($ext === 'mp4') {
        $mime = 'audio/mp4';
    } else {
        $mime = 'application/octet-stream';
    }

    // Touch the created file to extend the session's life while it's in use
    @touch(HLS_DIR . '/' . $session . '/.created');

    $size = filesize($path);
    header("Content-Type: $mime");
    header("Content-Length: $size");
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');

    // For .m3u8 playlists, rewrite segment URLs to include token params
    if ($ext === 'm3u8') {
        $content = file_get_contents($path);
        // Rewrite segment filenames to full hls_serve URLs
        $baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/api.php';
        $params = http_build_query([
            'action'  => 'hls_serve',
            'session' => $session,
            'token'   => $token,
            'expires' => $expires,
        ]);
        // Rewrite segment/init filenames to full hls_serve URLs.
        // Handles both multi-file (seg000.ts / seg000.m4s on bare lines) and
        // single-file fMP4 (seg.m4s on bare lines, init.mp4 inside #EXT-X-MAP URI).
        $makeUrl = function($file) use ($baseUrl, $params) {
            return $baseUrl . '?' . $params . '&file=' . $file;
        };
        // Bare segment filenames (works for TS, multi-file fMP4, and single-file fMP4)
        $content = preg_replace_callback('/^(seg[\d]*\.(?:m4s|ts))$/m', function($m) use ($makeUrl) {
            return $makeUrl($m[1]);
        }, $content);
        // #EXT-X-MAP:URI="init.mp4" (fMP4 init segment)
        $content = preg_replace_callback('/(#EXT-X-MAP:URI=")([^"]+)(")/', function($m) use ($makeUrl) {
            return $m[1] . $makeUrl($m[2]) . $m[3];
        }, $content);
        header('Content-Length: ' . strlen($content));
        echo $content;
    } else {
        // Serve segment file with range support
        $start = 0;
        $end = $size - 1;
        if (!empty($_SERVER['HTTP_RANGE'])) {
            preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
            $start = ($m[1] !== '') ? (int)$m[1] : 0;
            $end   = ($m[2] !== '') ? min((int)$m[2], $size - 1) : $size - 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$size");
            header('Content-Length: ' . ($end - $start + 1));
        }
        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp) && !connection_aborted()) {
            $chunk = fread($fp, min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
            if (ob_get_level()) ob_flush();
            flush();
        }
        fclose($fp);
    }
}

// Find ffmpeg binary
function findFfmpeg() {
    // Check the project-local path first (Synology NAS pattern)
    $local = MUSIC_DIR . '/ffmpeg/ffmpeg';
    if (@is_executable($local)) return $local;
    // Standard paths
    $candidates = ['/usr/local/bin/ffmpeg','/usr/bin/ffmpeg','/opt/ffmpeg/bin/ffmpeg','/opt/bin/ffmpeg','/bin/ffmpeg'];
    foreach ($candidates as $p) {
        if (@is_executable($p)) return $p;
    }
    $which = @shell_exec('which ffmpeg 2>/dev/null');
    if ($which) return trim($which);
    return null;
}

// Kill any previously running HLS ffmpeg process.
// Only one HLS generation should run at a time to avoid CPU spikes.
function hlsKillPrevious() {
    $pidFile = HLS_DIR . '/.ffmpeg.pid';
    if (!file_exists($pidFile)) return;
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0) {
        // Kill the process group to also stop any child processes
        @posix_kill($pid, 15); // SIGTERM
        // Brief wait, then force-kill if still running
        usleep(200000); // 200ms
        if (@posix_kill($pid, 0)) { // check if still alive
            @posix_kill($pid, 9); // SIGKILL
        }
    }
    @unlink($pidFile);
}

// Clean up HLS session directories older than HLS_MAX_AGE
function hlsCleanup() {
    if (!is_dir(HLS_DIR)) return;
    $now = time();
    $dh = @opendir(HLS_DIR);
    if (!$dh) return;
    while (($entry = readdir($dh)) !== false) {
        if ($entry[0] === '.') continue;
        $dir = HLS_DIR . '/' . $entry;
        if (!is_dir($dir)) continue;
        $tsFile = $dir . '/.created';
        $age = file_exists($tsFile) ? $now - (int)file_get_contents($tsFile) : $now - filemtime($dir);
        if ($age > HLS_MAX_AGE) {
            hlsCleanupDir($dir);
        }
    }
    closedir($dh);
}

// Recursively remove an HLS session directory
function hlsCleanupDir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) hlsCleanupDir($path);
            else @unlink($path);
        }
    }
    @rmdir($dir);
}

// ─── LIBRARY — serve cache (TTL 24h), rebuild on expiry ──────────────────────
// Fast filesystem walk — no ffprobe, just path:size:mtime strings.
function quickScanDir($dir, $base = '') {
    $exts  = ['mp3','m4a','aac','flac','ogg','wav','opus','aiff','wma'];
    $items = @scandir($dir);
    $out = [];
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $full = $dir . '/' . $item;
        $rel  = $base ? $base . '/' . $item : $item;
        if (is_dir($full)) {
            $out = array_merge($out, quickScanDir($full, $rel));
            continue;
        }
        if (!in_array(strtolower(pathinfo($item, PATHINFO_EXTENSION)), $exts)) continue;
        $out[] = $rel . ':' . (int)@filesize($full) . ':' . (int)@filemtime($full);
    }
    return $out;
}
function computeFingerprint($dir) {
    $files = quickScanDir($dir);
    sort($files);
    return ['hash' => md5(implode("\n", $files)), 'count' => count($files)];
}
function handleLibraryCheck() {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_dir(MUSIC_DIR)) {
        http_response_code(500);
        echo json_encode(['error' => 'MUSIC_DIR not found']);
        return;
    }
    $current   = computeFingerprint(MUSIC_DIR);
    $stored    = file_exists(FINGERPRINT_FILE) ? trim(file_get_contents(FINGERPRINT_FILE)) : '';
    $cachedCt  = 0;
    if (file_exists(CACHE_FILE)) {
        $c = @json_decode(file_get_contents(CACHE_FILE), true);
        $cachedCt = isset($c['count']) ? (int)$c['count'] : 0;
    }
    if ($stored === $current['hash']) {
        echo json_encode(['changed' => false, 'found' => $current['count']]);
    } else {
        // Invalidate cache so next ?action=library does a fresh ffprobe scan
        @unlink(CACHE_FILE);
        echo json_encode(['changed' => true, 'found' => $current['count'], 'cached' => $cachedCt]);
    }
}
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
    // Load per-song metadata cache — keyed by relative path, valid while size+mtime match.
    // Songs whose size/mtime are unchanged skip ffprobe entirely.
    $songCache = [];
    if (file_exists(SONG_CACHE_FILE)) {
        $raw = @json_decode(file_get_contents(SONG_CACHE_FILE), true);
        if (is_array($raw)) $songCache = $raw; // already keyed by relpath
    }
    $songs = doScanDir(MUSIC_DIR, '', $songCache);
    // Persist only entries for files that still exist (prunes deleted songs)
    $newCache = [];
    foreach ($songs as $s) { $newCache[$s['path']] = $s; }
    @file_put_contents(SONG_CACHE_FILE, json_encode($newCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    usort($songs, function($a, $b) {
        return strcasecmp($a['artist'] . $a['title'], $b['artist'] . $b['title']);
    });
    // Load audit-state once so we can stamp every damaged song with a status
    // the UI can render (badge, icon, "corrupt" label).
    $auditState = function_exists('auditStateLoad') ? auditStateLoad() : [];
    $tagOverrides = readTagOverrides();
    foreach ($songs as &$s) {
        $s['id'] = hash('crc32b', $s['path']);
        // Apply shared tag overrides so every user gets the corrected fields.
        applyTagOverridesToSong($s, $tagOverrides);
        if (isset($auditState[$s['path']])) {
            $st = $auditState[$s['path']]['status'] ?? '';
            if ($st && $st !== 'ok') {
                $s['audit_status'] = $st;                          // corrupt | fixed | unfixable | fix_failed
                if (isset($auditState[$s['path']]['method'])) {
                    $s['audit_method'] = $auditState[$s['path']]['method'];   // remux | reencode
                }
                if (isset($auditState[$s['path']]['reported'], $auditState[$s['path']]['decoded'])) {
                    $s['audit_gap'] = round(abs((float)$auditState[$s['path']]['decoded']
                                              - (float)$auditState[$s['path']]['reported']), 2);
                }
            }
        }
    }
    unset($s);
    $json = json_encode(
        ['songs' => $songs, 'count' => count($songs), 'generated' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    @file_put_contents(CACHE_FILE, $json);
    // Write fingerprint so library_check can compare without parsing the full cache
    $fp = computeFingerprint(MUSIC_DIR);
    @file_put_contents(FINGERPRINT_FILE, $fp['hash']);
    header('ETag: "' . md5($json) . '"');
    echo $json;
}
function doScanDir($dir, $base = '', &$songCache = []) {
    $exts  = ['mp3','m4a','aac','flac','ogg','wav','opus','aiff','wma'];
    $songs = [];
    $items = @scandir($dir);
    if (!$items) return $songs;
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $full = $dir . '/' . $item;
        $rel  = $base ? $base . '/' . $item : $item;
        if (is_dir($full)) {
            $songs = array_merge($songs, doScanDir($full, $rel, $songCache));
            continue;
        }
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts)) continue;
        $size  = (int)@filesize($full);
        $mtime = (int)@filemtime($full);
        // Cache hit: file unchanged — skip ffprobe entirely
        if (isset($songCache[$rel]) &&
            $songCache[$rel]['size']     === $size &&
            $songCache[$rel]['modified'] === $mtime) {
            $cached = $songCache[$rel];
            // Back-fill embedded art for cache entries written before that
            // field existed. Per-folder memo + on-disk .miss marker keeps
            // ffmpeg from running more than once per album.
            if (empty($cached['hasArt']) && !isset($cached['embeddedArt'])) {
                $embedded = extractEmbeddedArt($full, $rel);
                if ($embedded) { $cached['hasArt'] = true; $cached['embeddedArt'] = $embedded; }
                else           { $cached['embeddedArt'] = ''; }
                $songCache[$rel] = $cached;
            }
            $songs[] = $cached;
            continue;
        }
        // Cache miss or file changed — run full extraction and update cache
        $meta = extractMeta($full, $rel);
        $songCache[$rel] = $meta;
        $songs[] = $meta;
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
    $meta['embeddedArt'] = '';
    $dir = dirname($path);
    foreach (['cover.jpg','cover.png','folder.jpg','folder.png','artwork.jpg'] as $f) {
        if (file_exists($dir . '/' . $f)) {
            $meta['hasArt']  = true;
            $meta['artFile'] = dirname($rel) . '/' . $f;
            break;
        }
    }
    // Fall back to embedded artwork (one extraction per album folder, cached on disk).
    if (!$meta['hasArt']) {
        $embedded = extractEmbeddedArt($path, $rel);
        if ($embedded) {
            $meta['hasArt']      = true;
            $meta['embeddedArt'] = $embedded;
        }
    }
    return $meta;
}

// Extract the first embedded cover image from $path into ART_CACHE_DIR.
// Keyed by album folder (so multi-track albums extract once). Returns the
// basename (e.g. "embedded_<hash>.jpg") for URL construction, or '' if none.
// Per-request memoization avoids hammering ffmpeg for every track in a folder
// that has no embedded art either.
function extractEmbeddedArt(string $path, string $rel): string {
    static $tried = []; // folderHash => result string ('' for miss)
    $folder     = dirname($rel);
    $folderHash = substr(md5($folder), 0, 16);
    $out        = 'embedded_' . $folderHash . '.jpg';
    $outPath    = ART_CACHE_DIR . '/' . $out;
    $missPath   = ART_CACHE_DIR . '/' . 'embedded_' . $folderHash . '.miss';

    if (array_key_exists($folderHash, $tried)) return $tried[$folderHash];
    if (file_exists($outPath)) { $tried[$folderHash] = $out; return $out; }
    if (file_exists($missPath)) { $tried[$folderHash] = ''; return ''; }

    if (!is_dir(ART_CACHE_DIR)) @mkdir(ART_CACHE_DIR, 0775, true);

    $ffmpeg = findFfmpeg();
    if (!$ffmpeg) { $tried[$folderHash] = ''; return ''; }

    // -map 0:v? grabs all video streams (cover art); -frames:v 1 picks one; -an
    // drops audio; the '?' makes the map optional so ffmpeg won't error when
    // there is no embedded picture stream.
    $cmd = escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg($path)
         . ' -map 0:v? -frames:v 1 -an ' . escapeshellarg($outPath)
         . ' 2>/dev/null';
    @shell_exec($cmd);

    if (file_exists($outPath) && filesize($outPath) > 500) {
        $tried[$folderHash] = $out;
        return $out;
    }
    // Record a miss so we don't retry every scan
    @file_put_contents($missPath, (string)time());
    if (file_exists($outPath)) @unlink($outPath); // clean up empty/garbage output
    $tried[$folderHash] = '';
    return '';
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
    // ── Opportunistic audit: serve sidecar if we've already fixed this file;
    //    kick off a background audit if we haven't seen it yet.
    //    Both calls are fast no-ops in the common case. Failures are silent
    //    (audit is best-effort and must never block playback).
    $relPath = ltrim($path, '/');
    if (AUDIT_ENABLED) {
        auditMaybeTrigger($relPath, $full);
        $fixed = auditFixedPath($relPath, $full);
        if ($fixed) { $full = $fixed; header('X-Audit-Source: fixed'); }
        else        { header('X-Audit-Source: original'); }
    }
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

// ─── AUDIT ────────────────────────────────────────────────────────────────────
// Detect (and optionally fix) M4A files with corrupted MP4 container metadata.
// Symptom: playback freezes 10–30s before the end across multiple players.
// Cause: moov/stco/stts tables describe fewer samples than mdat actually holds.
// Fix:    ffmpeg -c copy -movflags +faststart  (lossless remux, web-streamable).
// Detection: decode-to-null with ffmpeg, compare decoded duration vs ffprobe's
// reported duration. Gap > 0.5s → fixable container damage.
function handleAudit(array $args): void {
    if (PHP_SAPI !== 'cli') { fwrite(STDERR, "audit is CLI-only\n"); return; }

    $doFix  = in_array('--fix', $args, true);
    $resume = in_array('--resume', $args, true);
    $limit  = 0;
    foreach ($args as $a) {
        if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];
    }

    $ffmpeg  = findFfmpeg();
    $ffprobe = findFfprobePath();
    if (!$ffmpeg || !$ffprobe) {
        fwrite(STDERR, "ffmpeg or ffprobe not found on PATH\n");
        return;
    }

    $logPath    = AUDIT_LOG_FILE;
    $backupBase = AUDIT_BACKUP_DIR;   // sits next to api.php, outside MUSIC_DIR

    // Resume: skip rel_paths we've already logged a verdict for.
    $seen = [];
    if ($resume && is_file($logPath)) {
        $rfp = fopen($logPath, 'r');
        while (($line = fgets($rfp)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            // Format: timestamp \t STATUS \t rel \t detail
            if (count($parts) >= 3 && !in_array($parts[1], ['RUN_START','RUN_END'], true)) {
                $seen[$parts[2]] = true;
            }
        }
        fclose($rfp);
    }

    $logFp = fopen($logPath, 'a');
    if (!$logFp) { fwrite(STDERR, "cannot open log: $logPath\n"); return; }

    auditLog($logFp, 'RUN_START', '-', ($doFix ? 'mode=fix' : 'mode=dryrun') . ($limit ? " limit=$limit" : '') . ($resume ? ' resume=1' : ''));

    $checked = 0; $skipped = 0; $corrupt = 0; $fixed = 0; $errors = 0;

    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(MUSIC_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    } catch (Exception $e) {
        fwrite(STDERR, "cannot iterate MUSIC_DIR: " . $e->getMessage() . "\n");
        fclose($logFp);
        return;
    }

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if ($ext !== 'm4a' && $ext !== 'mp4') continue;

        $full = $file->getPathname();
        if (strpos($full, $backupBase) === 0) continue;   // never touch the backup dir

        $rel = ltrim(substr($full, strlen(MUSIC_DIR)), '/');
        if (isset($seen[$rel])) { $skipped++; continue; }

        $checked++;
        if ($checked % 25 === 0) {
            fwrite(STDOUT, "[$checked checked | $corrupt corrupt | $fixed fixed | $errors errors] last: $rel\n");
        }

        $result = auditCheckFile($ffmpeg, $ffprobe, $full);

        if ($result['status'] === 'OK') {
            // Don't log OK lines (would balloon to 3000+ lines); summary at end covers it.
            continue;
        }

        if ($result['status'] === 'PROBE_FAIL' || $result['status'] === 'DECODE_ERROR') {
            $errors++;
            auditLog($logFp, $result['status'], $rel,
                sprintf('reported=%.2f decoded=%.2f %s', $result['reported'], $result['decoded'], $result['detail']));
            continue;
        }

        // CORRUPT_FIXABLE
        $corrupt++;
        if (!$doFix) {
            auditLog($logFp, 'CORRUPT', $rel,
                sprintf('reported=%.2fs decoded=%.2fs gap=%.2fs', $result['reported'], $result['decoded'], $result['decoded'] - $result['reported']));
        } else {
            $fix = auditFixFile($ffmpeg, $ffprobe, $full, $backupBase, $rel);
            if ($fix['ok']) {
                $fixed++;
                auditLog($logFp, 'FIXED', $rel,
                    sprintf('was=%.2fs now=%.2fs backup=%s', $result['reported'], $fix['new_duration'], 'audit-backup/' . $rel));
            } else {
                $errors++;
                auditLog($logFp, 'FIX_FAIL', $rel, $fix['detail']);
            }
        }

        if ($limit > 0 && $checked >= $limit) break;
    }

    auditLog($logFp, 'RUN_END', '-', "checked=$checked skipped=$skipped corrupt=$corrupt fixed=$fixed errors=$errors");
    fclose($logFp);

    fwrite(STDOUT, "\nDone. checked=$checked skipped=$skipped corrupt=$corrupt fixed=$fixed errors=$errors\nLog: $logPath\n");
    if ($doFix && $fixed > 0) {
        fwrite(STDOUT, "Originals moved to: $backupBase/\nTo invalidate the library cache so fixes are picked up:\n");
        fwrite(STDOUT, "  rm " . __DIR__ . "/.library-fingerprint " . __DIR__ . "/.library-cache.json\n");
    }
}

function findFfprobePath(): ?string {
    // Prefer the project-local toolchain (the M4A-correct copy at MUSIC_DIR/ffmpeg/)
    // so audit decisions are made by the same binary findFfmpeg() selects.
    $local = MUSIC_DIR . '/ffmpeg/ffprobe';
    if (@is_executable($local)) return $local;
    foreach (['/usr/local/bin/ffprobe','/usr/bin/ffprobe','/opt/ffmpeg/bin/ffprobe','/opt/bin/ffprobe','/bin/ffprobe'] as $p) {
        if (@is_executable($p)) return $p;
    }
    $w = @shell_exec('which ffprobe 2>/dev/null');
    return $w ? trim($w) : null;
}

// Returns: ['status' => OK|CORRUPT_FIXABLE|DECODE_ERROR|PROBE_FAIL, 'reported' => float, 'decoded' => float, 'detail' => string]
function auditCheckFile(string $ffmpeg, string $ffprobe, string $path): array {
    $probe = ffprobeViaProcOpen($ffprobe, $path);
    if (!$probe) return ['status' => 'PROBE_FAIL', 'reported' => 0.0, 'decoded' => 0.0, 'detail' => 'ffprobe returned nothing'];
    $d = json_decode($probe, true);
    if (!$d) return ['status' => 'PROBE_FAIL', 'reported' => 0.0, 'decoded' => 0.0, 'detail' => 'ffprobe json parse failed'];

    $reported = 0.0;
    if (isset($d['format']['duration']))         $reported = (float)$d['format']['duration'];
    elseif (isset($d['streams'][0]['duration'])) $reported = (float)$d['streams'][0]['duration'];
    if ($reported <= 0) return ['status' => 'PROBE_FAIL', 'reported' => 0.0, 'decoded' => 0.0, 'detail' => 'no duration in probe'];

    // Decode-to-null. -stats prints progress on stderr regardless of -v level.
    $cmd  = [$ffmpeg, '-nostdin', '-v', 'error', '-stats', '-i', $path, '-map', '0:a:0', '-f', 'null', '-'];
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!$proc) return ['status' => 'DECODE_ERROR', 'reported' => $reported, 'decoded' => 0.0, 'detail' => 'proc_open failed'];
    fclose($pipes[0]);
    $stderr = '';
    while (!feof($pipes[2])) { $stderr .= fread($pipes[2], 8192); }
    stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);

    // Final "time=HH:MM:SS.ms" in -stats output = decoded duration.
    $decoded = 0.0;
    if (preg_match_all('/time=(\d+):(\d+):(\d+\.\d+)/', $stderr, $matches, PREG_SET_ORDER)) {
        $last = end($matches);
        $decoded = ((int)$last[1]) * 3600 + ((int)$last[2]) * 60 + (float)$last[3];
    }

    $errLine = '';
    if (preg_match('/\b(error|invalid|corrupt|truncated)[^\n]*/i', $stderr, $em)) $errLine = trim($em[0]);

    // Real decode failure (data loss).
    if ($exit !== 0) {
        return ['status' => 'DECODE_ERROR', 'reported' => $reported, 'decoded' => $decoded,
                'detail' => 'ffmpeg exit=' . $exit . ($errLine ? ' [' . substr($errLine, 0, 160) . ']' : '')];
    }

    // Container duration disagrees with actual decoded audio in either direction:
    //   decoded > reported : moov/stco describes fewer samples than mdat holds
    //                        → player thinks track ends earlier than it does
    //   decoded < reported : moov/stco describes more samples than mdat holds
    //                        → player thinks track is longer; freezes at real end of audio
    // Both are fixable by remux: ffmpeg -c copy rebuilds the container around
    // whatever audio packets actually exist, producing honest duration metadata.
    $gap = $decoded - $reported;   // signed
    if (abs($gap) > 0.5) {
        $direction = $gap > 0 ? 'container_short' : 'container_long';
        return ['status'   => 'CORRUPT_FIXABLE',
                'reported' => $reported,
                'decoded'  => $decoded,
                'detail'   => sprintf('%s gap=%+.2fs', $direction, $gap)];
    }

    return ['status' => 'OK', 'reported' => $reported, 'decoded' => $decoded, 'detail' => ''];
}

// Bulk-mode in-place remux. Original moves to AUDIT_BACKUP_DIR/<rel>, fixed
// takes its place. Used only by `php api.php audit --fix`.
// Returns ['ok' => bool, 'detail' => string, 'new_duration' => float]
function auditFixFile(string $ffmpeg, string $ffprobe, string $path, string $backupBase, string $rel): array {
    $tmp = $path . '.audit-rewrite.tmp';
    @unlink($tmp);

    // -map 0:a    drop attached album-art "video" streams whose claimed
    //              duration often dominates format.duration in iTunes .m4a
    //              files and survives stream-copy unchanged.
    // -f mp4      force the mp4 muxer (extension is .tmp so it can't be inferred).
    $cmd  = [$ffmpeg, '-nostdin', '-y', '-v', 'error', '-i', $path,
             '-map', '0:a', '-c', 'copy', '-movflags', '+faststart', '-f', 'mp4', $tmp];
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!$proc) return ['ok' => false, 'detail' => 'proc_open remux failed', 'new_duration' => 0.0];
    fclose($pipes[0]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        return ['ok' => false, 'detail' => 'remux exit=' . $exit . ' ' . substr(trim($stderr), 0, 200), 'new_duration' => 0.0];
    }

    // Verify the remuxed file is now internally consistent.
    $verify = auditCheckFile($ffmpeg, $ffprobe, $tmp);
    if ($verify['status'] !== 'OK') {
        @unlink($tmp);
        return ['ok' => false, 'detail' => 'remux did not produce a clean file (status=' . $verify['status'] . ')', 'new_duration' => 0.0];
    }

    // Move original to backup mirror, fixed into place.
    $backupPath = $backupBase . '/' . $rel;
    if (!is_dir(dirname($backupPath))) {
        if (!@mkdir(dirname($backupPath), 0755, true) && !is_dir(dirname($backupPath))) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => 'mkdir backup dir failed', 'new_duration' => 0.0];
        }
    }
    if (!@rename($path, $backupPath)) {
        @unlink($tmp);
        return ['ok' => false, 'detail' => 'rename original -> backup failed', 'new_duration' => 0.0];
    }
    if (!@rename($tmp, $path)) {
        @rename($backupPath, $path);   // best-effort restore
        @unlink($tmp);
        return ['ok' => false, 'detail' => 'rename fixed -> original failed; original restored', 'new_duration' => 0.0];
    }

    return ['ok' => true, 'detail' => '', 'new_duration' => $verify['reported']];
}

function auditLog($fp, string $status, string $rel, string $detail): void {
    $line = date('Y-m-d H:i:s') . "\t" . $status . "\t" . $rel . "\t" . $detail . "\n";
    fwrite($fp, $line);
    fflush($fp);
    if (PHP_SAPI === 'cli') fwrite(STDOUT, $line);
}

// ─── AUDIT: per-song background trigger (called from handleStream) ───────────
// Fast no-op in the common case. Returns void on every path; never throws.
// Every decision is logged so an empty audit-log means handleStream isn't even
// reaching this — useful signal during troubleshooting.
function auditMaybeTrigger(string $rel, string $origFull): void {
    auditEnsureFolders();
    $ext = strtolower(pathinfo($origFull, PATHINFO_EXTENSION));
    if ($ext !== 'm4a' && $ext !== 'mp4') {
        if (AUDIT_VERBOSE) auditTrace('TRIG_SKIP_EXT', $rel, "ext=$ext");
        return;
    }

    $mtime = @filemtime($origFull);
    if ($mtime === false) {
        auditTrace('TRIG_SKIP_NO_MTIME', $rel, '');
        return;
    }

    $state    = auditStateLoad();
    $cached   = isset($state[$rel]['mtime']) && (int)$state[$rel]['mtime'] === $mtime;
    $reconcileNeeded = auditStagingHasContent();
    if ($cached && !$reconcileNeeded) {
        if (AUDIT_VERBOSE) auditTrace('TRIG_CACHED', $rel, 'status=' . ($state[$rel]['status'] ?? '?'));
        return;
    }

    $php = findPhpCli();
    if (!$php) {
        auditTrace('TRIG_NO_PHP_CLI', $rel, 'findPhpCli returned null — set PHP_CLI path manually if needed');
        return;
    }
    if (!function_exists('exec')) {
        auditTrace('TRIG_NO_EXEC', $rel, 'exec() not available');
        return;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        auditTrace('TRIG_EXEC_DISABLED', $rel, 'exec is in php disable_functions');
        return;
    }

    // Detached background spawn. Stderr -> AUDIT_SPAWN_ERRLOG so a broken
    // spawn (e.g., "command not found") is visible instead of swallowed.
    $self = __FILE__;
    $cmd  = sprintf(
        'nohup %s %s audit-one %s > /dev/null 2>> %s &',
        escapeshellarg($php),
        escapeshellarg($self),
        escapeshellarg($rel),
        escapeshellarg(AUDIT_SPAWN_ERRLOG)
    );
    if (AUDIT_VERBOSE) auditTrace('TRIG_SPAWN', $rel, "php=$php cmd=" . substr($cmd, 0, 200));
    @exec($cmd);
}

// Returns the sidecar path if we've previously verified a fix for this file at
// its current mtime. null otherwise → caller serves the original.
function auditFixedPath(string $rel, string $origFull): ?string {
    $state = auditStateLoad();
    if (!isset($state[$rel])) return null;
    $entry = $state[$rel];
    if (($entry['status'] ?? '') !== 'fixed') return null;
    if ((int)($entry['mtime'] ?? 0) !== @filemtime($origFull)) return null; // original changed; sidecar may be stale
    $sidecar = AUDIT_FIXED_DIR . '/' . $rel;
    if (!is_file($sidecar)) return null;
    return $sidecar;
}

// ─── AUDIT: single-file CLI worker (spawned by auditMaybeTrigger) ────────────
function handleAuditOne(string $rel): void {
    if (PHP_SAPI !== 'cli') {
        auditTrace('WORKER_NOT_CLI', $rel, 'PHP_SAPI=' . PHP_SAPI);
        return;
    }
    auditEnsureFolders();
    $pid = function_exists('getmypid') ? getmypid() : '?';
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : '?';
    auditTrace('WORKER_START', $rel, "pid=$pid uid=$uid cwd=" . getcwd());

    $lockFp = @fopen(AUDIT_LOCK_FILE, 'c');
    if (!$lockFp) {
        auditTrace('WORKER_LOCK_OPEN_FAIL', $rel, 'cannot open ' . AUDIT_LOCK_FILE);
        return;
    }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        auditTrace('WORKER_LOCKED', $rel, 'another audit in progress — exiting');
        fclose($lockFp);
        return;
    }

    try {
        $rel  = ltrim($rel, '/');
        $base = realpath(MUSIC_DIR);
        if (!$base) {
            auditTrace('WORKER_NO_MUSIC_DIR', $rel, MUSIC_DIR . ' not resolvable');
            return;
        }
        $full = realpath($base . '/' . $rel);
        if (!$full || strpos($full, $base) !== 0 || !is_file($full)) {
            auditTrace('WORKER_PATH_INVALID', $rel, 'resolved=' . ($full ?: 'null') . ' base=' . $base);
            return;
        }
        $fixedReal = realpath(AUDIT_FIXED_DIR) ?: AUDIT_FIXED_DIR;
        if (strpos($full, $fixedReal) === 0) {
            auditTrace('WORKER_IS_SIDECAR', $rel, 'refusing to audit a sidecar');
            return;
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        if ($ext !== 'm4a' && $ext !== 'mp4') {
            auditTrace('WORKER_SKIP_EXT', $rel, "ext=$ext");
            return;
        }

        $mtime = @filemtime($full);
        if ($mtime === false) {
            auditTrace('WORKER_NO_MTIME', $rel, '');
            return;
        }

        $state = auditStateLoad();
        if (isset($state[$rel]['mtime']) && (int)$state[$rel]['mtime'] === $mtime) {
            auditTrace('WORKER_CACHED', $rel, 'status=' . ($state[$rel]['status'] ?? '?'));
            return;
        }

        $ffmpeg  = findFfmpeg();
        $ffprobe = findFfprobePath();
        if (!$ffmpeg || !$ffprobe) {
            auditTrace('WORKER_NO_TOOLS', $rel, 'ffmpeg=' . ($ffmpeg ?: 'null') . ' ffprobe=' . ($ffprobe ?: 'null'));
            return;
        }
        if (AUDIT_VERBOSE) auditTrace('WORKER_CHECK', $rel, "ffmpeg=$ffmpeg ffprobe=$ffprobe size=" . @filesize($full));

        $tStart = microtime(true);
        $result = auditCheckFile($ffmpeg, $ffprobe, $full);
        $elapsed = round(microtime(true) - $tStart, 2);

        if ($result['status'] === 'OK') {
            $entry = ['status' => 'ok', 'mtime' => $mtime, 'ts' => time(),
                      'reported' => $result['reported'], 'decoded' => $result['decoded']];
            auditTrace('OK', $rel, sprintf('reported=%.2fs decoded=%.2fs took=%ss', $result['reported'], $result['decoded'], $elapsed));
        } elseif ($result['status'] === 'PROBE_FAIL' || $result['status'] === 'DECODE_ERROR') {
            $entry = ['status' => 'unfixable', 'mtime' => $mtime, 'ts' => time(),
                      'detail' => $result['detail']];
            auditTrace($result['status'], $rel,
                sprintf('reported=%.2f decoded=%.2f took=%ss %s',
                    $result['reported'], $result['decoded'], $elapsed, $result['detail']));
        } else { // CORRUPT_FIXABLE
            auditTrace('CORRUPT', $rel,
                sprintf('reported=%.2fs decoded=%.2fs %s — repairing',
                    $result['reported'], $result['decoded'], $result['detail']));
            $sidecar = AUDIT_FIXED_DIR . '/' . $rel;
            $tFix = microtime(true);
            $fix = auditFixFileToSidecar($ffmpeg, $ffprobe, $full, $sidecar, $rel);
            $fixElapsed = round(microtime(true) - $tFix, 2);
            if ($fix['ok']) {
                $entry = ['status' => 'fixed', 'mtime' => $mtime, 'ts' => time(),
                          'method'   => $fix['method'],
                          'reported' => $result['reported'],
                          'decoded'  => $result['decoded'],
                          'new_duration' => $fix['new_duration'],
                          'fixed_size'   => @filesize($sidecar),
                          'fixed_mtime'  => @filemtime($sidecar)];
                auditTrace('FIXED', $rel,
                    sprintf('method=%s was=%.2fs now=%.2fs took=%ss sidecar=audit-fixed/%s',
                        $fix['method'], $result['reported'], $fix['new_duration'], $fixElapsed, $rel));
            } else {
                $entry = ['status' => 'fix_failed', 'mtime' => $mtime, 'ts' => time(),
                          'method'  => $fix['method'],
                          'detail'  => $fix['detail']];
                auditTrace('FIX_FAIL', $rel, "method={$fix['method']} took={$fixElapsed}s " . $fix['detail']);
            }
        }

        auditStateUpdate($rel, $entry);
        // Refresh the album-level manifest. If this song's status is now 'ok'
        // and no others in the album are damaged, the manifest gets removed.
        auditRefreshAlbumManifest(auditAlbumPath($rel), $ffprobe);
        // Surface damaged songs in each affected user's "Corrupt" playlist.
        if (($entry['status'] ?? 'ok') !== 'ok') {
            $usersFlagged = auditFlagCorruptForUsers(hash('crc32b', $rel));
            if (AUDIT_VERBOSE && $usersFlagged > 0) {
                auditTrace('PLAYLIST_CORRUPT_ADD', $rel, "flagged in $usersFlagged user playlist(s)");
            }
        }
        auditTrace('WORKER_END', $rel, 'status=' . $entry['status']);
    } catch (\Throwable $e) {
        auditTrace('WORKER_EXCEPTION', $rel, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        // Opportunistic reconciliation: runs even when song-level work
        // early-returned (e.g., cached song with staged replacement waiting).
        // Bounded to one swap per worker.
        try {
            if (auditStagingHasContent()) {
                $rcFf  = findFfmpeg();
                $rcFp  = findFfprobePath();
                if ($rcFf && $rcFp) {
                    $rc = auditReconcileStaged($rcFf, $rcFp);
                    if (AUDIT_VERBOSE) auditTrace('RECONCILE_DONE', '-', "considered={$rc['considered']} replaced={$rc['replaced']}");
                }
            }
        } catch (\Throwable $e2) {
            auditTrace('RECONCILE_EXCEPTION', '-', $e2->getMessage());
        }
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

// Lightweight log line. Opens, appends, closes — safe to call from any code
// path including the per-play hook where we don't hold an fd.
function auditTrace(string $status, string $rel, string $detail): void {
    $line = date('Y-m-d H:i:s') . "\t" . $status . "\t" . $rel . "\t" . $detail . "\n";
    @file_put_contents(AUDIT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// Two-pass sidecar repair:
//   Pass 1 — lossless `-c copy` remux. Fixes container-only damage.
//   Pass 2 — `-c:a aac` re-encode (lossy, gated by AUDIT_ALLOW_REENCODE).
//            Only runs if Pass 1 produced a file that still has decode/reported
//            mismatch, i.e. the audio packets themselves contain undecodable
//            data. Decoder reads only the good frames; encoder writes a fresh
//            container of correct length.
// Returns ['ok'=>bool, 'method'=>'remux'|'reencode'|'none', 'detail'=>str, 'new_duration'=>float]
// $rel is used only for log lines and may be ''.
function auditFixFileToSidecar(string $ffmpeg, string $ffprobe, string $src, string $dst, string $rel = ''): array {
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'method' => 'none', 'detail' => 'mkdir sidecar dir failed'];
    }
    $tmp = $dst . '.tmp';

    // ── Pass 1: lossless remux ──────────────────────────────────────────────
    @unlink($tmp);
    $cmd = [$ffmpeg, '-nostdin', '-y', '-v', 'error', '-i', $src,
            '-map', '0:a', '-c', 'copy', '-movflags', '+faststart', '-f', 'mp4', $tmp];
    [$exit, $stderr] = auditRunFfmpeg($cmd);

    if ($exit !== 0 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        $err = 'remux exit=' . $exit . ' ' . substr(trim($stderr), 0, 200);
        if ($rel !== '') auditTrace('REMUX_FAIL', $rel, $err);
        return ['ok' => false, 'method' => 'remux', 'detail' => $err];
    }

    $verify = auditCheckFile($ffmpeg, $ffprobe, $tmp);
    if ($verify['status'] === 'OK') {
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            return ['ok' => false, 'method' => 'remux', 'detail' => 'rename tmp -> sidecar failed'];
        }
        if ($rel !== '') auditTrace('REMUX_OK', $rel,
            sprintf('sidecar reported=%.2fs decoded=%.2fs', $verify['reported'], $verify['decoded']));
        return ['ok' => true, 'method' => 'remux', 'new_duration' => $verify['reported']];
    }

    // Remux finished cleanly but verify still mismatched → packet-level damage.
    @unlink($tmp);
    $remuxDetail = sprintf('sidecar reported=%.2fs decoded=%.2fs %s',
        $verify['reported'], $verify['decoded'], $verify['detail']);
    if ($rel !== '') auditTrace('REMUX_INSUFFICIENT', $rel, $remuxDetail . ' — packets themselves are damaged');

    if (!AUDIT_ALLOW_REENCODE) {
        return ['ok' => false, 'method' => 'remux',
                'detail' => 'remux insufficient; AUDIT_ALLOW_REENCODE=false so refusing to re-encode (' . $remuxDetail . ')'];
    }

    // ── Pass 2: re-encode AAC ───────────────────────────────────────────────
    // Decoder reads only the valid AAC frames; encoder writes a clean
    // container of correct length. Lossy, but quality loss is inaudible at
    // matching bitrate on already-lossy source.
    $bitrate = auditGetAudioBitrate($ffprobe, $src);
    $bArg    = $bitrate > 0 ? ($bitrate . 'k') : '256k';
    if ($rel !== '') auditTrace('REENCODE_START', $rel,
        "target_bitrate=$bArg (source=" . ($bitrate ?: '?') . "kbps)");

    @unlink($tmp);
    $cmd = [$ffmpeg, '-nostdin', '-y', '-v', 'error', '-i', $src,
            '-map', '0:a', '-c:a', 'aac', '-b:a', $bArg,
            '-movflags', '+faststart', '-f', 'mp4', $tmp];
    [$exit, $stderr] = auditRunFfmpeg($cmd);

    if ($exit !== 0 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        $err = 're-encode exit=' . $exit . ' ' . substr(trim($stderr), 0, 200);
        if ($rel !== '') auditTrace('REENCODE_FAIL', $rel, $err);
        return ['ok' => false, 'method' => 'reencode', 'detail' => $err];
    }

    $verify = auditCheckFile($ffmpeg, $ffprobe, $tmp);
    if ($verify['status'] !== 'OK') {
        @unlink($tmp);
        $err = sprintf('re-encode verify status=%s reported=%.2f decoded=%.2f',
            $verify['status'], $verify['reported'], $verify['decoded']);
        if ($rel !== '') auditTrace('REENCODE_VERIFY_FAIL', $rel, $err);
        return ['ok' => false, 'method' => 'reencode', 'detail' => $err];
    }

    if (!@rename($tmp, $dst)) {
        @unlink($tmp);
        return ['ok' => false, 'method' => 'reencode', 'detail' => 'rename tmp -> sidecar failed after re-encode'];
    }
    if ($rel !== '') auditTrace('REENCODE_OK', $rel,
        sprintf('sidecar reported=%.2fs decoded=%.2fs bitrate=%s', $verify['reported'], $verify['decoded'], $bArg));
    return ['ok' => true, 'method' => 'reencode', 'new_duration' => $verify['reported']];
}

function auditRunFfmpeg(array $cmd): array {
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!$proc) return [-1, 'proc_open failed'];
    fclose($pipes[0]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stderr];
}

function auditGetAudioBitrate(string $ffprobe, string $path): int {
    $json = ffprobeViaProcOpen($ffprobe, $path);
    if (!$json) return 0;
    $d = json_decode($json, true);
    if (!is_array($d)) return 0;
    foreach (($d['streams'] ?? []) as $s) {
        if (($s['codec_type'] ?? '') === 'audio' && !empty($s['bit_rate'])) {
            $kbps = (int)round(((int)$s['bit_rate']) / 1000);
            if ($kbps >= 64 && $kbps <= 512) return $kbps;
        }
    }
    if (!empty($d['format']['bit_rate'])) {
        $kbps = (int)round(((int)$d['format']['bit_rate']) / 1000);
        if ($kbps >= 64 && $kbps <= 512) return $kbps;
    }
    return 0;
}

// ─── AUDIT: state cache (load + atomic update under flock) ───────────────────
function auditStateLoad(): array {
    if (!is_file(AUDIT_STATE_FILE)) return [];
    $raw = @file_get_contents(AUDIT_STATE_FILE);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function auditStateUpdate(string $rel, array $entry): void {
    $fp = @fopen(AUDIT_STATE_FILE, 'c+');
    if (!$fp) return;
    if (!@flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw   = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];
    $state[$rel] = $entry;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
}

// PHP_BINARY in FPM points at php-fpm — useless for CLI invocation. Find php.
// Make sure all three workflow folders exist with a README the user can read
// in Finder when they first open them. Cheap; safe to call every spawn.
function auditEnsureFolders(): void {
    foreach ([AUDIT_REPLACE_QUEUE_DIR, AUDIT_STAGING_DIR, AUDIT_REPLACED_DIR] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    $stagingReadme = AUDIT_STAGING_DIR . '/README.txt';
    if (!is_file($stagingReadme)) {
        @file_put_contents($stagingReadme,
            "# Album Replacement Staging\n" .
            "\n" .
            "Drop a complete fresh copy of an album into THIS folder. Any subfolder\n" .
            "layout is fine — you don't have to recreate the original path. The\n" .
            "audit system identifies the album from its tags, validates every\n" .
            "track, renames new files to match the originals (keeping any new\n" .
            "extension), and swaps the album into the library.\n" .
            "\n" .
            "Supported formats: m4a, mp3, flac, aac, wav, ogg, opus, aiff, mp4\n" .
            "Identification:    album_artist (or artist) + album tags\n" .
            "Track matching:    track # + title (or duration as fallback)\n" .
            "\n" .
            "What happens next:\n" .
            "  - Background audit runs each time someone plays a song.\n" .
            "  - When a match is found and all tracks decode cleanly, the old\n" .
            "    album is archived to ../albums-replaced/ and the new one takes\n" .
            "    its place. Existing favorites / playlists / library entries\n" .
            "    survive because tracks are renamed to match the originals.\n" .
            "  - If the new copy is also damaged, VALIDATION_FAILED.txt appears\n" .
            "    in your folder explaining which tracks are still broken.\n" .
            "  - If tags don't match any pending replacement, NO_MATCH.txt\n" .
            "    appears with the tag mismatch details.\n" .
            "  - Trigger a check manually:  php api.php reconcile\n"
        );
    }
    $queueReadme = AUDIT_REPLACE_QUEUE_DIR . '/README.txt';
    if (!is_file($queueReadme)) {
        @file_put_contents($queueReadme,
            "# Albums Needing Replacement\n" .
            "\n" .
            "Each .txt file in this folder describes an album with at least one\n" .
            "audio-damaged track. To replace one:\n" .
            "\n" .
            "  1. Obtain a fresh copy of the album.\n" .
            "  2. Drop the folder into ../albums-staging/  (any layout).\n" .
            "  3. Play any song. The audit will validate, rename to match the\n" .
            "     originals, archive the old album, and swap the new one in.\n" .
            "\n" .
            "Manifests are regenerated automatically from audit-state.json.\n" .
            "They're deleted once the album is successfully replaced.\n"
        );
    }
}

function findPhpCli(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    foreach (['/usr/local/bin/php','/usr/bin/php','/opt/bin/php','/opt/php/bin/php','/volume1/@appstore/PHP7.4/usr/local/bin/php','/volume1/@appstore/PHP8.0/usr/local/bin/php','/volume1/@appstore/PHP8.2/usr/local/bin/php'] as $p) {
        if (@is_executable($p)) { $cached = $p; return $cached; }
    }
    $w = @shell_exec('which php 2>/dev/null');
    $cached = $w ? trim($w) : false;
    return $cached ?: null;
}

// ─── ALBUM REPLACEMENT WORKFLOW ──────────────────────────────────────────────
// auditAlbumPath(): given a song rel-path "albums/Mobile/Tomorrow Starts Today/03 X.m4a"
// returns the album dir rel-path "albums/Mobile/Tomorrow Starts Today".
function auditAlbumPath(string $songRel): string {
    $d = dirname($songRel);
    return ($d === '.' || $d === '') ? '' : $d;
}

function auditAlbumManifestName(string $albumRel): string {
    return str_replace('/', '__', $albumRel) . '.txt';
}

// Does AUDIT_STAGING_DIR contain anything? Cheap check used to decide whether
// reconciliation is worth running.
function auditStagingHasContent(): bool {
    if (!is_dir(AUDIT_STAGING_DIR)) return false;
    $items = @scandir(AUDIT_STAGING_DIR);
    if (!$items) return false;
    foreach ($items as $i) {
        if ($i !== '.' && $i !== '..') return true;
    }
    return false;
}

// Returns rel-paths of damaged songs (anything in state with status != 'ok')
// that share the given album prefix.
function auditDamagedSongsForAlbum(string $albumRel, array $state): array {
    $out = [];
    $prefix = $albumRel . '/';
    foreach ($state as $rel => $entry) {
        if (strpos($rel, $prefix) !== 0) continue;
        if (($entry['status'] ?? '') === 'ok') continue;
        $out[$rel] = $entry;
    }
    return $out;
}

// Count audio files in a directory (one level deep, no recursion).
function auditCountAudioFiles(string $dir): int {
    if (!is_dir($dir)) return 0;
    $exts = ['m4a','mp4','mp3','flac','aac','wav','ogg','opus','aiff'];
    $n = 0;
    foreach ((array)@scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item[0] === '.') continue;
        $full = $dir . '/' . $item;
        if (!is_file($full)) continue;
        if (in_array(strtolower(pathinfo($item, PATHINFO_EXTENSION)), $exts, true)) $n++;
    }
    return $n;
}

// Regenerate or remove the manifest for a single album, based on current state.
function auditRefreshAlbumManifest(string $albumRel, string $ffprobe = ''): void {
    if ($albumRel === '') return;
    if (!is_dir(AUDIT_REPLACE_QUEUE_DIR)) @mkdir(AUDIT_REPLACE_QUEUE_DIR, 0755, true);

    $manifestFile = AUDIT_REPLACE_QUEUE_DIR . '/' . auditAlbumManifestName($albumRel);
    $state = auditStateLoad();
    $damaged = auditDamagedSongsForAlbum($albumRel, $state);

    if (empty($damaged)) {
        @unlink($manifestFile);
        return;
    }

    // Pull artist + album tags from the first damaged song (best effort).
    $artist = ''; $album = '';
    if ($ffprobe) {
        $firstRel  = array_keys($damaged)[0];
        $firstFull = MUSIC_DIR . '/' . $firstRel;
        if (is_file($firstFull) && function_exists('tryFFprobe')) {
            $tags = @tryFFprobe($firstFull);
            if (is_array($tags)) {
                $artist = trim((string)($tags['album_artist'] ?: $tags['artist']));
                $album  = trim((string)$tags['album']);
            }
        }
    }
    if ($album === '')  $album  = basename($albumRel);
    if ($artist === '') $artist = basename(dirname($albumRel)) ?: '(unknown)';

    $totalTracks = auditCountAudioFiles(MUSIC_DIR . '/' . $albumRel);

    $lines = [];
    $lines[] = 'Album:    ' . $album;
    $lines[] = 'Artist:   ' . $artist;
    $lines[] = 'Path:     ' . $albumRel;
    $lines[] = 'Tracks:   ' . $totalTracks . ' total, ' . count($damaged) . ' damaged';
    $lines[] = 'Updated:  ' . date('Y-m-d H:i:s');
    $lines[] = '';
    $lines[] = 'Damaged tracks:';
    ksort($damaged);
    foreach ($damaged as $rel => $entry) {
        $name     = basename($rel);
        $reported = (float)($entry['reported'] ?? 0);
        $decoded  = (float)($entry['decoded']  ?? 0);
        $status   = $entry['status'] ?? '?';
        $method   = $entry['method'] ?? '';
        $gap      = abs($reported - $decoded);
        $lines[]  = sprintf('  %-50s  %.1fs missing  (decoded %.1fs of %.1fs)  [%s%s]',
            $name, $gap, $decoded, $reported, $status, $method ? ", $method" : '');
    }
    $lines[] = '';
    $lines[] = 'To replace this album:';
    $lines[] = '  1. Obtain a complete fresh copy of the album.';
    $lines[] = '  2. Drop the new album folder at:';
    $lines[] = '       albums-staging/' . $albumRel . '/';
    $lines[] = '  3. Play any song. The next background audit will validate the';
    $lines[] = '     replacement. If clean, the old album is moved to albums-replaced/';
    $lines[] = '     and the new one takes its place. If validation fails, a';
    $lines[] = '     VALIDATION_FAILED.txt appears inside the staging folder.';
    $lines[] = '';
    $lines[] = '(While you wait: damaged tracks still play via lossy re-encoded';
    $lines[] = ' sidecars in audit-fixed/. The sidecars are removed once the album';
    $lines[] = ' is replaced.)';

    @file_put_contents($manifestFile, implode("\n", $lines) . "\n");
}

// Tag-driven reconciliation. The user can drop a fresh album anywhere under
// albums-staging/ with any folder layout, any audio format (m4a/mp3/flac/...),
// and any track filename convention. The reconciler:
//   1. Scans albums-staging/ for "candidate" folders (any dir containing audio files).
//   2. Reads tags from each candidate to derive (album_artist, album).
//   3. Matches against pending manifests by tag equivalence.
//   4. Maps each new track to an original track by (track #, title, duration).
//   5. Renames matched new files to the originals' basenames (extension may change).
//   6. Validates every new file decodes cleanly.
//   7. Swaps the album into place and cleans up downstream state.
//
// Bounded to one swap per call to keep lock-hold time predictable.
function auditReconcileStaged(string $ffmpeg, string $ffprobe): array {
    $considered = 0; $replaced = 0;
    if (!is_dir(AUDIT_STAGING_DIR)) return compact('considered', 'replaced');

    // Build a map of damaged-album-rel-paths and their identifying tags.
    $state = auditStateLoad();
    $damagedAlbumsByRel = [];   // albumRel => true
    foreach ($state as $rel => $entry) {
        if (($entry['status'] ?? 'ok') === 'ok') continue;
        $alb = auditAlbumPath($rel);
        if ($alb !== '') $damagedAlbumsByRel[$alb] = true;
    }
    if (empty($damagedAlbumsByRel)) return compact('considered', 'replaced');

    // For each damaged album, learn (artist, album) tags from one of its (probably broken)
    // original files. Tags live in moov/udta and are intact even when audio packets aren't.
    $manifestTags = [];   // albumRel => ['artist'=>x, 'album'=>y]
    foreach (array_keys($damagedAlbumsByRel) as $alb) {
        $tags = auditReadAlbumTagsForOriginal($ffprobe, $alb);
        if ($tags) $manifestTags[$alb] = $tags;
    }

    // Walk staging tree to find candidate album folders.
    foreach (auditScanStagingCandidates(AUDIT_STAGING_DIR) as $candidateDir) {
        if (auditStagingRecentlyTouched($candidateDir, 20)) {
            auditTrace('RECONCILE_DEFER', auditRelToStaging($candidateDir), 'files modified <20s ago — copy in progress?');
            continue;
        }

        $candTags = auditReadAlbumTagsFromDir($ffprobe, $candidateDir);
        if (!$candTags || (!$candTags['artist'] && !$candTags['album'])) {
            auditWriteStagingFailure($candidateDir, "NO_MATCH.txt",
                "Could not read album/artist tags from any audio file in this folder.\n" .
                "Make sure files have proper ID3/MP4 tags (album_artist and album fields).\n");
            auditTrace('RECONCILE_NO_TAGS', auditRelToStaging($candidateDir), 'cannot identify album from tags');
            continue;
        }

        // Match this candidate to one of the pending manifests by tag.
        $matchedAlbumRel = auditFindManifestMatch($candTags, $manifestTags);
        if ($matchedAlbumRel === null) {
            auditWriteStagingFailure($candidateDir, "NO_MATCH.txt",
                "This folder contains an album that does not match any of the\n" .
                "pending replacement manifests.\n\n" .
                "Tags read from this folder:\n" .
                "  album_artist: " . ($candTags['artist'] ?: '(empty)') . "\n" .
                "  album:        " . ($candTags['album']  ?: '(empty)') . "\n\n" .
                "Pending albums needing replacement:\n" .
                implode("\n", array_map(function($k) use ($manifestTags) {
                    $t = $manifestTags[$k] ?? ['artist'=>'?','album'=>'?'];
                    return "  - {$t['artist']} / {$t['album']}   ($k)";
                }, array_keys($manifestTags))) . "\n\n" .
                "Either drop the correct album here, or remove this folder.\n" .
                "Delete this NO_MATCH.txt to have the system re-check on the next audit run.\n");
            auditTrace('RECONCILE_NO_MATCH', auditRelToStaging($candidateDir),
                "candidate=" . ($candTags['artist'] ?: '?') . " / " . ($candTags['album'] ?: '?'));
            continue;
        }

        $considered++;
        $relForLog = $matchedAlbumRel;
        auditTrace('RECONCILE_MATCH', $relForLog,
            "candidate=" . auditRelToStaging($candidateDir) . " tags=" . $candTags['artist'] . " / " . $candTags['album']);

        // Build a rename map: candidate file path => target basename (matching old).
        $origAlbumDir = realpath(MUSIC_DIR) . '/' . $matchedAlbumRel;
        $matching = auditMatchTracks($ffprobe, $origAlbumDir, $candidateDir);
        auditTrace('RECONCILE_TRACKS', $relForLog,
            "matched=" . count($matching['matched']) . " unmatched_new=" . count($matching['unmatched_new']) .
            " missing_from_new=" . count($matching['missing_from_new']));

        // Validate every candidate audio file BEFORE renaming (so if validation
        // fails the staging area is left untouched).
        $v = auditValidateStagingAlbum($ffmpeg, $ffprobe, $candidateDir);
        if (!$v['ok']) {
            $msg  = "Replacement validation failed " . date('Y-m-d H:i:s') . "\n";
            $msg .= "Matched manifest: $matchedAlbumRel\n\n";
            $msg .= "The replacement copy has audio damage in the following tracks.\n";
            $msg .= "Re-source from a different copy. Delete this file to retry.\n\n";
            foreach ($v['failures'] as $name => $detail) {
                $msg .= "  - $name\n      $detail\n";
            }
            auditWriteStagingFailure($candidateDir, "VALIDATION_FAILED.txt", $msg);
            auditTrace('RECONCILE_VALIDATION_FAIL', $relForLog,
                count($v['failures']) . ' track(s) failed validation');
            continue;
        }

        // Apply renames in place — only matched files.
        $renamed = auditApplyTrackRenames($matching['matched']);
        auditTrace('RECONCILE_RENAMED', $relForLog, "renamed " . $renamed . " file(s) to match originals");

        // Perform the swap.
        $swap = auditReplaceAlbum($matchedAlbumRel, $candidateDir, $matching);
        if ($swap['ok']) {
            $replaced++;
            auditTrace('ALBUM_REPLACED', $relForLog,
                "archived={$swap['archived']} new_tracks=" . auditCountAudioFiles(MUSIC_DIR . '/' . $matchedAlbumRel) .
                " orphans_pruned={$swap['orphans_pruned']}" .
                " rekeyed=" . ($swap['rekeyed'] ?? 0) .
                " still_orphaned=" . ($swap['still_orphaned'] ?? 0) .
                " playlists_touched=" . ($swap['playlists_touched'] ?? 0));
        } else {
            auditTrace('ALBUM_REPLACE_FAIL', $relForLog, $swap['detail']);
        }
        // Bounded: one swap per reconcile call.
        break;
    }

    return compact('considered', 'replaced');
}

// Yield every directory under $root that contains at least one audio file.
function auditScanStagingCandidates(string $root): array {
    if (!is_dir($root)) return [];
    $exts = ['m4a','mp4','mp3','flac','aac','wav','ogg','opus','aiff'];
    $out  = [];
    $stack = [$root];
    while ($stack) {
        $dir = array_pop($stack);
        $hasAudio = false;
        $subs = [];
        foreach ((array)@scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            if ($item[0] === '.') continue;
            if ($item === 'README.txt') continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) { $subs[] = $full; continue; }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $exts, true)) $hasAudio = true;
        }
        if ($hasAudio) $out[] = $dir;
        foreach ($subs as $s) $stack[] = $s;
    }
    return $out;
}

function auditRelToStaging(string $absPath): string {
    $base = realpath(AUDIT_STAGING_DIR) ?: AUDIT_STAGING_DIR;
    $r = realpath($absPath) ?: $absPath;
    if (strpos($r, $base) === 0) return ltrim(substr($r, strlen($base)), '/');
    return basename($absPath);
}

// Read tags from one of the (likely damaged) original album files in MUSIC_DIR.
// Tags survive packet-level damage because they live in moov/udta.
function auditReadAlbumTagsForOriginal(string $ffprobe, string $albumRel): ?array {
    $dir = MUSIC_DIR . '/' . $albumRel;
    if (!is_dir($dir) || !$ffprobe) return null;
    return auditReadAlbumTagsFromDir($ffprobe, $dir);
}

// Read normalised (artist, album) tags from the first audio file in $dir
// whose ffprobe returns usable metadata.
function auditReadAlbumTagsFromDir(string $ffprobe, string $dir): ?array {
    if (!$ffprobe || !is_dir($dir)) return null;
    $exts = ['m4a','mp4','mp3','flac','aac','wav','ogg','opus','aiff'];
    $items = (array)@scandir($dir);
    sort($items);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item[0] === '.') continue;
        $full = $dir . '/' . $item;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $tags = function_exists('tryFFprobe') ? @tryFFprobe($full) : null;
        if (!is_array($tags)) continue;
        $artist = trim((string)($tags['album_artist'] ?: $tags['artist']));
        $album  = trim((string)$tags['album']);
        if ($artist === '' && $album === '') continue;
        return ['artist' => $artist, 'album' => $album];
    }
    return null;
}

// Find which pending-manifest album the candidate's tags refer to. Match is
// case-insensitive after stripping non-alphanumerics. Returns albumRel or null.
function auditFindManifestMatch(array $candTags, array $manifestTags): ?string {
    $cKey = auditNormalize($candTags['artist']) . '|' . auditNormalize($candTags['album']);
    foreach ($manifestTags as $albumRel => $mt) {
        $mKey = auditNormalize($mt['artist']) . '|' . auditNormalize($mt['album']);
        if ($mKey === $cKey) return $albumRel;
    }
    // Looser fallback: album name alone (handles "Various Artists" / compilation cases).
    foreach ($manifestTags as $albumRel => $mt) {
        if (auditNormalize($mt['album']) !== '' &&
            auditNormalize($mt['album']) === auditNormalize($candTags['album'])) {
            return $albumRel;
        }
    }
    return null;
}

function auditNormalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    // Strip parenthetical qualifiers like "(Remastered 2011)", "(feat. X)".
    $s = preg_replace('/\s*\([^)]*\)\s*/u', '', $s);
    $s = preg_replace('/\s*\[[^\]]*\]\s*/u', '', $s);
    // Strip all non-alphanumeric (ASCII + unicode word chars).
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    return (string)$s;
}

// Build a tag-keyed map for every audio file in $dir. Returns
//   [ basename => ['track'=>int, 'disc'=>int, 'title'=>str, 'duration'=>float, 'path'=>str] ].
function auditReadTrackTags(string $ffprobe, string $dir): array {
    $out = [];
    if (!$ffprobe || !is_dir($dir)) return $out;
    $exts = ['m4a','mp4','mp3','flac','aac','wav','ogg','opus','aiff'];
    foreach ((array)@scandir($dir) as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        $full = $dir . '/' . $item;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $tags = function_exists('tryFFprobe') ? @tryFFprobe($full) : null;
        if (!is_array($tags)) continue;
        $out[$item] = [
            'track'    => (int)($tags['track'] ?? 0),
            'disc'     => (int)($tags['disc']  ?? 1),
            'title'    => trim((string)($tags['title'] ?? '')),
            'duration' => (float)($tags['duration'] ?? 0),
            'path'     => $full,
        ];
    }
    return $out;
}

// Match new files to original files by tags. Returns
//   ['matched' => [newPath => targetBasename (with new ext)],
//    'unmatched_new'    => [newPath, ...]   — bonus tracks or unmatchable
//    'missing_from_new' => [origBasename, ...]  — present in old, absent from new
//   ]
function auditMatchTracks(string $ffprobe, string $origDir, string $newDir): array {
    $orig = auditReadTrackTags($ffprobe, $origDir);   // basename => meta
    $new  = auditReadTrackTags($ffprobe, $newDir);     // basename => meta

    $matched = [];
    $usedNew = [];

    // Index new tracks by track+title and by track+duration for two-pass lookup.
    $newByTitle = [];
    $newByTrack = [];
    foreach ($new as $newBase => $meta) {
        $tk = ($meta['disc'] ?: 1) . ':' . $meta['track'];
        $tkTitle = $tk . '|' . auditNormalize($meta['title']);
        $newByTitle[$tkTitle][] = $newBase;
        $newByTrack[$tk][] = $newBase;
    }

    // Pass 1: track + normalized title.
    foreach ($orig as $origBase => $om) {
        $tk = ($om['disc'] ?: 1) . ':' . $om['track'];
        $tkTitle = $tk . '|' . auditNormalize($om['title']);
        if (!isset($newByTitle[$tkTitle])) continue;
        foreach ($newByTitle[$tkTitle] as $candBase) {
            if (isset($usedNew[$candBase])) continue;
            $usedNew[$candBase] = true;
            $matched[$new[$candBase]['path']] = auditRenameTarget($origBase, $candBase);
            break;
        }
    }

    // Pass 2: for still-unmatched originals, match by track # + duration tolerance.
    foreach ($orig as $origBase => $om) {
        $alreadyMatched = false;
        foreach ($matched as $tgtBasename) {
            if (pathinfo($tgtBasename, PATHINFO_FILENAME) === pathinfo($origBase, PATHINFO_FILENAME)) { $alreadyMatched = true; break; }
        }
        if ($alreadyMatched) continue;
        $tk = ($om['disc'] ?: 1) . ':' . $om['track'];
        if (!isset($newByTrack[$tk])) continue;
        foreach ($newByTrack[$tk] as $candBase) {
            if (isset($usedNew[$candBase])) continue;
            // Accept if duration is within 5 seconds (loose — broken originals
            // have wrong container duration anyway).
            if ($om['duration'] > 0 && $new[$candBase]['duration'] > 0
                && abs($new[$candBase]['duration'] - $om['duration']) > 5) continue;
            $usedNew[$candBase] = true;
            $matched[$new[$candBase]['path']] = auditRenameTarget($origBase, $candBase);
            break;
        }
    }

    // Pass 3: by title alone (handles missing/wrong track numbers).
    foreach ($orig as $origBase => $om) {
        $title = auditNormalize($om['title']);
        if ($title === '') continue;
        $alreadyMatched = false;
        foreach ($matched as $tgtBasename) {
            if (pathinfo($tgtBasename, PATHINFO_FILENAME) === pathinfo($origBase, PATHINFO_FILENAME)) { $alreadyMatched = true; break; }
        }
        if ($alreadyMatched) continue;
        foreach ($new as $candBase => $nm) {
            if (isset($usedNew[$candBase])) continue;
            if (auditNormalize($nm['title']) !== $title) continue;
            $usedNew[$candBase] = true;
            $matched[$new[$candBase]['path']] = auditRenameTarget($origBase, $candBase);
            break;
        }
    }

    $unmatchedNew     = [];
    foreach ($new as $candBase => $nm) {
        if (!isset($usedNew[$candBase])) $unmatchedNew[] = $nm['path'];
    }
    $missingFromNew   = [];
    foreach ($orig as $origBase => $om) {
        $found = false;
        foreach ($matched as $tgtBasename) {
            if (pathinfo($tgtBasename, PATHINFO_FILENAME) === pathinfo($origBase, PATHINFO_FILENAME)) { $found = true; break; }
        }
        if (!$found) $missingFromNew[] = $origBase;
    }

    return [
        'matched'          => $matched,
        'unmatched_new'    => $unmatchedNew,
        'missing_from_new' => $missingFromNew,
    ];
}

// Given an original filename and a candidate filename, produce the target
// basename for the candidate — original's name with the candidate's extension.
function auditRenameTarget(string $origBase, string $candBase): string {
    $stem = pathinfo($origBase, PATHINFO_FILENAME);
    $ext  = $candBase ? pathinfo($candBase, PATHINFO_EXTENSION) : pathinfo($origBase, PATHINFO_EXTENSION);
    return $stem . '.' . $ext;
}

// Rename matched candidate files to their target basenames, in place.
// Returns the number of files actually renamed.
function auditApplyTrackRenames(array $matched): int {
    $count = 0;
    foreach ($matched as $srcPath => $targetBasename) {
        $dir = dirname($srcPath);
        $dst = $dir . '/' . $targetBasename;
        if ($srcPath === $dst) continue;
        // If target already exists, free it (different file with same target name).
        if (file_exists($dst)) @unlink($dst);
        if (@rename($srcPath, $dst)) $count++;
    }
    return $count;
}

// True if any audio file in the staging dir was modified in the last $seconds.
// Used to avoid validating an in-progress copy.
function auditStagingRecentlyTouched(string $dir, int $seconds): bool {
    $cutoff = time() - $seconds;
    foreach ((array)@scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dir . '/' . $item;
        if (is_file($full) && @filemtime($full) > $cutoff) return true;
        if (is_dir($full) && auditStagingRecentlyTouched($full, $seconds)) return true;
    }
    return false;
}

// Validate every audio file in a folder. Codec-agnostic.
//   - For MP4-family: require auditCheckFile == OK (gap <0.5s either way).
//   - For others: require ffmpeg decodes to completion without error.
// Returns ['ok'=>bool, 'failures'=>[basename=>detail]].
function auditValidateStagingAlbum(string $ffmpeg, string $ffprobe, string $stagingDir): array {
    $failures = [];
    $audioCount = 0;
    $exts = ['m4a','mp4','mp3','flac','aac','wav','ogg','opus','aiff'];

    foreach ((array)@scandir($stagingDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item === 'VALIDATION_FAILED.txt' || $item === 'NO_MATCH.txt' || $item === 'README.txt') continue;
        if ($item[0] === '.') continue;
        $full = $stagingDir . '/' . $item;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $audioCount++;

        if ($ext === 'm4a' || $ext === 'mp4') {
            $check = auditCheckFile($ffmpeg, $ffprobe, $full);
            if ($check['status'] !== 'OK') {
                $failures[$item] = sprintf('status=%s reported=%.2fs decoded=%.2fs %s',
                    $check['status'], $check['reported'], $check['decoded'], $check['detail']);
            }
        } else {
            $cmd = [$ffmpeg, '-nostdin', '-v', 'error', '-i', $full, '-f', 'null', '-'];
            [$exit, $stderr] = auditRunFfmpeg($cmd);
            if ($exit !== 0) $failures[$item] = 'decode failed exit=' . $exit . ' ' . substr(trim($stderr), 0, 160);
        }
    }

    if ($audioCount === 0) return ['ok' => false, 'failures' => ['<dir>' => 'no audio files in staging folder']];
    return ['ok' => empty($failures), 'failures' => $failures];
}

function auditWriteStagingFailure(string $dir, string $name, string $body): void {
    @file_put_contents($dir . '/' . $name, $body);
}

// Perform the album swap. Cross-volume safe: rename() is attempted first
// (fast, atomic on same fs); falls back to copy+delete across Synology volumes.
//
// $stagingDir is the actual folder the user dropped (anywhere under
// albums-staging/); $matching is the result of auditMatchTracks(). Track-level
// renames must already be applied to $stagingDir before this call.
//
// After the swap, user-data files (userlib/meta/playlists) are scanned for
// orphan IDs whose path used to live under this album and no longer exists,
// and those entries are pruned. IDs that still resolve to a file in the new
// album (because we renamed-to-match) carry over untouched.
function auditReplaceAlbum(string $albumRel, string $stagingDir, array $matching = []): array {
    $baseReal = realpath(MUSIC_DIR);
    if (!$baseReal) return ['ok' => false, 'detail' => 'MUSIC_DIR unresolvable'];

    $orig = $baseReal . '/' . $albumRel;
    if (!is_dir($orig))       return ['ok' => false, 'detail' => 'original album dir missing: ' . $orig];
    if (!is_dir($stagingDir)) return ['ok' => false, 'detail' => 'staging dir missing: ' . $stagingDir];

    if (!is_dir(AUDIT_REPLACED_DIR) && !@mkdir(AUDIT_REPLACED_DIR, 0755, true) && !is_dir(AUDIT_REPLACED_DIR)) {
        return ['ok' => false, 'detail' => 'cannot create albums-replaced/'];
    }

    // Snapshot the OLD song basenames before we touch anything so we can
    // compute orphan IDs precisely after the swap.
    $oldBasenames = [];
    foreach ((array)@scandir($orig) as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        if (is_file($orig . '/' . $item)) $oldBasenames[] = $item;
    }

    $safeName = str_replace('/', '__', $albumRel) . '_' . date('Ymd-His');
    $archive  = AUDIT_REPLACED_DIR . '/' . $safeName;

    // Step 1: archive the original (copy, leaving it in place).
    if (!auditCopyDir($orig, $archive)) {
        auditRmrf($archive);
        return ['ok' => false, 'detail' => 'copy original -> archive failed'];
    }

    // Step 2: move original aside (safety net), then install staging into place.
    $origTmp = $orig . '.replacing.' . getmypid();
    if (!@rename($orig, $origTmp)) {
        auditRmrf($archive);
        return ['ok' => false, 'detail' => 'temp-rename of original failed'];
    }
    if (!auditMoveDir($stagingDir, $orig)) {
        @rename($origTmp, $orig);
        auditRmrf($archive);
        return ['ok' => false, 'detail' => 'install of staged copy failed; original restored'];
    }
    auditRmrf($origTmp);

    // Step 3: prune any leftover empty parent dirs in albums-staging/ that
    // resulted from moving out the user's nested drop.
    auditPruneEmptyStagingDirs($stagingDir);

    // Cleanup downstream audit state.
    auditStateClearPrefix($albumRel);
    auditRmrf(AUDIT_FIXED_DIR . '/' . $albumRel);
    @unlink(AUDIT_REPLACE_QUEUE_DIR . '/' . auditAlbumManifestName($albumRel));

    // User-data orphan cleanup: any old basename that has no corresponding
    // file in the new album (rename target gone or removed) is an orphan ID.
    $orphans = [];
    foreach ($oldBasenames as $b) {
        $stem = pathinfo($b, PATHINFO_FILENAME);
        $foundInNew = false;
        foreach ((array)@scandir($orig) as $newItem) {
            if ($newItem === '.' || $newItem === '..') continue;
            if (pathinfo($newItem, PATHINFO_FILENAME) === $stem) { $foundInNew = true; break; }
        }
        if (!$foundInNew) {
            $orphans[] = hash('crc32b', $albumRel . '/' . $b);
        }
    }
    $orphansPruned = 0;
    if ($orphans) $orphansPruned = auditCleanupUserDataIds($orphans);

    // Force library scanner to rebuild so new tracks appear in the UI.
    @unlink(CACHE_FILE);
    @unlink(FINGERPRINT_FILE);

    // Heal user-data: re-key any entries whose ID no longer resolves but
    // whose stored (artist,album,title) matches a song in the new library.
    // Belt-and-suspenders backup for the orphan-prune step above, which only
    // catches stem-identical renames. Tag-based lookup covers everything else.
    $heal = auditHealUserData($albumRel);

    // Migrate any of those rekeyed songs from each user's "Corrupt" playlist
    // into their "Recently Replaced" playlist so the workflow is visible in
    // the front-end.
    $playlistsTouched = auditMarkReplacedForUsers($heal['rekey_map'] ?? []);

    return ['ok' => true, 'archived' => 'albums-replaced/' . $safeName,
            'orphans_pruned'    => $orphansPruned,
            'rekeyed'           => $heal['rekeyed'],
            'still_orphaned'    => $heal['still_orphaned'],
            'playlists_touched' => $playlistsTouched];
}

// Walk up from the candidate dir and rmdir any now-empty parent that still
// lives under albums-staging/. Stops at AUDIT_STAGING_DIR itself.
function auditPruneEmptyStagingDirs(string $startDir): void {
    $base = realpath(AUDIT_STAGING_DIR) ?: AUDIT_STAGING_DIR;
    $d = dirname($startDir);
    while ($d && strpos($d, $base) === 0 && $d !== $base) {
        $children = @scandir($d);
        $hasContent = false;
        foreach ((array)$children as $c) {
            if ($c !== '.' && $c !== '..') { $hasContent = true; break; }
        }
        if ($hasContent) break;
        if (!@rmdir($d)) break;
        $d = dirname($d);
    }
}

// Remove the given crc32b song-IDs from every user-data JSON file in the web
// folder. Touches: userlib-*.json (ids map), meta-*.json (id map), playlists-*.json
// (sids arrays). Returns total number of entries pruned across all files.
function auditCleanupUserDataIds(array $ids): int {
    if (empty($ids)) return 0;
    $idSet = array_flip($ids);
    $removed = 0;

    foreach (glob(__DIR__ . '/.userlib-*.json') ?: [] as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data) || !isset($data['ids']) || !is_array($data['ids'])) continue;
        $changed = false;
        foreach (array_keys($data['ids']) as $id) {
            if (isset($idSet[$id])) { unset($data['ids'][$id]); $removed++; $changed = true; }
        }
        if ($changed) @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    foreach (glob(__DIR__ . '/.meta-*.json') ?: [] as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data)) continue;
        $changed = false;
        foreach (array_keys($data) as $id) {
            if (isset($idSet[$id])) { unset($data[$id]); $removed++; $changed = true; }
        }
        if ($changed) @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    foreach (glob(__DIR__ . '/.playlists-*.json') ?: [] as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data) || !isset($data['playlists']) || !is_array($data['playlists'])) continue;
        $changed = false;
        foreach ($data['playlists'] as &$pl) {
            if (!isset($pl['sids']) || !is_array($pl['sids'])) continue;
            $before = count($pl['sids']);
            $pl['sids'] = array_values(array_filter($pl['sids'], function($sid) use ($idSet) {
                return !isset($idSet[$sid]);
            }));
            $after = count($pl['sids']);
            if ($before !== $after) { $removed += ($before - $after); $changed = true; }
        }
        unset($pl);
        if ($changed) @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    return $removed;
}

// Move a directory tree. Tries rename() first (atomic, same-fs), falls back
// to copy+delete for cross-volume moves (Synology /volume1 vs /volume3).
function auditMoveDir(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (is_dir($dst))  return false;
    if (@rename($src, $dst)) return true;
    if (!auditCopyDir($src, $dst)) {
        auditRmrf($dst);
        return false;
    }
    auditRmrf($src);
    return !is_dir($src);
}

// Recursive directory copy. Returns true if every file copied.
function auditCopyDir(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) return false;
    foreach ((array)@scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s) && !is_link($s)) {
            if (!auditCopyDir($s, $d)) return false;
        } else {
            if (!@copy($s, $d)) return false;
        }
    }
    return true;
}

// Remove all audit-state.json entries whose key starts with "$prefix/".
function auditStateClearPrefix(string $prefix): void {
    $fp = @fopen(AUDIT_STATE_FILE, 'c+');
    if (!$fp) return;
    if (!@flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw   = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];
    $p = $prefix . '/';
    $changed = false;
    foreach (array_keys($state) as $k) {
        if (strpos($k, $p) === 0) { unset($state[$k]); $changed = true; }
    }
    if ($changed) {
        rewind($fp); ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    @flock($fp, LOCK_UN);
    fclose($fp);
}

// Recursive directory removal. Used to clean stale sidecars after a swap.
function auditRmrf(string $dir): void {
    if (!is_dir($dir)) return;
    foreach ((array)@scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p) && !is_link($p)) auditRmrf($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

// CLI: php api.php reconcile
//   1. Rewrites every album manifest from current audit-state.json
//      (catches up albums whose damage was recorded before this workflow existed)
//   2. Validates and swaps any staged replacements that are ready
// CLI: php api.php heal-userdata [albumRel]
//   Re-keys user-data entries (userlib/meta/playlists) whose path-derived ID
//   no longer resolves to a file in MUSIC_DIR, by looking up the stored
//   (title, artist, album) in the current library scan and re-keying to the
//   new ID. Use with no arg to heal everything; with an albumRel to scope.
function handleHealUserData(string $albumRel = ''): void {
    if (PHP_SAPI !== 'cli') return;
    auditEnsureFolders();
    $stats = auditHealUserData($albumRel);
    fwrite(STDOUT, "heal-userdata results:\n");
    foreach ($stats as $k => $v) fwrite(STDOUT, "  $k: $v\n");
}

// Build a tag→id lookup of the current library, then walk every user-data
// file. For each entry whose stored ID no longer resolves to a real song
// in the library, look it up by (artist, album, title) and re-key.
// Returns ['scanned', 'rekeyed', 'still_orphaned', 'files_touched'].
function auditHealUserData(string $albumRelScope = ''): array {
    $stats = ['scanned' => 0, 'rekeyed' => 0, 'still_orphaned' => 0, 'files_touched' => 0];

    // 1. Walk MUSIC_DIR fresh (don't trust caches) and build:
    //    $validIds[id] = true                            — every ID currently resolvable
    //    $tagIndex["artist|album|title"] = id            — for fuzzy re-keying
    if (!is_dir(MUSIC_DIR)) return $stats;
    $ffprobe = findFfprobePath();
    $validIds = [];
    $tagIndex = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(MUSIC_DIR, FilesystemIterator::SKIP_DOTS));
    $exts = ['mp3','m4a','aac','flac','ogg','wav','opus','aiff','wma','mp4'];
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), $exts, true)) continue;
        $full = $file->getPathname();
        $rel  = ltrim(substr($full, strlen(realpath(MUSIC_DIR))), '/');
        if ($albumRelScope !== '' && strpos($rel, $albumRelScope . '/') !== 0) continue;
        $id   = hash('crc32b', $rel);
        $validIds[$id] = true;
        $tags = $ffprobe ? @tryFFprobe($full) : null;
        if (is_array($tags)) {
            $key = auditTagKey(
                (string)($tags['album_artist'] ?: $tags['artist']),
                (string)$tags['album'],
                (string)$tags['title']);
            if ($key !== '||') $tagIndex[$key] = $id;
        }
    }

    // 2. Walk every user-data file in __DIR__ (the dotfiles).
    $userlibs  = glob(__DIR__ . '/.userlib-*.json')   ?: [];
    $metas     = glob(__DIR__ . '/.meta-*.json')      ?: [];
    $playlists = glob(__DIR__ . '/.playlists-*.json') ?: [];

    // 2a. Userlib: build per-file rekey map by examining each entry's stored tags.
    $rekeyMap = [];   // oldId => newId (global across all files, since IDs are universal hashes)

    foreach ($userlibs as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data) || !isset($data['ids']) || !is_array($data['ids'])) continue;
        $touched = false;
        foreach ($data['ids'] as $oldId => $entry) {
            $stats['scanned']++;
            if (isset($validIds[$oldId])) continue;   // still resolvable
            if (!is_array($entry)) { $stats['still_orphaned']++; continue; }
            // Scope: skip entries outside this album when scope is set.
            if ($albumRelScope !== ''
                && stripos((string)($entry['album'] ?? ''), basename($albumRelScope)) === false) {
                $stats['still_orphaned']++;
                continue;
            }
            $key = auditTagKey(
                (string)($entry['artist'] ?? ''),
                (string)($entry['album']  ?? ''),
                (string)($entry['title']  ?? ''));
            if (isset($tagIndex[$key])) {
                $newId = $tagIndex[$key];
                $rekeyMap[$oldId] = $newId;
                $data['ids'][$newId] = $entry;
                unset($data['ids'][$oldId]);
                $stats['rekeyed']++;
                $touched = true;
            } else {
                $stats['still_orphaned']++;
            }
        }
        if ($touched) {
            @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stats['files_touched']++;
        }
    }

    // 2b. Meta files: keyed by ID, no tags inside, so we rely on the rekey
    //     map built from userlibs above. This is best-effort — meta entries
    //     for songs that aren't in any userlib won't get rekeyed (we don't
    //     know what they refer to).
    foreach ($metas as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data)) continue;
        $touched = false;
        foreach (array_keys($data) as $id) {
            if (isset($rekeyMap[$id])) {
                $newId = $rekeyMap[$id];
                $data[$newId] = $data[$id];
                unset($data[$id]);
                $touched = true;
            }
        }
        if ($touched) {
            @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stats['files_touched']++;
        }
    }

    // 2c. Playlists: rewrite sids arrays in place.
    foreach ($playlists as $f) {
        $data = @json_decode(@file_get_contents($f), true);
        if (!is_array($data) || !isset($data['playlists']) || !is_array($data['playlists'])) continue;
        $touched = false;
        foreach ($data['playlists'] as &$pl) {
            if (!isset($pl['sids']) || !is_array($pl['sids'])) continue;
            foreach ($pl['sids'] as $i => $sid) {
                if (isset($rekeyMap[$sid])) {
                    $pl['sids'][$i] = $rekeyMap[$sid];
                    $touched = true;
                }
            }
        }
        unset($pl);
        if ($touched) {
            @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stats['files_touched']++;
        }
    }

    $stats['rekey_map'] = $rekeyMap;
    return $stats;
}

// Build a stable tag-comparison key from (artist, album, title).
function auditTagKey(string $artist, string $album, string $title): string {
    return auditNormalize($artist) . '|' . auditNormalize($album) . '|' . auditNormalize($title);
}

// ─── AUTO-MANAGED USER PLAYLISTS ─────────────────────────────────────────────
// "Corrupt" and "Recently Replaced" appear in each user's playlists with no
// manual setup. The user can rename or delete them at any time; the system
// recreates them with the canonical names next time an event fires.

// Yield [userlibPath, playlistsPath] for every user that has either file.
function auditUserDataPairs(): array {
    $pairs = [];
    foreach (glob(__DIR__ . '/.userlib-*.json') ?: [] as $u) {
        if (!preg_match('/\.userlib-([a-f0-9]+)\.json$/', $u, $m)) continue;
        $slug = $m[1];
        $pairs[$slug] = [
            'userlib'   => $u,
            'playlists' => __DIR__ . '/.playlists-' . $slug . '.json',
        ];
    }
    return $pairs;
}

// Load a playlists file (defaults to empty doc) or return null if unreadable.
function auditLoadPlaylistsFile(string $path): array {
    if (!is_file($path)) return ['playlists' => []];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return ['playlists' => []];
    $d = @json_decode($raw, true);
    if (!is_array($d)) return ['playlists' => []];
    if (!isset($d['playlists']) || !is_array($d['playlists'])) $d['playlists'] = [];
    return $d;
}

function auditSavePlaylistsFile(string $path, array $data): void {
    $data['saved'] = time();
    @file_put_contents($path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// Find a playlist by name (case-sensitive), or append a fresh one. Returns
// a reference to the playlist array inside $data['playlists'].
function &auditFindOrCreatePlaylist(array &$data, string $name): array {
    foreach ($data['playlists'] as $i => $pl) {
        if (($pl['name'] ?? '') === $name) return $data['playlists'][$i];
    }
    $newPl = [
        'id'      => 'audit' . substr(bin2hex(random_bytes(4)), 0, 8),
        'name'    => $name,
        'sids'    => [],
        'created' => time() * 1000,
        'updated' => time(),
    ];
    $data['playlists'][] = $newPl;
    return $data['playlists'][count($data['playlists']) - 1];
}

// Add $sid to the named playlist if not already present. Returns true if added.
function auditPlaylistAdd(array &$data, string $name, string $sid): bool {
    $pl =& auditFindOrCreatePlaylist($data, $name);
    if (in_array($sid, $pl['sids'], true)) return false;
    $pl['sids'][] = $sid;
    $pl['updated'] = time();
    return true;
}

// Remove $sid from the named playlist. Returns true if removed.
function auditPlaylistRemove(array &$data, string $name, string $sid): bool {
    foreach ($data['playlists'] as $i => $pl) {
        if (($pl['name'] ?? '') !== $name) continue;
        $before = count($pl['sids'] ?? []);
        $data['playlists'][$i]['sids'] = array_values(array_filter(
            $pl['sids'] ?? [], function($s) use ($sid) { return $s !== $sid; }
        ));
        if (count($data['playlists'][$i]['sids']) !== $before) {
            $data['playlists'][$i]['updated'] = time();
            return true;
        }
        return false;
    }
    return false;
}

// On detection of a damaged song, surface it in the "Corrupt" playlist of every
// user who has it in their library. Returns the count of users updated.
function auditFlagCorruptForUsers(string $songId): int {
    $updated = 0;
    foreach (auditUserDataPairs() as $pair) {
        $ulib = @json_decode(@file_get_contents($pair['userlib']), true);
        if (!is_array($ulib) || !isset($ulib['ids'][$songId])) continue;
        $pls = auditLoadPlaylistsFile($pair['playlists']);
        if (auditPlaylistAdd($pls, AUDIT_PLAYLIST_CORRUPT, $songId)) {
            auditSavePlaylistsFile($pair['playlists'], $pls);
            $updated++;
        }
    }
    return $updated;
}

// Full rebuild of every user's "Corrupt" playlist from current audit-state:
//   - Every userlib ID whose corresponding rel-path has status != 'ok' is added.
//   - Every existing Corrupt entry whose rel-path is now OK (or absent) is removed.
// Returns ['added', 'removed', 'users_touched'].
function auditRefreshAllCorruptPlaylists(): array {
    $added = 0; $removed = 0; $usersTouched = 0;
    $state = auditStateLoad();
    // Build set of currently-damaged IDs: hash(rel) where state status != 'ok'.
    $damagedIds = [];
    foreach ($state as $rel => $entry) {
        if (($entry['status'] ?? 'ok') === 'ok') continue;
        $damagedIds[hash('crc32b', $rel)] = true;
    }
    foreach (auditUserDataPairs() as $pair) {
        $ulib = @json_decode(@file_get_contents($pair['userlib']), true);
        if (!is_array($ulib) || !isset($ulib['ids']) || !is_array($ulib['ids'])) continue;
        $pls = auditLoadPlaylistsFile($pair['playlists']);
        $touched = false;
        // Add any damaged-and-owned IDs.
        foreach ($damagedIds as $sid => $_) {
            if (!isset($ulib['ids'][$sid])) continue;
            if (auditPlaylistAdd($pls, AUDIT_PLAYLIST_CORRUPT, $sid)) { $added++; $touched = true; }
        }
        // Remove any IDs in Corrupt that are no longer damaged or no longer owned.
        foreach ($pls['playlists'] as $pl) {
            if (($pl['name'] ?? '') !== AUDIT_PLAYLIST_CORRUPT) continue;
            foreach ($pl['sids'] ?? [] as $sid) {
                $stillDamaged = isset($damagedIds[$sid]);
                $stillOwned   = isset($ulib['ids'][$sid]);
                if (!$stillDamaged || !$stillOwned) {
                    if (auditPlaylistRemove($pls, AUDIT_PLAYLIST_CORRUPT, $sid)) { $removed++; $touched = true; }
                }
            }
        }
        if ($touched) {
            auditSavePlaylistsFile($pair['playlists'], $pls);
            $usersTouched++;
        }
    }
    return ['added' => $added, 'removed' => $removed, 'users_touched' => $usersTouched];
}

// Migrate rekey-map entries from "Corrupt" to "Recently Replaced" in every
// user's playlists. The new ID lands in "Recently Replaced"; the old ID is
// pulled out of "Corrupt". Idempotent — safe to call multiple times.
function auditMarkReplacedForUsers(array $rekeyMap): int {
    if (empty($rekeyMap)) return 0;
    $changed = 0;
    foreach (auditUserDataPairs() as $pair) {
        $pls = auditLoadPlaylistsFile($pair['playlists']);
        $touched = false;
        foreach ($rekeyMap as $oldId => $newId) {
            // Only act if the user had the old ID in their library at some point —
            // we only know that for sure if it's in Corrupt now, or if newId is in
            // their userlib (heal already moved the userlib entry).
            $ulib = @json_decode(@file_get_contents($pair['userlib']), true);
            $userHasIt = is_array($ulib) && isset($ulib['ids'][$newId]);
            if (!$userHasIt) continue;
            $removed = auditPlaylistRemove($pls, AUDIT_PLAYLIST_CORRUPT, $oldId);
            $added   = auditPlaylistAdd($pls, AUDIT_PLAYLIST_REPLACED, $newId);
            if ($removed || $added) $touched = true;
        }
        if ($touched) {
            auditSavePlaylistsFile($pair['playlists'], $pls);
            $changed++;
        }
    }
    return $changed;
}

function handleReconcile(): void {
    if (PHP_SAPI !== 'cli') return;
    auditEnsureFolders();
    $lockFp = @fopen(AUDIT_LOCK_FILE, 'c');
    if (!$lockFp) { fwrite(STDERR, "cannot open lock\n"); return; }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "another audit is running\n"); fclose($lockFp); return; }
    try {
        $ffprobe = findFfprobePath();   // optional — manifests work without it

        // Pass 1: refresh every manifest from current state.
        $state = auditStateLoad();
        $damagedAlbums = [];
        foreach ($state as $rel => $entry) {
            if (($entry['status'] ?? 'ok') === 'ok') continue;
            $alb = auditAlbumPath($rel);
            if ($alb !== '') $damagedAlbums[$alb] = true;
        }
        foreach (array_keys($damagedAlbums) as $alb) {
            auditRefreshAlbumManifest($alb, $ffprobe ?: '');
        }
        // Drop any stale manifest files whose album no longer has damage.
        if (is_dir(AUDIT_REPLACE_QUEUE_DIR)) {
            foreach ((array)@scandir(AUDIT_REPLACE_QUEUE_DIR) as $f) {
                if ($f === '.' || $f === '..' || substr($f, -4) !== '.txt') continue;
                $alb = str_replace('__', '/', substr($f, 0, -4));
                if (!isset($damagedAlbums[$alb])) @unlink(AUDIT_REPLACE_QUEUE_DIR . '/' . $f);
            }
        }
        fwrite(STDOUT, "Manifests refreshed for " . count($damagedAlbums) . " album(s)\n");

        // Pass 1b: rebuild every user's "Corrupt" playlist from current state.
        // Retroactively populates the playlist for damage detected before this
        // workflow existed, and reconciles entries for any songs whose status
        // recently flipped back to OK (those get pulled out).
        $playlistStats = auditRefreshAllCorruptPlaylists();
        fwrite(STDOUT, "Corrupt playlists: added=" . $playlistStats['added'] .
            " removed=" . $playlistStats['removed'] .
            " users_touched=" . $playlistStats['users_touched'] . "\n");

        // Pass 2: validate/swap any staged replacements.
        $ffmpeg = findFfmpeg();
        if (!$ffmpeg || !$ffprobe) {
            fwrite(STDOUT, "(skipping staged-album reconcile: ffmpeg/ffprobe not found)\n");
            return;
        }
        $rc = auditReconcileStaged($ffmpeg, $ffprobe);
        fwrite(STDOUT, "Reconcile: considered={$rc['considered']} replaced={$rc['replaced']}\n");
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}
