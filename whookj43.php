<?php
/**
 * GitHub webhook receiver: verifies HMAC signature and optionally runs git pull.
 *
 * Production (CyberPanel): https://hotel.abdalmenem.com/whookj43.php
 *
 * Setup:
 * 1. Copy webhook_secret.example.php → webhook_secret.local.php on the server (public_html).
 * 2. GitHub → Webhooks → Payload URL = URL above, same secret as in webhook_secret.local.php.
 * 3. repo_path must be the server path to public_html where .git exists; www-data/nobody must
 *    be able to run `git pull` there (permissions + deploy key or HTTPS credential).
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(200);
    echo "GitHub webhook endpoint. Use POST.\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed\n";
    exit;
}

$local = __DIR__ . '/webhook_secret.local.php';
if (!is_readable($local)) {
    http_response_code(500);
    echo "Missing webhook_secret.local.php — copy from webhook_secret.example.php\n";
    exit;
}

/** @var array<string, mixed> $config */
$config = require $local;
$secret = isset($config['secret']) ? (string) $config['secret'] : '';
if ($secret === '' || $secret === 'change-me-to-a-long-random-string') {
    http_response_code(500);
    echo "Configure secret in webhook_secret.local.php\n";
    exit;
}

$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    echo "Empty body\n";
    exit;
}

$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!is_string($sigHeader) || $sigHeader === '') {
    http_response_code(401);
    echo "Missing X-Hub-Signature-256\n";
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sigHeader)) {
    http_response_code(401);
    echo "Invalid signature\n";
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid JSON\n";
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    http_response_code(200);
    echo "Ignored event: " . (is_string($event) ? $event : '') . "\n";
    exit;
}

$deployBranch = isset($config['deploy_branch']) ? (string) $config['deploy_branch'] : 'main';
$ref = isset($data['ref']) && is_string($data['ref']) ? $data['ref'] : '';
$wantRef = 'refs/heads/' . $deployBranch;
if ($ref !== $wantRef) {
    http_response_code(200);
    echo "Ignored ref: {$ref}\n";
    exit;
}

$runPull = !array_key_exists('run_git_pull', $config) || (bool) $config['run_git_pull'];
if (!$runPull) {
    http_response_code(200);
    echo "OK (run_git_pull disabled)\n";
    exit;
}

$repoPath = isset($config['repo_path']) ? (string) $config['repo_path'] : __DIR__;
$realRepo = realpath($repoPath);
if ($realRepo === false || !is_dir($realRepo . DIRECTORY_SEPARATOR . '.git')) {
    http_response_code(500);
    echo "repo_path is not a git repository: {$repoPath}\n";
    exit;
}

$branch = escapeshellarg($deployBranch);
$cmd = 'git -C ' . escapeshellarg($realRepo) . ' pull origin ' . $branch . ' 2>&1';
exec($cmd, $outputLines, $exitCode);
http_response_code($exitCode === 0 ? 200 : 500);
echo implode("\n", $outputLines) . "\n";
