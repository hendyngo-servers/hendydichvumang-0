<?php
declare(strict_types=1);

$allowedCategories = ['api', 'bot', 'dashboard'];
$args = [];
for ($index = 1; $index < count($argv); $index++) {
    $argument = $argv[$index];
    if (str_starts_with($argument, '--')) {
        $args[substr($argument, 2)] = $argv[$index + 1] ?? '';
        $index++;
    }
}

$name = trim((string) ($args['name'] ?? ''));
$category = trim((string) ($args['category'] ?? 'api'));
$description = trim((string) ($args['description'] ?? 'Mô tả tính năng mới.'));
if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name) || strlen($name) > 40) {
    fwrite(STDERR, "Tên tính năng phải là slug chữ thường, ví dụ order-history.\n");
    exit(1);
}
if (!in_array($category, $allowedCategories, true)) {
    fwrite(STDERR, "Loại tính năng phải là api, bot hoặc dashboard.\n");
    exit(1);
}

$description = function_exists('mb_substr') ? mb_substr($description, 0, 500) : substr($description, 0, 500);
$title = ucwords(str_replace('-', ' ', $name));
$root = dirname(__DIR__);
$base = $category === 'dashboard'
    ? $root . '/apps/admin-dashboard/features/' . $name
    : $root . '/app/features/' . $name;

$readme = "# {$title}\n\nLoại: {$category}\n\n{$description}\n\n" .
    "## Checklist trước khi bật\n" .
    "- [ ] Xác định quyền người dùng và Admin.\n" .
    "- [ ] Xác thực và kiểm tra dữ liệu đầu vào.\n" .
    "- [ ] Thêm migration nếu thay đổi PostgreSQL.\n" .
    "- [ ] Thêm test và cập nhật tài liệu API.\n" .
    "- [ ] Không đưa token hoặc secret vào source code/log.\n";

$feature = [
    'name' => $name,
    'title' => $title,
    'category' => $category,
    'enabled' => false,
    'description' => $description,
];
$files = [
    $base . '/README.md' => $readme,
    $base . '/feature.json' => json_encode($feature, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
];

if ($category !== 'dashboard') {
    $class = str_replace(' ', '', $title) . 'Feature';
    $files[$base . '/' . $class . '.php'] =
        "<?php\ndeclare(strict_types=1);\n\nnamespace Hendy\\Features\\{$class};\n\n" .
        "final class {$class}\n{\n" .
        "    public const ENABLED = false;\n\n" .
        "    public function register(): void\n    {\n" .
        "        // Thêm handler sau khi hoàn tất review quyền và kiểm tra dữ liệu.\n" .
        "    }\n}\n";
}

foreach ($files as $path => $content) {
    if (file_exists($path)) {
        fwrite(STDERR, "Không ghi đè tệp đã tồn tại: {$path}\n");
        exit(1);
    }
}

foreach ($files as $path => $content) {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Không thể tạo thư mục: ' . dirname($path));
    }
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Không thể ghi tệp: ' . $path);
    }
    echo 'Đã tạo: ' . substr($path, strlen($root) + 1) . "\n";
}

echo "Scaffold đã tạo ở trạng thái disabled; cần review và đăng ký thủ công.\n";
