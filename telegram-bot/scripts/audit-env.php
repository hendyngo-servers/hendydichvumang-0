<?php
declare(strict_types=1);

/**
 * Quét biến môi trường được tham chiếu tĩnh trong source code và đối chiếu
 * config/env.schema.json. Pattern dùng dấu phân cách ~, quote class rõ ràng,
 * và được self-test để tránh lỗi escape regex âm thầm.
 */

$root = dirname(__DIR__);
$schemaPath = $root . '/config/env.schema.json';
$schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
$known = $schema['variables'] ?? [];
if (!is_array($known)) {
    fwrite(STDERR, "env.schema.json không có object variables hợp lệ.\n");
    exit(2);
}

$patterns = [
    // Env::get('KEY'), Hendy\Env::get("KEY"), Env::int/Env::bool
    '~\b(?:Hendy\\\\)?Env::(?:get|int|bool)\s*\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]~',
    // getenv('KEY')
    '~\bgetenv\s*\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]~',
    // $_ENV['KEY'] only. $_SERVER includes request/server metadata, not config.
    '~\$_ENV\s*\[\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*\]~',
    // process.env.KEY and process.env['KEY']
    '~\bprocess\.env\.([A-Z][A-Z0-9_]*)\b~',
    '~\bprocess\.env\s*\[\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*\]~',
    // Shell expansions such as ${PORT:-10000}
    '~\$\{([A-Z][A-Z0-9_]*)(?::[-+=?][^}]*)?\}~',
];

foreach ($patterns as $index => $pattern) {
    set_error_handler(static fn (): bool => true);
    $result = preg_match($pattern, '');
    restore_error_handler();
    if ($result === false) {
        fwrite(STDERR, "Regex #" . ($index + 1) . " không hợp lệ: " . preg_last_error_msg() . "\n");
        exit(2);
    }
}

if (in_array('--self-test', $argv, true)) {
    $fixtures = [
        "Env::get('APP_URL')" => 'APP_URL',
        'Hendy\\Env::get("ADMIN_ID")' => 'ADMIN_ID',
        "Env::bool('DATABASE_SSL')" => 'DATABASE_SSL',
        'getenv("BOT1_TOKEN")' => 'BOT1_TOKEN',
        'process.env.WEBHOOK_AUTO_REGISTER' => 'WEBHOOK_AUTO_REGISTER',
        '${PORT:-10000}' => 'PORT',
    ];
    foreach ($fixtures as $source => $expected) {
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches) !== false && isset($matches[1])) {
                foreach ($matches[1] as $name) {
                    $found[] = $name;
                }
            }
        }
        if (!in_array($expected, $found, true)) {
            fwrite(STDERR, "Regex self-test thất bại cho {$expected}.\n");
            exit(2);
        }
    }
}

$roots = ['app', 'bin', 'public', 'apps/admin-dashboard', 'docker', 'scripts'];
$files = [];
$allowed = ['php', 'js', 'mjs', 'cjs', 'html', 'sh'];
foreach ($roots as $relative) {
    $directory = $root . '/' . $relative;
    if (!is_dir($directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        // Do not scan this scanner's regex definitions/fixtures as runtime code.
        if ($file->getPathname() === $root . '/scripts/audit-env.php') {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (in_array($extension, $allowed, true)) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$usage = [];
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    foreach ($patterns as $pattern) {
        $count = preg_match_all($pattern, $source, $matches);
        if ($count === false) {
            fwrite(STDERR, "Không thể quét " . substr($file, strlen($root) + 1) . ": " . preg_last_error_msg() . "\n");
            exit(2);
        }
        foreach ($matches[1] ?? [] as $name) {
            $usage[$name][substr($file, strlen($root) + 1)] = true;
        }
    }
}

ksort($usage);
$unknown = array_values(array_diff(array_keys($usage), array_keys($known)));
$report = [];
foreach ($usage as $name => $paths) {
    $entry = $known[$name] ?? ['classification' => 'unknown'];
    $report[] = [
        'name' => $name,
        'classification' => $entry['classification'] ?? 'unknown',
        'source' => $entry['source'] ?? 'unknown',
        'required' => (bool) ($entry['required'] ?? false),
        'required_when' => $entry['required_when'] ?? null,
        'used_in' => array_keys($paths),
        'sync_to_render' => (bool) ($entry['sync_to_render'] ?? false),
    ];
}

if (in_array('--json', $argv, true)) {
    echo json_encode(['variables' => $report, 'unknown' => $unknown], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($report as $item) {
        echo sprintf(
            "%-36s %-8s %-20s %-10s %s\n",
            $item['name'],
            strtoupper($item['classification']),
            $item['source'],
            $item['required'] ? 'REQUIRED' : ($item['required_when'] ? 'CONDITIONAL' : 'OPTIONAL'),
            implode(', ', $item['used_in'])
        );
    }
    echo "Đã quét " . count($files) . " tệp; tìm thấy " . count($report) . " biến tham chiếu tĩnh.\n";
}

if (in_array('--check', $argv, true) && $unknown !== []) {
    fwrite(STDERR, "Biến chưa khai báo trong config/env.schema.json: " . implode(', ', $unknown) . "\n");
    exit(1);
}
if ($unknown !== []) {
    exit(1);
}
