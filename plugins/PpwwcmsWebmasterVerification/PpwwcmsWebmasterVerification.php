<?php
/**
 * PpwwcmsWebmasterVerification - injects webmaster verification meta tags without touching Twig.
 * - Reads codes from config.yml under `webmaster_verification`.
 * - Skips error/404, noindex, draft/private pages.
 * - Avoids duplicate tags if already present in the output.
 */
class PpwwcmsWebmasterVerification extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /** @var array */
    protected $tags = array();

    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        $this->tags = array();

        $meta = isset($twigVariables['meta']) ? $twigVariables['meta'] : array();
        $current = isset($twigVariables['current_page']) ? $twigVariables['current_page'] : array();
        $config = $this->getConfig('webmaster_verification', array());

        $baseUrl = rtrim($this->getBaseUrl(), '/');

        // Determine primary host; if uncertain, stay silent (no injection)
        $primaryHost = $this->determinePrimaryHost($config, $baseUrl);
        if ($primaryHost === null) {
            return;
        }

        // Only inject on primary host's homepage
        $requestHost = $this->detectRequestHost($current, $baseUrl);
        if ($requestHost === '' || $requestHost !== $primaryHost) {
            return;
        }
        if (!$this->isHomePage($current, $baseUrl)) {
            return;
        }

        // Skip non-indexable pages
        if ($this->isErrorPage($templateName, $meta, $current) || $this->isNoindexPage($meta) || $this->isSkipped($current)) {
            return;
        }

        if (!is_array($config)) {
            return;
        }

        $map = array(
            'google' => 'google-site-verification',
            'bing' => 'msvalidate.01',
            'yandex' => 'yandex-verification',
            'baidu' => 'baidu-site-verification',
            'pinterest' => 'p:domain_verify'
        );

        foreach ($map as $cfgKey => $metaName) {
            if (!isset($config[$cfgKey]) || $config[$cfgKey] === '') {
                continue;
            }
            $code = (string) $config[$cfgKey];
            if ($code === '') {
                continue;
            }
            $this->tags[] = array('name' => $metaName, 'content' => $code);
        }
    }

    public function onPageRendered(&$output)
    {
        if (empty($this->tags)) {
            return;
        }
        $headClose = stripos($output, '</head>');
        if ($headClose === false) {
            return;
        }

        // Prefer insertion right after <meta charset> if present; otherwise before </head>
        $insertPos = $this->findAfterMetaCharset($output, $headClose);

        $lines = array();
        foreach ($this->tags as $tag) {
            $name = $tag['name'];
            $content = $tag['content'];
            if ($content === '') {
                continue;
            }
            if ($this->hasMetaTag($output, $name)) {
                continue; // do not duplicate
            }
            $lines[] = '    <meta name="' . $this->escape($name) . '" content="' . $this->escape($content) . '" />';
        }

        if (empty($lines)) {
            return;
        }

        $injection = "\n" . implode("\n", $lines) . "\n";
        $output = substr($output, 0, $insertPos) . $injection . substr($output, $insertPos);
    }

    protected function hasMetaTag($output, $name)
    {
        $pattern = '/<meta[^>]+name=[\"\']' . preg_quote($name, '/') . '[\"\'][^>]*>/i';
        return (bool) preg_match($pattern, $output);
    }

    protected function isErrorPage($templateName, array $meta, array $current)
    {
        $tpl = strtolower((string)$templateName);
        if (strpos($tpl, '404') !== false || strpos($tpl, 'error') !== false) {
            return true;
        }
        if (isset($meta['http_status']) && (int)$meta['http_status'] === 404) {
            return true;
        }
        if (isset($meta['status_code']) && (int)$meta['status_code'] === 404) {
            return true;
        }
        $id = isset($current['id']) ? strtolower((string)$current['id']) : '';
        if ($id === '404' || $id === '_404' || $id === 'error' || $id === '_error') {
            return true;
        }
        return false;
    }

    protected function isNoindexPage(array $meta)
    {
        if (!isset($meta['robots'])) {
            return false;
        }
        $robots = strtolower((string)$meta['robots']);
        return strpos($robots, 'noindex') !== false;
    }

    protected function isSkipped(array $current)
    {
        $id = isset($current['id']) ? strtolower((string)$current['id']) : '';
        if ($id === '') {
            return false; // no id info, treat as normal
        }
        if ($id === '_draft' || $id === '_private') {
            return true;
        }
        if (strpos($id, '/_draft') !== false || strpos($id, '/_private') !== false) {
            return true;
        }
        return false;
    }

    protected function escape($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }

    protected function findAfterMetaCharset($output, $headClosePos)
    {
        // Find <meta charset=...> and insert right after it to ensure early priority
        $pattern = '/<meta\s+charset=["\'][^"\']+["\'][^>]*>/i';
        if (preg_match($pattern, $output, $m, PREG_OFFSET_CAPTURE)) {
            $match = $m[0];
            return $match[1] + strlen($match[0]);
        }
        // fallback: before </head>
        return $headClosePos;
    }

    protected function determinePrimaryHost($config, $baseUrl)
    {
        $host = null;

        if (is_array($config) && isset($config['primary_host']) && trim((string)$config['primary_host']) !== '') {
            $host = $this->extractHost((string)$config['primary_host']);
        }

        if ($host === null) {
            $host = $this->extractHost($baseUrl);
        }

        // If still unknown, remain silent to avoid wrong host injection
        return $host;
    }

    protected function detectRequestHost(array $current, $baseUrl)
    {
        // Prefer HTTP_HOST; fallback to current page URL host; last fallback base URL host
        if (!empty($_SERVER['HTTP_HOST'])) {
            return strtolower($_SERVER['HTTP_HOST']);
        }

        if (!empty($current['url'])) {
            $host = $this->extractHost($current['url']);
            if ($host !== null) {
                return $host;
            }
        }

        $host = $this->extractHost($baseUrl);
        return $host !== null ? $host : '';
    }

    protected function isHomePage(array $current, $baseUrl)
    {
        $id = isset($current['id']) ? strtolower((string)$current['id']) : '';
        $url = isset($current['url']) ? (string)$current['url'] : '';
        $base = rtrim($baseUrl, '/');

        if ($id === 'index' || $id === '') {
            return true;
        }

        if ($url !== '') {
            $normalized = rtrim($url, '/');
            if ($normalized === $base) {
                return true;
            }
        }

        return false;
    }

    protected function extractHost($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        // If it's a bare host without scheme, parse manually
        if (strpos($value, '://') === false) {
            $hostOnly = $value;
        } else {
            $parts = parse_url($value);
            $hostOnly = isset($parts['host']) ? $parts['host'] : null;
        }

        if ($hostOnly === null || $hostOnly === '') {
            return null;
        }

        return strtolower($hostOnly);
    }
}
