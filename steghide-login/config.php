<?php
// Error reporting untuk dev (matikan di produksi)
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $host = '127.0.0.1';
    $db   = 'steghide_app'; // nama database
    $user = 'root';
    $pass = ''; // sesuaikan kalau pakai password
    $dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4"; // ini yang benar
    $opt  = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);
  }
  return $pdo;
}


// CSRF helpers
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}
function csrf_validate(string $token): bool {
  return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// Rate limit sederhana (per session)
function rate_limited(): bool {
  $limit = 5;        // maksimal 5 percobaan gagal
  $window = 10;     // dalam 10 detik
  $now = time();

  $_SESSION['rl'] = $_SESSION['rl'] ?? ['count'=>0, 'start'=>$now];
  if (($now - $_SESSION['rl']['start']) > $window) {
    $_SESSION['rl'] = ['count'=>0, 'start'=>$now];
  }
  return $_SESSION['rl']['count'] >= $limit;
}
function rate_inc_fail(): void {
  $_SESSION['rl']['count'] = ($_SESSION['rl']['count'] ?? 0) + 1;
}
function rate_reset(): void {
  $_SESSION['rl'] = ['count'=>0, 'start'=>time()];
}

function base_path(): string {
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}
function url(string $path): string {
  return base_path() . '/' . ltrim($path, '/');
}
function redirect(string $path): never {
  header('Location: ' . url($path));
  exit;
}

