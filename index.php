<?php
/**
 * SIGNAL — AI Data Vault
 * A single-file viewer, gallery and assistant for your exported data from
 * Anthropic (Claude), OpenAI (ChatGPT), Google (Gemini / NotebookLM / Flow) and xAI (Grok).
 *
 * Drop this index.php next to your export folders and open it with PHP:
 *     php -S localhost:8080
 *
 * Everything is local. The only outbound calls are to the Anthropic API for the
 * built-in assistant, and only when you use it with a key you provide.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
@ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------------
// Paths & constants
// ---------------------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('CACHE_DIR', APP_ROOT . '/.aivault');
define('THUMB_DIR', CACHE_DIR . '/thumbs');
define('DB_PATH', CACHE_DIR . '/index.sqlite');

if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
if (!is_dir(THUMB_DIR)) @mkdir(THUMB_DIR, 0775, true);

/**
 * Provider registry. `roots` are the default folders to look in; users can
 * override them from the Import screen. Discovery is tolerant of nesting.
 */
function default_providers(): array {
    return [
        'anthropic' => ['label' => 'Claude',  'company' => 'Anthropic',    'dir' => 'Anthropic',     'accent' => '#d97757'],
        'openai'    => ['label' => 'ChatGPT', 'company' => 'OpenAI',       'dir' => 'OpenAI',        'accent' => '#10a37f'],
        'google'    => ['label' => 'Gemini',  'company' => 'Google',       'dir' => 'Google-Gemini', 'accent' => '#4285f4'],
        'xai'       => ['label' => 'Grok',    'company' => 'xAI',          'dir' => 'SpaceXAI',      'accent' => '#5ed0e6'],
    ];
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void {
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS conversations (
        id TEXT PRIMARY KEY, provider TEXT, title TEXT, created_at INTEGER,
        updated_at INTEGER, msg_count INTEGER, snippet TEXT, category TEXT,
        subcategory TEXT, has_media INTEGER DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS ix_conv_provider ON conversations(provider);
    CREATE INDEX IF NOT EXISTS ix_conv_created ON conversations(created_at);

    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, conv_id TEXT, provider TEXT,
        seq INTEGER, role TEXT, text TEXT, created_at INTEGER, media_ids TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_msg_conv ON messages(conv_id, seq);

    CREATE TABLE IF NOT EXISTS media (
        id TEXT PRIMARY KEY, provider TEXT, path TEXT, kind TEXT, ext TEXT,
        width INTEGER, height INTEGER, size INTEGER, title TEXT, prompt TEXT,
        category TEXT, conv_id TEXT, created_at INTEGER
    );
    CREATE INDEX IF NOT EXISTS ix_media_provider ON media(provider);
    CREATE INDEX IF NOT EXISTS ix_media_kind ON media(kind);

    CREATE TABLE IF NOT EXISTS personas (
        id TEXT PRIMARY KEY, provider TEXT, name TEXT, description TEXT,
        instructions TEXT, source TEXT, created_at INTEGER
    );

    CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, kind TEXT,
        title TEXT, body TEXT, created_at INTEGER
    );

    CREATE TABLE IF NOT EXISTS saved (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, title TEXT,
        body TEXT, meta TEXT, created_at INTEGER
    );

    CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
    SQL);
}

