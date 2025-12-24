<?php
/**
 * PpwwcmsSchemaLd - auto inject JSON-LD (Schema.org) without touching Twig.
 * Uses onPageRendering to build data, onPageRendered to inject <script type="application/ld+json">.
 */
class PpwwcmsSchemaLd extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /** @var array */
    protected $schemas = array();

    /**
     * Decide which schemas to build for the current page.
     */
    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        $this->schemas = array();

        $meta = isset($twigVariables['meta']) ? $twigVariables['meta'] : array();
        $current = isset($twigVariables['current_page']) ? $twigVariables['current_page'] : array();
        $config = $this->getPluginConfig(null, array());

        $siteTitle = $this->getConfig('site_title');
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        $currentUrl = isset($current['url']) ? $current['url'] : $baseUrl . '/';
        $currentId = isset($current['id']) ? $current['id'] : '';

        $isHome = ($currentId === 'index' || $currentUrl === $baseUrl . '/' || $currentUrl === $baseUrl);

        // Build per type according to rules
        if ($isHome) {
            $website = $this->buildWebsite($meta, $config, $siteTitle, $baseUrl);
            if ($website) {
                $this->schemas[] = $website;
            }

            $org = $this->buildOrganization($meta, $config, $siteTitle, $baseUrl);
            if ($org) {
                $this->schemas[] = $org;
            }
        }

        // Breadcrumb applies to non-home pages only.
        if (!$isHome) {
            $breadcrumb = $this->buildBreadcrumb($meta, $config, $currentUrl, $siteTitle, $baseUrl, $currentId);
            if ($breadcrumb) {
                $this->schemas[] = $breadcrumb;
            }
        }

        // Article detection: meta.schema_type/article flag or template hint.
        if ($this->isArticlePage($meta, $templateName)) {
            $article = $this->buildArticle($meta, $config, $currentUrl, $siteTitle);
            if ($article) {
                $this->schemas[] = $article;
            }
        }

        // ItemList detection: meta.schema_type == itemlist or has items list.
        if ($this->isItemListPage($meta)) {
            $itemList = $this->buildItemList($meta, $config, $currentUrl, $siteTitle);
            if ($itemList) {
                $this->schemas[] = $itemList;
            }
        }

        // FAQ detection: meta.schema_type == faqpage or has faq items.
        if ($this->isFaqPage($meta)) {
            $faq = $this->buildFaqPage($meta, $config, $currentUrl, $siteTitle);
            if ($faq) {
                $this->schemas[] = $faq;
            }
        }
    }

    /**
     * Inject JSON-LD scripts before </head>.
     */
    public function onPageRendered(&$output)
    {
        if (empty($this->schemas) || stripos($output, '</head>') === false) {
            return;
        }

        $scripts = array();
        foreach ($this->schemas as $schema) {
            $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                continue;
            }
            $scripts[] = "    <script type=\"application/ld+json\">" . $json . "</script>";
        }

        if (empty($scripts)) {
            return;
        }

        $injection = "\n" . implode("\n", $scripts) . "\n";
        $pos = stripos($output, '</head>');
        $output = substr($output, 0, $pos) . $injection . substr($output, $pos);
    }

    protected function buildWebsite(array $meta, array $config, $siteTitle, $baseUrl)
    {
        $cfg = isset($config['website']) ? $config['website'] : array();
        $name = $this->pick($meta, $cfg, array('schema_website_name', 'website_name', 'name'), $siteTitle);
        $url = $this->pick($meta, $cfg, array('schema_website_url', 'website_url', 'url'), $baseUrl . '/');

        if (!$name || !$url) {
            return null;
        }

        $website = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $url
        );

        $searchUrl = $this->pick($meta, $cfg, array('schema_search_url', 'search_url'), null);
        if ($searchUrl) {
            $website['potentialAction'] = array(
                '@type' => 'SearchAction',
                'target' => $searchUrl,
                'query-input' => 'required name=search_term_string'
            );
        }

        return $website;
    }

    protected function buildOrganization(array $meta, array $config, $siteTitle, $baseUrl)
    {
        $cfg = isset($config['organization']) ? $config['organization'] : array();
        $name = $this->pick($meta, $cfg, array('schema_org_name', 'org_name', 'name'), $siteTitle);
        $url = $this->pick($meta, $cfg, array('schema_org_url', 'org_url', 'url'), $baseUrl . '/');
        if (!$name || !$url) {
            return null;
        }

        $org = array(
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $name,
            'url' => $url
        );

        $logo = $this->pick($meta, $cfg, array('schema_org_logo', 'org_logo', 'logo'), null);
        if ($logo) {
            $org['logo'] = $logo;
        }

        $sameAs = $this->pickList($meta, $cfg, array('schema_org_sameas', 'org_sameas', 'sameAs'), array());
        if (!empty($sameAs)) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
    }

    protected function buildBreadcrumb(array $meta, array $config, $currentUrl, $siteTitle, $baseUrl, $currentId)
    {
        // Prefer explicit breadcrumb from meta, then config. Fallback to home + current page.
        $items = array();
        if (!empty($meta['breadcrumb']) && is_array($meta['breadcrumb'])) {
            $items = $meta['breadcrumb'];
        } elseif (!empty($config['breadcrumb']) && is_array($config['breadcrumb'])) {
            $items = $config['breadcrumb'];
        } else {
            // Minimal but accurate fallback: Home + Current page
            $homeUrl = $baseUrl . '/';
            $items = array(
                array('name' => $siteTitle, 'url' => $homeUrl)
            );
            if ($currentId !== 'index') {
                $items[] = array(
                    'name' => $this->pick($meta, array(), array('title'), $siteTitle),
                    'url' => $currentUrl
                );
            }
        }

        $list = array();
        $pos = 1;
        foreach ($items as $item) {
            if (empty($item['name']) || empty($item['url'])) {
                continue;
            }
            $list[] = array(
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $item['name'],
                'item' => $item['url']
            );
        }

        if (count($list) < 1) {
            return null;
        }

        return array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list
        );
    }

    protected function buildArticle(array $meta, array $config, $currentUrl, $siteTitle)
    {
        $cfg = isset($config['article']) ? $config['article'] : array();

        $title = $this->pick($meta, $cfg, array('schema_article_title', 'article_title', 'title'), null);
        $description = $this->pick($meta, $cfg, array('schema_article_description', 'article_description', 'description'), null);
        $image = $this->pick($meta, $cfg, array('schema_article_image', 'article_image', 'image'), null);

        $datePublished = $this->pick($meta, $cfg, array('schema_article_published', 'article_published', 'date', 'published'), null);
        $dateModified = $this->pick($meta, $cfg, array('schema_article_modified', 'article_modified', 'time', 'modified'), $datePublished);

        $author = $this->pick($meta, $cfg, array('schema_article_author', 'article_author', 'author'), $siteTitle);
        $section = $this->pick($meta, $cfg, array('schema_article_section', 'article_section', 'section', 'category'), null);
        $tags = $this->pickList($meta, $cfg, array('schema_article_tags', 'article_tags', 'tags'), array());

        // Require minimal fields to avoid inaccurate data
        if (!$title || !$currentUrl) {
            return null;
        }

        $article = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'mainEntityOfPage' => $currentUrl,
            'author' => array(
                '@type' => 'Person',
                'name' => $author
            )
        );

        if ($description) {
            $article['description'] = $description;
        }
        if ($image) {
            $article['image'] = $image;
        }
        if ($section) {
            $article['articleSection'] = $section;
        }
        if (!empty($tags)) {
            $article['keywords'] = $tags;
        }
        if ($datePublished) {
            $article['datePublished'] = $datePublished;
        }
        if ($dateModified) {
            $article['dateModified'] = $dateModified;
        }

        return $article;
    }

    protected function buildItemList(array $meta, array $config, $currentUrl, $siteTitle)
    {
        $cfg = isset($config['itemList']) ? $config['itemList'] : array();
        $items = array();
        if (!empty($meta['itemList']) && is_array($meta['itemList'])) {
            $items = $meta['itemList'];
        } elseif (!empty($meta['items']) && is_array($meta['items'])) {
            $items = $meta['items'];
        } elseif (!empty($cfg['items']) && is_array($cfg['items'])) {
            $items = $cfg['items'];
        }

        $elements = array();
        $pos = 1;
        foreach ($items as $item) {
            if (empty($item['name']) || empty($item['url'])) {
                continue;
            }
            $elements[] = array(
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $item['name'],
                'url' => $item['url']
            );
        }

        if (empty($elements)) {
            return null;
        }

        $name = $this->pick($meta, $cfg, array('schema_itemlist_name', 'itemlist_name', 'title'), $siteTitle);

        return array(
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'itemListElement' => $elements,
            'url' => $currentUrl
        );
    }

    protected function buildFaqPage(array $meta, array $config, $currentUrl, $siteTitle)
    {
        $cfg = isset($config['faq']) ? $config['faq'] : array();
        $faqs = array();
        if (!empty($meta['faq']) && is_array($meta['faq'])) {
            $faqs = $meta['faq'];
        } elseif (!empty($cfg['items']) && is_array($cfg['items'])) {
            $faqs = $cfg['items'];
        }

        $entities = array();
        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }
            $entities[] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $faq['answer']
                )
            );
        }

        if (empty($entities)) {
            return null;
        }

        return array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
            'name' => $this->pick($meta, $cfg, array('schema_faq_name', 'faq_name', 'title'), $siteTitle),
            'url' => $currentUrl
        );
    }

    protected function isArticlePage(array $meta, $templateName)
    {
        $type = isset($meta['schema_type']) ? strtolower($meta['schema_type']) : '';
        if ($type === 'article') {
            return true;
        }
        if (!empty($meta['article']) || !empty($meta['article_title']) || !empty($meta['schema_article_title'])) {
            return true;
        }
        if (strpos(strtolower($templateName), 'article') !== false || strpos(strtolower($templateName), 'post') !== false) {
            return true;
        }
        return false;
    }

    protected function isItemListPage(array $meta)
    {
        $type = isset($meta['schema_type']) ? strtolower($meta['schema_type']) : '';
        if ($type === 'itemlist') {
            return true;
        }
        if (!empty($meta['itemList']) || !empty($meta['items'])) {
            return true;
        }
        return false;
    }

    protected function isFaqPage(array $meta)
    {
        $type = isset($meta['schema_type']) ? strtolower($meta['schema_type']) : '';
        if ($type === 'faq' || $type === 'faqpage') {
            return true;
        }
        if (!empty($meta['faq'])) {
            return true;
        }
        return false;
    }

    protected function pick(array $meta, array $cfg, array $keys, $fallback)
    {
        foreach ($keys as $key) {
            if (isset($meta[$key]) && $meta[$key] !== '') {
                return $meta[$key];
            }
            if (isset($cfg[$key]) && $cfg[$key] !== '') {
                return $cfg[$key];
            }
        }
        return $fallback;
    }

    protected function pickList(array $meta, array $cfg, array $keys, $fallback)
    {
        foreach ($keys as $key) {
            if (isset($meta[$key])) {
                return $this->normalizeList($meta[$key]);
            }
            if (isset($cfg[$key])) {
                return $this->normalizeList($cfg[$key]);
            }
        }
        return $fallback;
    }

    protected function normalizeList($value)
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
        $out = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
