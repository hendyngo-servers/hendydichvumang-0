<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

/**
 * Restricted GitHub source editor for the Admin console.
 * Edits are committed directly to the configured base branch using a fast-forward-only ref update.
 * Every write is SHA-checked, secret-scanned and audited by the Admin API.
 */
final class SourceControlService
{
    private const DEFAULT_REPOSITORY = 'hendyngo-servers/hendydichvumang-0';
    private const DEFAULT_BASE_BRANCH = 'main';
    private const MAX_TREE_ENTRIES = 8000;
    private const MAX_FILE_BYTES = 524288;
    private const MAX_BATCH_FILES = 20;
    private const MAX_BATCH_BYTES = 2097152;

    private const TEXT_EXTENSIONS = [
        'php','phtml','inc','js','mjs','cjs','ts','tsx','jsx','json','jsonc',
        'css','scss','sass','less','html','htm','md','mdx','txt','xml','svg',
        'yml','yaml','toml','ini','conf','config','sh','bash','sql','py','go',
        'rs','java','kt','swift','rb','pl','ps1','bat','cmd','dockerfile',
        'graphql','gql','prisma','proto','tf','hcl','properties','csv','vue',
        'svelte','astro','lock','map','webmanifest'
    ];

    private static function configuration(): array
    {
        $token = trim((string) Env::get('HENDY_GITHUB_TOKEN', ''));
        $repository = trim((string) Env::get('HENDY_SOURCE_CONTROL_REPOSITORY', self::DEFAULT_REPOSITORY));
        $baseBranch = trim((string) Env::get('HENDY_SOURCE_CONTROL_BASE_BRANCH', self::DEFAULT_BASE_BRANCH));

        if (!preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $repository)) {
            throw new RuntimeException('HENDY_SOURCE_CONTROL_REPOSITORY phải có dạng owner/repository.');
        }
        if ($baseBranch === '' || strlen($baseBranch) > 120
            || preg_match('/[\x00-\x20~^:?*\[\\\\]/', $baseBranch)
            || str_contains($baseBranch, '..')
            || str_starts_with($baseBranch, '-')) {
            throw new RuntimeException('HENDY_SOURCE_CONTROL_BASE_BRANCH không hợp lệ.');
        }

