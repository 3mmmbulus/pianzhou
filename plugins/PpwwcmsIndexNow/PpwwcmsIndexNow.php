<?php
/**
 * PpwwcmsIndexNow -主动推送 URL 到 IndexNow。
 * - 不改 Twig，仅用插件钩子。
 * - 变更检测：文件 mtime 去重 + 最小间隔。
 * - 推送失败不影响页面渲染；记录日志在插件 logs/ 下。
 */
class PpwwcmsIndexNow extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /** @var string */
    protected $logDir;
    /** @var string */
    protected $cacheFile;
    /** @var array */
    protected $cache = array();
    /** @var string */
    protected $metaKey = '__meta';

    public function onPluginsLoaded()
    {
        $this->logDir = dirname(__FILE__) . '/logs/';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        $this->cacheFile = $this->logDir . 'indexnow_cache.json';
        $this->cache = $this->loadCache();
    }

    /**
     * 页面渲染时检查并触发推送。
     */
    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        // 仅在启用时工作
        $enabled = $this->getPluginConfig('enabled', true);
        if (!$enabled) {
            return;
        }

        $meta = isset($twigVariables['meta']) ? $twigVariables['meta'] : array();
        $current = isset($twigVariables['current_page']) ? $twigVariables['current_page'] : array();

        $baseUrl = rtrim($this->getBaseUrl(), '/');

        // 初始化推送（仅执行一次，受 min_interval 控制）
        $this->ensureInitPush($baseUrl);

        // 跳过草稿/私有页
        $id = isset($current['id']) ? $current['id'] : '';
        if ($this->isSkipped($id)) {
            return;
        }

        // 跳过 noindex 页面
        if ($this->isNoindex($meta)) {
            return;
        }

        $url = isset($current['url']) ? $current['url'] : null;
        $file = isset($current['file_path']) ? $current['file_path'] : null;
        if (!$file) {
            $file = $this->resolveContentPath(isset($current['id']) ? $current['id'] : null);
        }
        if (!$url || !$file) {
            return;
        }

        $url = $this->normalizeUrl($url, $baseUrl);
        $mtime = @filemtime($file);
        if ($mtime === false) {
            return;
        }

        $host = $this->detectHost($url);
        $endpoint = $this->getPluginConfig('endpoint', 'https://api.indexnow.org/indexnow');
        $key = $this->getPluginConfig('key', null);
        $minInterval = (int) $this->getPluginConfig('min_interval', 3600);

        if (empty($key) || empty($host)) {
            return;
        }

        // 检查最小间隔与 mtime 去重
        $last = isset($this->cache[$url]) ? $this->cache[$url] : null;
        if ($last && isset($last['mtime']) && $last['mtime'] == $mtime) {
            return; // 内容未变化
        }
        $now = time();
        if ($last && isset($last['pushed_at']) && ($now - (int)$last['pushed_at']) < $minInterval) {
            return; // 间隔未到
        }

        // 异步化不是必需，这里同步，失败不抛异常
        $ok = $this->pushIndexNow($endpoint, $host, $key, array($url));

        // 更新缓存与日志，无论成功与否都记录
        $this->cache[$url] = array(
            'mtime' => $mtime,
            'pushed_at' => $ok ? $now : (isset($last['pushed_at']) ? $last['pushed_at'] : 0),
            'last_try' => $now,
            'last_status' => $ok ? 'success' : 'failed'
        );
        $this->saveCache();
        $this->log(($ok ? '[OK] ' : '[FAIL] ') . $url . ' mtime=' . $mtime . ' host=' . $host . ' endpoint=' . $endpoint . ' key=' . $this->maskKey($key));
    }

    /**
     * 推送请求，失败不抛异常。
     */
    protected function pushIndexNow($endpoint, $host, $key, array $urls)
    {
        $payload = array(
            'host' => $host,
            'key' => $key,
            'urlList' => $urls
        );

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $resp = curl_exec($ch);
        $err = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== 0) {
            $this->log('[ERR] cURL err=' . $err . ' url=' . $endpoint . ' host=' . $host);
            return false;
        }
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log('[HTTP] code=' . $code . ' url=' . $endpoint . ' host=' . $host . ' body=' . substr((string)$resp, 0, 200));
        return false;
    }

    protected function detectHost($url)
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'])) {
            return null;
        }
        return $parts['host'];
    }

    protected function isSkipped($id)
    {
        if ($id === null) {
            return true;
        }
        $lower = strtolower($id);
        if ($lower === '_draft' || $lower === '_private') {
            return true;
        }
        if (strpos($lower, '/_draft') !== false || strpos($lower, '/_private') !== false) {
            return true;
        }
        return false;
    }

    protected function loadCache()
    {
        if (!is_file($this->cacheFile)) {
            return array();
        }
        $json = @file_get_contents($this->cacheFile);
        if ($json === false) {
            return array();
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : array();
    }

    protected function saveCache()
    {
        @file_put_contents($this->cacheFile, json_encode($this->cache));
    }

    protected function log($line)
    {
        $msg = '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
        @file_put_contents($this->logDir . 'indexnow.log', $msg, FILE_APPEND);
    }

    protected function resolveContentPath($id)
    {
        if ($id === null || $id === '') {
            return null;
        }
        $contentDir = rtrim($this->getConfig('content_dir'), '/');
        if ($contentDir === '') {
            return null;
        }
        $ext = $this->getConfig('content_ext');
        if (!$ext) {
            $ext = '.md';
        }
        if ($ext[0] !== '.') {
            $ext = '.' . $ext;
        }
        $path = $contentDir . '/' . $id . $ext;
        if (is_file($path)) {
            return $path;
        }
        return null;
    }

    protected function maskKey($key)
    {
        if (!$key) {
            return '';
        }
        if (strlen($key) <= 6) {
            return '***';
        }
        return substr($key, 0, 3) . '***' . substr($key, -3);
    }

    protected function normalizeUrl($url, $baseUrl)
    {
        $parts = parse_url($url);
        if ($parts && isset($parts['host'])) {
            return $url; // already absolute
        }

        // query-style (?sub/1)
        if (strpos($url, '?') === 0) {
            return $baseUrl . '/' . $url;
        }

        // relative path
        return $baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * 初始推送一次首页（或配置的 init_urls），受 min_interval 控制。
     */
    protected function ensureInitPush($baseUrl)
    {
        $key = $this->getPluginConfig('key', null);
        if (empty($key)) {
            return;
        }

        $endpoint = $this->getPluginConfig('endpoint', 'https://api.indexnow.org/indexnow');
        $minInterval = (int) $this->getPluginConfig('min_interval', 3600);

        $initState = isset($this->cache['__init']) ? $this->cache['__init'] : null;
        $now = time();
        if ($initState && isset($initState['pushed_at']) && ($now - (int)$initState['pushed_at']) < $minInterval) {
            return;
        }

        $initUrls = $this->getPluginConfig('init_urls', array($baseUrl . '/'));
        if (!is_array($initUrls)) {
            $initUrls = array($initUrls);
        }

        $urls = array();
        foreach ($initUrls as $u) {
            $u = trim((string)$u);
            if ($u === '') {
                continue;
            }
            $urls[] = $this->normalizeUrl($u, $baseUrl);
        }
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return;
        }

        $host = $this->detectHost($urls[0]);
        if (empty($host)) {
            return;
        }

        $ok = $this->pushIndexNow($endpoint, $host, $key, $urls);

        $this->cache['__init'] = array(
            'pushed_at' => $ok ? $now : (isset($initState['pushed_at']) ? $initState['pushed_at'] : 0),
            'last_try' => $now,
            'last_status' => $ok ? 'success' : 'failed'
        );
        $this->saveCache();
        $this->log(($ok ? '[INIT OK] ' : '[INIT FAIL] ') . implode(',', $urls) . ' host=' . $host . ' endpoint=' . $endpoint . ' key=' . $this->maskKey($key));
    }

    /**
     * 检查页面元数据是否设置 noindex。
     */
    protected function isNoindex($meta)
    {
        if (!is_array($meta)) {
            return false;
        }

        if (isset($meta['noindex']) && $meta['noindex']) {
            return true;
        }

        if (isset($meta['robots'])) {
            $robots = $meta['robots'];
            if (is_array($robots)) {
                $robots = implode(',', $robots);
            }
            $robots = strtolower((string)$robots);
            if (strpos($robots, 'noindex') !== false) {
                return true;
            }
        }

        if (isset($meta['x-robots-tag'])) {
            $robots = strtolower((string)$meta['x-robots-tag']);
            if (strpos($robots, 'noindex') !== false) {
                return true;
            }
        }

        return false;
    }
}
