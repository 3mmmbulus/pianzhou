<?php
/**
 * PpwwcmsNewsList - Auto-generate news index listing with thumbnail and pagination.
 * - No Twig changes required; overrides content for news index page.
 * - Collects child pages under news/ (except news/index) from existing page tree.
 * - Shows first image (thumbnail) from page meta image/thumbnail or first <img> in content.
 * - Supports pagination via ?page=N (or ?p=N), per_page configurable.
 */
class PpwwcmsNewsList extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        $current = isset($twigVariables['current_page']) ? $twigVariables['current_page'] : array();
        $currentId = isset($current['id']) ? $current['id'] : '';

        $config = $this->getPluginConfig(null, array());
        $section = isset($config['section']) && $config['section'] ? trim($config['section'], '/') : 'news';
        $sectionIndexIds = array($section, $section . '/index');

        // Only act on news index page
        if (!in_array($currentId, $sectionIndexIds, true)) {
            return;
        }

        $pages = isset($twigVariables['pages']) ? $twigVariables['pages'] : array();
        if (empty($pages)) {
            return;
        }
        $perPage = isset($config['per_page']) ? max(1, (int)$config['per_page']) : 10;
        $excerptLen = isset($config['excerpt_len']) ? max(10, (int)$config['excerpt_len']) : 120;

        $baseUrl = rtrim($this->getBaseUrl(), '/');

        $newsItems = $this->collectNews($pages, $baseUrl, $section, $excerptLen);
        if (empty($newsItems)) {
            return;
        }

        // Pagination
        $pageNum = 1;
        if (isset($_GET['page'])) {
            $pageNum = (int) $_GET['page'];
        } elseif (isset($_GET['p'])) {
            $pageNum = (int) $_GET['p'];
        }
        if ($pageNum < 1) {
            $pageNum = 1;
        }

        $total = count($newsItems);
        $totalPages = (int)ceil($total / $perPage);
        $pageNum = min($pageNum, $totalPages);
        $offset = ($pageNum - 1) * $perPage;
        $pageSlice = array_slice($newsItems, $offset, $perPage);

        $html = $this->renderList($pageSlice, $pageNum, $totalPages, $current, $baseUrl);

        // Override content with generated list, ensure not escaped by Twig
        if (class_exists('\\Twig\\Markup')) {
            $twigVariables['content'] = new \Twig\Markup($html, 'UTF-8');
        } else {
            $twigVariables['content'] = $html;
        }
    }

    protected function collectNews(array $pages, $baseUrl, $section, $excerptLen)
    {
        $items = array();
        foreach ($pages as $page) {
            $id = isset($page['id']) ? $page['id'] : '';
            if ($id === $section || $id === $section . '/index') {
                continue;
            }
            if (strpos($id, $section . '/') !== 0) {
                continue;
            }
            if (!empty($page['hidden'])) {
                continue;
            }

            $meta = isset($page['meta']) ? $page['meta'] : array();
            // Skip drafts/private similar to other plugins
            $lowerId = strtolower($id);
            if ($lowerId === '_draft' || $lowerId === '_private' || strpos($lowerId, '/_draft') !== false || strpos($lowerId, '/_private') !== false) {
                continue;
            }

            $title = isset($page['title']) ? $page['title'] : (isset($meta['title']) ? $meta['title'] : '');
            $description = isset($meta['description']) ? $meta['description'] : '';
            $url = isset($page['url']) ? $page['url'] : '';
            $time = $this->resolveDate($meta, $page, $id);
            $thumb = $this->resolveThumb($meta, isset($page['content']) ? $page['content'] : '', $baseUrl, $url, $id);
            if ($description === '') {
                $description = $this->makeExcerpt(isset($page['content']) ? $page['content'] : '', $excerptLen);
            }

            $items[] = array(
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'url' => $url,
                'time' => $time,
                'thumb' => $thumb
            );
        }

        // Sort by time desc
        usort($items, function ($a, $b) {
            if ($a['time'] == $b['time']) {
                return 0;
            }
            return ($a['time'] > $b['time']) ? -1 : 1;
        });

        return $items;
    }

    protected function resolveDate(array $meta, array $page, $id)
    {
        if (isset($meta['date']) && $meta['date']) {
            $ts = strtotime($meta['date']);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (isset($meta['time']) && $meta['time']) {
            $ts = strtotime($meta['time']);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (isset($page['date']) && $page['date']) {
            $ts = strtotime($page['date']);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (isset($page['modified']) && $page['modified']) {
            $ts = strtotime($page['modified']);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (isset($page['time']) && $page['time']) {
            $ts = strtotime($page['time']);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $id, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false) {
                return $ts;
            }
        }
        if (isset($page['file_path']) && is_file($page['file_path'])) {
            $ts = @filemtime($page['file_path']);
            if ($ts !== false) {
                return $ts;
            }
        }
        // fallback stable ordering
        return 1;
    }

    protected function resolveThumb(array $meta, $contentHtml, $baseUrl, $pageUrl, $pageId)
    {
        if (isset($meta['image']) && $meta['image'] !== '') {
            return $this->absoluteUrl($meta['image'], $baseUrl);
        }
        if (isset($meta['thumbnail']) && $meta['thumbnail'] !== '') {
            return $this->absoluteUrl($meta['thumbnail'], $baseUrl);
        }

        // Try to find first <img> in content
        if ($contentHtml) {
            if (preg_match('/<img[^>]+(data-src|src)=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
                return $this->relativeToPageUrl($m[2], $baseUrl, $pageUrl, $pageId);
            }
        }

        return '';
    }

    protected function renderList(array $items, $pageNum, $totalPages, array $current, $baseUrl)
    {
        // Allow theme override: if partial exists, render via Twig
        $partial = 'partials/news_list.twig';
        try {
            $twig = $this->getWwppcms()->getTwig();
            if ($twig && $twig->getLoader() && $twig->getLoader()->exists($partial)) {
                return $twig->render($partial, array(
                    'ppww_items' => $items,
                    'ppww_page' => $pageNum,
                    'ppww_total_pages' => $totalPages,
                    'ppww_base' => isset($current['url']) ? $current['url'] : ($baseUrl . '/news')
                ));
            }
        } catch (\Exception $e) {
            // fallback to default HTML
        }

        $html = "<div class=\"ppww-news-list\">\n";
        foreach ($items as $item) {
            $html .= "  <article class=\"ppww-news-item\">\n";
            if ($item['thumb'] !== '') {
                $html .= "    <a class=\"ppww-thumb\" href=\"" . $this->escape($item['url']) . "\"><img src=\"" . $this->escape($item['thumb']) . "\" alt=\"" . $this->escape($this->limit($item['title'], 120)) . "\" /></a>\n";
            }
            $html .= "    <h2><a href=\"" . $this->escape($item['url']) . "\">" . $this->escape($item['title']) . "</a></h2>\n";
            if (!empty($item['description'])) {
                $html .= "    <p class=\"ppww-desc\">" . $this->escape($item['description']) . "</p>\n";
            }
            if (!empty($item['time'])) {
                $html .= "    <time class=\"ppww-date\" datetime=\"" . date('c', $item['time']) . "\">" . date('Y-m-d', $item['time']) . "</time>\n";
            }
            $html .= "  </article>\n";
        }
        $html .= "</div>\n";

        // Pagination controls
        if ($totalPages > 1) {
            $base = isset($current['url']) ? rtrim($current['url'], '/') : ($baseUrl . '/news');
            $html .= '<nav class="ppww-pagination">';
            if ($pageNum > 1) {
                $html .= '<a class="ppww-prev" href="' . $this->escape($this->buildPageUrl($base, $pageNum - 1)) . '" rel="prev">上一页</a>';
            }
            $html .= '<span class="ppww-page-info">第 ' . $pageNum . ' / ' . $totalPages . ' 页</span>';
            if ($pageNum < $totalPages) {
                $html .= '<a class="ppww-next" href="' . $this->escape($this->buildPageUrl($base, $pageNum + 1)) . '" rel="next">下一页</a>';
            }
            $html .= '</nav>';
        }

        return $html;
    }

    protected function absoluteUrl($url, $baseUrl)
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (strpos($url, '/') === 0) {
            return rtrim($baseUrl, '/') . $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    protected function relativeToPageUrl($url, $baseUrl, $pageUrl, $pageId)
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if ($url[0] === '/') {
            return rtrim($baseUrl, '/') . $url;
        }

        // derive directory from pageUrl or pageId
        $dir = '/';
        if ($pageUrl) {
            $parts = parse_url($pageUrl);
            if ($parts && isset($parts['path'])) {
                $dir = rtrim(dirname($parts['path']), '/');
                if ($dir === '') {
                    $dir = '/';
                }
            }
        } elseif ($pageId) {
            $dir = '/' . trim(dirname($pageId), '/');
            if ($dir === '/.') {
                $dir = '/';
            }
        }

        if (strpos($url, './') === 0) {
            $url = substr($url, 2);
        }

        return rtrim($baseUrl, '/') . rtrim($dir, '/') . '/' . ltrim($url, '/');
    }

    protected function makeExcerpt($html, $len)
    {
        if ($html === '') {
            return '';
        }
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $len) {
                return mb_substr($text, 0, $len, 'UTF-8') . '…';
            }
            return $text;
        }
        if (strlen($text) > $len) {
            return substr($text, 0, $len) . '…';
        }
        return $text;
    }

    protected function buildPageUrl($base, $pageNum)
    {
        $parts = parse_url($base);
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = array();
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        $query['page'] = $pageNum;
        $qs = http_build_query($query);
        $out = $path;
        if ($qs !== '') {
            $out .= '?' . $qs;
        }
        if (isset($parts['scheme']) && isset($parts['host'])) {
            $out = $parts['scheme'] . '://' . $parts['host'] . $out;
        }
        return $out;
    }

    protected function limit($value, $len)
    {
        if (function_exists('mb_strlen')) {
            if (mb_strlen($value, 'UTF-8') > $len) {
                return mb_substr($value, 0, $len, 'UTF-8');
            }
            return $value;
        }
        if (strlen($value) > $len) {
            return substr($value, 0, $len);
        }
        return $value;
    }

    protected function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8', false);
    }
}
