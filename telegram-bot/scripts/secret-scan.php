<?php
declare(strict_types=1);

/**
 * Lightweight current-tree secret guard. Also enable GitHub secret scanning
 * and review Git history; this script does not rewrite or scan past commits.
 */
$root = dirname(__DIR__);
$excludedDirs = ['.git', 'vendor', 'node_modules', 'storage', 'dist'];
$extensions = ['php', 'js', 'mjs', 'cjs', 'html', 'sh', 'yml', 'yaml', 'json', 'md', 'sql', 'env', 'txt', 'conf', 'ini', 'cfg', 'toml', 'xml', 'pem', 'key', 'crt'];
$patterns = [
    'Telegram bot token' => '~(?<![A-Za-z0-9_])[0-9]{8,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9_-])~',
    'Private key' => '~-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----~',
    'AWS access key' => '~\bAKIA[0-9A-Z]{16}\b~',
];

$findings = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getSize() > 2_000_000) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    $segments = explode(DIRECTORY_SEPARATOR, $relative);
    if (array_intersect($segments, $excludedDirs) !== []) {
        continue;
    }
    $baseName = basename($relative);
    $isEnvFile = str_starts_with($baseName, '.env');
    if (!$isEnvFile && !in_array(strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents) || str_contains($contents, "\0")) {
        continue;
    }
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $findings[] = $relative . ': ' . $label;
        }
    }
}

if ($findings !== []) {
    fwrite(STDERR, "Phát hiện credential có khả năng bị commit:\n- " . implode("\n- ", $findings) . "\n");
    fwrite(STDERR, "Hãy thu hồi credential, thay bằng environment variable và kiểm tra Git history.\n");
    exit(1);
}

echo "Secret scan passed: không tìm thấy Telegram token/private key/AWS key trong source text hiện tại.\n";