function cfg_get(string $key, $default = null) {
    try {
        $st = db()->prepare('SELECT value FROM config WHERE key=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
function cfg_set(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO config(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $st->execute([$key, $value]);
}
function meta_get(string $key, $default = null) {
    try {
        $st = db()->prepare('SELECT value FROM meta WHERE key=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
function meta_set(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $st->execute([$key, $value]);
}

/** Resolved base directory for a provider (config override or default). */
function provider_dir(string $pid): ?string {
    $providers = default_providers();
    if (!isset($providers[$pid])) return null;
    $override = cfg_get("path_$pid");
    $dir = $override ?: (APP_ROOT . '/' . $providers[$pid]['dir']);
    $real = realpath($dir);
    return $real ?: null;
}

/** The user's home directory — the root the folder browser is confined to. */
function home_dir(): string {
    $h = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$h && function_exists('posix_getpwuid')) { $u = @posix_getpwuid(posix_getuid()); $h = $u['dir'] ?? ''; }
    $h = $h ? realpath($h) : '';
    return $h ?: (realpath(dirname(APP_ROOT)) ?: APP_ROOT);
}

/** Allowed media roots — used to sandbox the media route against traversal. */
function media_roots(): array {
    $roots = [];
    foreach (array_keys(default_providers()) as $pid) {
        $d = provider_dir($pid);
        if ($d) $roots[] = $d;
    }
    return $roots;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function rglob(string $base, int $maxDepth = 8): Generator {
    if (!is_dir($base)) return;
    try {
        $dir = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
        $it = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::LEAVES_ONLY);
        $it->setMaxDepth($maxDepth);
        foreach ($it as $f) {
            if ($f->isFile()) yield $f->getPathname();
        }
    } catch (Throwable $e) { /* unreadable dir */ }
}

function find_first(string $base, string $needle, int $maxDepth = 6): ?string {
    foreach (rglob($base, $maxDepth) as $p) {
        if (basename($p) === $needle) return $p;
    }
    return null;
}

function media_id(string $provider, string $path): string {
    return $provider . ':' . substr(sha1($path), 0, 22);
}

function to_ts($v): int {
    if (is_int($v) || is_float($v)) {
        $n = (float)$v;
        if ($n > 1e12) $n /= 1000.0; // milliseconds
        return (int)$n;
    }
    if (is_string($v) && $v !== '') {
        $t = strtotime($v);
        if ($t !== false) return $t;
        if (ctype_digit($v)) return to_ts((int)$v);
    }
    return 0;
}

function snippet_of(string $s, int $len = 180): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_substr($s, 0, $len);
}

function mime_of(string $path): string {
    static $fi = null;
    if ($fi === null) $fi = finfo_open(FILEINFO_MIME_TYPE);
    $m = @finfo_file($fi, $path);
    return $m ?: 'application/octet-stream';
}

function kind_from(string $path, ?string $mime = null): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $imgExt = ['jpg','jpeg','png','gif','webp','bmp','svg','heic','heif','avif'];
    $vidExt = ['mp4','webm','mov','m4v','mkv','avi'];
    $audExt = ['wav','mp3','m4a','ogg','flac','aac','opus'];
    if (in_array($ext, $imgExt, true)) return 'image';
    if (in_array($ext, $vidExt, true)) return 'video';
    if (in_array($ext, $audExt, true)) return 'audio';
    // Unknown extension (e.g. .dat, "content") — probe magic bytes.
    $mime = $mime ?? mime_of($path);
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    return null;
}

// ---------------------------------------------------------------------------
// Streaming JSON — yields each object of a very large top-level array without
// loading the whole file (used for Anthropic's 200MB conversations.json).
// ---------------------------------------------------------------------------
function stream_json_array(string $path): Generator {
    $fh = @fopen($path, 'rb');
    if (!$fh) return;
    $buf = '';
    $pos = 0;
    $len = 0;
    $started = false;   // seen opening '['
    $depth = 0;
    $inStr = false;
    $esc = false;
    $capture = '';
    $capturing = false;

    $read = function () use ($fh, &$buf, &$pos, &$len): bool {
        $chunk = fread($fh, 1 << 20);
        if ($chunk === false || $chunk === '') return false;
        $buf = $chunk; $pos = 0; $len = strlen($chunk);
        return true;
    };

    while (true) {
        if ($pos >= $len) {
            if (!$read()) break;
        }
        $c = $buf[$pos++];

        if (!$started) {
            if ($c === '[') { $started = true; }
            continue;
        }

        if ($capturing) $capture .= $c;

        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"') { $inStr = false; }
            continue;
        }

        switch ($c) {
            case '"':
                $inStr = true;
                break;
            case '{':
            case '[':
                if ($depth === 0 && !$capturing) { $capturing = true; $capture = $c; }
                $depth++;
                break;
            case '}':
            case ']':
                $depth--;
                if ($depth === 0 && $capturing) {
                    $capturing = false;
                    $obj = json_decode($capture, true);
                    if (is_array($obj)) yield $obj;
                    $capture = '';
                }
                break;
        }
    }
    fclose($fh);
}

// ---------------------------------------------------------------------------
// Indexers
// ---------------------------------------------------------------------------
function reset_provider(string $pid): void {
    $pdo = db();
    foreach (['conversations','messages','media','personas','notes'] as $t) {
        $st = $pdo->prepare("DELETE FROM $t WHERE provider=?");
        $st->execute([$pid]);
    }
}

function ins_conversation(PDO $pdo, array $c): void {
    static $st = null;
    if (!$st) $st = $pdo->prepare('INSERT OR REPLACE INTO conversations(id,provider,title,created_at,updated_at,msg_count,snippet,category,subcategory,has_media) VALUES(:id,:p,:t,:ca,:ua,:mc,:sn,:cat,:sub,:hm)');
    $st->execute([
        ':id' => $c['id'], ':p' => $c['provider'], ':t' => $c['title'],
        ':ca' => $c['created_at'], ':ua' => $c['updated_at'], ':mc' => $c['msg_count'],
        ':sn' => $c['snippet'], ':cat' => $c['category'], ':sub' => $c['subcategory'] ?? '',
        ':hm' => $c['has_media'] ?? 0,
    ]);
}
function ins_message(PDO $pdo, array $m): void {
    static $st = null;
    if (!$st) $st = $pdo->prepare('INSERT INTO messages(conv_id,provider,seq,role,text,created_at,media_ids) VALUES(:c,:p,:s,:r,:t,:ca,:mi)');
    $st->execute([
        ':c' => $m['conv_id'], ':p' => $m['provider'], ':s' => $m['seq'],
        ':r' => $m['role'], ':t' => $m['text'], ':ca' => $m['created_at'],
        ':mi' => $m['media_ids'] ?? '',
    ]);
}
function ins_persona(PDO $pdo, array $p): void {
    static $st = null;
    if (!$st) $st = $pdo->prepare('INSERT OR REPLACE INTO personas(id,provider,name,description,instructions,source,created_at) VALUES(:id,:p,:n,:d,:i,:s,:ca)');
    $st->execute([':id'=>$p['id'],':p'=>$p['provider'],':n'=>$p['name'],':d'=>$p['description'],':i'=>$p['instructions'],':s'=>$p['source'],':ca'=>$p['created_at']]);
}
function ins_note(PDO $pdo, array $n): void {
    static $st = null;
    if (!$st) $st = $pdo->prepare('INSERT INTO notes(provider,kind,title,body,created_at) VALUES(:p,:k,:t,:b,:ca)');
    $st->execute([':p'=>$n['provider'],':k'=>$n['kind'],':t'=>$n['title'],':b'=>$n['body'],':ca'=>$n['created_at']]);
}
function ins_media(PDO $pdo, array $m): void {
    static $st = null;
    if (!$st) $st = $pdo->prepare('INSERT OR REPLACE INTO media(id,provider,path,kind,ext,width,height,size,title,prompt,category,conv_id,created_at) VALUES(:id,:p,:pa,:k,:e,:w,:h,:s,:t,:pr,:cat,:c,:ca)');
    $st->execute([
        ':id'=>$m['id'], ':p'=>$m['provider'], ':pa'=>$m['path'], ':k'=>$m['kind'],
        ':e'=>$m['ext'], ':w'=>$m['width']??0, ':h'=>$m['height']??0, ':s'=>$m['size']??0,
        ':t'=>$m['title']??'', ':pr'=>$m['prompt']??'', ':cat'=>$m['category']??'Media',
        ':c'=>$m['conv_id']??'', ':ca'=>$m['created_at']??0,
    ]);
}

/** Generic media sweep for a provider directory. Returns count added. */
function sweep_media(string $pid, string $base, array $overrides = []): int {
    $pdo = db();
    $count = 0;
    $pdo->beginTransaction();
    $i = 0;
    foreach (rglob($base) as $path) {
        $name = basename($path);
        $kind = kind_from($path);
        if ($kind === null) continue;
        $id = media_id($pid, $path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $w = 0; $h = 0;
        if ($kind === 'image') {
            $g = @getimagesize($path);
            if ($g) { $w = (int)$g[0]; $h = (int)$g[1]; }
        }
        $meta = $overrides[$path] ?? ['title' => $name, 'prompt' => '', 'category' => guess_category($path), 'conv_id' => '', 'created_at' => 0];
        ins_media($pdo, [
            'id'=>$id, 'provider'=>$pid, 'path'=>$path, 'kind'=>$kind, 'ext'=>$ext,
            'width'=>$w, 'height'=>$h, 'size'=>@filesize($path) ?: 0,
            'title'=>$meta['title'] ?: $name, 'prompt'=>$meta['prompt'] ?? '',
            'category'=>$meta['category'] ?? guess_category($path),
            'conv_id'=>$meta['conv_id'] ?? '', 'created_at'=>$meta['created_at'] ?? (@filemtime($path) ?: 0),
        ]);
        $count++;
        if ((++$i % 400) === 0) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit();
    return $count;
}

function guess_category(string $path): string {
    if (stripos($path, '/Flow/') !== false) return 'Flow';
    if (stripos($path, 'NotebookLM') !== false) return 'NotebookLM';
    if (stripos($path, 'asset-server') !== false) return 'Imagine';
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dat') return 'Images';
    return 'Media';
}

// --- Anthropic -------------------------------------------------------------
function index_anthropic(string $base): array {
    $pdo = db();
    reset_provider('anthropic');
    $convFile = null;
    // Prefer the largest conversations.json we can find.
    $best = 0;
    foreach (rglob($base, 4) as $p) {
        if (basename($p) === 'conversations.json') {
            $sz = @filesize($p) ?: 0;
            if ($sz > $best) { $best = $sz; $convFile = $p; }
        }
    }
    $convN = 0; $msgN = 0;
    if ($convFile) {
        $pdo->beginTransaction();
        $i = 0;
        foreach (stream_json_array($convFile) as $c) {
            $id = 'anthropic:' . ($c['uuid'] ?? substr(sha1(json_encode($c)),0,16));
            $title = trim((string)($c['name'] ?? '')) ?: 'Untitled';
            $msgs = $c['chat_messages'] ?? $c['messages'] ?? [];
            $seq = 0; $firstUser = '';
            foreach ($msgs as $m) {
                $role = ($m['sender'] ?? $m['role'] ?? 'user');
                $role = ($role === 'assistant' || $role === 'model') ? 'assistant' : ($role === 'human' ? 'user' : $role);
                $text = '';
                if (!empty($m['content']) && is_array($m['content'])) {
                    foreach ($m['content'] as $part) {
                        if (is_array($part) && ($part['type'] ?? '') === 'text') $text .= ($part['text'] ?? '') . "\n";
                    }
                }
                if ($text === '' && isset($m['text'])) $text = (string)$m['text'];
                $text = trim($text);
                if ($text === '') continue;
                if ($firstUser === '' && $role === 'user') $firstUser = $text;
                ins_message($pdo, [
                    'conv_id'=>$id, 'provider'=>'anthropic', 'seq'=>$seq++, 'role'=>$role,
                    'text'=>$text, 'created_at'=>to_ts($m['created_at'] ?? 0), 'media_ids'=>'',
                ]);
                $msgN++;
            }
            ins_conversation($pdo, [
                'id'=>$id, 'provider'=>'anthropic', 'title'=>$title,
                'created_at'=>to_ts($c['created_at'] ?? 0), 'updated_at'=>to_ts($c['updated_at'] ?? 0),
                'msg_count'=>$seq, 'snippet'=>snippet_of(($c['summary'] ?? '') ?: $firstUser),
                'category'=>'Conversations', 'subcategory'=>'', 'has_media'=>0,
            ]);
            $convN++;
            if ((++$i % 200) === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }
        $pdo->commit();
    }

    // Projects -> personas (custom instructions / prompt templates).
    $projN = 0;
    foreach (rglob($base, 5) as $p) {
        if (strpos($p, '/projects/') !== false && str_ends_with($p, '.json')) {
            $d = json_decode(@file_get_contents($p), true);
            if (is_array($d) && isset($d['name'])) {
                ins_persona($pdo, [
                    'id'=>'anthropic:proj:' . ($d['uuid'] ?? substr(sha1($p),0,12)),
                    'provider'=>'anthropic', 'name'=>$d['name'],
                    'description'=>(string)($d['description'] ?? ''),
                    'instructions'=>(string)($d['prompt_template'] ?? ''),
                    'source'=>'imported', 'created_at'=>to_ts($d['created_at'] ?? 0),
                ]);
                $projN++;
            }
        }
    }

    // Memories.
    $memN = 0;
    $memFile = find_first($base, 'memories.json', 5);
    if ($memFile) {
        $d = json_decode(@file_get_contents($memFile), true);
        $items = [];
        if (is_array($d)) $items = $d['memories'] ?? (array_is_list($d) ? $d : [$d]);
        foreach ($items as $mem) {
            if (is_array($mem)) {
                // Prefer known keys, else concatenate all string values.
                $body = (string)($mem['content'] ?? $mem['text'] ?? $mem['conversations_memory'] ?? '');
                if ($body === '') {
                    $parts = [];
                    foreach ($mem as $k => $val) if (is_string($val) && trim($val) !== '') $parts[] = $val;
                    $body = implode("\n\n", $parts);
                }
            } else {
                $body = (string)$mem;
            }
            if (trim($body) === '') continue;
            ins_note($pdo, ['provider'=>'anthropic','kind'=>'memory','title'=>snippet_of($body,60),'body'=>$body,'created_at'=>0]);
            $memN++;
        }
    }

    $mediaN = sweep_media('anthropic', $base);
    return ['conversations'=>$convN, 'messages'=>$msgN, 'personas'=>$projN, 'memories'=>$memN, 'media'=>$mediaN];
}

// --- OpenAI ----------------------------------------------------------------
function openai_asset_from_pointer(string $ptr, string $dir): ?string {
    // file-service://file-XXXX  ->  <dir>/file-XXXX.dat
    // Skip the scheme ("file-service") and grab the real file id.
    if (preg_match('#(?:^|//)(file[-_][A-Za-z0-9]+)\s*$#', $ptr, $m)) {
        $id = $m[1];
    } elseif (preg_match_all('/(file[-_][A-Za-z0-9]+)/', $ptr, $mm)) {
        $id = end($mm[1]); // last token is the id, not the "file-service" scheme
    } else {
        return null;
    }
    if ($id === 'file-service') return null;
    $cand = $dir . '/' . $id . '.dat';
    return is_file($cand) ? $cand : null;
}

function index_openai(string $base): array {
    $pdo = db();
    // Locate the folder that contains conversations-*.json (or conversations.json).
    $dataDir = null;
    $convFiles = [];
    foreach (rglob($base, 4) as $p) {
        $bn = basename($p);
        if (preg_match('/^conversations(-\d+)?\.json$/', $bn)) {
            $convFiles[] = $p;
            $dataDir = $dataDir ?? dirname($p);
        }
    }
    sort($convFiles);
    if (!$convFiles) return ['conversations'=>0, 'messages'=>0, 'media'=>0, 'note'=>'No conversations-*.json found under this path.'];
    // Only wipe old data once we know we have something to replace it with.
    reset_provider('openai');
    $assetNames = [];
    if ($dataDir && is_file($dataDir . '/conversation_asset_file_names.json')) {
        $assetNames = json_decode(@file_get_contents($dataDir . '/conversation_asset_file_names.json'), true) ?: [];
    }

    $convN = 0; $msgN = 0; $linkedMedia = [];
    foreach ($convFiles as $file) {
        $list = json_decode(@file_get_contents($file), true);
        if (!is_array($list)) continue;
        $pdo->beginTransaction();
        foreach ($list as $conv) {
            $mapping = $conv['mapping'] ?? [];
            if (!is_array($mapping)) continue;
            $id = 'openai:' . ($conv['conversation_id'] ?? $conv['id'] ?? substr(sha1(json_encode($conv)),0,16));
            $title = trim((string)($conv['title'] ?? '')) ?: 'New chat';
            $rows = [];
            foreach ($mapping as $node) {
                $msg = $node['message'] ?? null;
                if (!$msg) continue;
                $role = $msg['author']['role'] ?? 'user';
                if ($role === 'system' || $role === 'tool') continue;
                if (!empty($msg['metadata']['is_visually_hidden_from_conversation'])) continue;
                $content = $msg['content'] ?? [];
                $ctype = $content['content_type'] ?? '';
                if ($ctype !== 'text' && $ctype !== 'multimodal_text') continue;
                $text = ''; $mids = [];
                foreach (($content['parts'] ?? []) as $part) {
                    if (is_string($part)) { $text .= $part . "\n"; }
                    elseif (is_array($part) && ($part['content_type'] ?? '') === 'image_asset_pointer') {
                        $ptr = $part['asset_pointer'] ?? '';
                        $assetPath = $dataDir ? openai_asset_from_pointer($ptr, $dataDir) : null;
                        if ($assetPath) {
                            $mid = media_id('openai', $assetPath);
                            $mids[] = $mid;
                            $linkedMedia[$assetPath] = [
                                'title' => $assetNames[basename($assetPath)] ?? basename($assetPath),
                                'prompt' => snippet_of(trim($text)),
                                'category' => 'Images', 'conv_id' => $id,
                                'created_at' => to_ts($msg['create_time'] ?? 0),
                            ];
                        }
                    }
                }
                $text = trim($text);
                if ($text === '' && empty($mids)) continue;
                $rows[] = ['role'=>($role==='assistant'?'assistant':'user'), 'text'=>$text, 'ct'=>(float)($msg['create_time'] ?? 0), 'mids'=>$mids];
            }
            usort($rows, fn($a,$b)=>$a['ct'] <=> $b['ct']);
            $seq = 0; $first = ''; $hasMedia = 0;
            foreach ($rows as $r) {
                if ($first === '' && $r['role'] === 'user') $first = $r['text'];
                if (!empty($r['mids'])) $hasMedia = 1;
                ins_message($pdo, ['conv_id'=>$id,'provider'=>'openai','seq'=>$seq++,'role'=>$r['role'],'text'=>$r['text'],'created_at'=>to_ts($r['ct']),'media_ids'=>implode(',', $r['mids'])]);
                $msgN++;
            }
            if ($seq === 0) continue;
            ins_conversation($pdo, [
                'id'=>$id, 'provider'=>'openai', 'title'=>$title,
                'created_at'=>to_ts($conv['create_time'] ?? 0), 'updated_at'=>to_ts($conv['update_time'] ?? 0),
                'msg_count'=>$seq, 'snippet'=>snippet_of($first), 'category'=>'Conversations',
                'subcategory'=>($conv['is_starred'] ?? false) ? 'Starred' : '', 'has_media'=>$hasMedia,
            ]);
            $convN++;
        }
        $pdo->commit();
    }

    $mediaN = sweep_media('openai', $base, $linkedMedia);
    return ['conversations'=>$convN, 'messages'=>$msgN, 'media'=>$mediaN];
}

// --- xAI / Grok ------------------------------------------------------------
function index_xai(string $base): array {
    $pdo = db();
    $backend = find_first($base, 'prod-grok-backend.json', 6);
    $convN = 0; $msgN = 0; $overrides = []; $personaN = 0;
    $assetRoot = null;
    reset_provider('xai'); // safe: find_first succeeded (or there is no backend to lose)
    if ($backend) {
        $assetRoot = dirname($backend) . '/prod-mc-asset-server';
        $d = json_decode(@file_get_contents($backend), true) ?: [];

        // Custom personalities from projects/workspaces.
        foreach (($d['projects'] ?? []) as $pr) {
            $inst = (string)($pr['custom_personality'] ?? '');
            if (trim($inst) === '' && trim((string)($pr['name'] ?? '')) === '') continue;
            ins_persona($pdo, [
                'id'=>'xai:proj:'.substr(sha1(json_encode($pr)),0,12), 'provider'=>'xai',
                'name'=>(string)($pr['name'] ?? 'Workspace'), 'description'=>'',
                'instructions'=>$inst, 'source'=>'imported', 'created_at'=>to_ts($pr['create_time'] ?? 0),
            ]);
            $personaN++;
        }

        // Media posts -> prompt map for the asset sweep.
        $postMap = [];
        foreach (($d['media_posts'] ?? []) as $mp) {
            if (isset($mp['id'])) $postMap[$mp['id']] = $mp;
        }

        $pdo->beginTransaction();
        $i = 0;
        foreach (($d['conversations'] ?? []) as $wrap) {
            $conv = $wrap['conversation'] ?? [];
            $id = 'xai:' . ($conv['id'] ?? substr(sha1(json_encode($wrap)),0,16));
            $title = trim((string)($conv['title'] ?? '')) ?: 'Conversation';
            $rows = [];
            foreach (($wrap['responses'] ?? []) as $rw) {
                $resp = $rw['response'] ?? [];
                $sender = $resp['sender'] ?? 'human';
                $text = trim((string)($resp['message'] ?? ''));
                if ($text === '') continue;
                $ct = 0;
                if (isset($resp['create_time']['$date']['$numberLong'])) $ct = (int)$resp['create_time']['$date']['$numberLong'];
                elseif (isset($resp['create_time'])) $ct = to_ts($resp['create_time']);
                $rows[] = ['role'=>($sender==='human'?'user':'assistant'),'text'=>$text,'ct'=>$ct];
            }
            usort($rows, fn($a,$b)=>$a['ct'] <=> $b['ct']);
            $seq = 0; $first = '';
            foreach ($rows as $r) {
                if ($first==='' && $r['role']==='user') $first = $r['text'];
                ins_message($pdo, ['conv_id'=>$id,'provider'=>'xai','seq'=>$seq++,'role'=>$r['role'],'text'=>$r['text'],'created_at'=>to_ts($r['ct']),'media_ids'=>'']);
                $msgN++;
            }
            if ($seq === 0) continue;
            ins_conversation($pdo, [
                'id'=>$id, 'provider'=>'xai', 'title'=>$title,
                'created_at'=>to_ts($conv['create_time'] ?? 0), 'updated_at'=>to_ts($conv['modify_time'] ?? 0),
                'msg_count'=>$seq, 'snippet'=>snippet_of($first), 'category'=>'Conversations',
                'subcategory'=>($conv['starred'] ?? false) ? 'Starred' : '', 'has_media'=>0,
            ]);
            $convN++;
            if ((++$i % 200) === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }
        $pdo->commit();

        // Build overrides for asset-server content files using the media_post map.
        if ($assetRoot && is_dir($assetRoot)) {
            foreach (rglob($assetRoot, 3) as $path) {
                if (basename($path) !== 'content') continue;
                $uuid = basename(dirname($path));
                $post = $postMap[$uuid] ?? null;
                $overrides[$path] = [
                    'title' => $post ? snippet_of((string)($post['original_prompt'] ?? ''), 70) : basename(dirname($path)),
                    'prompt' => $post ? (string)($post['original_prompt'] ?? '') : '',
                    'category' => 'Imagine', 'conv_id' => '',
                    'created_at' => $post ? to_ts($post['create_time'] ?? 0) : (@filemtime($path) ?: 0),
                ];
            }
        }
    }
    $mediaN = sweep_media('xai', $base, $overrides);
    return ['conversations'=>$convN, 'messages'=>$msgN, 'personas'=>$personaN, 'media'=>$mediaN];
}

// --- Google (Gemini / NotebookLM / Flow) -----------------------------------
function index_google(string $base): array {
    $pdo = db();
    reset_provider('google');
    $personaN = 0; $nbN = 0;

    // Gemini Gems -> personas.
    $gemsFile = find_first($base, 'gemini_gems_data.html', 5);
    if ($gemsFile) {
        $html = @file_get_contents($gemsFile) ?: '';
        // Gems may all live in one <div>, so split on each "Name:" marker
        // rather than relying on </div> to terminate a block.
        $blocks = preg_split('#(?=<b>\s*Name:\s*</b>)#i', $html);
        foreach ($blocks as $blk) {
            if (!preg_match('#<b>\s*Name:\s*</b>(.*?)<br>\s*<b>\s*Instructions:\s*</b>(.*)$#si', $blk, $g)) continue;
            $name = trim(html_entity_decode(strip_tags($g[1])));
            $inst = trim(html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $g[2]))));
            if ($name === '' && $inst === '') continue;
            ins_persona($pdo, [
                'id'=>'google:gem:'.substr(sha1($name.$inst),0,12), 'provider'=>'google',
                'name'=>$name ?: 'Gem', 'description'=>'Gemini Gem', 'instructions'=>$inst,
                'source'=>'imported', 'created_at'=>0,
            ]);
            $personaN++;
        }
    }

    // NotebookLM notebooks -> conversation-style entries.
    $nbRoot = null;
    foreach (rglob($base, 3) as $p) {
        if (is_dir(dirname($p)) && stripos($p, 'NotebookLM') !== false) { /* handled below */ }
    }
    // Walk NotebookLM/<set>/<notebook>/ directories directly.
    $dirIter = null;
    $candidates = [];
    foreach (glob($base . '/*/', GLOB_ONLYDIR) ?: [] as $lvl1) {
        if (stripos($lvl1, 'NotebookLM') === false) continue;
        foreach (glob($lvl1 . '*/', GLOB_ONLYDIR) ?: [] as $set) {         // NotebookLM1/2/3
            foreach (glob($set . '*/', GLOB_ONLYDIR) ?: [] as $nb) {       // the notebooks
                $candidates[] = rtrim($nb, '/');
            }
        }
    }
    $pdo->beginTransaction();
    foreach ($candidates as $nb) {
        $title = basename($nb);
        $set = basename(dirname($nb));
        $sources = [];
        foreach (glob($nb . '/Sources/*') ?: [] as $s) $sources[] = basename($s);
        $artifacts = [];
        foreach (rglob($nb . '/Artifacts', 3) as $a) $artifacts[] = basename($a);
        $body = "NotebookLM notebook (" . $set . ")\n\n";
        if ($sources) $body .= "Sources (" . count($sources) . "):\n- " . implode("\n- ", array_slice($sources, 0, 40)) . "\n\n";
        if ($artifacts) $body .= "Artifacts (" . count($artifacts) . "):\n- " . implode("\n- ", array_slice($artifacts, 0, 40)) . "\n";
        $id = 'google:nb:' . substr(sha1($nb), 0, 16);
        ins_message($pdo, ['conv_id'=>$id,'provider'=>'google','seq'=>0,'role'=>'assistant','text'=>trim($body),'created_at'=>0,'media_ids'=>'']);
        ins_conversation($pdo, [
            'id'=>$id, 'provider'=>'google', 'title'=>$title,
            'created_at'=>(@filemtime($nb) ?: 0), 'updated_at'=>(@filemtime($nb) ?: 0),
            'msg_count'=>1, 'snippet'=>snippet_of(count($sources) . " sources · " . count($artifacts) . " artifacts"),
            'category'=>'NotebookLM', 'subcategory'=>$set, 'has_media'=>1,
        ]);
        $nbN++;
    }
    $pdo->commit();

    $mediaN = sweep_media('google', $base);
    return ['conversations'=>$nbN, 'personas'=>$personaN, 'media'=>$mediaN];
}

function run_index(string $pid): array {
    @set_time_limit(0);
    @ini_set('memory_limit', '1536M');
    $dir = provider_dir($pid);
    if (!$dir) return ['error' => "No folder found for $pid. Set its path on the Import screen."];
    $t0 = microtime(true);
    $res = match ($pid) {
        'anthropic' => index_anthropic($dir),
        'openai'    => index_openai($dir),
        'xai'       => index_xai($dir),
        'google'    => index_google($dir),
        default     => ['error' => 'Unknown provider'],
    };
    $res['seconds'] = round(microtime(true) - $t0, 1);
    $res['dir'] = $dir;
    meta_set("indexed_$pid", (string)time());
    meta_set("stats_$pid", json_encode($res));
    return $res;
}

// ---------------------------------------------------------------------------
// Media serving (sandboxed) + thumbnails
// ---------------------------------------------------------------------------
function media_row(string $id): ?array {
    $st = db()->prepare('SELECT * FROM media WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function path_allowed(string $path): bool {
    $real = realpath($path);
    if (!$real) return false;
    foreach (media_roots() as $root) {
        if (str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || $real === $root) return true;
    }
    return false;
}

function content_type_for(string $path, string $kind): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
        'webp'=>'image/webp','svg'=>'image/svg+xml','avif'=>'image/avif','bmp'=>'image/bmp',
        'heic'=>'image/heic','heif'=>'image/heif',
        'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','m4v'=>'video/mp4','mkv'=>'video/x-matroska',
        'wav'=>'audio/wav','mp3'=>'audio/mpeg','m4a'=>'audio/mp4','ogg'=>'audio/ogg','flac'=>'audio/flac','opus'=>'audio/ogg',
    ];
    if (isset($map[$ext])) return $map[$ext];
    return mime_of($path);
}

function serve_media(string $id): void {
    $row = media_row($id);
    if (!$row || !path_allowed($row['path']) || !is_file($row['path'])) { http_response_code(404); exit; }
    $path = $row['path'];
    $size = filesize($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $ctype = content_type_for($path, $row['kind']);
    $isActive = ($ext === 'svg' || $ctype === 'image/svg+xml' || $ctype === 'text/html');
    $wantsDownload = isset($_GET['dl']);
    $fp = fopen($path, 'rb');
    if (!$fp) { http_response_code(500); exit; }

    header('Content-Type: ' . $ctype);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    // Neutralize SVG/HTML which are active documents: never let embedded
    // script run in this origin. Force download + sandbox them.
    if ($isActive) {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $wantsDownload = true;
    }
    if ($wantsDownload) {
        $fname = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($row['title'] ?: basename($path)));
        $fname = trim($fname, '_');
        if ($fname === '') $fname = 'media';
        if (!str_contains($fname, '.')) {
            $sub = preg_replace('/[^a-z0-9]/', '', explode('/', $ctype)[1] ?? '');   // e.g. image/jpeg -> jpeg
            $fname .= '.' . ($ext ?: ($sub ?: 'bin'));
        }
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }

    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];
        // Unsatisfiable range -> 416 rather than a bogus/negative length.
        if ($m[1] !== '' && ($start >= $size || $start > $end)) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            fclose($fp); exit;
        }
        if ($end >= $size) $end = $size - 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(1 << 20, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        @ob_flush(); @flush();
    }
    fclose($fp);
    exit;
}

function serve_thumb(string $id, int $w = 460): void {
    $row = media_row($id);
    $isSvg = $row && strtolower(pathinfo($row['path'], PATHINFO_EXTENSION)) === 'svg';
    if (!$row || $row['kind'] !== 'image' || $isSvg || !path_allowed($row['path']) || !is_file($row['path'])) {
        // Non-image, SVG (active content) or missing — 1x1 transparent.
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    $cache = THUMB_DIR . '/' . preg_replace('/[^a-z0-9]/i', '_', $id) . "_$w.jpg";
    if (is_file($cache) && filemtime($cache) >= filemtime($row['path'])) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=604800');
        readfile($cache);
        exit;
    }
    $src = @thumb_load($row['path']);
    if (!$src) { serve_media($id); return; }
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = min(1.0, $w / max(1, $sw));
    $tw = max(1, (int)round($sw * $scale));
    $th = max(1, (int)round($sh * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagejpeg($dst, $cache, 82);
    imagedestroy($src); imagedestroy($dst);
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=604800');
    readfile($cache);
    exit;
}

function thumb_load(string $path) {
    $g = @getimagesize($path);
    if (!$g) return false;
    return match ($g[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
        default        => false,
    };
}

// ---------------------------------------------------------------------------
// JSON API
// ---------------------------------------------------------------------------
function jout($data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // JSON_INVALID_UTF8_SUBSTITUTE: filesystem-derived titles can contain
    // non-UTF-8 bytes; without this one bad byte makes json_encode return
    // false and the whole endpoint silently returns an empty body.
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function api_overview(): void {
    $pdo = db();
    $providers = default_providers();
    $out = [];
    foreach ($providers as $pid => $p) {
        $conv = (int)$pdo->query("SELECT COUNT(*) FROM conversations WHERE provider=" . $pdo->quote($pid))->fetchColumn();
        $msg  = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE provider=" . $pdo->quote($pid))->fetchColumn();
        $img  = (int)$pdo->query("SELECT COUNT(*) FROM media WHERE kind='image' AND provider=" . $pdo->quote($pid))->fetchColumn();
        $vid  = (int)$pdo->query("SELECT COUNT(*) FROM media WHERE kind='video' AND provider=" . $pdo->quote($pid))->fetchColumn();
        $aud  = (int)$pdo->query("SELECT COUNT(*) FROM media WHERE kind='audio' AND provider=" . $pdo->quote($pid))->fetchColumn();
        $per  = (int)$pdo->query("SELECT COUNT(*) FROM personas WHERE provider=" . $pdo->quote($pid))->fetchColumn();
        $dir = provider_dir($pid);
        $out[] = [
            'id'=>$pid, 'label'=>$p['label'], 'company'=>$p['company'], 'accent'=>$p['accent'],
            'conversations'=>$conv, 'messages'=>$msg, 'images'=>$img, 'videos'=>$vid,
            'audio'=>$aud, 'personas'=>$per, 'indexed'=>meta_get("indexed_$pid") ? (int)meta_get("indexed_$pid") : 0,
            'detected'=>(bool)$dir, 'dir'=>$dir,
        ];
    }
    $recent = $pdo->query("SELECT id,provider,kind,title,category,width,height FROM media WHERE kind IN ('image','video') ORDER BY created_at DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
    jout(['providers'=>$out, 'recent'=>$recent, 'has_key'=>(getenv('ANTHROPIC_API_KEY') || cfg_get('anthropic_api_key'))]);
}

function api_conversations(): void {
    $pdo = db();
    $provider = $_GET['provider'] ?? '';
    $category = $_GET['category'] ?? '';
    $q = trim($_GET['q'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 40)));
    $where = []; $args = [];
    if ($provider !== '') { $where[] = 'provider=?'; $args[] = $provider; }
    if ($category !== '' && $category !== 'All') { $where[] = 'category=?'; $args[] = $category; }
    if ($q !== '') { $where[] = '(title LIKE ? OR snippet LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $total = (int)(function() use ($pdo,$wsql,$args){ $st=$pdo->prepare("SELECT COUNT(*) FROM conversations $wsql"); $st->execute($args); return $st->fetchColumn(); })();
    $st = $pdo->prepare("SELECT id,provider,title,created_at,updated_at,msg_count,snippet,category,subcategory,has_media FROM conversations $wsql ORDER BY (created_at>0) DESC, created_at DESC, title ASC LIMIT $limit OFFSET $offset");
    $st->execute($args);
    jout(['total'=>$total, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

function api_conversation(): void {
    $pdo = db();
    $id = $_GET['id'] ?? '';
    $st = $pdo->prepare('SELECT * FROM conversations WHERE id=?'); $st->execute([$id]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) jout(['error'=>'not found']);
    $st = $pdo->prepare('SELECT seq,role,text,created_at,media_ids FROM messages WHERE conv_id=? ORDER BY seq ASC'); $st->execute([$id]);
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
    // Resolve media rows referenced inline.
    $ids = [];
    foreach ($msgs as $m) foreach (array_filter(explode(',', $m['media_ids'] ?? '')) as $mid) $ids[$mid] = true;
    $mediaMap = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id,kind,title,width,height FROM media WHERE id IN ($in)");
        $st->execute(array_keys($ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mediaMap[$r['id']] = $r;
    }
    jout(['conversation'=>$conv, 'messages'=>$msgs, 'media'=>$mediaMap]);
}

function api_categories(): void {
    $pdo = db();
    $provider = $_GET['provider'] ?? '';
    $st = $pdo->prepare('SELECT category, COUNT(*) n FROM conversations WHERE provider=? GROUP BY category ORDER BY n DESC');
    $st->execute([$provider]);
    jout(['categories'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

function api_gallery(): void {
    $pdo = db();
    $provider = $_GET['provider'] ?? '';
    $kind = $_GET['kind'] ?? '';
    $category = $_GET['category'] ?? '';
    $q = trim($_GET['q'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = min(120, max(1, (int)($_GET['limit'] ?? 60)));
    $where = []; $args = [];
    if ($provider !== '' && $provider !== 'all') { $where[] = 'provider=?'; $args[] = $provider; }
    if ($kind !== '' && $kind !== 'all') { $where[] = 'kind=?'; $args[] = $kind; }
    else { $where[] = "kind IN ('image','video','audio')"; }
    if ($category !== '' && $category !== 'All') { $where[] = 'category=?'; $args[] = $category; }
    if ($q !== '') { $where[] = '(title LIKE ? OR prompt LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $total = (int)(function() use ($pdo,$wsql,$args){ $st=$pdo->prepare("SELECT COUNT(*) FROM media $wsql"); $st->execute($args); return $st->fetchColumn(); })();
    $st = $pdo->prepare("SELECT id,provider,kind,ext,width,height,title,prompt,category,created_at FROM media $wsql ORDER BY (created_at>0) DESC, created_at DESC LIMIT $limit OFFSET $offset");
    $st->execute($args);
    // Facets
    $facets = [];
    foreach (['provider','kind','category'] as $f) {
        $st2 = $pdo->prepare("SELECT $f v, COUNT(*) n FROM media WHERE kind IN ('image','video','audio') GROUP BY $f ORDER BY n DESC");
        $st2->execute();
        $facets[$f] = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
    jout(['total'=>$total, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC), 'facets'=>$facets]);
}

function api_personas(): void {
    $pdo = db();
    $rows = $pdo->query('SELECT id,provider,name,description,instructions,source,created_at FROM personas ORDER BY source ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
    jout(['items'=>$rows]);
}

function api_notes(): void {
    $pdo = db();
    $provider = $_GET['provider'] ?? '';
    $where = $provider ? 'WHERE provider=?' : '';
    $st = $pdo->prepare("SELECT provider,kind,title,body,created_at FROM notes $where ORDER BY id DESC LIMIT 500");
    $st->execute($provider ? [$provider] : []);
    jout(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

function api_saved_list(): void {
    $pdo = db();
    $rows = $pdo->query('SELECT id,kind,title,body,meta,created_at FROM saved ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    jout(['items'=>$rows]);
}
function api_saved_create(array $in): void {
    $st = db()->prepare('INSERT INTO saved(kind,title,body,meta,created_at) VALUES(?,?,?,?,?)');
    $st->execute([$in['kind'] ?? 'note', $in['title'] ?? 'Untitled', $in['body'] ?? '', json_encode($in['meta'] ?? []), time()]);
    jout(['ok'=>true, 'id'=>db()->lastInsertId()]);
}
function api_saved_delete(array $in): void {
    $st = db()->prepare('DELETE FROM saved WHERE id=?');
    $st->execute([(int)($in['id'] ?? 0)]);
    jout(['ok'=>true]);
}

function api_settings_get(): void {
    $providers = default_providers();
    $out = [];
    foreach ($providers as $pid => $p) {
        $out[$pid] = [
            'label'=>$p['label'], 'company'=>$p['company'], 'accent'=>$p['accent'],
            'default'=> APP_ROOT . '/' . $p['dir'],
            'path'=> cfg_get("path_$pid") ?: (APP_ROOT . '/' . $p['dir']),
            'resolved'=> provider_dir($pid),
            'stats'=> json_decode(meta_get("stats_$pid", 'null'), true),
            'indexed'=> meta_get("indexed_$pid") ? (int)meta_get("indexed_$pid") : 0,
        ];
    }
    jout([
        'providers'=>$out,
        'has_key'=> (bool)(getenv('ANTHROPIC_API_KEY') || cfg_get('anthropic_api_key')),
        'key_source'=> getenv('ANTHROPIC_API_KEY') ? 'env' : (cfg_get('anthropic_api_key') ? 'saved' : 'none'),
        'app_root'=> APP_ROOT,
    ]);
}
/** Server-side folder browser for the Settings "Browse" button. Confined to $HOME. */
function api_browse(): void {
    $home = home_dir();
    $homeSlash = rtrim($home, '/') . '/';
    $req = $_GET['path'] ?? '';
    $real = $req !== '' ? realpath($req) : $home;
    if (!$real || !is_dir($real)) $real = $home;
    // Confine to the home directory tree.
    if ($real !== $home && !str_starts_with(rtrim($real, '/') . '/', $homeSlash)) $real = $home;

    $dirs = [];
    foreach (@scandir($real) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') continue;
        $full = $real . '/' . $e;
        if (is_dir($full)) $dirs[] = ['name' => $e, 'path' => $full];
    }
    usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $parent = null;
    if ($real !== $home) {
        $p = dirname($real);
        $parent = ($p === $home || str_starts_with(rtrim($p, '/') . '/', $homeSlash)) ? $p : $home;
    }
    jout(['path' => $real, 'parent' => $parent, 'home' => $home, 'dirs' => $dirs, 'is_home' => ($real === $home)]);
}

function api_settings_save(array $in): void {
    foreach (default_providers() as $pid => $_) {
        if (isset($in["path_$pid"])) cfg_set("path_$pid", trim((string)$in["path_$pid"]));
    }
    if (isset($in['anthropic_api_key'])) {
        $k = trim((string)$in['anthropic_api_key']);
        if ($k !== '') cfg_set('anthropic_api_key', $k);
    }
    jout(['ok'=>true]);
}

// ---------------------------------------------------------------------------
// Assistant proxy (streaming SSE from the Anthropic API)
// ---------------------------------------------------------------------------
function api_chat(array $in): void {
    $key = getenv('ANTHROPIC_API_KEY') ?: cfg_get('anthropic_api_key');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();

    if (!$key) {
        echo "event: apperror\n";
        echo 'data: ' . json_encode(['message'=>'No Anthropic API key configured. Add one on the Import screen (or set ANTHROPIC_API_KEY).']) . "\n\n";
        flush(); exit;
    }

    $model = $in['model'] ?? 'claude-opus-4-8';
    if (!in_array($model, ['claude-opus-4-8', 'claude-sonnet-5'], true)) $model = 'claude-opus-4-8';
    $messages = $in['messages'] ?? [];
    $system = (string)($in['system'] ?? 'You are SIGNAL, a sharp, resourceful assistant embedded in a personal AI data vault. The user has exported their conversations, images and personas from Claude, ChatGPT, Gemini and Grok. Help them explore that corpus, build personas, create content, and design custom datasets. Be concrete and useful.');

    $payload = [
        'model' => $model,
        'max_tokens' => 4096,
        'stream' => true,
        'system' => $system,
        'thinking' => ['type' => 'adaptive'],
        'messages' => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            echo $data; // pass raw SSE straight through
            @flush();
            return strlen($data);
        },
        CURLOPT_TIMEOUT => 300,
    ]);
    $ok = curl_exec($ch);
    if ($ok === false) {
        echo "event: apperror\n";
        echo 'data: ' . json_encode(['message'=>'Request failed: ' . curl_error($ch)]) . "\n\n";
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code >= 400) {
        echo "event: apperror\n";
        echo 'data: ' . json_encode(['message'=>"API returned HTTP $code. Check your key and model access."]) . "\n\n";
    }
    curl_close($ch);
    echo "event: appdone\ndata: {}\n\n";
    flush();
    exit;
}

function api_context(): void {
    // Lightweight context bundle the assistant can be primed with.
    $pdo = db();
    $convs = $pdo->query("SELECT id,provider,title,category FROM conversations ORDER BY (created_at>0) DESC, created_at DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
    $personas = $pdo->query("SELECT id,provider,name FROM personas ORDER BY name ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    jout(['conversations'=>$convs, 'personas'=>$personas]);
}

function api_context_text(): void {
    // Build a text blob for a set of conversation / persona ids to inject as context.
    $pdo = db();
    $convIds = array_filter(explode(',', $_GET['conv'] ?? ''));
    $personaIds = array_filter(explode(',', $_GET['persona'] ?? ''));
    $out = '';
    foreach ($convIds as $cid) {
        $st = $pdo->prepare('SELECT title FROM conversations WHERE id=?'); $st->execute([$cid]);
        $title = $st->fetchColumn();
        if ($title === false) continue;
        $out .= "### Conversation: $title\n";
        $st = $pdo->prepare('SELECT role,text FROM messages WHERE conv_id=? ORDER BY seq ASC LIMIT 60'); $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out .= strtoupper($m['role']) . ': ' . mb_substr($m['text'], 0, 1500) . "\n";
        }
        $out .= "\n";
    }
    foreach ($personaIds as $pidid) {
        $st = $pdo->prepare('SELECT name,instructions FROM personas WHERE id=?'); $st->execute([$pidid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) $out .= "### Persona/Instructions: {$p['name']}\n{$p['instructions']}\n\n";
    }
    jout(['text'=>mb_substr($out, 0, 60000)]);
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------
function export_conversation_md(string $id): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM conversations WHERE id=?'); $st->execute([$id]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { http_response_code(404); exit; }
    $st = $pdo->prepare('SELECT role,text,created_at FROM messages WHERE conv_id=? ORDER BY seq ASC'); $st->execute([$id]);
    $md = "# " . $conv['title'] . "\n\n_Provider: {$conv['provider']}_\n\n";
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $who = $m['role'] === 'assistant' ? 'Assistant' : 'You';
        $md .= "**$who:**\n\n" . $m['text'] . "\n\n---\n\n";
    }
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '_', $conv['title']) . '.md"');
    echo $md; exit;
}

function export_index_json(): void {
    $pdo = db();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="signal-vault-index.json"');
    $F = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    echo '{"exported_at":' . time() . ',"conversations":';
    echo json_encode($pdo->query('SELECT id,provider,title,created_at,msg_count,category FROM conversations')->fetchAll(PDO::FETCH_ASSOC), $F);
    echo ',"personas":';
    echo json_encode($pdo->query('SELECT * FROM personas')->fetchAll(PDO::FETCH_ASSOC), $F);
    echo ',"media":';
    echo json_encode($pdo->query('SELECT id,provider,kind,title,prompt,category FROM media')->fetchAll(PDO::FETCH_ASSOC), $F);
    echo '}';
    exit;
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------
$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Require a JSON body. Dropping the $_POST form fallback means a cross-site
    // HTML form (which cannot set Content-Type: application/json without a CORS
    // preflight) can't drive the mutating endpoints — basic CSRF hardening.
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    $input = is_array($decoded) ? $decoded : [];
}

if (isset($_GET['media']))  { serve_media((string)$_GET['media']); }
if (isset($_GET['thumb']))  { serve_thumb((string)$_GET['thumb'], min(1200, max(120, (int)($_GET['w'] ?? 460)))); }

if (isset($_GET['export'])) {
    switch ($_GET['export']) {
        case 'conversation': export_conversation_md((string)($_GET['id'] ?? '')); break;
        case 'index': export_index_json(); break;
        default: http_response_code(400); exit;
    }
}

if (isset($_GET['api'])) {
    try {
        switch ($_GET['api']) {
            case 'overview':       api_overview(); break;
            case 'conversations':  api_conversations(); break;
            case 'conversation':   api_conversation(); break;
            case 'categories':     api_categories(); break;
            case 'gallery':        api_gallery(); break;
            case 'personas':       api_personas(); break;
            case 'notes':          api_notes(); break;
            case 'context':        api_context(); break;
            case 'context_text':   api_context_text(); break;
            case 'saved':          api_saved_list(); break;
            case 'saved_create':   api_saved_create($input); break;
            case 'saved_delete':   api_saved_delete($input); break;
            case 'settings':       api_settings_get(); break;
            case 'browse':         api_browse(); break;
            case 'settings_save':  api_settings_save($input); break;
            case 'index':          jout(run_index((string)($input['provider'] ?? $_GET['provider'] ?? ''))); break;
            case 'chat':           api_chat($input); break;
            default:               jout(['error'=>'unknown api']);
        }
    } catch (Throwable $e) {
        jout(['error'=>$e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// App shell
// ---------------------------------------------------------------------------
// Defense in depth: same-origin by default; fonts from Google; inline JS/CSS
// allowed (the app is one file); connect only to self so an injected script
// can't exfiltrate the vault to an external host.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; "
     . "font-src 'self' https://fonts.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
     . "script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
$boot = [
    'providers' => default_providers(),
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SIGNAL — AI Data Vault</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script>window.__BOOT__ = <?= json_encode($boot, JSON_UNESCAPED_SLASHES) ?>;</script>
<?php echo app_styles(); ?>
</head>
<body>
<div id="app"></div>
<?php echo app_script(); ?>
</body>
</html>
<?php

function app_styles(): string {
    return <<<'HTML'
<style>
:root{
  --ink:#0a0c11; --panel:#111420; --panel-2:#161a28; --elev:#1c2233;
  --line:rgba(150,165,200,.13); --line-2:rgba(150,165,200,.22);
  --txt:#e9ecf4; --muted:#9aa2b8; --dim:#6b7288;
  --anthropic:#d97757; --openai:#10a37f; --google:#4285f4; --xai:#5ed0e6;
  --signal:#8b8cf5; --signal-2:#c8b6ff;
  --radius:14px; --mono:'IBM Plex Mono',ui-monospace,monospace;
  --disp:'Space Grotesk',system-ui,sans-serif; --body:'IBM Plex Sans',system-ui,sans-serif;
}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{
  background:
    radial-gradient(1200px 700px at 80% -10%, rgba(139,140,245,.10), transparent 55%),
    radial-gradient(900px 600px at -5% 100%, rgba(94,208,230,.06), transparent 55%),
    var(--ink);
  color:var(--txt); font-family:var(--body); font-size:14.5px; line-height:1.55;
  -webkit-font-smoothing:antialiased;
}
::selection{background:rgba(139,140,245,.32)}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
#app{display:grid;grid-template-columns:76px 1fr;min-height:100vh}
.mono{font-family:var(--mono)}

/* ---------- Rail ---------- */
.rail{position:sticky;top:0;height:100vh;border-right:1px solid var(--line);
  background:linear-gradient(180deg,rgba(20,24,38,.6),rgba(10,12,17,.4));
  display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 0;z-index:20}
.brand{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;
  background:conic-gradient(from 220deg,var(--signal),var(--xai),var(--signal-2),var(--signal));
  box-shadow:0 0 0 1px rgba(255,255,255,.08),0 8px 22px -8px rgba(139,140,245,.7);margin-bottom:14px;position:relative}
.brand::after{content:"";position:absolute;inset:5px;border-radius:8px;background:var(--ink)}
.brand b{position:relative;z-index:2;font-family:var(--disp);font-weight:700;font-size:19px;background:linear-gradient(180deg,#fff,#b9bdf5);-webkit-background-clip:text;background-clip:text;color:transparent}
.navbtn{width:46px;height:46px;border-radius:12px;border:1px solid transparent;background:transparent;color:var(--muted);
  display:grid;place-items:center;cursor:pointer;transition:.16s;position:relative}
.navbtn:hover{color:var(--txt);background:rgba(255,255,255,.04);border-color:var(--line)}
.navbtn.active{color:var(--txt);background:rgba(139,140,245,.14);border-color:rgba(139,140,245,.4)}
.navbtn.active::before{content:"";position:absolute;left:-13px;top:12px;bottom:12px;width:3px;border-radius:3px;background:var(--signal)}
.navbtn svg{width:21px;height:21px}
.navbtn .tip{position:absolute;left:56px;white-space:nowrap;background:var(--elev);border:1px solid var(--line-2);
  padding:5px 10px;border-radius:8px;font-size:12px;opacity:0;pointer-events:none;transform:translateX(-6px);transition:.14s;z-index:40;box-shadow:0 10px 30px -12px #000}
.navbtn:hover .tip{opacity:1;transform:translateX(0)}
.rail .sep{flex:1}
.pdot{width:9px;height:9px;border-radius:50%;box-shadow:0 0 8px currentColor}

/* ---------- Main ---------- */
.main{min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:15;display:flex;align-items:center;gap:14px;
  padding:14px 26px;border-bottom:1px solid var(--line);
  background:linear-gradient(180deg,rgba(10,12,17,.94),rgba(10,12,17,.72));backdrop-filter:blur(10px)}
.crumb{font-family:var(--disp);font-weight:600;font-size:15px;letter-spacing:.2px}
.crumb .sub{color:var(--muted);font-weight:400;font-family:var(--body);font-size:12.5px;margin-left:8px}
.search{margin-left:auto;position:relative;width:min(340px,40vw)}
.search input{width:100%;background:var(--panel);border:1px solid var(--line);color:var(--txt);
  border-radius:10px;padding:9px 12px 9px 34px;font-family:var(--body);font-size:13.5px;outline:none}
.search input:focus{border-color:var(--line-2)}
.search svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted)}
.content{padding:26px;max-width:1500px;width:100%;margin:0 auto}

/* ---------- Bits ---------- */
.eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.28em;text-transform:uppercase;color:var(--muted)}
.h1{font-family:var(--disp);font-weight:700;font-size:clamp(26px,4vw,40px);line-height:1.04;letter-spacing:-.01em;margin:8px 0 0}
.chip{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;border:1px solid var(--line);
  background:var(--panel);color:var(--muted);font-size:12.5px;cursor:pointer;transition:.15s;white-space:nowrap}
.chip:hover{color:var(--txt);border-color:var(--line-2)}
.chip.active{color:var(--txt);background:rgba(139,140,245,.14);border-color:rgba(139,140,245,.45)}
.chip .n{font-family:var(--mono);font-size:11px;color:var(--dim)}
.chip.active .n{color:var(--signal-2)}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line-2);background:var(--elev);color:var(--txt);
  padding:9px 15px;border-radius:10px;font-size:13px;cursor:pointer;transition:.15s;font-family:var(--body)}
.btn:hover{border-color:var(--signal);background:rgba(139,140,245,.12)}
.btn.primary{background:linear-gradient(180deg,#8b8cf5,#6f70e6);border-color:transparent;color:#fff;font-weight:600}
.btn.primary:hover{filter:brightness(1.08)}
.btn.ghost{background:transparent}
.btn:disabled{opacity:.5;cursor:not-allowed}
.card{background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,0));border:1px solid var(--line);border-radius:var(--radius)}
.muted{color:var(--muted)}
.dim{color:var(--dim)}
.spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;display:inline-block}
@keyframes sp{to{transform:rotate(360deg)}}
.empty{padding:60px 20px;text-align:center;color:var(--muted)}
.empty .big{font-family:var(--disp);font-size:20px;color:var(--txt);margin-bottom:6px}

/* ---------- Overview ---------- */
.hero{position:relative;border:1px solid var(--line);border-radius:20px;overflow:hidden;padding:34px 34px 26px;
  background:linear-gradient(180deg,rgba(139,140,245,.07),rgba(255,255,255,0))}
#constellation{position:absolute;inset:0;width:100%;height:100%;opacity:.85}
.hero-inner{position:relative;z-index:2}
.hero .stats{display:flex;gap:30px;flex-wrap:wrap;margin-top:22px}
.hero .stat b{font-family:var(--disp);font-size:30px;font-weight:700;display:block;line-height:1}
.hero .stat span{font-family:var(--mono);font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--muted)}
.stations{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-top:22px}
.station{position:relative;border:1px solid var(--line);border-radius:16px;padding:20px;cursor:pointer;overflow:hidden;transition:.18s;background:var(--panel)}
.station:hover{transform:translateY(-3px);border-color:var(--line-2)}
.station::before{content:"";position:absolute;inset:0;background:radial-gradient(120px 80px at 90% 0%,var(--acc),transparent 70%);opacity:.16}
.station .no{font-family:var(--mono);font-size:11px;color:var(--dim);letter-spacing:.2em}
.station h3{font-family:var(--disp);font-size:22px;margin:8px 0 2px}
.station .co{font-size:12px;color:var(--muted)}
.station .row{display:flex;gap:18px;margin-top:16px;flex-wrap:wrap}
.station .row b{font-family:var(--mono);font-size:18px;color:var(--txt);display:block}
.station .row span{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.station .bar{height:3px;border-radius:3px;background:var(--acc);margin-top:16px;box-shadow:0 0 12px var(--acc)}
.section-h{display:flex;align-items:baseline;gap:12px;margin:34px 0 14px}
.section-h h2{font-family:var(--disp);font-size:19px;margin:0}
.filmstrip{display:grid;grid-auto-flow:column;grid-auto-columns:180px;gap:12px;overflow-x:auto;padding-bottom:8px;scrollbar-width:thin}
.film{aspect-ratio:1;border-radius:12px;overflow:hidden;border:1px solid var(--line);background:var(--panel-2);cursor:pointer;position:relative}
.film img,.film video{width:100%;height:100%;object-fit:cover;display:block}
.film .tag{position:absolute;left:8px;bottom:8px;font-family:var(--mono);font-size:10px;padding:2px 7px;border-radius:6px;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)}

/* ---------- Provider / Conversations ---------- */
.split{display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:0;border:1px solid var(--line);border-radius:16px;overflow:hidden;min-height:64vh}
.list-pane{border-right:1px solid var(--line);display:flex;flex-direction:column;background:rgba(255,255,255,.012)}
.list-scroll{overflow-y:auto;flex:1}
.conv-item{padding:13px 16px;border-bottom:1px solid var(--line);cursor:pointer;transition:.12s}
.conv-item:hover{background:rgba(255,255,255,.03)}
.conv-item.active{background:rgba(139,140,245,.12);box-shadow:inset 3px 0 0 var(--signal)}
.conv-item h4{margin:0 0 3px;font-size:14px;font-weight:600;font-family:var(--body);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv-item p{margin:0;font-size:12px;color:var(--muted);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.conv-item .meta{display:flex;gap:10px;margin-top:6px;font-family:var(--mono);font-size:10.5px;color:var(--dim)}
.reader{overflow-y:auto;padding:26px 30px;max-height:78vh}
.reader-head{display:flex;align-items:flex-start;gap:12px;justify-content:space-between;margin-bottom:20px}
.reader-head h2{font-family:var(--disp);font-size:22px;margin:0}
.msg{display:flex;gap:12px;margin-bottom:20px}
.msg .who{flex:none;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-family:var(--mono);font-size:11px;font-weight:600}
.msg.user .who{background:rgba(139,140,245,.18);color:var(--signal-2);border:1px solid rgba(139,140,245,.35)}
.msg.assistant .who{background:var(--elev);color:var(--muted);border:1px solid var(--line)}
.msg .body{min-width:0;flex:1}
.msg .txt{white-space:pre-wrap;word-wrap:break-word;font-size:14px}
.msg .txt code{background:rgba(255,255,255,.07);padding:1px 5px;border-radius:5px;font-family:var(--mono);font-size:12.5px}
.msg .txt pre{background:var(--panel-2);border:1px solid var(--line);border-radius:10px;padding:12px;overflow-x:auto;max-width:100%;font-family:var(--mono);font-size:12.5px}
.msg .inline-media{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.msg .inline-media img{max-height:200px;border-radius:10px;border:1px solid var(--line);cursor:pointer}

/* ---------- Gallery ---------- */
.gtoolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
.grid{columns:5 220px;column-gap:14px}
.tile{break-inside:avoid;margin-bottom:14px;border-radius:12px;overflow:hidden;border:1px solid var(--line);background:var(--panel-2);cursor:pointer;position:relative;display:block}
.tile img,.tile video{width:100%;display:block}
.tile .ov{position:absolute;inset:0;background:linear-gradient(180deg,transparent 50%,rgba(0,0,0,.72));opacity:0;transition:.15s;display:flex;align-items:flex-end;padding:10px}
.tile:hover .ov{opacity:1}
.tile .ov p{margin:0;font-size:11.5px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.tile .badge{position:absolute;top:8px;left:8px;font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;padding:3px 7px;border-radius:6px;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);text-transform:uppercase}
.tile .kindtag{position:absolute;top:8px;right:8px;width:22px;height:22px;border-radius:6px;background:rgba(0,0,0,.55);display:grid;place-items:center}
.tile .kindtag svg{width:13px;height:13px}
.audio-tile{padding:16px;display:block}
.audio-tile h5{margin:0 0 10px;font-size:13px;font-family:var(--body)}
.audio-tile audio{width:100%}

/* Lightbox */
.lightbox{position:fixed;inset:0;z-index:100;background:rgba(6,7,11,.92);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center}
.lightbox.on{display:flex}
.lb-stage{max-width:88vw;max-height:82vh;display:flex;flex-direction:column;gap:12px;align-items:center}
.lb-stage img,.lb-stage video{max-width:88vw;max-height:70vh;border-radius:12px;box-shadow:0 30px 80px -20px #000}
.lb-meta{max-width:760px;text-align:center;color:var(--muted);font-size:13px}
.lb-close,.lb-nav{position:fixed;background:rgba(255,255,255,.06);border:1px solid var(--line-2);color:#fff;border-radius:12px;cursor:pointer;display:grid;place-items:center}
.lb-close{top:20px;right:24px;width:42px;height:42px}
.lb-nav{top:50%;transform:translateY(-50%);width:48px;height:60px}
.lb-nav.prev{left:20px}.lb-nav.next{right:20px}
.lb-nav:hover,.lb-close:hover{background:rgba(139,140,245,.2)}

/* ---------- Assistant ---------- */
.assistant-page{display:grid;grid-template-columns:1fr 300px;gap:18px;height:calc(100vh - 140px)}
.chat-wrap{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:16px;overflow:hidden;background:rgba(255,255,255,.012)}
.chat-head{display:flex;gap:10px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.select{background:var(--panel);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:8px 10px;font-family:var(--body);font-size:13px;cursor:pointer}
.modes{display:flex;gap:6px;flex-wrap:wrap}
.chat-scroll{flex:1;overflow-y:auto;padding:22px 24px}
.bubble{max-width:82%;margin-bottom:16px;padding:12px 15px;border-radius:14px;font-size:14px;white-space:pre-wrap;word-wrap:break-word}
.bubble.user{margin-left:auto;background:linear-gradient(180deg,rgba(139,140,245,.22),rgba(139,140,245,.12));border:1px solid rgba(139,140,245,.3)}
.bubble.assistant{background:var(--panel);border:1px solid var(--line)}
.bubble.assistant code{background:rgba(255,255,255,.07);padding:1px 5px;border-radius:5px;font-family:var(--mono);font-size:12.5px}
.bubble.assistant pre{background:var(--panel-2);border:1px solid var(--line);border-radius:10px;padding:12px;overflow-x:auto;font-family:var(--mono);font-size:12.5px}
.chat-input{border-top:1px solid var(--line);padding:14px 16px;display:flex;gap:10px;align-items:flex-end}
.chat-input textarea{flex:1;resize:none;background:var(--panel);border:1px solid var(--line);color:var(--txt);border-radius:12px;
  padding:11px 14px;font-family:var(--body);font-size:14px;outline:none;max-height:160px;min-height:46px}
.chat-input textarea:focus{border-color:var(--line-2)}
.side-panel{border:1px solid var(--line);border-radius:16px;padding:16px;overflow-y:auto;background:rgba(255,255,255,.012)}
.side-panel h4{font-family:var(--disp);font-size:14px;margin:0 0 10px}
.ctx-item{display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:9px;font-size:12.5px;cursor:pointer;border:1px solid transparent}
.ctx-item:hover{background:rgba(255,255,255,.03)}
.ctx-item.on{background:rgba(139,140,245,.12);border-color:rgba(139,140,245,.3)}
.ctx-item .pd{width:7px;height:7px;border-radius:50%;flex:none}

/* ---------- Import ---------- */
.imp-grid{display:grid;gap:16px}
.imp-row{display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center;padding:18px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
.imp-row .mark{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-family:var(--disp);font-weight:700;font-size:18px;color:#0a0c11}
.imp-row input{width:100%;background:var(--ink);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:9px 11px;font-family:var(--mono);font-size:12px}
.imp-row .st{font-family:var(--mono);font-size:11px;color:var(--dim)}
.field{margin:14px 0}
.field label{display:block;font-size:12.5px;color:var(--muted);margin-bottom:6px}
.field input{width:100%;background:var(--ink);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:11px 13px;font-family:var(--mono);font-size:13px}
.notice{border-left:3px solid var(--signal);background:rgba(139,140,245,.07);padding:12px 16px;border-radius:0 10px 10px 0;font-size:13px;color:var(--muted)}

/* ---------- Folder picker ---------- */
.fp-ov{position:fixed;inset:0;z-index:120;background:rgba(6,7,11,.86);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center}
.fp{width:min(640px,92vw);max-height:82vh;display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--line-2);border-radius:16px;overflow:hidden;box-shadow:0 30px 80px -20px #000}
.fp-head{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line)}
.fp-head b{font-family:var(--disp);font-size:16px}
.fp-head .fp-x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;padding:4px 8px;border-radius:8px}
.fp-head .fp-x:hover{background:rgba(255,255,255,.06);color:var(--txt)}
.fp-crumb{padding:9px 18px;font-size:11.5px;color:var(--muted);border-bottom:1px solid var(--line);word-break:break-all;background:var(--ink)}
.fp-list{flex:1;overflow-y:auto;padding:8px;min-height:180px}
.fp-item{padding:9px 12px;border-radius:9px;cursor:pointer;font-size:13.5px;display:flex;gap:9px;align-items:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fp-item:hover{background:rgba(139,140,245,.12)}
.fp-item.up{color:var(--muted)}
.fp-item .ic{flex:none;width:16px;text-align:center}
.fp-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-top:1px solid var(--line);flex-wrap:wrap}
.fp-foot .h{font-size:12px;color:var(--muted);max-width:280px}

.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--elev);border:1px solid var(--line-2);
  padding:11px 18px;border-radius:12px;font-size:13px;z-index:200;box-shadow:0 20px 50px -18px #000;opacity:0;transition:.2s}
.toast.on{opacity:1}

@media(max-width:1100px){.assistant-page{grid-template-columns:1fr}.side-panel{display:none}.split{grid-template-columns:1fr}.reader{max-height:none}}
@media(max-width:640px){#app{grid-template-columns:60px 1fr}.content{padding:16px}.grid{columns:2 150px}}
</style>
HTML;
}

function app_script(): string {
    return <<<'HTML'
<script>
const $ = (s,el=document)=>el.querySelector(s);
const $$ = (s,el=document)=>[...el.querySelectorAll(s)];
const app = $('#app');
const PROV = window.__BOOT__.providers;
const ACC = {anthropic:'#d97757',openai:'#10a37f',google:'#4285f4',xai:'#5ed0e6'};
const CO = {anthropic:'Anthropic',openai:'OpenAI',google:'Google',xai:'xAI'};
const LB = {anthropic:'Claude',openai:'ChatGPT',google:'Gemini',xai:'Grok'};

const state = { view:'overview', provider:null, gallery:{provider:'all',kind:'all',category:'All',q:''} };

// ---- utils ----
function esc(s){return (s??'').toString().replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function fmtDate(ts){ if(!ts) return '—'; const d=new Date(ts*1000); return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'}); }
function fmtN(n){ n=+n||0; return n>=1000? (n/1000).toFixed(n>=10000?0:1)+'k' : ''+n; }
async function api(name,params={}){ const q=new URLSearchParams({api:name,...params}); const r=await fetch('?'+q.toString()); return r.json(); }
async function post(name,body){ const r=await fetch('?api='+name,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)}); return r.json(); }
function toast(msg){ let t=$('.toast'); if(!t){t=document.createElement('div');t.className='toast';document.body.appendChild(t);} t.textContent=msg; t.classList.add('on'); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('on'),2600); }
function mini_md(s){
  s = esc(s);
  s = s.replace(/```([\s\S]*?)```/g,(m,c)=>'<pre>'+c.replace(/^\n/,'')+'</pre>');
  s = s.replace(/`([^`\n]+)`/g,'<code>$1</code>');
  s = s.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
  s = s.replace(/^### (.*)$/gm,'<strong>$1</strong>');
  s = s.replace(/^## (.*)$/gm,'<strong>$1</strong>');
  return s;
}
const ICON = {
  overview:'<path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/>',
  gallery:'<path d="M3 5h18v14H3z"/><circle cx="8.5" cy="10" r="1.8"/><path d="M21 17l-6-6-4 4-2-2-6 6"/>',
  assistant:'<path d="M12 2a7 7 0 017 7c0 3-2 5-2 7v1H7v-1c0-2-2-4-2-7a7 7 0 017-7z"/><path d="M9 21h6"/>',
  import:'<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>',
  video:'<path d="M4 5h11v14H4zM17 9l4-2v10l-4-2"/>',
  play:'<path d="M6 4l14 8-14 8z"/>',
  dl:'<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 20h16"/>',
  save:'<path d="M5 3h11l4 4v14H5zM8 3v6h8"/>',
  folder:'<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
  x:'<path d="M6 6l12 12M18 6L6 18"/>',
};
function svg(name,extra=''){ return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" ${extra}>${ICON[name]||''}</svg>`; }

// ---- shell ----
function shell(){
  app.innerHTML = `
  <nav class="rail">
    <div class="brand"><b>S</b></div>
    <button class="navbtn" data-nav="overview"><span class="tip">Overview</span>${svg('overview')}</button>
    ${Object.keys(PROV).map((p,i)=>`<button class="navbtn" data-nav="p:${p}"><span class="tip">${LB[p]}</span><span class="pdot" style="color:${ACC[p]}"></span></button>`).join('')}
    <button class="navbtn" data-nav="gallery"><span class="tip">Gallery</span>${svg('gallery')}</button>
    <button class="navbtn" data-nav="assistant"><span class="tip">Assistant</span>${svg('assistant')}</button>
    <div class="sep"></div>
    <button class="navbtn" data-nav="import"><span class="tip">Import & Settings</span>${svg('import')}</button>
  </nav>
  <main class="main">
    <div class="topbar">
      <div class="crumb" id="crumb">SIGNAL <span class="sub">AI Data Vault</span></div>
      <div class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input id="globalSearch" placeholder="Search conversations & media…" autocomplete="off"></div>
    </div>
    <div class="content" id="view"></div>
  </main>`;
  $$('.rail [data-nav]').forEach(b=>b.onclick=()=>{ const n=b.dataset.nav; if(n.startsWith('p:')) go('provider',n.slice(2)); else go(n); });
  $('#globalSearch').addEventListener('keydown',e=>{ if(e.key==='Enter'){ state.gallery.q=e.target.value.trim(); go('gallery'); } });
}
function setActive(){
  $$('.rail [data-nav]').forEach(b=>{ const n=b.dataset.nav;
    const on = (state.view==='overview'&&n==='overview')||(state.view==='provider'&&n==='p:'+state.provider)||(state.view===n);
    b.classList.toggle('active',on); });
}
function crumb(main,sub=''){ $('#crumb').innerHTML = `${esc(main)} ${sub?`<span class="sub">${esc(sub)}</span>`:''}`; }

function go(view,provider=null,push=true){
  cancelAnimationFrame(window._raf); window._raf=0;   // stop the constellation loop when leaving Overview
  state.view=view; if(provider)state.provider=provider;
  const prov = view==='provider' ? (provider||state.provider) : '';
  const hash = view==='provider'?`#/p/${prov}`:`#/${view}`;
  if(push && location.hash!==hash) history.pushState({},'',hash);
  window._rk = view+':'+prov;   // remember what is rendered (de-dupes back/forward double events)
  setActive();
  const v=$('#view'); v.innerHTML='';
  if(view==='overview') renderOverview(v);
  else if(view==='provider') renderProvider(v,state.provider);
  else if(view==='gallery') renderGallery(v);
  else if(view==='assistant') renderAssistant(v);
  else if(view==='import') renderImport(v);
}

// ================= OVERVIEW =================
async function renderOverview(v){
  crumb('SIGNAL','AI Data Vault');
  v.innerHTML = `<section class="hero"><canvas id="constellation"></canvas><div class="hero-inner">
    <div class="eyebrow">Personal signal archive · four sources, one instrument</div>
    <h1 class="h1">Every conversation, image and idea<br>you've made with the machines.</h1>
    <div class="stats" id="heroStats"><div class="stat"><b>·</b><span>loading</span></div></div>
  </div></section>
  <div class="stations" id="stations"></div>
  <div class="section-h"><h2>Recent generations</h2><div class="dim mono" style="font-size:11px">images & video across all sources</div></div>
  <div class="filmstrip" id="film"></div>`;
  const d = await api('overview');
  const totals = d.providers.reduce((a,p)=>({c:a.c+p.conversations,m:a.m+p.messages,i:a.i+p.images,vv:a.vv+p.videos}),{c:0,m:0,i:0,vv:0});
  $('#heroStats').innerHTML = [
    ['conversations',totals.c],['messages',totals.m],['images',totals.i],['videos',totals.vv],['sources',d.providers.filter(p=>p.conversations||p.images).length+' / 4']
  ].map(([l,n])=>`<div class="stat"><b>${typeof n==='number'?fmtN(n):n}</b><span>${l}</span></div>`).join('');
  const maxConv = Math.max(1,...d.providers.map(p=>p.conversations+p.images+p.videos));
  $('#stations').innerHTML = d.providers.map((p,i)=>`
    <div class="station" data-p="${p.id}" style="--acc:${p.accent}">
      <div class="no">STATION 0${i+1}${p.detected?'':' · not found'}</div>
      <h3>${esc(p.label)}</h3><div class="co">${esc(p.company)}${p.indexed?'':' · not indexed'}</div>
      <div class="row">
        <div><b>${fmtN(p.conversations)}</b><span>chats</span></div>
        <div><b>${fmtN(p.images+p.videos)}</b><span>media</span></div>
        <div><b>${fmtN(p.personas)}</b><span>personas</span></div>
      </div>
      <div class="bar" style="width:${20+80*(p.conversations+p.images+p.videos)/maxConv}%"></div>
    </div>`).join('');
  $$('#stations .station').forEach(s=>s.onclick=()=>go('provider',s.dataset.p));
  $('#film').innerHTML = d.recent.length ? d.recent.map(m=>filmTile(m)).join('') :
    `<div class="dim" style="padding:20px">No media indexed yet — open <b>Import & Settings</b> to build your index.</div>`;
  $$('#film .film').forEach((el,i)=>el.onclick=()=>openLightbox(d.recent,i));
  drawConstellation(d.providers);
  if(!d.providers.some(p=>p.indexed)) setTimeout(()=>toast('Tip: open Import & Settings to build your index.'),700);
}
function filmTile(m){
  const src = `?thumb=${encodeURIComponent(m.id)}&w=360`;
  const inner = m.kind==='video' ? `<video src="?media=${encodeURIComponent(m.id)}#t=0.5" muted preload="metadata"></video>` : `<img loading="lazy" src="${src}">`;
  return `<div class="film">${inner}<div class="tag">${LB[m.provider]||m.provider}</div></div>`;
}

function drawConstellation(providers){
  const c=$('#constellation'); if(!c) return; const ctx=c.getContext('2d');
  let W,H; const dpr=Math.min(2,devicePixelRatio||1);
  function size(){ const r=c.getBoundingClientRect(); W=r.width; H=r.height; c.width=W*dpr; c.height=H*dpr; ctx.setTransform(dpr,0,0,dpr,0,0);}
  size();
  const nodes=[];
  providers.forEach((p,i)=>{ const count=Math.min(60,8+Math.round((p.conversations+p.images)/40)); for(let k=0;k<count;k++){ nodes.push({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.22,vy:(Math.random()-.5)*.22,c:p.accent,r:Math.random()*1.6+.6});}});
  cancelAnimationFrame(window._raf);   // stop any prior loop before starting a new one
  function frame(){
    ctx.clearRect(0,0,W,H);
    for(const n of nodes){ n.x+=n.vx; n.y+=n.vy; if(n.x<0||n.x>W)n.vx*=-1; if(n.y<0||n.y>H)n.vy*=-1; }
    for(let i=0;i<nodes.length;i++){ for(let j=i+1;j<nodes.length;j++){ const a=nodes[i],b=nodes[j]; const dx=a.x-b.x,dy=a.y-b.y; const dd=dx*dx+dy*dy; if(dd<9000){ ctx.strokeStyle=a.c; ctx.globalAlpha=(1-dd/9000)*.12; ctx.lineWidth=.6; ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke(); } } }
    ctx.globalAlpha=1;
    for(const n of nodes){ ctx.fillStyle=n.c; ctx.globalAlpha=.8; ctx.beginPath(); ctx.arc(n.x,n.y,n.r,0,7); ctx.fill(); }
    ctx.globalAlpha=1; window._raf=requestAnimationFrame(frame);
  }
  window._raf=requestAnimationFrame(frame);
  if(window._constResize) removeEventListener('resize',window._constResize);
  window._constResize=size; addEventListener('resize',size);
}

// ================= PROVIDER =================
async function renderProvider(v,pid){
  crumb(LB[pid],CO[pid]+' export');
  v.innerHTML = `
   <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">
     <span class="pdot" style="color:${ACC[pid]};width:12px;height:12px"></span>
     <div><div class="eyebrow">Station · ${esc(CO[pid])}</div><h1 class="h1" style="font-size:30px">${esc(LB[pid])}</h1></div>
     <div style="margin-left:auto" id="pMode"></div>
   </div>
   <div id="catbar" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0"></div>
   <div id="pBody"></div>`;
  $('#pMode').innerHTML = `<div class="modes"><button class="chip active" data-mode="chats">Conversations</button><button class="chip" data-mode="media">Media</button><button class="chip" data-mode="personas">Personas</button></div>`;
  let mode='chats';
  const setMode=(m)=>{ mode=m; $$('#pMode .chip').forEach(c=>c.classList.toggle('active',c.dataset.mode===m)); paint(); };
  $$('#pMode .chip').forEach(c=>c.onclick=()=>setMode(c.dataset.mode));

  const cats = await api('categories',{provider:pid});
  let activeCat='All';
  function paintCats(){
    $('#catbar').innerHTML = mode==='chats' ? [{category:'All',n:cats.categories.reduce((a,c)=>a+ +c.n,0)},...cats.categories]
      .map(c=>`<button class="chip ${c.category===activeCat?'active':''}" data-cat="${esc(c.category)}">${esc(c.category)} <span class="n">${c.n}</span></button>`).join('') : '';
    $$('#catbar .chip').forEach(ch=>ch.onclick=()=>{ activeCat=ch.dataset.cat; paintCats(); paint(); });
  }
  function paint(){
    paintCats();
    if(mode==='chats') convBrowser($('#pBody'),pid,activeCat);
    else if(mode==='media') providerMedia($('#pBody'),pid);
    else providerPersonas($('#pBody'),pid);
  }
  paint();
}

async function convBrowser(host,pid,category){
  host.innerHTML = `<div class="split">
    <div class="list-pane">
      <div style="padding:10px 12px;border-bottom:1px solid var(--line)">
        <div class="search" style="width:100%;margin:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input id="convSearch" placeholder="Search these conversations…" autocomplete="off"></div>
      </div>
      <div class="list-scroll" id="convList"><div class="empty"><span class="spin"></span></div></div>
    </div>
    <div class="reader" id="reader"><div class="empty"><div class="big">Select a conversation</div>Pick one from the left to read the full thread.</div></div>
  </div>`;
  let offset=0, total=0, loading=false, done=false, q='';
  const list=$('#convList');
  async function loadMore(reset){
    if(loading||(done&&!reset))return; loading=true;
    if(reset){offset=0;done=false;list.innerHTML='<div class="empty"><span class="spin"></span></div>';}
    const d=await api('conversations',{provider:pid,category,offset,limit:40,q});
    total=d.total;
    if(reset)list.innerHTML='';
    if(!d.items.length&&reset){list.innerHTML=`<div class="empty">${q?'No matches.':'No conversations in this category.'}</div>`;loading=false;return;}
    d.items.forEach(c=>{
      const el=document.createElement('div'); el.className='conv-item'; el.dataset.id=c.id;
      el.innerHTML=`<h4>${esc(c.title)}</h4><p>${esc(c.snippet)}</p>
        <div class="meta"><span>${fmtDate(c.created_at)}</span><span>${c.msg_count} msgs</span>${c.has_media?'<span>◈ media</span>':''}${c.subcategory?`<span>${esc(c.subcategory)}</span>`:''}</div>`;
      el.onclick=()=>{ $$('#convList .conv-item').forEach(x=>x.classList.remove('active')); el.classList.add('active'); openConv(c.id); };
      list.appendChild(el);
    });
    offset+=d.items.length; if(offset>=total)done=true; loading=false;
  }
  list.onscroll=()=>{ if(list.scrollTop+list.clientHeight>list.scrollHeight-200) loadMore(false); };
  $('#convSearch').addEventListener('input',debounce(e=>{ q=e.target.value.trim(); loadMore(true); },300));
  await loadMore(true);
  const first=$('#convList .conv-item'); if(first){first.classList.add('active');openConv(first.dataset.id);}
}

async function openConv(id){
  const r=$('#reader'); r.innerHTML='<div class="empty"><span class="spin"></span></div>';
  const d=await api('conversation',{id});
  if(d.error){r.innerHTML='<div class="empty">Not found.</div>';return;}
  const c=d.conversation;
  r.innerHTML=`<div class="reader-head"><div><div class="eyebrow">${esc(c.category)}${c.subcategory?' · '+esc(c.subcategory):''}</div><h2>${esc(c.title)}</h2>
    <div class="dim mono" style="font-size:11px;margin-top:4px">${fmtDate(c.created_at)} · ${c.msg_count} messages</div></div>
    <a class="btn ghost" href="?export=conversation&id=${encodeURIComponent(id)}">${svg('dl','style="width:15px;height:15px"')} Export</a></div>
    <div id="thread"></div>`;
  const thread=$('#thread');
  thread.innerHTML=d.messages.map(m=>{
    const mids=(m.media_ids||'').split(',').filter(Boolean);
    const media=mids.map(mid=>{ const mm=d.media[mid]; if(!mm)return''; if(mm.kind==='image')return `<img loading="lazy" src="?thumb=${encodeURIComponent(mid)}&w=400" data-full="${encodeURIComponent(mid)}">`; return ''; }).join('');
    return `<div class="msg ${m.role}"><div class="who">${m.role==='user'?'YOU':'AI'}</div>
      <div class="body"><div class="txt">${mini_md(m.text)}</div>${media?`<div class="inline-media">${media}</div>`:''}</div></div>`;
  }).join('');
  $$('#thread .inline-media img').forEach(img=>img.onclick=()=>openLightbox([{id:decodeURIComponent(img.dataset.full),kind:'image',title:c.title,provider:c.provider}],0));
}

async function providerMedia(host,pid){
  host.innerHTML='<div id="pgal"></div>';
  const g=await api('gallery',{provider:pid,kind:'all',limit:60,offset:0});
  renderTiles($('#pgal'),g.items,{provider:pid});
}
async function providerPersonas(host,pid){
  host.innerHTML='<div class="empty"><span class="spin"></span></div>';
  const [d,n]=await Promise.all([api('personas'),api('notes',{provider:pid})]);
  const items=d.items.filter(p=>p.provider===pid);
  const notes=(n.items||[]);
  if(!items.length&&!notes.length){host.innerHTML=`<div class="empty"><div class="big">Nothing here yet</div>Imported gems, projects, custom instructions & memories show up here.</div>`;return;}
  const grid='display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))';
  let html='';
  if(notes.length){
    html+=`<div class="eyebrow" style="margin:4px 0 12px">Memory & notes</div><div style="${grid};margin-bottom:26px">`+
      notes.map(x=>`<div class="card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:center"><b style="font-family:var(--disp);font-size:15px">${esc(x.title||x.kind)}</b><span class="dim mono" style="font-size:10px">${esc(x.kind)}</span></div>
        <div class="dim" style="font-size:12.5px;white-space:pre-wrap;max-height:240px;overflow:auto;margin-top:8px">${esc(x.body)}</div></div>`).join('')+`</div>`;
  }
  if(items.length){
    html+=`<div class="eyebrow" style="margin:4px 0 12px">Personas & custom instructions</div><div style="${grid}">`+
      items.map(p=>`<div class="card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:center"><b style="font-family:var(--disp);font-size:16px">${esc(p.name)}</b>
        <span class="dim mono" style="font-size:10px">${esc(p.source)}</span></div>
        ${p.description?`<div class="muted" style="font-size:12px;margin:4px 0 8px">${esc(p.description)}</div>`:''}
        <div class="dim" style="font-size:12.5px;white-space:pre-wrap;max-height:150px;overflow:auto">${esc(p.instructions)}</div>
        <button class="btn ghost usePersona" data-id="${esc(p.id)}" style="margin-top:12px;font-size:12px">Use in Assistant →</button>
      </div>`).join('')+`</div>`;
  }
  host.innerHTML=html;
  $$('.usePersona').forEach(b=>b.onclick=()=>{ pendingPersona=b.dataset.id; go('assistant'); });
}

// ================= GALLERY =================
let galleryItems=[], gOffset=0, gTotal=0, gLoading=false, gDone=false;
async function renderGallery(v){
  crumb('Gallery','images · video · audio, across all sources');
  v.innerHTML=`
   <div class="gtoolbar">
     <div class="search" style="width:min(320px,40vw);margin:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
       <input id="gq" placeholder="Search prompts & titles…" value="${esc(state.gallery.q)}"></div>
     <div id="provChips" style="display:flex;gap:8px"></div>
     <div id="kindChips" style="display:flex;gap:8px;margin-left:auto"></div>
   </div>
   <div id="catChips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px"></div>
   <div class="grid" id="gGrid"></div>
   <div id="gMore" style="text-align:center;padding:24px"></div>`;
  $('#gq').addEventListener('input',debounce(()=>{state.gallery.q=$('#gq').value;loadGallery(true);},350));
  loadGallery(true);
  window.onscroll=()=>{ if(state.view!=='gallery')return; if(innerHeight+scrollY>document.body.offsetHeight-500) loadGallery(false); };
}
function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};}
async function loadGallery(reset){
  if(gLoading||(gDone&&!reset))return; gLoading=true;
  if(reset){gOffset=0;galleryItems=[];gDone=false;$('#gGrid').innerHTML='<div class="dim" style="padding:14px"><span class="spin"></span></div>';}
  const g=state.gallery;
  const d=await api('gallery',{provider:g.provider,kind:g.kind,category:g.category,q:g.q,offset:gOffset,limit:60});
  gTotal=d.total;
  if(reset){
    $('#gGrid').innerHTML='';
    $('#provChips').innerHTML=[{v:'all',n:d.total,label:'All'},...d.facets.provider.map(f=>({v:f.v,n:f.n,label:LB[f.v]||f.v}))]
      .map(f=>`<button class="chip ${g.provider===f.v?'active':''}" data-prov="${f.v}">${esc(f.label)} <span class="n">${f.n}</span></button>`).join('');
    $('#kindChips').innerHTML=[{v:'all',label:'All'},...d.facets.kind.map(f=>({v:f.v,n:f.n,label:f.v}))]
      .map(f=>`<button class="chip ${g.kind===f.v?'active':''}" data-kind="${f.v}">${esc(f.label)}${f.n?` <span class="n">${f.n}</span>`:''}</button>`).join('');
    $('#catChips').innerHTML=[{v:'All'},...d.facets.category].map(f=>`<button class="chip ${g.category===(f.v||f.category)?'active':''}" data-cat="${esc(f.v||f.category)}">${esc(f.v||f.category)}${f.n?` <span class="n">${f.n}</span>`:''}</button>`).join('');
    $$('#provChips .chip').forEach(c=>c.onclick=()=>{g.provider=c.dataset.prov;loadGallery(true);});
    $$('#kindChips .chip').forEach(c=>c.onclick=()=>{g.kind=c.dataset.kind;loadGallery(true);});
    $$('#catChips .chip').forEach(c=>c.onclick=()=>{g.category=c.dataset.cat;loadGallery(true);});
  }
  galleryItems.push(...d.items);
  appendTiles($('#gGrid'),d.items,galleryItems);
  gOffset+=d.items.length; if(gOffset>=gTotal||!d.items.length) gDone=true; gLoading=false;
  $('#gMore').innerHTML = gOffset>=gTotal ? (gTotal? `<span class="dim mono" style="font-size:11px">${gTotal} items</span>`:'') : '<span class="spin"></span>';
  if(reset&&!d.items.length) $('#gGrid').innerHTML=`<div class="empty" style="columns:1"><div class="big">No media here</div>Try another filter, or build your index in Import & Settings.</div>`;
}
function renderTiles(host,items){ host.className='grid'; host.innerHTML=''; if(!items.length){host.innerHTML='<div class="empty" style="columns:1">No media.</div>';return;} appendTiles(host,items,items); }
function appendTiles(host,items,sourceArr){
  sourceArr = sourceArr || items;
  const frag=document.createDocumentFragment();
  items.forEach(m=>{
    const el=document.createElement('div'); el.className='tile'+(m.kind==='audio'?' audio-tile card':'');
    if(m.kind==='audio'){
      el.innerHTML=`<h5>${esc(m.title||'Audio')}</h5><audio controls preload="none" src="?media=${encodeURIComponent(m.id)}"></audio>
        <div class="dim mono" style="font-size:10px;margin-top:8px">${LB[m.provider]||m.provider} · ${esc(m.category)}</div>`;
    } else if(m.kind==='video'){
      el.innerHTML=`<video src="?media=${encodeURIComponent(m.id)}#t=0.4" preload="metadata" muted></video>
        <div class="kindtag">${svg('play','style="width:12px;height:12px"')}</div>
        <span class="badge" style="background:${ACC[m.provider]}66">${LB[m.provider]||m.provider}</span>
        <div class="ov"><p>${esc(m.prompt||m.title||'')}</p></div>`;
      el.onclick=()=>openLightbox(sourceArr, sourceArr.indexOf(m));
    } else {
      const ratio=m.width&&m.height? (m.height/m.width):1;
      el.innerHTML=`<img loading="lazy" src="?thumb=${encodeURIComponent(m.id)}&w=440" style="min-height:${Math.min(340,Math.max(90,220*ratio))}px;background:#12151d">
        <span class="badge" style="background:${ACC[m.provider]}66">${LB[m.provider]||m.provider}</span>
        <div class="ov"><p>${esc(m.prompt||m.title||'')}</p></div>`;
      el.onclick=()=>openLightbox(sourceArr, sourceArr.indexOf(m));
    }
    frag.appendChild(el);
  });
  host.appendChild(frag);
}

// ---- lightbox ----
let lbItems=[], lbIndex=0;
function ensureLightbox(){
  if($('.lightbox'))return;
  const lb=document.createElement('div'); lb.className='lightbox';
  lb.innerHTML=`<button class="lb-close">${svg('x')}</button>
    <button class="lb-nav prev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
    <button class="lb-nav next"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/></svg></button>
    <div class="lb-stage" id="lbStage"></div>`;
  document.body.appendChild(lb);
  lb.querySelector('.lb-close').onclick=()=>lb.classList.remove('on');
  lb.querySelector('.prev').onclick=()=>lbShow(lbIndex-1);
  lb.querySelector('.next').onclick=()=>lbShow(lbIndex+1);
  lb.onclick=e=>{ if(e.target===lb) lb.classList.remove('on'); };
  addEventListener('keydown',e=>{ if(!lb.classList.contains('on'))return; if(e.key==='Escape')lb.classList.remove('on'); if(e.key==='ArrowLeft')lbShow(lbIndex-1); if(e.key==='ArrowRight')lbShow(lbIndex+1); });
}
function openLightbox(items,i){ ensureLightbox(); lbItems=items; lbShow(i); $('.lightbox').classList.add('on'); }
function lbShow(i){
  if(i<0||i>=lbItems.length)return; lbIndex=i; const m=lbItems[i];
  const stage=$('#lbStage');
  const media = m.kind==='video'
    ? `<video src="?media=${encodeURIComponent(m.id)}" controls autoplay style="max-width:88vw;max-height:70vh"></video>`
    : `<img src="?media=${encodeURIComponent(m.id)}">`;
  stage.innerHTML=`${media}<div class="lb-meta"><div class="mono" style="font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)">${LB[m.provider]||m.provider} · ${esc(m.category||'')}</div>
    ${m.prompt?`<div style="margin-top:8px;color:var(--txt)">${esc(m.prompt)}</div>`:(m.title?`<div style="margin-top:8px;color:var(--txt)">${esc(m.title)}</div>`:'')}
    <div style="margin-top:10px"><a class="btn ghost" href="?media=${encodeURIComponent(m.id)}&dl=1" download>${svg('dl','style="width:14px;height:14px"')} Download original</a></div></div>`;
}

// ================= ASSISTANT =================
let pendingPersona=null;
const chatState={ model:'claude-opus-4-8', mode:'chat', messages:[], ctxConv:new Set(), ctxPersona:new Set(), streaming:false };
const MODES={
  chat:{label:'Chat',sys:'You are SIGNAL, a sharp assistant inside the user\'s personal AI data vault. Be concrete and useful.'},
  persona:{label:'Build a Persona',sys:'You are a persona architect. From the user\'s data and requests, craft vivid, usable AI personas: name, voice, values, do/don\'t, and a ready-to-paste system prompt. Ask only what you must.'},
  content:{label:'Content Creation',sys:'You are a content strategist and writer. Turn the user\'s ideas and past conversations into polished posts, threads, scripts, or copy. Offer 2-3 options with distinct angles.'},
  dataset:{label:'Custom Dataset',sys:'You help the user distill their exported AI history into structured, reusable datasets — instruction/response pairs, topic taxonomies, JSON records. Propose a schema, then produce clean examples.'},
  analyze:{label:'Analyze My Data',sys:'You are an analyst of the user\'s AI usage. Surface patterns, themes, recurring interests, and suggestions. Reference the provided context specifically.'},
};
async function renderAssistant(v){
  crumb('Assistant','Claude Opus 4.8 · Sonnet 5');
  v.innerHTML=`<div class="assistant-page">
    <div class="chat-wrap">
      <div class="chat-head">
        <select class="select" id="modelSel">
          <option value="claude-opus-4-8">Claude Opus 4.8</option>
          <option value="claude-sonnet-5">Claude Sonnet 5</option>
        </select>
        <div class="modes" id="modeChips">${Object.entries(MODES).map(([k,m])=>`<button class="chip ${k==='chat'?'active':''}" data-mode="${k}">${m.label}</button>`).join('')}</div>
        <button class="btn ghost" id="clearChat" style="margin-left:auto;font-size:12px">Clear</button>
      </div>
      <div class="chat-scroll" id="chatScroll"><div class="empty"><div class="big">Ask anything about your vault</div>Pick a mode, attach context from the right, and go. Uses your Anthropic API key.</div></div>
      <div class="chat-input">
        <textarea id="chatText" placeholder="Message SIGNAL…  (⏎ to send, ⇧⏎ for newline)"></textarea>
        <button class="btn primary" id="sendBtn">Send</button>
      </div>
    </div>
    <div class="side-panel">
      <h4>Attach context</h4>
      <div class="dim" style="font-size:12px;margin-bottom:12px">Selected items are summarized and given to the model.</div>
      <div id="ctxPersonas"></div>
      <h4 style="margin-top:18px">Recent conversations</h4>
      <div id="ctxConvs"></div>
    </div>
  </div>`;
  $('#modelSel').value=chatState.model;
  $('#modelSel').onchange=e=>chatState.model=e.target.value;
  $$('#modeChips .chip').forEach(c=>c.onclick=()=>{ chatState.mode=c.dataset.mode; $$('#modeChips .chip').forEach(x=>x.classList.toggle('active',x===c)); });
  $('#clearChat').onclick=()=>{ chatState.messages=[]; $('#chatScroll').innerHTML='<div class="empty"><div class="big">Cleared</div>Start fresh.</div>'; };
  const ta=$('#chatText');
  ta.addEventListener('input',()=>{ta.style.height='auto';ta.style.height=Math.min(160,ta.scrollHeight)+'px';});
  ta.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendChat();} });
  $('#sendBtn').onclick=sendChat;

  const ctx=await api('context');
  $('#ctxPersonas').innerHTML=ctx.personas.length? ctx.personas.map(p=>`<div class="ctx-item" data-persona="${esc(p.id)}"><span class="pd" style="background:${ACC[p.provider]}"></span>${esc(p.name)}</div>`).join('') : '<div class="dim" style="font-size:12px">No personas indexed.</div>';
  $('#ctxConvs').innerHTML=ctx.conversations.map(c=>`<div class="ctx-item" data-conv="${esc(c.id)}"><span class="pd" style="background:${ACC[c.provider]}"></span>${esc(c.title)}</div>`).join('');
  $$('#ctxPersonas .ctx-item').forEach(el=>el.onclick=()=>{ const id=el.dataset.persona; toggleSet(chatState.ctxPersona,id); el.classList.toggle('on'); });
  $$('#ctxConvs .ctx-item').forEach(el=>el.onclick=()=>{ const id=el.dataset.conv; toggleSet(chatState.ctxConv,id); el.classList.toggle('on'); });
  if(pendingPersona){ chatState.ctxPersona.add(pendingPersona); const el=$(`#ctxPersonas [data-persona="${cssq(pendingPersona)}"]`); if(el)el.classList.add('on'); chatState.mode='persona'; $$('#modeChips .chip').forEach(x=>x.classList.toggle('active',x.dataset.mode==='persona')); pendingPersona=null; }
  if(chatState.messages.length) repaintChat();
}
function cssq(s){ return s.replace(/"/g,'\\"'); }
function toggleSet(set,v){ set.has(v)?set.delete(v):set.add(v); }
function repaintChat(){
  const sc=$('#chatScroll'); sc.innerHTML='';
  chatState.messages.forEach(m=>{
    const b=document.createElement('div'); b.className='bubble '+m.role;
    b.innerHTML=m.role==='assistant'?mini_md(m.content):esc(m.content);
    sc.appendChild(b);
  });
  sc.scrollTop=sc.scrollHeight;
}
async function sendChat(){
  const ta=$('#chatText'); const text=ta.value.trim(); if(!text||chatState.streaming)return;
  ta.value=''; ta.style.height='auto';
  chatState.messages.push({role:'user',content:text});
  repaintChat();
  const sc=$('#chatScroll');
  const bubble=document.createElement('div'); bubble.className='bubble assistant'; bubble.innerHTML='<span class="spin"></span>'; sc.appendChild(bubble); sc.scrollTop=sc.scrollHeight;
  chatState.streaming=true; $('#sendBtn').disabled=true;

  // Build system prompt + context
  let sys=MODES[chatState.mode].sys;
  if(chatState.ctxConv.size||chatState.ctxPersona.size){
    const ct=await api('context_text',{conv:[...chatState.ctxConv].join(','),persona:[...chatState.ctxPersona].join(',')});
    if(ct.text) sys+='\n\n# Context from the user\'s vault\n'+ct.text;
  }
  const payloadMsgs=chatState.messages.map(m=>({role:m.role,content:m.content}));
  let acc='';
  try{
    const res=await fetch('?api=chat',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({model:chatState.model,system:sys,messages:payloadMsgs})});
    const reader=res.body.getReader(); const dec=new TextDecoder(); let buf='';
    while(true){
      const {value,done}=await reader.read(); if(done)break;
      buf+=dec.decode(value,{stream:true});
      let idx;
      while((idx=buf.indexOf('\n\n'))>=0){
        const chunk=buf.slice(0,idx); buf=buf.slice(idx+2);
        let ev='message', data='';
        chunk.split('\n').forEach(line=>{ if(line.startsWith('event:'))ev=line.slice(6).trim(); else if(line.startsWith('data:'))data+=line.slice(5).trim(); });
        if(!data)continue;
        if(ev==='apperror'){ try{acc+='\n\n⚠ '+JSON.parse(data).message;}catch(_){acc+='\n\n⚠ error';} bubble.innerHTML=mini_md(acc); continue; }
        if(ev==='appdone')continue;
        try{
          const j=JSON.parse(data);
          if(j.type==='content_block_delta'&&j.delta&&j.delta.type==='text_delta'){ acc+=j.delta.text; bubble.innerHTML=mini_md(acc); sc.scrollTop=sc.scrollHeight; }
          if(j.type==='error'){ acc+='\n\n⚠ '+(j.error?.message||'error'); bubble.innerHTML=mini_md(acc); }
        }catch(_){}
      }
    }
  }catch(e){ acc+='\n\n⚠ '+e.message; bubble.innerHTML=mini_md(acc); }
  if(!acc){ acc='(no response)'; bubble.textContent=acc; }
  chatState.messages.push({role:'assistant',content:acc});
  chatState.streaming=false; $('#sendBtn').disabled=false;
  // Save button
  const bar=document.createElement('div'); bar.style.cssText='display:flex;gap:8px;margin:-8px 0 16px';
  bar.innerHTML=`<button class="btn ghost" style="font-size:11px" id="saveOut">${svg('save','style="width:13px;height:13px"')} Save output</button>`;
  sc.appendChild(bar);
  $('#saveOut').onclick=async()=>{ await post('saved_create',{kind:chatState.mode,title:text.slice(0,60),body:acc,meta:{model:chatState.model}}); toast('Saved to your library.'); };
}

// ---- server-side folder picker (returns an absolute path, or null) ----
async function pickFolder(start){
  return new Promise(resolve=>{
    const ov=document.createElement('div'); ov.className='fp-ov';
    ov.innerHTML=`<div class="fp">
      <div class="fp-head"><b>Select the unzipped export folder</b><button class="fp-x">Close</button></div>
      <div class="fp-crumb" id="fpPath">…</div>
      <div class="fp-list" id="fpList"><div class="dim" style="padding:20px"><span class="spin"></span></div></div>
      <div class="fp-foot"><span class="h">Open the folder that holds the export's files, then “Use this folder”.</span>
        <div style="display:flex;gap:8px"><button class="btn ghost fp-cancel">Cancel</button><button class="btn primary fp-use">Use this folder</button></div></div>
    </div>`;
    document.body.appendChild(ov);
    let cur=start||'';
    const close=v=>{ ov.remove(); document.removeEventListener('keydown',onKey); resolve(v); };
    const onKey=e=>{ if(e.key==='Escape') close(null); };
    document.addEventListener('keydown',onKey);
    ov.querySelector('.fp-x').onclick=()=>close(null);
    ov.querySelector('.fp-cancel').onclick=()=>close(null);
    ov.querySelector('.fp-use').onclick=()=>close(cur);
    ov.addEventListener('click',e=>{ if(e.target===ov) close(null); });
    async function load(p){
      const d=await api('browse', p?{path:p}:{});
      cur=d.path; $('#fpPath').textContent=d.path;
      let html='';
      if(d.parent) html+=`<div class="fp-item up" data-path="${esc(d.parent)}"><span class="ic">↰</span>Parent folder</div>`;
      html+=d.dirs.map(x=>`<div class="fp-item" data-path="${esc(x.path)}"><span class="ic">📁</span>${esc(x.name)}</div>`).join('');
      if(!d.dirs.length) html+='<div class="dim" style="padding:14px 12px">No sub-folders here — this folder itself may be the one to use.</div>';
      $('#fpList').innerHTML=html;
      $$('#fpList .fp-item').forEach(it=>it.onclick=()=>load(it.dataset.path));
    }
    load(cur);
  });
}

// ================= IMPORT =================
async function renderImport(v){
  crumb('Import & Settings','connect folders · build index · assistant key');
  let s=await api('settings');
  v.innerHTML=`
   <div class="notice"><b>Getting your data in:</b> export from each AI (they download as <span class="mono">.zip</span>) → <b>unzip</b> each one → keep each in its own folder. Then either drop those folders next to <span class="mono">index.php</span> (auto-detected), or click <b>Browse</b> below to point SIGNAL straight at an unzipped folder. Build the index and you're set. Everything stays on this machine.</div>
   <div class="section-h"><h2>Sources</h2></div>
   <div class="imp-grid" id="impRows"></div>
   <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
     <button class="btn primary" id="indexAll">Build / rebuild all indexes</button>
     <a class="btn ghost" href="?export=index" download>${svg('dl','style="width:15px;height:15px"')} Export index (JSON)</a>
   </div>
   <div class="section-h"><h2>Assistant</h2></div>
   <div class="card" style="padding:20px;max-width:640px">
     <div class="field"><label>Anthropic API key ${s.key_source==='env'?'<span class="dim mono">(using ANTHROPIC_API_KEY from env)</span>':''}</label>
       <input id="apiKey" type="password" placeholder="${s.has_key?'•••••••••• (saved) — leave blank to keep':'sk-ant-…'}"></div>
     <div class="dim" style="font-size:12px;margin-bottom:12px">Used only for the built-in assistant (Opus 4.8 / Sonnet 5). Stored locally in <span class="mono">.aivault/</span>.</div>
     <button class="btn" id="saveKey">Save key</button>
   </div>
   <div class="section-h"><h2>Saved from the assistant</h2></div>
   <div id="savedList" class="imp-grid"></div>`;

  function rows(){
    $('#impRows').innerHTML=Object.entries(s.providers).map(([pid,p])=>{
      const st=p.stats||{};
      const parts=[]; if(st.conversations!=null)parts.push(st.conversations+' chats'); if(st.media!=null)parts.push(st.media+' media'); if(st.personas!=null)parts.push(st.personas+' personas');
      return `<div class="imp-row">
        <div class="mark" style="background:${p.accent}">${LB[pid][0]}</div>
        <div>
          <div style="font-family:var(--disp);font-weight:600">${esc(p.label)} <span class="dim" style="font-weight:400;font-size:12px">${esc(p.company)}</span></div>
          <input class="pathInput" data-p="${pid}" value="${esc(p.path)}" spellcheck="false">
          <div class="st">${p.resolved?('✓ found · '+ (parts.join(' · ')||'not indexed')):'✗ folder not found'}</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <button class="btn ghost browse" data-p="${pid}">${svg('folder','style="width:14px;height:14px"')} Browse…</button>
          <button class="btn idx" data-p="${pid}" ${p.resolved?'':'disabled'}>Build index</button>
        </div>
      </div>`;
    }).join('');
    $$('#impRows .idx').forEach(b=>b.onclick=()=>doIndex(b.dataset.p,b));
    $$('#impRows .pathInput').forEach(i=>i.onchange=async()=>{ await setPath(i.dataset.p, i.value); });
    $$('#impRows .browse').forEach(b=>b.onclick=async()=>{
      const inp=$(`.pathInput[data-p="${b.dataset.p}"]`);
      const chosen=await pickFolder(inp && inp.value ? inp.value : s.app_root);
      if(chosen){ await setPath(b.dataset.p, chosen); toast(`${LB[b.dataset.p]} path set — now Build index.`); }
    });
  }
  async function setPath(pid,path){
    await post('settings_save',{['path_'+pid]:path});
    s=await api('settings');   // refresh so the ✓ found / ✗ status is accurate
    rows();
  }
  rows();

  async function doIndex(pid,btn){
    // save path first
    const inp=$(`.pathInput[data-p="${pid}"]`); if(inp) await post('settings_save',{['path_'+pid]:inp.value});
    btn.disabled=true; const old=btn.innerHTML; btn.innerHTML='<span class="spin"></span> Indexing…';
    const r=await post('index',{provider:pid});
    btn.disabled=false; btn.innerHTML=old;
    if(r.error){ toast('Error: '+r.error); return; }
    s.providers[pid].stats=r; s.providers[pid].resolved=r.dir; rows();
    toast(`${LB[pid]}: ${r.conversations||0} chats · ${r.media||0} media · ${r.seconds}s`);
  }
  $('#indexAll').onclick=async()=>{
    const btn=$('#indexAll'); btn.disabled=true;
    for(const pid of Object.keys(s.providers)){
      if(!s.providers[pid].resolved)continue;
      btn.innerHTML=`<span class="spin"></span> Indexing ${LB[pid]}…`;
      const r=await post('index',{provider:pid}); if(!r.error){s.providers[pid].stats=r;}
    }
    btn.disabled=false; btn.innerHTML='Build / rebuild all indexes'; rows();
    toast('All sources indexed.');
  };
  $('#saveKey').onclick=async()=>{ const k=$('#apiKey').value.trim(); if(!k){toast('Enter a key.');return;} await post('settings_save',{anthropic_api_key:k}); $('#apiKey').value=''; toast('API key saved.'); };

  const saved=await api('saved');
  $('#savedList').innerHTML=saved.items.length? saved.items.map(x=>`
    <div class="imp-row" style="grid-template-columns:1fr auto">
      <div><div style="font-family:var(--disp);font-weight:600">${esc(x.title)} <span class="dim mono" style="font-size:10px">${esc(x.kind)}</span></div>
      <div class="dim" style="font-size:12.5px;max-height:80px;overflow:auto;white-space:pre-wrap;margin-top:6px">${esc((x.body||'').slice(0,400))}</div></div>
      <button class="btn ghost delSaved" data-id="${x.id}" style="font-size:11px">Delete</button>
    </div>`).join('') : '<div class="dim" style="font-size:13px">Nothing saved yet. Generate something in the Assistant and hit “Save output”.</div>';
  $$('.delSaved').forEach(b=>b.onclick=async()=>{ await post('saved_delete',{id:+b.dataset.id}); renderImport(v); });
}

// ---- boot / routing ----
function routeFromHash(){
  const h=location.hash.replace(/^#\//,'');
  let view='overview', provider=null;
  if(h.startsWith('p/')){ view='provider'; provider=h.slice(2); }
  else if(['overview','gallery','assistant','import'].includes(h)) view=h;
  // Back/forward on a hash URL fires BOTH popstate and hashchange; skip the duplicate.
  if(window._rk === view+':'+(view==='provider'?provider:'')) return;
  go(view,provider,false);
}
shell();
routeFromHash();
addEventListener('popstate',routeFromHash);
addEventListener('hashchange',routeFromHash);
</script>
HTML;
}
