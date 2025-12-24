<?php
/**
 * Ppwwcms OG plugin - inject Open Graph meta tags via onMetaHeaders/onPageRendered
 */
class PpwwcmsOg extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /** @var array|null */
    protected $ogData = null;
    /** @var array|null */
    protected $twitterData = null;
    /** @var string|null */
    protected $robotsMeta = null;
    /** @var array|null */
    protected $articleMeta = null;

    /**
     * Register the `og` meta block so front-matter `og:*` is parsed.
     */
    public function onMetaHeaders(array &$headers)
    {
        $headers['og'] = 'og';

        // Also accept flat keys like `og_title` for convenience
        $headers['og_title'] = 'og_title';
        $headers['og_description'] = 'og_description';
        $headers['og_url'] = 'og_url';
        $headers['og_type'] = 'og_type';
        $headers['og_site_name'] = 'og_site_name';
        $headers['og_image'] = 'og_image';
        $headers['og_locale'] = 'og_locale';
        $headers['og_image_width'] = 'og_image_width';
        $headers['og_image_height'] = 'og_image_height';

        // Article-specific fields
        $headers['article'] = 'article';
        $headers['article_published_time'] = 'article_published_time';
        $headers['article_modified_time'] = 'article_modified_time';
        $headers['article_author'] = 'article_author';
        $headers['article_section'] = 'article_section';
        $headers['article_tag'] = 'article_tag';

        // Twitter card support
        $headers['twitter'] = 'twitter';
        $headers['twitter_card'] = 'twitter_card';
        $headers['twitter_title'] = 'twitter_title';
        $headers['twitter_description'] = 'twitter_description';
        $headers['twitter_image'] = 'twitter_image';

        // Robots fallback if theme omits it
        $headers['robots'] = 'robots';
    }

    /**
     * Build OG data during rendering so it is ready for injection.
     */
    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        // reset per-request state
        $this->ogData = null;
        $this->twitterData = null;
        $this->robotsMeta = null;
        $this->articleMeta = null;

        $meta = isset($twigVariables['meta']) ? $twigVariables['meta'] : array();
        $current = isset($twigVariables['current_page']) ? $twigVariables['current_page'] : array();
        $configOg = $this->getPluginConfig('og', array());
        $configTwitter = $this->getPluginConfig('twitter', array());
        $configArticle = $this->getPluginConfig('article', array());
        $configRobots = $this->getPluginConfig('robots', null);

        // Skip OG/Twitter/robots on error/404 pages or explicitly noindex pages
        if ($this->isErrorPage($templateName, $meta, $current) || $this->isNoindexPage($meta)) {
            return;
        }

        $siteTitle = $this->getConfig('site_title');
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        $currentUrl = isset($current['url']) ? $current['url'] : $baseUrl . '/';

        $metaOg = $this->normalizeMetaOg($meta);
        $metaTwitter = $this->normalizeTwitterMeta($meta);
        $metaArticle = $this->normalizeArticleMeta($meta);

        $titleFallback = isset($meta['title']) ? $meta['title'] : $siteTitle;
        $descriptionFallback = isset($meta['description']) ? $meta['description'] : '';

        $ogImage = $this->pickValue($metaOg, $configOg, 'image', null);
        if ($ogImage === null || $ogImage === '') {
            $ogImage = $baseUrl . '/assets/og-logo.png';
        }

        $this->ogData = array(
            'title' => $this->pickValue($metaOg, $configOg, 'title', $titleFallback),
            'description' => $this->pickValue($metaOg, $configOg, 'description', $descriptionFallback),
            'url' => $this->pickValue($metaOg, $configOg, 'url', $currentUrl),
            'type' => $this->pickValue($metaOg, $configOg, 'type', 'website'),
            'site_name' => $this->pickValue($metaOg, $configOg, 'site_name', $siteTitle),
            'image' => $ogImage,
            'locale' => $this->pickValue($metaOg, $configOg, 'locale', null),
            'image_width' => $this->pickValue($metaOg, $configOg, 'image_width', null),
            'image_height' => $this->pickValue($metaOg, $configOg, 'image_height', null)
        );

        $this->articleMeta = $this->buildArticleMeta($metaArticle, $configArticle, $meta);

        $twitterImage = $this->pickValue($metaTwitter, $configTwitter, 'image', $this->ogData['image']);
        if ($twitterImage === null || $twitterImage === '') {
            $twitterImage = $this->ogData['image'];
        }

        $this->twitterData = array(
            'card' => $this->pickValue($metaTwitter, $configTwitter, 'card', 'summary_large_image'),
            'title' => $this->pickValue($metaTwitter, $configTwitter, 'title', $this->ogData['title']),
            'description' => $this->pickValue($metaTwitter, $configTwitter, 'description', $this->ogData['description']),
            'image' => $twitterImage
        );

        $robotsMeta = null;
        if (isset($meta['robots']) && $meta['robots'] !== '') {
            $robotsMeta = $meta['robots'];
        } elseif ($configRobots !== null && $configRobots !== '') {
            $robotsMeta = $configRobots;
        } else {
            $robotsMeta = 'index,follow';
        }
        $this->robotsMeta = $robotsMeta;
    }

    /**
     * Inject <meta property="og:*"> tags before </head> after the page is rendered.
     */
    public function onPageRendered(&$output)
    {
        if (stripos($output, '</head>') === false) {
            return;
        }

        $lines = array();

        if (!empty($this->ogData)) {
            $this->appendProperty($lines, $output, 'og:title', $this->ogData['title']);
            $this->appendProperty($lines, $output, 'og:description', $this->ogData['description']);
            $this->appendProperty($lines, $output, 'og:url', $this->ogData['url']);
            $this->appendProperty($lines, $output, 'og:type', $this->ogData['type']);
            $this->appendProperty($lines, $output, 'og:site_name', $this->ogData['site_name']);
            $this->appendProperty($lines, $output, 'og:image', $this->ogData['image']);
            $this->appendProperty($lines, $output, 'og:locale', $this->ogData['locale']);
            $this->appendProperty($lines, $output, 'og:image:width', $this->ogData['image_width']);
            $this->appendProperty($lines, $output, 'og:image:height', $this->ogData['image_height']);
        }

        if (!empty($this->articleMeta)) {
            $this->appendProperty($lines, $output, 'article:published_time', $this->articleMeta['published_time']);
            $this->appendProperty($lines, $output, 'article:modified_time', $this->articleMeta['modified_time']);
            $this->appendProperty($lines, $output, 'article:author', $this->articleMeta['author']);
            $this->appendProperty($lines, $output, 'article:section', $this->articleMeta['section']);
            foreach ($this->articleMeta['tags'] as $tag) {
                $this->appendProperty($lines, $output, 'article:tag', $tag);
            }
        }

        if (!empty($this->robotsMeta)) {
            $this->appendName($lines, $output, 'robots', $this->robotsMeta);
        }

        if (!empty($this->twitterData)) {
            $this->appendName($lines, $output, 'twitter:card', $this->twitterData['card']);
            $this->appendName($lines, $output, 'twitter:title', $this->twitterData['title']);
            $this->appendName($lines, $output, 'twitter:description', $this->twitterData['description']);
            $this->appendName($lines, $output, 'twitter:image', $this->twitterData['image']);
        }

        if (empty($lines)) {
            return;
        }

        $injection = "\n" . implode("\n", $lines) . "\n";
        $pos = stripos($output, '</head>');
        if ($pos !== false) {
            $output = substr($output, 0, $pos) . $injection . substr($output, $pos);
        }
    }

    protected function normalizeMetaOg(array $meta)
    {
        $og = array();
        if (!empty($meta['og']) && is_array($meta['og'])) {
            $og = $meta['og'];
        }

        // Allow flat keys og_title, og_description, etc.
        $map = array(
            'title' => 'og_title',
            'description' => 'og_description',
            'url' => 'og_url',
            'type' => 'og_type',
            'site_name' => 'og_site_name',
            'image' => 'og_image',
            'locale' => 'og_locale',
            'image_width' => 'og_image_width',
            'image_height' => 'og_image_height'
        );
        foreach ($map as $key => $flatKey) {
            if (!empty($meta[$flatKey])) {
                $og[$key] = $meta[$flatKey];
            }
        }

        return $og;
    }

    protected function normalizeTwitterMeta(array $meta)
    {
        $tw = array();
        if (!empty($meta['twitter']) && is_array($meta['twitter'])) {
            $tw = $meta['twitter'];
        }

        $map = array(
            'card' => 'twitter_card',
            'title' => 'twitter_title',
            'description' => 'twitter_description',
            'image' => 'twitter_image'
        );
        foreach ($map as $key => $flatKey) {
            if (!empty($meta[$flatKey])) {
                $tw[$key] = $meta[$flatKey];
            }
        }

        return $tw;
    }

    protected function normalizeArticleMeta(array $meta)
    {
        $article = array();
        if (!empty($meta['article']) && is_array($meta['article'])) {
            $article = $meta['article'];
        }

        $map = array(
            'published_time' => 'article_published_time',
            'modified_time' => 'article_modified_time',
            'author' => 'article_author',
            'section' => 'article_section',
            'tags' => 'article_tag'
        );
        foreach ($map as $key => $flatKey) {
            if (!empty($meta[$flatKey])) {
                $article[$key] = $meta[$flatKey];
            }
        }

        return $article;
    }

    protected function buildArticleMeta(array $metaArticle, array $configArticle, array $meta)
    {
        $articleTags = $this->normalizeToList($this->pickValue($metaArticle, $configArticle, 'tags', array()));

        return array(
            'published_time' => $this->pickValue($metaArticle, $configArticle, 'published_time', isset($meta['date']) ? $meta['date'] : null),
            'modified_time' => $this->pickValue($metaArticle, $configArticle, 'modified_time', isset($meta['time']) ? $meta['time'] : null),
            'author' => $this->pickValue($metaArticle, $configArticle, 'author', isset($meta['author']) ? $meta['author'] : $this->getConfig('site_title')),
            'section' => $this->pickValue($metaArticle, $configArticle, 'section', null),
            'tags' => $articleTags
        );
    }

    protected function normalizeToList($value)
    {
        if ($value === null || $value === '') {
            return array();
        }
        if (is_array($value)) {
            return array_values(array_filter($value, function ($item) {
                return $item !== null && $item !== '';
            }));
        }
        $parts = explode(',', $value);
        $trimmed = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $trimmed[] = $part;
            }
        }
        return $trimmed;
    }

    protected function pickValue(array $metaValues, array $configValues, $key, $fallback)
    {
        if (isset($metaValues[$key]) && $metaValues[$key] !== '') {
            return $metaValues[$key];
        }
        if (isset($configValues[$key]) && $configValues[$key] !== '') {
            return $configValues[$key];
        }
        return $fallback;
    }

    protected function escape($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }

    protected function appendProperty(array &$lines, $output, $property, $value)
    {
        if ($value === null || $value === '') {
            return;
        }
        if (stripos($output, 'property="' . $property . '"') !== false) {
            return;
        }
        $lines[] = '    <meta property="' . $property . '" content="' . $this->escape($value) . '" />';
    }

    protected function appendName(array &$lines, $output, $name, $value)
    {
        if ($value === null || $value === '') {
            return;
        }
        if (stripos($output, 'name="' . $name . '"') !== false) {
            return;
        }
        $lines[] = '    <meta name="' . $name . '" content="' . $this->escape($value) . '" />';
    }

    protected function isErrorPage($templateName, array $meta, array $current)
    {
        // Detect via template name, explicit meta status, or common id markers
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
}