        return ['token' => $token, 'repository' => $repository, 'baseBranch' => $baseBranch];
    }

    public static function status(): array
    {
        $config = self::configuration();
        if ($config['token'] === '') {
            return [
                'configured' => false,
                'connected' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'message' => 'Chưa cấu hình HENDY_GITHUB_TOKEN trong Render Environment. Trình biên tập đang ở chế độ chưa kết nối.',
                'permissionsRequired' => ['Metadata: read', 'Contents: read/write'],
            ];
        }

        try {
            $repo = self::request($config, 'GET', '');
            return [
                'configured' => true,
                'connected' => true,
                'repository' => (string) ($repo['full_name'] ?? $config['repository']),
                'baseBranch' => $config['baseBranch'],
                'private' => (bool) ($repo['private'] ?? false),
                'defaultBranch' => (string) ($repo['default_branch'] ?? ''),
                'message' => 'Đã kết nối GitHub. Mọi thay đổi sẽ được gửi bằng Draft Pull Request.',
                'permissionsRequired' => ['Metadata: read', 'Contents: read/write'],
            ];
        } catch (Throwable $error) {
            return [
                'configured' => true,
                'connected' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'message' => $error->getMessage(),
                'permissionsRequired' => ['Metadata: read', 'Contents: read/write'],
            ];
        }
    }

    /**
     * Dispatch the repository's fixed HENDY monorepo CI workflow on its configured base branch.
     * This requires Actions: write. No user-supplied workflow file or ref is accepted.
     */
    public static function dispatchWorkflow(): array
    {
        $config = self::configuration();
        if ($config['token'] === '') {
            return [
                'dispatched' => false,
                'configured' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'message' => 'Chưa cấu hình HENDY_GITHUB_TOKEN trên Render.',
                'permissionsRequired' => ['Actions: write'],
            ];
        }

        try {
            self::request(
                $config,
                'POST',
                '/actions/workflows/hendy-monorepo-ci.yml/dispatches',
                ['ref' => $config['baseBranch']]
            );
            return [
                'dispatched' => true,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'workflow' => 'hendy-monorepo-ci.yml',
                'requestedAt' => gmdate(DATE_ATOM),
                'message' => 'Đã gửi yêu cầu chạy HENDY monorepo CI. Mở CI / Build Monitor để theo dõi khi GitHub ghi nhận lần chạy.',
            ];
        } catch (Throwable $error) {
            return [
                'dispatched' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'message' => 'Không thể kích hoạt CI. Hãy kiểm tra HENDY_GITHUB_TOKEN, quyền Actions: write và trạng thái workflow. ' . $error->getMessage(),
                'permissionsRequired' => ['Actions: write'],
            ];
        }
    }

    /**
     * Read the latest GitHub Actions runs for the configured source repository.
     * Only a small, allow-listed summary is returned to the Admin UI.
     * The GitHub token is never included in the response or logs.
     */
    public static function workflowRuns(int $limit = 10): array
    {
        $config = self::configuration();
        $limit = max(1, min(20, $limit));
        if ($config['token'] === '') {
            return [
                'configured' => false,
                'connected' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'runs' => [],
                'message' => 'Chưa cấu hình HENDY_GITHUB_TOKEN trên Render.',
                'permissionsRequired' => ['Actions: read'],
            ];
        }

        try {
            $query = '/actions/runs?per_page=' . $limit . '&branch=' . rawurlencode($config['baseBranch']);
            $payload = self::request($config, 'GET', $query);
            $rawRuns = is_array($payload['workflow_runs'] ?? null) ? $payload['workflow_runs'] : [];
            $runs = [];
            foreach (array_slice($rawRuns, 0, $limit) as $run) {
                if (!is_array($run)) continue;
                $id = (int) ($run['id'] ?? 0);
                $url = (string) ($run['html_url'] ?? '');
                if ($id < 1 || !preg_match('#\\Ahttps://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/actions/runs/[0-9]+\\z#D', $url)) {
                    $url = '';
                }
                $status = (string) ($run['status'] ?? 'unknown');
                if (!in_array($status, ['queued', 'in_progress', 'completed', 'waiting', 'pending', 'requested'], true)) {
                    $status = 'unknown';
                }
                $conclusion = $run['conclusion'] ?? null;
                if (!is_string($conclusion) || !in_array($conclusion, ['success', 'failure', 'cancelled', 'skipped', 'timed_out', 'action_required', 'neutral', 'stale'], true)) {
                    $conclusion = null;
                }
                $sha = strtolower((string) ($run['head_sha'] ?? ''));
                $runs[] = [
                    'id' => $id,
                    'name' => mb_substr((string) ($run['name'] ?? 'GitHub Actions'), 0, 140),
                    'displayTitle' => mb_substr((string) ($run['display_title'] ?? $run['name'] ?? 'Workflow run'), 0, 180),
                    'runNumber' => (int) ($run['run_number'] ?? 0),
                    'event' => mb_substr((string) ($run['event'] ?? ''), 0, 40),
                    'branch' => mb_substr((string) ($run['head_branch'] ?? ''), 0, 120),
                    'sha' => preg_match('/\\A[0-9a-f]{40}\\z/D', $sha) ? substr($sha, 0, 12) : '',
                    'status' => $status,
                    'conclusion' => $conclusion,
                    'createdAt' => (string) ($run['created_at'] ?? ''),
                    'updatedAt' => (string) ($run['updated_at'] ?? ''),
                    'url' => $url,
                ];
            }
            return [
                'configured' => true,
                'connected' => true,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'checkedAt' => gmdate(DATE_ATOM),
                'runs' => $runs,
                'message' => 'Đã đọc trạng thái GitHub Actions ở chế độ chỉ đọc.',
                'permissionsRequired' => ['Actions: read'],
            ];
        } catch (Throwable $error) {
            return [
                'configured' => true,
                'connected' => false,
                'repository' => $config['repository'],
                'baseBranch' => $config['baseBranch'],
                'runs' => [],
                'message' => $error->getMessage(),
                'permissionsRequired' => ['Actions: read'],
            ];
        }
    }

    /** Returns repository files; secret files and generated directories are hidden. */
    public static function tree(string $prefix = ''): array
    {
        $config = self::configuration();
        self::requireConfigured($config);
        $prefix = self::normalizePrefix($prefix);
        [$baseSha, $tree] = self::currentTree($config);

        if (($tree['truncated'] ?? false) === true) {
            throw new RuntimeException('Repository có quá nhiều mục cho một lần duyệt. Hãy lọc theo thư mục cụ thể.');
        }
        $rawEntries = $tree['tree'] ?? [];
        if (!is_array($rawEntries) || count($rawEntries) > self::MAX_TREE_ENTRIES) {
            throw new RuntimeException('Danh mục mã nguồn vượt giới hạn duyệt an toàn.');
        }

        $entries = [];
        foreach ($rawEntries as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'blob') continue;
            $filePath = (string) ($item['path'] ?? '');
            if ($prefix !== '' && !str_starts_with($filePath, $prefix)) continue;
            $policy = self::pathPolicy($filePath);
            if (!$policy['visible']) continue;
            $entries[] = [
                'path' => $filePath,
                'name' => basename($filePath),
                'size' => (int) ($item['size'] ?? 0),
                'sha' => (string) ($item['sha'] ?? ''),
                'mode' => (string) ($item['mode'] ?? '100644'),
                'editable' => $policy['editable'],
                'reason' => $policy['reason'],
                'requiresConfirmation' => $policy['requiresConfirmation'],
            ];
        }
        usort($entries, static fn(array $left, array $right): int => strnatcasecmp($left['path'], $right['path']));
        return [
            'repository' => $config['repository'],
            'baseBranch' => $config['baseBranch'],
            'baseSha' => $baseSha,
            'entries' => $entries,
            'count' => count($entries),
            'readOnly' => true,
        ];
    }

    public static function readFile(string $path): array
    {
        $config = self::configuration();
        self::requireConfigured($config);
        $path = self::normalizePath($path);
        $policy = self::pathPolicy($path);
        if (!$policy['visible'] || !$policy['editable']) {
            throw new RuntimeException($policy['reason'] ?: 'Tệp này không được phép mở trong trình biên tập.');
        }

        [$baseSha] = self::currentTree($config);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $response = self::request($config, 'GET', '/contents/' . $encodedPath . '?ref=' . rawurlencode($config['baseBranch']));
        if (($response['type'] ?? '') !== 'file') {
            throw new RuntimeException('Đường dẫn này không phải tệp mã nguồn thông thường.');
        }
        $size = (int) ($response['size'] ?? 0);
        if ($size < 0 || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Tệp vượt giới hạn 512 KB của trình biên tập. Hãy dùng Git trực tiếp cho tệp lớn.');
        }
        if (($response['encoding'] ?? '') !== 'base64' || !is_string($response['content'] ?? null)) {
            throw new RuntimeException('GitHub không trả nội dung UTF-8 có thể chỉnh sửa cho tệp này.');
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $response['content']), true);
        if (!is_string($decoded) || !mb_check_encoding($decoded, 'UTF-8')) {
            throw new RuntimeException('Tệp không phải nội dung UTF-8 hợp lệ; trình biên tập từ chối mở để tránh hỏng dữ liệu.');
        }
        return [
            'path' => $path,
            'content' => $decoded,
            'sha' => (string) ($response['sha'] ?? ''),
            'baseSha' => $baseSha,
            'size' => strlen($decoded),
            'mode' => '100644',
            'requiresConfirmation' => $policy['requiresConfirmation'],
        ];
    }

    /**
     * Creates a Git commit on a new branch and opens a draft PR.
     * Each file is checked against the exact version originally loaded by Admin.
     */
    public static function commitToMain(array $input): array
    {
        $config = self::configuration();
        self::requireConfigured($config);
        $files = $input['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > self::MAX_BATCH_FILES) {
            throw new RuntimeException('Chọn từ 1 đến ' . self::MAX_BATCH_FILES . ' tệp thay đổi cho mỗi lần ghi.');
        }

        $expectedBaseSha = strtolower(trim((string) ($input['expectedBaseSha'] ?? '')));
        if (!preg_match('/\A[0-9a-f]{40}\z/D', $expectedBaseSha)) {
            throw new RuntimeException('Thiếu mã phiên bản base; hãy tải lại danh sách tệp trước khi gửi.');
        }
        $sensitiveConfirmation = filter_var($input['sensitiveConfirmation'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $title = trim((string) ($input['title'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new RuntimeException('Thông điệp commit phải có từ 1 đến 120 ký tự.');
        }
        if (mb_strlen($summary) > 8000) {
            throw new RuntimeException('Mô tả thay đổi không được vượt quá 8.000 ký tự.');
        }

        [$baseSha, $baseTree] = self::currentTree($config);
        if (!hash_equals($expectedBaseSha, strtolower($baseSha))) {
            throw new RuntimeException('Nhánh ' . $config['baseBranch'] . ' đã có commit mới kể từ lúc mở tệp. Hãy tải lại và áp dụng thay đổi lên phiên bản mới nhất.');
        }
        if (($baseTree['truncated'] ?? false) === true) {
            throw new RuntimeException('Không thể xác minh toàn bộ cây mã nguồn; đã dừng để tránh ghi đè thay đổi khác.');
        }

        $current = [];
        foreach (($baseTree['tree'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'blob' && isset($item['path'])) {
                $current[(string) $item['path']] = $item;
            }
        }

        $normalized = [];
        $seen = [];
        $totalBytes = 0;
        $needsConfirmation = false;
        foreach ($files as $file) {
            if (!is_array($file)) throw new RuntimeException('Dữ liệu tệp thay đổi không hợp lệ.');
            $path = self::normalizePath((string) ($file['path'] ?? ''));
            if (isset($seen[$path])) throw new RuntimeException('Một tệp xuất hiện nhiều lần trong cùng bản lưu: ' . $path);
            $seen[$path] = true;
            $policy = self::pathPolicy($path);
            if (!$policy['visible'] || !$policy['editable']) {
                throw new RuntimeException($policy['reason'] ?: 'Không được phép sửa tệp ' . $path . '.');
            }
            $needsConfirmation = $needsConfirmation || $policy['requiresConfirmation'];

            $existing = $current[$path] ?? null;
            $expectedFileSha = trim((string) ($file['sha'] ?? ''));
            if ($existing === null && $expectedFileSha !== '') {
                throw new RuntimeException('Tệp ' . $path . ' đã bị xóa hoặc di chuyển trên ' . $config['baseBranch'] . '. Hãy tải lại.');
            }
            if ($existing !== null && ($expectedFileSha === '' || !hash_equals((string) $existing['sha'], $expectedFileSha))) {
                throw new RuntimeException('Tệp ' . $path . ' đã thay đổi trên ' . $config['baseBranch'] . '. Hãy mở lại tệp trước khi lưu.');
            }

            $deleted = filter_var($file['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($deleted) {
                if ($existing === null) throw new RuntimeException('Không thể xóa tệp không còn tồn tại: ' . $path);
                $normalized[] = ['path' => $path, 'deleted' => true, 'mode' => (string) ($existing['mode'] ?? '100644')];
                continue;
            }

            $fileContent = $file['content'] ?? null;
            if (!is_string($fileContent) || !mb_check_encoding($fileContent, 'UTF-8')) {
                throw new RuntimeException('Nội dung ' . $path . ' không phải văn bản UTF-8 hợp lệ.');
            }
            $bytes = strlen($fileContent);
            if ($bytes > self::MAX_FILE_BYTES) throw new RuntimeException('Tệp ' . $path . ' vượt giới hạn 512 KB.');
            $totalBytes += $bytes;
            if ($totalBytes > self::MAX_BATCH_BYTES) throw new RuntimeException('Tổng nội dung gửi lên vượt giới hạn 2 MB cho mỗi lần ghi.');
            if (self::containsSecret($fileContent)) {
                throw new RuntimeException('Phát hiện mẫu private key/token trong ' . $path . '. Hãy đưa credential vào Render Environment, không đưa vào mã nguồn.');
            }
            $normalized[] = [
                'path' => $path,
                'content' => $fileContent,
                'mode' => (string) ($existing['mode'] ?? '100644'),
                'deleted' => false,
            ];
        }

        if ($needsConfirmation && !$sensitiveConfirmation) {
            throw new RuntimeException('Có tệp SQL/migration hoặc cấu hình triển khai nhạy cảm. Hãy xác nhận đã rà soát tác động trước khi ghi trực tiếp vào nhánh chính.');
        }

        $treeElements = [];
        foreach ($normalized as $file) {
            if ($file['deleted']) {
                $treeElements[] = ['path' => $file['path'], 'mode' => $file['mode'], 'type' => 'blob', 'sha' => null];
                continue;
            }
            $blob = self::request($config, 'POST', '/git/blobs', [
                'content' => $file['content'],
                'encoding' => 'utf-8',
            ]);
            $treeElements[] = [
                'path' => $file['path'],
                'mode' => in_array($file['mode'], ['100644', '100755'], true) ? $file['mode'] : '100644',
                'type' => 'blob',
                'sha' => (string) ($blob['sha'] ?? ''),
            ];
        }

        $tree = self::request($config, 'POST', '/git/trees', [
            'base_tree' => (string) ($baseTree['sha'] ?? ''),
            'tree' => $treeElements,
        ]);
        $treeSha = (string) ($tree['sha'] ?? '');
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $treeSha)) throw new RuntimeException('GitHub không tạo được cây mã nguồn mới.');

        $changedPaths = array_map(static fn(array $file): string => $file['path'], $normalized);
        $commitMessage = trim((string) ($input['title'] ?? 'Update source from HENDY Admin'));
        $commit = self::request($config, 'POST', '/git/commits', [
            'message' => 'chore(admin-editor): ' . $commitMessage,
            'tree' => $treeSha,
            'parents' => [$baseSha],
        ]);
        $commitSha = (string) ($commit['sha'] ?? '');
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $commitSha)) {
            throw new RuntimeException('GitHub không tạo được commit mới.');
        }

        // Fast-forward only: GitHub rejects the update if main moved since the
        // tree/commit was prepared. Never force-push or overwrite another commit.
        self::request($config, 'PATCH', '/git/refs/heads/' . rawurlencode($config['baseBranch']), [
            'sha' => $commitSha,
            'force' => false,
        ]);

        return [
            'commitSha' => $commitSha,
            'baseSha' => $baseSha,
            'branch' => $config['baseBranch'],
            'changedFiles' => $changedPaths,
            'message' => 'Đã ghi commit trực tiếp vào nhánh ' . $config['baseBranch'] . '. CI/CD có thể tự khởi chạy theo cấu hình repository.',
        ];
    }

    /** Path visibility/editor policy shared by UI and write API. */
    public static function pathPolicy(string $path): array
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0")
            || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return ['visible' => false, 'editable' => false, 'reason' => 'Đường dẫn không hợp lệ.', 'requiresConfirmation' => false];
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return ['visible' => false, 'editable' => false, 'reason' => 'Đường dẫn chứa thành phần không an toàn.', 'requiresConfirmation' => false];
            }
        }

        $lower = strtolower($path);
        foreach (['/.git/', '/vendor/', '/node_modules/', '/.next/', '/.cache/', '/coverage/', '/uploads/', '/storage/', '/tmp/'] as $directory) {
            if (str_contains('/' . $lower . '/', $directory)) {
                return ['visible' => false, 'editable' => false, 'reason' => 'Thư mục sinh tự động hoặc không thuộc mã nguồn chỉnh sửa.', 'requiresConfirmation' => false];
            }
        }

        $base = strtolower(basename($path));
        $secretNames = ['.env', '.npmrc', '.pypirc', 'id_rsa', 'id_ed25519', 'credentials.json', 'service-account.json'];
        $secretSuffixes = ['.pem', '.key', '.p12', '.pfx', '.jks', '.keystore', '.tfstate', '.secret'];
        if (in_array($base, $secretNames, true) || str_starts_with($base, '.env.')
            || str_contains($base, 'private-key') || str_contains($base, 'service-account')
            || str_contains($base, 'credentials.')
        ) {
            return ['visible' => false, 'editable' => false, 'reason' => 'Tệp có thể chứa secret/credential; trình biên tập không hiển thị.', 'requiresConfirmation' => false];
        }
        foreach ($secretSuffixes as $suffix) {
            if (str_ends_with($base, $suffix)) {
                return ['visible' => false, 'editable' => false, 'reason' => 'Tệp khóa/chứng thư không được phép mở qua Admin.', 'requiresConfirmation' => false];
            }
        }

        if (str_starts_with($lower, '.github/workflows/') || str_starts_with($lower, '.github/actions/')) {
            return ['visible' => true, 'editable' => false, 'reason' => 'Workflow CI/CD được giữ chế độ chỉ đọc để tránh thay đổi luồng có quyền truy cập secret.', 'requiresConfirmation' => false];
        }

        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $specialNames = ['dockerfile', 'makefile', 'caddyfile', 'procfile', 'readme', 'license', 'notice', 'copying', 'gemfile', 'brewfile', 'justfile', 'taskfile', '.gitignore', '.dockerignore', '.gitattributes', '.editorconfig'];
        $isText = in_array($base, $specialNames, true) || in_array($extension, self::TEXT_EXTENSIONS, true);
        if (!$isText) {
            return ['visible' => true, 'editable' => false, 'reason' => 'Định dạng không phải tệp văn bản được hỗ trợ.', 'requiresConfirmation' => false];
        }

        $requiresConfirmation = str_ends_with($lower, '.sql')
            || $base === 'render.yaml'
            || $base === 'dockerfile'
            || str_contains($lower, 'composer.lock')
            || str_contains($lower, 'package-lock.json')
            || str_contains($lower, 'pnpm-lock.yaml')
            || str_contains($lower, 'yarn.lock');

        return ['visible' => true, 'editable' => true, 'reason' => '', 'requiresConfirmation' => $requiresConfirmation];
    }

    public static function containsSecret(string $content): bool
    {
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/i',
            '/\b(?:gh[pousr]|github_pat)_[A-Za-z0-9_]{20,}\b/',
            '/\b[0-9]{5,12}:[A-Za-z0-9_-]{30,}\b/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) return true;
        }
        return false;
    }

    private static function normalizePath(string $path): string
    {
        $policy = self::pathPolicy($path);
        if (!$policy['visible'] || !$policy['editable']) {
            throw new RuntimeException($policy['reason'] ?: 'Đường dẫn tệp không được phép.');
        }
        return trim($path);
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') return '';
        if (str_contains($prefix, "\0") || str_contains($prefix, '\\')
            || str_starts_with($prefix, '/') || preg_match('#(^|/)\.\.?(/|$)#', $prefix)) {
            throw new RuntimeException('Bộ lọc thư mục không hợp lệ.');
        }
        return rtrim($prefix, '/') . '/';
    }

    private static function requireConfigured(array $config): void
    {
        if ($config['token'] === '') {
            throw new RuntimeException('Trình quản lý mã nguồn chưa kết nối. Hãy cấu hình HENDY_GITHUB_TOKEN trong Render Environment.');
        }
    }

    private static function currentTree(array $config): array
    {
        $branch = rawurlencode($config['baseBranch']);
        $ref = self::request($config, 'GET', '/git/ref/heads/' . $branch);
        $baseSha = (string) ($ref['object']['sha'] ?? '');
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $baseSha)) {
            throw new RuntimeException('Không đọc được commit hiện tại của nhánh ' . $config['baseBranch'] . '.');
        }
        $commit = self::request($config, 'GET', '/git/commits/' . $baseSha);
        $treeSha = (string) ($commit['tree']['sha'] ?? '');
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $treeSha)) {
            throw new RuntimeException('Không đọc được cây mã nguồn Git hiện tại.');
        }
        $tree = self::request($config, 'GET', '/git/trees/' . $treeSha . '?recursive=1');
        return [$baseSha, $tree];
    }

    private static function request(array $config, string $method, string $endpoint, ?array $payload = null): array
    {
        $url = 'https://api.github.com/repos/' . $config['repository'] . $endpoint;
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Không khởi tạo được kết nối GitHub.');
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $config['token'],
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: HENDY-Admin-Source-Control',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                curl_close($handle);
                throw new RuntimeException('Không tuần tự hóa được yêu cầu GitHub.');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body)) {
            throw new RuntimeException('Kết nối GitHub thất bại: ' . ($curlError !== '' ? $curlError : 'không có phản hồi.'));
        }
        if ($status === 204 && trim($body) === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            if ($status === 401 || $status === 403) {
                throw new RuntimeException('GitHub từ chối token hoặc thiếu quyền. Cần quyền phù hợp với thao tác GitHub được yêu cầu, bao gồm Actions: read/write khi theo dõi hoặc kích hoạt workflow.');
            }
            if ($status === 404) throw new RuntimeException('Không tìm thấy repository/nhánh/tệp hoặc token không có quyền truy cập.');
            if ($status === 409 || $status === 422) {
                $message = (string) ($decoded['message'] ?? 'GitHub từ chối thay đổi.');
                throw new RuntimeException('GitHub không chấp nhận yêu cầu (' . $status . '): ' . mb_substr($message, 0, 300));
            }
            if ($status === 429) throw new RuntimeException('GitHub API đang giới hạn tốc độ; hãy thử lại sau.');
            throw new RuntimeException('GitHub API trả về HTTP ' . $status . '. Hãy kiểm tra quyền token và trạng thái repository.');
        }
        return $decoded;
    }
}
