<?php
if (!defined('EZYPUBLIC')) { http_response_code(403); echo 'Forbidden'; exit; }

// Copied utils.php into app/ and kept original behavior — functions are the same
session_start();

function env_path() {
    $entry = null;

    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $entry = $_SERVER['SCRIPT_FILENAME'];
    } elseif (php_sapi_name() === 'cli' && !empty($_SERVER['argv'][0])) {
        $entry = $_SERVER['argv'][0];
    }

    if ($entry) {
        $entryDir = dirname(@realpath($entry) ?: $entry);
        if ($entryDir) return rtrim($entryDir, '/\\') . DIRECTORY_SEPARATOR . '.env';
    }

    return __DIR__ . DIRECTORY_SEPARATOR . '.env';
}

function normalize_path($path) {
    if (!is_string($path) || $path === '') return $path;
    $path = str_replace('\\', '/', $path);
    $prefix = '';
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $prefix = substr($path, 0, 3);
        $path = substr($path, 3);
    } elseif (preg_match('#^[A-Za-z]:$#', $path)) {
        $prefix = rtrim($path, '/') . '/';
        $path = '';
    } elseif (strpos($path, '/') === 0) {
        $prefix = '/';
        $path = ltrim($path, '/');
    }
    $parts = $path === '' ? [] : explode('/', $path);
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if (!empty($stack) && end($stack) !== '..') {
                array_pop($stack);
            } else {
                if ($prefix === '') {
                    $stack[] = '..';
                }
            }
        } else {
            $stack[] = $part;
        }
    }
    $joined = implode(DIRECTORY_SEPARATOR, $stack);
    if ($prefix !== '') {
        if (substr($prefix, -1) === '/' || substr($prefix, -1) === '\\') {
            return rtrim($prefix, '\\/') . DIRECTORY_SEPARATOR . $joined;
        }
        return $prefix . ($joined !== '' ? DIRECTORY_SEPARATOR . $joined : '');
    }
    return $joined === '' ? '.' : $joined;
}

function resolve_absolute_path($path, $base = null) {
    if (!is_string($path) || $path === '') return false;
    $base = $base === null ? __DIR__ : $base;
    if (preg_match('#^([A-Za-z]:)?[\\/]#', $path)) {
        $real = @realpath($path);
        if ($real) return $real;
        return rtrim(normalize_path($path), '/\\');
    }
    $joined = rtrim($base, '/\\') . '/' . $path;
    $real = @realpath($joined);
    if ($real) return $real;
    return rtrim(normalize_path($joined), '/\\');
}

function load_env($path = null) {
    $path = $path ?: env_path();
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}

function save_env($arr, $path = null) {
    $path = $path ?: env_path();
    $out = '';
    foreach ($arr as $k => $v) {
        $out .= "$k=$v\n";
    }
    file_put_contents($path, $out, LOCK_EX);
}

function log_message($msg) {
    $env = load_env();
    $logFile = isset($env['LOG_FILE']) && $env['LOG_FILE'] ? $env['LOG_FILE'] : __DIR__ . '/logs/app.log';
    if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0777, true);
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function is_authenticated() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function require_auth() {
    if (!is_authenticated()) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

function get_repo_local_path() {
    $env = load_env();
    $path = isset($env['REPO_PATH']) && $env['REPO_PATH']
        ? $env['REPO_PATH']
        : (__DIR__ . '/repos/myrepo');
    return resolve_absolute_path($path, __DIR__);
}

function is_exec_available() {
    static $cached_exec = null;
    if ($cached_exec !== null) return $cached_exec;
    if (!function_exists('exec')) {
        $cached_exec = false;
        log_message('exec() is not available in this PHP environment (function does not exist)');
        return $cached_exec;
    }
    $disabled = ini_get('disable_functions');
    if ($disabled) {
        $parts = array_map('trim', array_filter(explode(',', $disabled)));
        $lower = array_map('strtolower', $parts);
        if (in_array('exec', $lower, true)) {
            $cached_exec = false;
            log_message('exec() is disabled in php.ini (disable_functions)');
            return $cached_exec;
        }
    }
    $cached_exec = true;
    return $cached_exec;
}

function safe_exec($cmd, &$output = null, &$return_var = null) {
    if (!is_exec_available()) {
        $output = [];
        $return_var = -1;
        log_message('exec() not available — attempted command: ' . $cmd);
        return false;
    }
    exec($cmd, $output, $return_var);
    return $return_var === 0;
}

function git_is_available() {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!is_exec_available()) {
        $cached = false;
        log_message('Cannot check git availability because exec() is not available');
        return $cached;
    }
    safe_exec('git --version 2>&1', $o, $r);
    $cached = ($r === 0);
    if (!$cached) {
        log_message("Git binary not found on server (git --version failed)");
    }
    return $cached;
}

