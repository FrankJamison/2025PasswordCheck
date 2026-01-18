<?php
// index.php - Password Checker PHP frontend for check_password.py
header('X-PW-Checker-App: 2025PasswordCheckingUtility');
header('X-PW-Checker-Version: 2026-01-05');

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function is_local_host(string $host): bool
{
    $host = strtolower($host);
    return $host === 'localhost' || str_ends_with($host, '.localhost');
}

// Configure Python interpreter:
// - Set env var PYTHON_BIN to an absolute path (recommended for production), e.g. /usr/bin/python3
// - Optionally set env var PW_CHECKER_DEBUG=1 to return stderr in JSON (also logs to error_log)
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
$debug = (getenv('PW_CHECKER_DEBUG') === '1') || $isLocalRequest;

$host = $_SERVER['HTTP_HOST'] ?? '';
$allowHttp = (getenv('PW_ALLOW_HTTP') === '1');
$isHttps = is_https_request();
$isLocalHost = is_local_host($host);

// Production safety: this tool accepts passwords; require HTTPS outside localhost.
// Keep it configurable so local dev and certain health checks can still work.
if (!$allowHttp && !$isHttps && !$isLocalRequest && !$isLocalHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $location = 'https://' . $host . $uri;
    header('Location: ' . $location, true, 308);
    exit;
}

// Security headers (safe defaults). Only advertise HSTS when actually on HTTPS.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// With JS moved out of the HTML, we can keep CSP reasonably strict.
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self';");

$python_bin = getenv('PYTHON_BIN');
$python_args = [];
if (!$python_bin) {
    if (PHP_OS_FAMILY === 'Windows') {
        // Prefer Python launcher if available (common on Windows)
        $python_bin = 'py';
        $python_args = ['-3'];
    } else {
        $python_bin = file_exists('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
    }
}

function run_python_cmd(array $cmdParts, &$output, &$exitCode): void
{
    $cmd = implode(' ', array_map('escapeshellarg', $cmdParts));
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);
}

function looks_like_missing_python(string $combinedOutput): bool
{
    $text = strtolower($combinedOutput);
    return strpos($text, 'no installed python found') !== false
        || strpos($text, 'is not recognized') !== false
        || strpos($text, 'cannot find the file') !== false
        || strpos($text, 'no such file or directory') !== false
        || strpos($text, 'not found') !== false;
}

function find_windows_python_bin(): ?string
{
    $candidates = [];

    foreach (['C:/Program Files/Python*/python.exe', 'C:/Program Files (x86)/Python*/python.exe'] as $pattern) {
        $matches = glob($pattern) ?: [];
        foreach ($matches as $path) {
            $candidates[] = $path;
        }
    }

    // Per-user installs (common when installed from python.org)
    $userMatches = glob('C:/Users/*/AppData/Local/Programs/Python/Python*/python.exe') ?: [];
    foreach ($userMatches as $path) {
        $candidates[] = $path;
    }

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $password = $_POST['password'] ?? '';
    if (empty($password)) {
        echo json_encode(['error' => 'Password is required.']);
        exit;
    }

    $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'check_password.py';

    $attempts = [];
    if (getenv('PYTHON_BIN')) {
        $attempts[] = array_merge([$python_bin], $python_args, [$script_path, $password]);
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $attempts[] = array_merge(['py', '-3'], [$script_path, $password]);
        $detected = find_windows_python_bin();
        if ($detected) {
            $attempts[] = [$detected, $script_path, $password];
        }
        $attempts[] = ['python', $script_path, $password];
    } else {
        $attempts[] = [$python_bin, $script_path, $password];
    }

    $output = [];
    $return_var = 0;
    $cmd = '';
    foreach ($attempts as $attempt) {
        run_python_cmd($attempt, $output, $return_var);
        $cmd = implode(' ', array_map('escapeshellarg', $attempt));
        if ($return_var === 0) {
            break;
        }

        if (!looks_like_missing_python(implode("\n", $output))) {
            break;
        }
    }
    if ($return_var !== 0) {
        $stderr = trim(implode("\n", $output));
        error_log('Password checker: Python invocation failed. cmd=' . $cmd . ' exit=' . $return_var . ' output=' . $stderr);
        http_response_code(500);
        echo json_encode($debug
            ? ['error' => 'Error running Python script.', 'details' => $stderr, 'exit_code' => $return_var, 'cmd' => $cmd]
            : ['error' => 'Error running Python script.']);
    } else {
        echo json_encode(['result' => implode("\n", $output)]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Password Checker</title>
    <link rel="stylesheet" href="css/styles.css" />
</head>

<body>
    <div class="container">
        <h1>Check if your password has been breached</h1>
        <p class="subtitle">Enter a password to see whether it appears in known breach datasets.</p>
        <form method="POST" id="pwForm">
            <input type="password" name="password" placeholder="Enter password" required>
            <button type="submit" id="checkBtn">Check</button>
        </form>
        <pre id="result" class="is-empty" aria-live="polite"></pre>
        <p class="fineprint">Tip: use a unique, long passphrase. This tool does not store your password.</p>
    </div>
    <script src="js/app.js" defer></script>
</body>

</html>