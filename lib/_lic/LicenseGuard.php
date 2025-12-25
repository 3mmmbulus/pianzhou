<?php

final class LicenseGuard
{
    // 注意：你后续会对 /index/lib 加密，这个常量的“保密性”依赖加密方案。
    private const SECRET_HEX = '777770706370735f6c6963656e73655f67756172645f7631';

    private const QUERY_FLAG = '__license';
    private const QUERY_FLAG_VALUE = '1';

    private const QUERY_RETURN = 'r';

    private const REMOTE_ENDPOINT_HEX = '68747470733a2f2f6170692e77777070636d732e636f6d2f6170692f777770702d636d73312e302f617574686f72697a65';

    private const STORAGE_DIR = '.license';
    private const STATE_FILE = 'state.json';
    private const HISTORY_FILE = 'history.jsonl';
    private const MANIFEST_FILE = 'manifest.json';

    private const FORM_FIELD_ACTION = 'action';
    private const ACTION_AUTHORIZE = 'authorize';
    private const ACTION_RESET = 'reset';

    /**
     * 入口：在 Wwppcms::run() 早期调用。
     * - 非授权入口：校验通过则继续；失败则 302 跳转 /?__license=1
     * - 授权入口：直接输出内置授权 UI（GET）或处理提交（POST），并 exit
     */
    public static function enforce(Wwppcms $wwppcms): void
    {
        $rootDir = $wwppcms->getRootDir();
        $configDir = rtrim($wwppcms->getConfigDir(), '/');

        $storageDir = $configDir . '/' . self::STORAGE_DIR;

        // 授权入口：完全不走 content/theme/routing
        if (self::isLicenseEndpoint()) {
            self::handleLicenseEndpoint($rootDir, $storageDir);
            exit;
        }

        // 常规请求：校验授权，失败则全站跳转授权入口
        $result = self::checkOrRevalidate($rootDir, $storageDir);
        if (!$result['ok']) {
            self::redirectToLicense();
            exit;
        }
    }

    private static function isLicenseEndpoint(): bool
    {
        return isset($_GET[self::QUERY_FLAG]) && (string) $_GET[self::QUERY_FLAG] === self::QUERY_FLAG_VALUE;
    }

    private static function redirectToLicense(): void
    {
        $r = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // 避免把授权页自身作为 return
        if (strpos($r, self::QUERY_FLAG . '=' . self::QUERY_FLAG_VALUE) !== false) {
            $r = '/';
        }
        $target = '/?' . self::QUERY_FLAG . '=' . self::QUERY_FLAG_VALUE . '&' . self::QUERY_RETURN . '=' . rawurlencode($r);
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Location: ' . $target, true, 302);
        }