function recursive_rmdir($dir) {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            recursive_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function get_protected_paths() {
    $env = load_env();
    $raw = isset($env['PROTECT_PATHS']) ? $env['PROTECT_PATHS'] : '';
    if (!$raw) return [];
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    $paths = [];
    foreach ($parts as $p) {
        $real = resolve_absolute_path($p, __DIR__);
        if ($real) $paths[] = rtrim($real, '/\\');
    }
    return array_values(array_unique($paths));
}

function is_path_protected($path) {
    $real = resolve_absolute_path($path);
    if (!$real) return false;
    $protected = get_protected_paths();
    foreach ($protected as $prot) {
        if ($real === $prot) return true;
        if (strpos($real, $prot . DIRECTORY_SEPARATOR) === 0) return true;
    }
    return false;
}

function safe_rmdir($dir) {
    $real = resolve_absolute_path($dir, __DIR__);
    $root = resolve_absolute_path(__DIR__);
    if (!$real) return false;
    if ($real === '/' || preg_match('#^[A-Z]:\\$#i', $real)) {
        log_message('Refused to remove root path: ' . $real);
        return false;
    }
    if ($root && strpos($real, $root) !== 0) {
        log_message('Refused to remove directory outside project: ' . $real);
        return false;
    }
    if (is_path_protected($real)) {
        log_message('Refused to remove protected path: ' . $real);
        return false;
    }
    recursive_rmdir($real);
    return !is_dir($real);
}

function parse_git_http_parts($git_url) {
    $u = trim($git_url);
    if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $u, $m)) {
        $host = $m[1];
        $path = $m[2];
    } else {
        $parts = parse_url($u);
        if (!$parts || !isset($parts['host']) || !isset($parts['path'])) return null;
        $host = $parts['host'];
        $path = ltrim($parts['path'], '/');
    }
    $path = preg_replace('#\.git$#', '', $path);
    $parts = explode('/', $path);
    if (count($parts) < 2) return null;
    return ['host' => $host, 'owner' => $parts[0], 'repo' => $parts[1]];
}

function read_download_info($repoPath) {
    $f = rtrim($repoPath, '/\\') . '/.download_info';
    if (!file_exists($f)) return null;
    $j = @json_decode(file_get_contents($f), true);
    return $j ?: null;
}

function write_download_info($repoPath, $info) {
    if (!is_dir($repoPath)) @mkdir($repoPath, 0777, true);
    $f = rtrim($repoPath, '/\\') . '/.download_info';
    file_put_contents($f, json_encode($info));
}

function download_file_http($url, $savePath, $git_user = '', $git_pass = '', $writer = null) {
    if (function_exists('curl_version')) {
        log_message('Using curl to download: ' . $url);
        $ch = curl_init($url);
        $fp = fopen($savePath, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_USERAGENT, 'EzyGitSync');
        if ($git_user !== '' || $git_pass !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $git_user . ':' . $git_pass);
        }
        $ok = curl_exec($ch);
        if (!$ok) {
            if ($writer) $writer('Download error: ' . curl_error($ch));
            log_message('Curl download error for ' . $url . ': ' . curl_error($ch));
        }
        curl_close($ch);
        fclose($fp);
        return $ok;
    } else {
        log_message('Using file_get_contents to download: ' . $url);
        $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: EzyGitSync\r\n", 'timeout' => 300]];
        if ($git_user !== '' || $git_pass !== '') {
            $auth = base64_encode($git_user . ':' . $git_pass);
            $opts['http']['header'] .= "Authorization: Basic $auth\r\n";
        }
        $context = stream_context_create($opts);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            if ($writer) $writer('Download via file_get_contents failed for ' . $url);
            return false;
        }
        file_put_contents($savePath, $data);
        return true;
    }
}