        // redirect 不可靠时兜底输出
        self::renderHtml(
            '系统已进入受限状态',
            '<p>系统需要授权验证。</p><p><a href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">进入授权页面</a></p>'
        );
    }

    private static function handleLicenseEndpoint(string $rootDir, string $storageDir): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        $returnTo = isset($_GET[self::QUERY_RETURN]) ? (string) $_GET[self::QUERY_RETURN] : '/';
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }

        // 授权页：如果到期/抽检/异常，会在这里触发远端复核（远端不可达则直接显示失败）
        $check = self::checkOrRevalidate($rootDir, $storageDir, false);

        if ($method === 'POST') {
            $action = isset($_POST[self::FORM_FIELD_ACTION]) ? (string) $_POST[self::FORM_FIELD_ACTION] : self::ACTION_AUTHORIZE;

            if ($action === self::ACTION_RESET) {
                self::clearDirContents($storageDir);

                self::renderLicensePage(
                    array('ok' => false, 'reason' => 'reset'),
                    '已重置授权信息 / License reset. 请重新授权。',
                    $returnTo
                );
                return;
            }

            $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
            $code = isset($_POST['code']) ? strtoupper(trim((string) $_POST['code'])) : '';

            if ($email === '' || $code === '') {
                self::renderLicensePage($check, '请填写邮箱与授权码 / Please fill in email and license code.', $returnTo);
                return;
            }

            $serverIp = self::getPrimaryIp();
            if ($serverIp === null) {
                self::appendHistory($rootDir, $storageDir, array(
                    'event' => 'ip_detect_failed',
                    'ok' => false,
                    'reason' => 'cannot_detect_primary_ip',
                ));
                self::renderLicensePage($check, '无法获取服务器主 IP / Cannot detect primary server IP.', $returnTo);
                return;
            }

            $remote = self::remoteAuthorize($email, $code, $serverIp);
            self::appendHistory($rootDir, $storageDir, array(
                'event' => 'authorize_submit',
                'email_sha256' => hash('sha256', strtolower($email)),
                'code_sha256' => hash('sha256', $code),
                'email_enc' => self::encryptField($rootDir, $email),
                'code_enc' => self::encryptField($rootDir, $code),
                'server_ip_enc' => self::encryptField($rootDir, $serverIp),
                'remote' => self::sanitizeRemoteForHistory($rootDir, $remote),
            ));

            if (!($remote['http_ok'] ?? false) || !($remote['body_ok'] ?? false)) {
                // 远端不可达：无宽限期，直接受限
                self::renderLicensePage($check, self::formatRemoteError($remote), $returnTo);
                return;
            }

            $payload = $remote['payload'];
            if (($payload['ok'] ?? false) !== true || ($payload['code'] ?? '') !== 'AUTH_APPROVED') {
                self::renderLicensePage($check, self::formatBusinessError($payload), $returnTo);
                return;
            }

            // 写入 state + manifest
            $state = self::buildStateFromRemote($rootDir, $email, $code, $serverIp, $payload);
            self::saveState($rootDir, $storageDir, $state);
            self::writeManifest($rootDir, $storageDir);

            // 成功后跳回原访问地址
            if (!headers_sent()) {
                header('Location: ' . $returnTo, true, 302);
            }
            self::renderHtml('授权成功', '<p>授权已生效 / License approved.</p><p><a href="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">继续访问</a></p>');
            return;
        }

        // GET：展示表单/状态
        self::renderLicensePage($check, null, $returnTo);
    }

    private static function clearDirContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $path => $info) {
            if ($info->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private static function checkOrRevalidate(string $rootDir, string $storageDir, bool $skipRemoteIfPossible = false): array
    {
        self::ensureStorageDir($storageDir);

        $abnormal = self::detectAbnormal($rootDir, $storageDir);
        if (defined('WWPP_SELF_TAMPER') && WWPP_SELF_TAMPER === 1) {
            $abnormal = 'self_tamper';
        }
        $state = self::loadState($rootDir, $storageDir);

        $now = time();
        $needsRemote = false;
        $reason = null;

        if ($abnormal !== null) {
            $needsRemote = true;
            $reason = 'abnormal:' . $abnormal;
        } elseif ($state === null) {
            return array('ok' => false, 'reason' => 'no_state');
        } elseif (!self::isStateSane($state)) {
            $needsRemote = true;
            $reason = 'state_invalid';
        } else {
            $expiresAt = self::parseIsoTime($state['expires_at'] ?? null);
            if ($expiresAt === null || $expiresAt <= $now) {
                $needsRemote = true;
                $reason = 'expired_or_missing_expires_at';
            } else {
                $nextAuditAt = (int) ($state['next_audit_at'] ?? 0);
                if ($nextAuditAt > 0 && $nextAuditAt <= $now) {
                    $needsRemote = true;
                    $reason = 'scheduled_audit_due';
                }
            }
        }

        if (!$needsRemote) {
            return array('ok' => true, 'reason' => 'local_ok', 'state' => $state);
        }

        if ($skipRemoteIfPossible) {
            return array('ok' => ($state !== null && self::isStateSane($state)), 'reason' => $reason, 'state' => $state);
        }

        // 需要远端：尽量从本地缓存/历史中恢复 email+code（即便 state/manifest 被手动改坏）
        $cacheTampered = ($abnormal !== null) && self::isCacheTamperAbnormal($abnormal);
        $creds = self::getCredsForRemote($rootDir, $storageDir, $state);
        if ($creds === null) {
            return array('ok' => false, 'reason' => 'need_remote_but_no_state');
        }

        $email = $creds['email'];
        $code = $creds['code'];
        $credsSource = $creds['source'];

        $serverIp = self::getPrimaryIp();
        if ($serverIp === null) {
            self::appendHistory($rootDir, $storageDir, array(
                'event' => 'ip_detect_failed',
                'ok' => false,
                'reason' => 'cannot_detect_primary_ip',
            ));
            return array('ok' => false, 'reason' => 'ip_detect_failed');
        }

        $remote = self::remoteAuthorize($email, $code, $serverIp);
        self::appendHistory($rootDir, $storageDir, array(
            'event' => 'remote_revalidate',
            'ok' => $remote['http_ok'] ?? false,
            'reason' => $reason,
            'creds_source' => $credsSource,
            'server_ip_enc' => self::encryptField($rootDir, $serverIp),
            'remote' => self::sanitizeRemoteForHistory($rootDir, $remote),
        ));

        if (!($remote['http_ok'] ?? false) || !($remote['body_ok'] ?? false)) {
            // 远端不可达：无宽限期
            return array('ok' => false, 'reason' => 'remote_unreachable');
        }

        $payload = $remote['payload'];
        if (($payload['ok'] ?? false) !== true || ($payload['code'] ?? '') !== 'AUTH_APPROVED') {
            // 若本地缓存疑似被篡改：远端判定非法则直接清空全部授权数据
            if ($cacheTampered) {
                self::clearDirContents($storageDir);
            }
            return array('ok' => false, 'reason' => 'remote_denied:' . (string) ($payload['code'] ?? 'UNKNOWN'));
        }

        $newState = self::buildStateFromRemote($rootDir, $email, $code, $serverIp, $payload);

        // 若本地缓存疑似被篡改：复检通过则重置 .license 缓存（优化用户体验）
        if ($cacheTampered) {
            self::clearDirContents($storageDir);
            self::ensureStorageDir($storageDir);
        }

        self::saveState($rootDir, $storageDir, $newState);
        if ($cacheTampered) {
            self::appendHistory($rootDir, $storageDir, array(
                'event' => 'cache_rebuilt_after_revalidate',
                'ok' => true,
                'reason' => $reason,
                'creds_source' => $credsSource,
            ));
        }
        self::writeManifest($rootDir, $storageDir);

        return array('ok' => true, 'reason' => 'remote_ok', 'state' => $newState);
    }

    private static function isCacheTamperAbnormal(string $abnormal): bool
    {
        // 仅针对 /config/.license 的篡改/缺失/异常；guard/core 变化不在此列
        return in_array($abnormal, array(
            'manifest_invalid',
            'manifest_missing_files',
            'manifest_missing_state',
            'manifest_missing_history',
            'missing_state',
            'missing_history',
            'changed_state',
            'changed_history',
            'history_chain_broken',
        ), true);
    }

    private static function getCredsForRemote(string $rootDir, string $storageDir, ?array $signedState): ?array
    {
        // 1) 优先用已验证签名的 state
        if (is_array($signedState)) {
            $email = self::decryptField($rootDir, $signedState['email_enc'] ?? null);
            $code = self::decryptField($rootDir, $signedState['code_enc'] ?? null);
            if (is_string($email) && $email !== '' && is_string($code) && $code !== '') {
                return array('email' => $email, 'code' => $code, 'source' => 'state_signed');
            }
        }

        // 2) 若 state 被改坏：尝试读取未验签的 state.json（尽量提升体验）
        $statePath = $storageDir . '/' . self::STATE_FILE;
        $unsafe = self::readUnsignedJsonFile($statePath);
        if (is_array($unsafe)) {
            $email = null;
            $code = null;

            if (isset($unsafe['email']) && is_string($unsafe['email']) && $unsafe['email'] !== '') {
                $email = (string) $unsafe['email'];
            } else {
                $email = self::decryptField($rootDir, $unsafe['email_enc'] ?? null);
            }

            if (isset($unsafe['code']) && is_string($unsafe['code']) && $unsafe['code'] !== '') {
                $code = (string) $unsafe['code'];
            } else {
                $code = self::decryptField($rootDir, $unsafe['code_enc'] ?? null);
            }

            if (is_string($email) && $email !== '' && is_string($code) && $code !== '') {
                return array('email' => $email, 'code' => $code, 'source' => 'state_unsafe');
            }
        }

        // 3) 最后尝试从 history.jsonl 找回（优先使用加密字段）
        $historyPath = $storageDir . '/' . self::HISTORY_FILE;
        if (is_file($historyPath)) {
            $lines = self::readLastNonEmptyLines($historyPath, 400);
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $entry = json_decode($lines[$i], true);
                if (!is_array($entry)) {
                    continue;
                }
                $p = $entry['payload'] ?? null;
                if (!is_array($p)) {
                    continue;
                }
                $event = isset($p['event']) ? (string) $p['event'] : '';
                if ($event !== 'authorize_submit') {
                    continue;
                }

                $email = null;
                $code = null;
                if (isset($p['email']) && is_string($p['email']) && $p['email'] !== '') {
                    $email = (string) $p['email'];
                } else {
                    $email = self::decryptField($rootDir, $p['email_enc'] ?? null);
                }

                if (isset($p['code']) && is_string($p['code']) && $p['code'] !== '') {
                    $code = (string) $p['code'];
                } else {
                    $code = self::decryptField($rootDir, $p['code_enc'] ?? null);
                }

                if (is_string($email) && $email !== '' && is_string($code) && $code !== '') {
                    return array('email' => $email, 'code' => $code, 'source' => 'history');
                }
            }
        }

        return null;
    }

    private static function readUnsignedJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            return null;
        }
        $payload = $doc['payload'] ?? null;
        return is_array($payload) ? $payload : null;
    }

    private static function ensureStorageDir(string $storageDir): void
    {
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }
    }

    /**
     * 返回 null 表示未发现异常；否则返回异常原因字符串。
     */
    private static function detectAbnormal(string $rootDir, string $storageDir): ?string
    {
        $manifestPath = $storageDir . '/' . self::MANIFEST_FILE;
        $statePath = $storageDir . '/' . self::STATE_FILE;
        $historyPath = $storageDir . '/' . self::HISTORY_FILE;

        // 首次安装：没有 manifest，不算异常（但也视为未授权）
        if (!is_file($manifestPath)) {
            return null;
        }

        $manifest = self::readSignedJsonFile($rootDir, $manifestPath);
        if ($manifest === null) {
            return 'manifest_invalid';
        }

        $expected = $manifest['files'] ?? null;
        if (!is_array($expected)) {
            return 'manifest_missing_files';
        }

        $checks = array(
            'state' => $statePath,
            'history' => $historyPath,
            // 核心驱动指纹：guard 自身 + Wwppcms 核心文件
            'guard' => __FILE__,
            'core' => $rootDir . '/lib/Wwppcms.php',
        );

        foreach ($checks as $key => $path) {
            if (!is_file($path)) {
                return 'missing_' . $key;
            }

            $sha = hash_file('sha256', $path);
            $size = filesize($path);

            $exp = $expected[$key] ?? null;
            if (!is_array($exp)) {
                return 'manifest_missing_' . $key;
            }

            if (($exp['sha256'] ?? null) !== $sha || (int) ($exp['size'] ?? -1) !== (int) $size) {
                return 'changed_' . $key;
            }
        }

        // history 结构抽检（只验最后 N 条的链完整性，避免超大文件每次全扫）
        if (!self::verifyHistoryTail($rootDir, $historyPath, 50)) {
            return 'history_chain_broken';
        }

        return null;
    }

    private static function verifyHistoryTail(string $rootDir, string $historyPath, int $maxLines): bool
    {
        if (!is_file($historyPath)) {
            return true;
        }

        $lines = self::readLastNonEmptyLines($historyPath, $maxLines);
        $entries = array();
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            } else {
                return false;
            }
        }

        // 校验签名与 hash，并保证尾部片段内的 prev_hash 链自洽
        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];
            $sig = $entry['sig'] ?? null;
            $hash = $entry['hash'] ?? null;
            $prev = $entry['prev_hash'] ?? null;
            $payload = $entry['payload'] ?? null;

            if (!is_string($sig) || !is_string($hash) || !is_array($payload)) {
                return false;
            }

            $payloadJson = self::jsonCanonical($payload);
            $calcSig = self::hmac($rootDir, $payloadJson);
            if (!hash_equals($calcSig, $sig)) {
                return false;
            }

            $calcHash = hash('sha256', ((is_string($prev) ? $prev : '') . "\n" . $payloadJson));
            if (!hash_equals($calcHash, $hash)) {
                return false;
            }

            if ($i > 0) {
                $prevHash = $entries[$i - 1]['hash'] ?? null;
                if (!is_string($prevHash) || $prev !== $prevHash) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function readLastNonEmptyLines(string $path, int $maxLines): array
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return array();
        }

        $queue = array();
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $queue[] = $line;
            if (count($queue) > $maxLines) {
                array_shift($queue);
            }
        }
        fclose($fp);
        return $queue;
    }

    private static function loadState(string $rootDir, string $storageDir): ?array
    {
        $path = $storageDir . '/' . self::STATE_FILE;
        return self::readSignedJsonFile($rootDir, $path);
    }

    private static function isStateSane(array $state): bool
    {
        if (!isset($state['license_key_id']) || !is_string($state['license_key_id']) || $state['license_key_id'] === '') {
            return false;
        }
        if (!isset($state['expires_at']) || !is_string($state['expires_at']) || $state['expires_at'] === '') {
            return false;
        }
        if (!isset($state['email_enc']) || !is_string($state['email_enc']) || $state['email_enc'] === '') {
            return false;
        }
        if (!isset($state['code_enc']) || !is_string($state['code_enc']) || $state['code_enc'] === '') {
            return false;
        }
        return true;
    }

    private static function saveState(string $rootDir, string $storageDir, array $state): void
    {
        $path = $storageDir . '/' . self::STATE_FILE;
        self::writeSignedJsonFile($rootDir, $path, $state);
    }

    private static function appendHistory(string $rootDir, string $storageDir, array $payload): void
    {
        self::ensureStorageDir($storageDir);
        $path = $storageDir . '/' . self::HISTORY_FILE;

        $payload['ts'] = gmdate('c');
        $payload['request_uri'] = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null;
        $payload['request_ip'] = self::getRequestIp();

        // 读取上一条 hash
        $prevHash = self::readLastHistoryHash($path);

        $payloadJson = self::jsonCanonical($payload);
        $sig = self::hmac($rootDir, $payloadJson);
        $hash = hash('sha256', ($prevHash ?? '') . "\n" . $payloadJson);

        $entry = array(
            'prev_hash' => $prevHash,
            'hash' => $hash,
            'sig' => $sig,
            'payload' => $payload,
        );

        $line = self::jsonCanonical($entry) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private static function readLastHistoryHash(string $historyPath): ?string
    {
        if (!is_file($historyPath)) {
            return null;
        }

        $lines = self::readLastNonEmptyLines($historyPath, 5);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['hash']) && is_string($entry['hash'])) {
                return $entry['hash'];
            }
        }

        return null;
    }

    private static function writeManifest(string $rootDir, string $storageDir): void
    {
        self::ensureStorageDir($storageDir);

        $manifestPath = $storageDir . '/' . self::MANIFEST_FILE;
        $statePath = $storageDir . '/' . self::STATE_FILE;
        $historyPath = $storageDir . '/' . self::HISTORY_FILE;

        $files = array();
        $targets = array(
            'state' => $statePath,
            'history' => $historyPath,
            'guard' => __FILE__,
            'core' => $rootDir . '/lib/Wwppcms.php',
        );

        foreach ($targets as $key => $path) {
            if (!is_file($path)) {
                continue;
            }
            $files[$key] = array(
                'sha256' => hash_file('sha256', $path),
                'size' => filesize($path),
                'mtime' => filemtime($path),
            );
        }

        $manifest = array(
            'version' => 1,
            'ts' => gmdate('c'),
            'files' => $files,
        );

        self::writeSignedJsonFile($rootDir, $manifestPath, $manifest);
    }

    private static function readSignedJsonFile(string $rootDir, string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            return null;
        }

        $sig = $doc['sig'] ?? null;
        $payload = $doc['payload'] ?? null;
        if (!is_string($sig) || !is_array($payload)) {
            return null;
        }

        $payloadJson = self::jsonCanonical($payload);
        $calc = self::hmac($rootDir, $payloadJson);
        if (!hash_equals($calc, $sig)) {
            return null;
        }

        return $payload;
    }

    private static function writeSignedJsonFile(string $rootDir, string $path, array $payload): void
    {
        $payloadJson = self::jsonCanonical($payload);
        $doc = array(
            'sig' => self::hmac($rootDir, $payloadJson),
            'payload' => $payload,
        );

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        @file_put_contents($tmp, self::jsonCanonical($doc), LOCK_EX);
        @rename($tmp, $path);
    }

    private static function jsonCanonical($data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function hmac(string $rootDir, string $data): string
    {
        $key = self::deriveKey($rootDir);
        return hash_hmac('sha256', $data, $key);
    }

    private static function hx(string $hex): string
    {
        $bin = hex2bin($hex);
        return ($bin === false) ? '' : $bin;
    }

    private static function deriveKey(string $rootDir): string
    {
        // 加密后此密钥不可见；同时绑定安装路径，降低跨站复制
        return hash('sha256', self::hx(self::SECRET_HEX) . '|' . $rootDir, true);
    }

    private static function encryptField(string $rootDir, string $plain): string
    {
        $key = self::deriveKey($rootDir);

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(16);
            $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($cipher !== false) {
                return 'aes:' . base64_encode($iv . $cipher);
            }
        }

        // 极简兜底：XOR + base64（仅防止用户一眼看懂）
        $mask = hash('sha256', $key, true);
        $out = '';
        for ($i = 0; $i < strlen($plain); $i++) {
            $out .= $plain[$i] ^ $mask[$i % strlen($mask)];
        }
        return 'xor:' . base64_encode($out);
    }

    private static function decryptField(string $rootDir, ?string $enc): ?string
    {
        if (!is_string($enc) || $enc === '') {
            return null;
        }

        $key = self::deriveKey($rootDir);

        if (strpos($enc, 'aes:') === 0) {
            $raw = base64_decode(substr($enc, 4), true);
            if ($raw === false || strlen($raw) < 17) {
                return null;
            }
            $iv = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            if (function_exists('openssl_decrypt')) {
                $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                return ($plain === false) ? null : $plain;
            }
            return null;
        }

        if (strpos($enc, 'xor:') === 0) {
            $raw = base64_decode(substr($enc, 4), true);
            if ($raw === false) {
                return null;
            }
            $mask = hash('sha256', $key, true);
            $out = '';
            for ($i = 0; $i < strlen($raw); $i++) {
                $out .= $raw[$i] ^ $mask[$i % strlen($mask)];
            }
            return $out;
        }

        return null;
    }

    private static function buildStateFromRemote(string $rootDir, string $email, string $code, string $serverIp, array $payload): array
    {
        $now = time();
        return array(
            'version' => 1,
            'license_key_id' => (string) ($payload['license_key_id'] ?? ''),
            'status' => (string) ($payload['status'] ?? ''),
            'activated' => (bool) ($payload['activated'] ?? false),
            'first_used_at' => (string) ($payload['first_used_at'] ?? ''),
            'expires_at' => (string) ($payload['expires_at'] ?? ''),
            'last_ok_at' => gmdate('c'),
            'next_audit_at' => self::scheduleNextAudit($now),
            // 为了用户可视化：明文展示（仍受签名保护，任何修改都会触发异常复核）
            'email' => $email,
            'code' => $code,
            'email_enc' => self::encryptField($rootDir, $email),
            'code_enc' => self::encryptField($rootDir, $code),
            'server_ip_enc' => self::encryptField($rootDir, $serverIp),
        );
    }

    private static function scheduleNextAudit(int $now): int
    {
        $days = random_int(1, 3);
        $jitter = random_int(0, 86400);
        return $now + ($days * 86400) + $jitter;
    }

    private static function parseIsoTime(?string $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return ($ts === false) ? null : $ts;
    }

    private static function remoteAuthorize(string $email, string $code, string $serverIp): array
    {
        $body = array(
            'email' => $email,
            'code' => $code,
            'server_ip' => $serverIp,
        );

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return array('http_ok' => false, 'body_ok' => false, 'error' => 'json_encode_failed');
        }

        $headers = array('Content-Type: application/json');
        $timeout = 10;

        // 优先 curl
        if (function_exists('curl_init')) {
            $ch = curl_init(self::hx(self::REMOTE_ENDPOINT_HEX));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                return array('http_ok' => false, 'body_ok' => false, 'error' => 'curl:' . $err, 'http_code' => $httpCode);
            }

            $payload = json_decode($resp, true);
            return array(
                'http_ok' => ($httpCode >= 200 && $httpCode < 600),
                'body_ok' => is_array($payload),
                'http_code' => $httpCode,
                'raw' => $resp,
                'payload' => is_array($payload) ? $payload : null,
            );
        }

        // 兜底：file_get_contents
        $opts = array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ),
        );

        $ctx = stream_context_create($opts);
        $resp = @file_get_contents(self::hx(self::REMOTE_ENDPOINT_HEX), false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\\S+\\s+(\\d+)#', $h, $m)) {
                    $httpCode = (int) $m[1];
                    break;
                }
            }
        }

        if ($resp === false) {
            return array('http_ok' => false, 'body_ok' => false, 'error' => 'fopen_failed', 'http_code' => $httpCode);
        }

        $payload = json_decode($resp, true);
        return array(
            'http_ok' => ($httpCode >= 200 && $httpCode < 600),
            'body_ok' => is_array($payload),
            'http_code' => $httpCode,
            'raw' => $resp,
            'payload' => is_array($payload) ? $payload : null,
        );
    }

    private static function getPrimaryIp(): ?string
    {
        // 纯 PHP：UDP connect 获取本机主出口 IP（不依赖外网可达性）
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock !== false) {
                @socket_connect($sock, '1.1.1.1', 53);
                $addr = null;
                $port = null;
                if (@socket_getsockname($sock, $addr, $port) && is_string($addr) && $addr !== '') {
                    @socket_close($sock);
                    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $addr;
                    }
                }
                @socket_close($sock);
            }
        }

        // 兜底：SERVER_ADDR
        $addr = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $addr;
        }

        return null;
    }

    private static function getRequestIp(): ?string
    {
        // 注意：反代/CF 环境下这里可能不准确，按你接口文档描述属于部署层要修正的问题。
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
        return $ip;
    }

    private static function formatRemoteError(array $remote): string
    {
        $code = isset($remote['http_code']) ? (int) $remote['http_code'] : 0;
        $err = isset($remote['error']) ? (string) $remote['error'] : 'remote_unreachable';
        if ($code > 0) {
            return '远端校验失败（HTTP ' . $code . '）：' . $err;
        }
        return '远端校验失败：' . $err;
    }

    private static function formatBusinessError(array $payload): string
    {
        $biz = isset($payload['code']) ? (string) $payload['code'] : 'UNKNOWN';
        $zh = self::translateBizCodeZh($biz, $payload);
        $en = isset($payload['message']) && is_string($payload['message']) ? (string) $payload['message'] : '';
        if ($en === '') {
            $en = self::translateBizCodeEn($biz, $payload);
        }
        return $zh . ' / ' . $en . ' (' . $biz . ')';
    }

    private static function renderLicensePage(array $check, ?string $error, string $returnTo): void
    {
        $state = isset($check['state']) && is_array($check['state']) ? $check['state'] : null;
        $isAuthed = ($state && self::isStateSane($state) && ($check['ok'] ?? false) === true);

        $title = $isAuthed ? '授权已生效 / Licensed' : '系统授权验证 / License Verification';

        $errorHtml = '';
        if (is_string($error) && $error !== '') {
            $errorHtml = '<div class="lg-alert lg-alert--error">'
                . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
                . '</div>';
        } elseif (($check['ok'] ?? false) === false && isset($check['reason'])) {
            // 非授权状态下：给一个更友好的原因（不暴露内部细节）
            $errorHtml = '<div class="lg-alert lg-alert--info">'
                . htmlspecialchars(self::humanizeReason((string) $check['reason']), ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        $header = ''
            . '<div class="lg-head">'
            . '  <div>'
            . '    <div class="lg-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . '    <div class="lg-sub">此页面为系统中断态 UI，不依赖主题/内容/路由。</div>'
            . '  </div>'
            . '</div>';

        $content = $errorHtml;

        if ($isAuthed) {
            $email = isset($state['email']) ? (string) $state['email'] : '';
            $code = isset($state['code']) ? (string) $state['code'] : '';
            $lid = (string) ($state['license_key_id'] ?? '');
            $status = (string) ($state['status'] ?? '');
            if ($status === 'used') {
                $status = '正常';
            }
            $first = (string) ($state['first_used_at'] ?? '');
            $expires = (string) ($state['expires_at'] ?? '');
            $nextAudit = (int) ($state['next_audit_at'] ?? 0);
            $nextAuditStr = $nextAudit > 0 ? gmdate('c', $nextAudit) : '';

            $content .= ''
                . '<div class="lg-card">'
                . '  <div class="lg-card-title">当前授权信息</div>'
                . '  <div class="lg-grid">'
                . '    <div class="lg-k">邮箱</div><div class="lg-v">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">授权码</div><div class="lg-v">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">授权状态</div><div class="lg-v">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">License ID</div><div class="lg-v">' . htmlspecialchars($lid, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">初次授权时间</div><div class="lg-v">' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">到期时间</div><div class="lg-v">' . htmlspecialchars($expires, ENT_QUOTES, 'UTF-8') . '</div>'
                . '    <div class="lg-k">下次抽检时间</div><div class="lg-v">' . htmlspecialchars($nextAuditStr, ENT_QUOTES, 'UTF-8') . '</div>'
                . '  </div>'
                . '  <div class="lg-actions">'
                . '    <a class="lg-btn" href="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">继续访问</a>'
                . '    <form method="post" action="/?' . self::QUERY_FLAG . '=' . self::QUERY_FLAG_VALUE . '&' . self::QUERY_RETURN . '=' . rawurlencode($returnTo) . '" style="display:inline" onsubmit="return confirm(\'重置后网站将进入受限状态并需要重新授权，确定继续？\\nAfter reset, the site will be locked until re-authorized. Continue?\');">'
                . '      <input type="hidden" name="' . self::FORM_FIELD_ACTION . '" value="' . self::ACTION_RESET . '" />'
                . '      <button class="lg-btn lg-btn--danger" type="submit">重置授权 / Reset</button>'
                . '    </form>'
                . '  </div>'
                . '</div>';
        } else {
            $content .= ''
                . '<div class="lg-card">'
                . '  <div class="lg-card-title">填写授权信息</div>'
                . '  <form method="post" action="/?' . self::QUERY_FLAG . '=' . self::QUERY_FLAG_VALUE . '&' . self::QUERY_RETURN . '=' . rawurlencode($returnTo) . '">'
                . '    <input type="hidden" name="' . self::FORM_FIELD_ACTION . '" value="' . self::ACTION_AUTHORIZE . '" />'
                . '    <label class="lg-label">邮箱（您注册使用的邮箱地址）</label>'
                . '    <input class="lg-input" name="email" type="email" required placeholder="user@example.com" />'
                . '    <label class="lg-label">授权码</label>'
                . '    <input class="lg-input" name="code" type="text" required placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" style="text-transform:uppercase" />'
                . '    <button class="lg-btn lg-btn--primary" type="submit">提交授权 / Authorize</button>'
                . '  </form>'
                . '  <div class="lg-tip">提示：当需要远端校验且远端不可达时，不会放行。</div>'
                . '</div>';
        }

        $footer = ''
            . '<div class="lg-footer">'
            . '  <span>官网：</span><a class="lg-link" href="https://wwppcms.com" target="_blank" rel="noopener">wwppcms.com</a>'
            . '</div>';

        self::renderHtml($title, $header . $content . $footer);
    }

    private static function humanizeReason(string $reason): string
    {
        if ($reason === 'no_state') {
            return '未检测到有效授权信息 / No valid license state found.';
        }
        if ($reason === 'remote_unreachable') {
            return '远端校验不可达，系统已受限 / Remote verification unavailable, site locked.';
        }
        if (strpos($reason, 'remote_denied:') === 0) {
            $biz = substr($reason, strlen('remote_denied:'));
            return self::translateBizCodeZh($biz, array()) . ' / ' . self::translateBizCodeEn($biz, array()) . ' (' . $biz . ')';
        }
        if (strpos($reason, 'abnormal:') === 0) {
            return '检测到本地授权数据异常，已触发复核 / Local license data abnormal; re-validation required.';
        }
        if ($reason === 'reset') {
            return '授权已重置，请重新授权 / License reset; please re-authorize.';
        }
        return '授权检查未通过 / License check failed.';
    }

    private static function translateBizCodeZh(string $biz, array $payload): string
    {
        switch ($biz) {
            case 'AUTH_DENIED':
                return '邮箱或授权码不匹配';
            case 'AUTH_OCCUPIED':
                return '授权码已绑定到其他服务器，无法更换';
            case 'AUTH_EXPIRED':
                return '授权已过期';
            case 'AUTH_BANNED':
                return '授权已被封禁';
            case 'BAD_REQUEST':
                return '请求参数不合法';
            case 'INTERNAL_ERROR':
                return '授权服务异常';
            default:
                return '授权失败';
        }
    }

    private static function translateBizCodeEn(string $biz, array $payload): string
    {
        switch ($biz) {
            case 'AUTH_DENIED':
                return 'Invalid email or license code';
            case 'AUTH_OCCUPIED':
                return 'License code is already bound to another server';
            case 'AUTH_EXPIRED':
                return 'License expired';
            case 'AUTH_BANNED':
                return 'License banned';
            case 'BAD_REQUEST':
                return 'Bad request';
            case 'INTERNAL_ERROR':
                return 'Authorization service error';
            default:
                return 'Authorization failed';
        }
    }

    private static function sanitizeRemoteForHistory(string $rootDir, array $remote): array
    {
        $out = array();
        foreach (array('http_ok', 'body_ok', 'http_code', 'error') as $k) {
            if (isset($remote[$k])) {
                $out[$k] = $remote[$k];
            }
        }

        if (isset($remote['payload']) && is_array($remote['payload'])) {
            $p = $remote['payload'];
            // 保留审计关键字段，但对 IP 字段加密存储
            foreach (array('server_ip', 'bound_server_ip', 'request_ip') as $k) {
                if (isset($p[$k]) && is_string($p[$k]) && $p[$k] !== '') {
                    $p[$k . '_enc'] = self::encryptField($rootDir, (string) $p[$k]);
                    unset($p[$k]);
                }
            }
            // 不保存 raw 响应，避免泄露更多信息
            $out['payload'] = $p;
        }

        return $out;
    }

    private static function renderHtml(string $title, string $bodyHtml): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html>'
            . '<html lang="zh-CN">'
            . '<head>'
            . '<meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . $t . '</title>'
            . '<style>'
            . '  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;}'
            . '  .lg-wrap{width:100%;max-width:860px;}'
            . '  .lg-panel{border:1px solid #ddd;border-radius:8px;padding:18px;box-sizing:border-box;}'
            . '  .lg-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}'
            . '  .lg-title{font-size:18px;font-weight:700;}'
            . '  .lg-sub{font-size:12px;line-height:1.5;margin-top:6px;}'
            . '  .lg-link{text-decoration:underline;font-size:14px;}'
            . '  .lg-card{border:1px solid #ddd;border-radius:8px;padding:14px;margin-top:12px;}'
            . '  .lg-card-title{font-weight:650;font-size:13px;margin-bottom:10px;}'
            . '  .lg-label{display:block;font-size:12px;margin:10px 0 6px;}'
            . '  .lg-input{width:100%;padding:10px 12px;border-radius:6px;border:1px solid #ccc;outline:none;box-sizing:border-box;}'
            . '  .lg-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:6px;border:1px solid #ccc;background:transparent;text-decoration:none;cursor:pointer;font-size:13px;}'
            . '  .lg-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}'
            . '  .lg-tip{margin-top:12px;font-size:12px;}'
            . '  .lg-grid{display:grid;grid-template-columns:140px 1fr;gap:8px 10px;font-size:13px;}'
            . '  .lg-v{word-break:break-all;}'
            . '  .lg-alert{border-radius:8px;padding:12px;border:1px solid #ddd;margin-top:12px;font-size:13px;line-height:1.55;}'
            . '  .lg-footer{margin-top:16px;padding-top:12px;border-top:1px solid #ddd;text-align:center;font-size:16px;}'
            . '</style>'
            . '</head>'
            . '<body>'
            . '<div class="lg-wrap">'
            . '<div class="lg-panel">'
            . $bodyHtml
            . '</div>'
            . '</div>'
            . '</body>'
            . '</html>';
    }
}