function try_download_archive_from_git_url($git_url, $repoPath, $git_user = '', $git_pass = '', $writer = null) {
    $parts = parse_git_http_parts($git_url);
    if (!$parts) {
        if ($writer) $writer('Could not parse repository URL for download fallback');
        log_message('Could not parse repository URL for download fallback: ' . $git_url);
        return false;
    }
    $host = $parts['host'];
    $owner = $parts['owner'];
    $repo = $parts['repo'];
    $branches = ['main', 'master', 'develop'];
    $candidates = [];
    if (strpos($host, 'github.com') !== false) {
        foreach ($branches as $b) {
            $candidates[] = "https://github.com/" . urlencode($owner) . "/" . urlencode($repo) . "/archive/refs/heads/" . rawurlencode($b) . ".zip";
        }
    }
    if (strpos($host, 'gitlab.com') !== false) {
        foreach ($branches as $b) {
            $candidates[] = "https://gitlab.com/" . urlencode($owner) . "/" . urlencode($repo) . "/-/archive/" . rawurlencode($b) . "/" . urlencode($repo) . "-" . rawurlencode($b) . ".zip";
        }
    }
    foreach ($branches as $b) {
        $candidates[] = "https://" . $host . "/" . urlencode($owner) . "/" . urlencode($repo) . "/archive/" . rawurlencode($b) . ".zip";
        $candidates[] = "https://" . $host . "/" . urlencode($owner) . "/" . urlencode($repo) . "/-/archive/" . rawurlencode($b) . "/" . urlencode($repo) . "-" . rawurlencode($b) . ".zip";
    }
    foreach ($candidates as $candidate) {
        if ($writer) $writer('Trying archive URL: ' . $candidate);
        $tmp = tempnam(sys_get_temp_dir(), 'ezgit_') . '.zip';
        $ok = download_file_http($candidate, $tmp, $git_user, $git_pass, $writer);
        if (!$ok || !file_exists($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            if ($writer) $writer('No archive at: ' . $candidate);
            continue;
        }
        if (!class_exists('ZipArchive')) {
            if ($writer) $writer('ZipArchive is not available in PHP; cannot extract archive');
            @unlink($tmp);
            log_message('ZipArchive missing; cannot extract downloaded archive');
            return false;
        }
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezgit_unpack_' . uniqid();
        @mkdir($tmpDir);
        $za = new ZipArchive();
        if ($za->open($tmp) !== true) {
            if ($writer) $writer('Failed to open downloaded zip file');
            @unlink($tmp);
            recursive_rmdir($tmpDir);
            continue;
        }
        $za->extractTo($tmpDir);
        $za->close();
        $children = array_values(array_diff(scandir($tmpDir), ['.', '..']));
        $sourceDir = $tmpDir;
        if (count($children) === 1 && is_dir($tmpDir . DIRECTORY_SEPARATOR . $children[0])) {
            $sourceDir = $tmpDir . DIRECTORY_SEPARATOR . $children[0];
        }
        if (is_dir($repoPath)) {
            $items = array_diff(scandir($repoPath), ['.', '..']);
            if (count($items) > 0) {
                if ($writer) $writer('Removing existing files in ' . $repoPath);
                $files = array_diff(scandir($repoPath), ['.', '..']);
                foreach ($files as $f) {
                    $path = $repoPath . DIRECTORY_SEPARATOR . $f;
                    if (is_dir($path)) recursive_rmdir($path); else @unlink($path);
                }
            }
        } else {
            @mkdir($repoPath, 0777, true);
        }
        $items = array_diff(scandir($sourceDir), ['.', '..']);
        foreach ($items as $item) {
            $src = $sourceDir . DIRECTORY_SEPARATOR . $item;
            $dst = $repoPath . DIRECTORY_SEPARATOR . $item;
            if (!@rename($src, $dst)) {
                if (is_dir($src)) {
                    mkdir($dst, 0777, true);
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($it as $s) {
                        $ds = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
                        if ($s->isDir()) { @mkdir($ds, 0777, true); } else { copy($s->getRealPath(), $ds); }
                    }
                } else {
                    copy($src, $dst);
                }
            }
        }
        @unlink($tmp);
        recursive_rmdir($tmpDir);
        $info = ['source' => $candidate, 'fetched_at' => date('c')];
        write_download_info($repoPath, $info);
        if ($writer) $writer('Downloaded and extracted archive successfully');
        log_message('Downloaded archive from ' . $candidate . ' into ' . $repoPath);
        return true;
    }
    if ($writer) $writer('All archive download attempts failed');
    log_message('All archive download attempts failed for ' . $git_url);
    return false;
}

function git_latest_commits($repoPath, $count = 5) {
    if (git_is_available()) {
        if (!is_dir($repoPath . '/.git')) return ['Repository not cloned yet'];
        $cmd = "git -C " . escapeshellarg($repoPath) . " log -n " . intval($count) . " --pretty=format:'%h %ad %s' --date=short";
        safe_exec($cmd, $out, $ret);
        if ($ret !== 0) return ['Could not read git log'];
        return $out;
    } else {
        $info = read_download_info($repoPath);
        if ($info) {
            return ["Repository downloaded from: " . ($info['source'] ?? 'unknown') . " on " . ($info['fetched_at'] ?? 'unknown')];
        }
        return ['Git is not available on the server. Please install Git or rely on the download fallback.'];
    }
}