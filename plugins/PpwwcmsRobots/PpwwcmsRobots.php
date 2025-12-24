<?php

/**
 * Pico robots plugin - add a robots.txt and sitemap.xml to your website
 *
 * PicoRobots is a simple plugin that add a `robots.txt` and `sitemap.xml` to
 * your website. Both the robots exclusion protocol (`robots.txt`) and the
 * Sitemaps protocol (`sitemap.xml`) are used to communicate with web crawlers
 * and other web robots. `robots.txt` informs the web robot about which areas
 * of your website should not be processed or scanned. `sitemap.xml` allows
 * web robots to crawl your website more intelligently. `sitemap.xml` is a URL
 * inclusion protocol and complements `robots.txt`, a URL exclusion protocol.
 *
 * @author  Daniel Rudolf
 * @link    http://picocms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 1.0.0
 */
class PpwwcmsRobots extends AbstractWwppcmsPlugin
{
    /**
     * This plugin uses Pico's API version 2 as of Pico 2.0
     *
     * @var int
     */
    const API_VERSION = 2;

    /**
     * List of robots exclusion rules
     *
     * @see PicoRobots::getRobots()
     * @var array[]|null
     */
    protected $robots;

    /**
     * List of sitemap records
     *
     * @see PicoRobots::getSitemap()
     * @var array[]|null
     */
    protected $sitemap;

    /**
     * Cached sitemap index records
     *
     * @var array[]|null
     */
    protected $sitemapIndex;

    /**
     * Cached page sitemap records
     *
     * @var array[]|null
     */
    protected $pagesSitemap;

    /**
     * Cached post sitemap records
     *
     * @var array[]|null
     */
    protected $postsSitemap;

    /**
     * Disables this plugin if neither robots.txt nor sitemap.xml is requested
     *
     * @see DummyPlugin::onRequestUrl()
     */
    public function onRequestUrl(&$requestUrl)
    {
        $sitemaps = array('sitemap.xml', 'sitemap-pages.xml', 'sitemap-posts.xml');
        if (!in_array($requestUrl, array_merge(array('robots.txt'), $sitemaps), true)) {
            $this->setEnabled(false);
        }
    }

    /**
     * Sets a page's last modification time and its default sitemap status
     *
     * @see DummyPlugin::onSinglePageLoaded()
     */
    public function onSinglePageLoaded(array &$pageData)
    {
        $sitemapRequests = array('sitemap.xml', 'sitemap-pages.xml', 'sitemap-posts.xml');
        if (in_array($this->getRequestUrl(), $sitemapRequests, true) && $pageData['id']) {
            $fileName = $this->getConfig('content_dir') . $pageData['id'] . $this->getConfig('content_ext');
            if (file_exists($fileName) && !isset($pageData['modificationTime'])) {
                $pageData['modificationTime'] = filemtime($fileName);
            }

            if (!$pageData['meta']['sitemap'] && ($pageData['meta']['sitemap'] !== false)) {
                $pageData['meta']['sitemap'] = true;

                if (preg_match('/(?:^|\/)_/', $pageData['id'])) {
                    $pageData['meta']['sitemap'] = false;
                } else {
                    $robots = explode(',', $pageData['meta']['robots']);
                    $robots = array_map('strtolower', $robots);
                    if (in_array('noindex', $robots)) {
                        $pageData['meta']['sitemap'] = false;
                    }
                }
            }
        }
    }

    /**
     * Tells Pico to serve the robots.txt resp. sitemap.xml
     *
     * You can overwrite the plugin's default templates for `robots.txt` and
     * `sitemap.xml` by simply adding a `robots.twig` resp. `sitemap.twig` to
     * your theme.
     *
     * @see DummyPlugin::onPageRendering()
     */
    public function onPageRendering(&$twigTemplate, array &$twigVariables)
    {
        if ($this->getRequestUrl() === 'robots.txt') {
            header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
            header('Content-Type: text/plain; charset=utf-8');
            $twigTemplate = 'robots.twig';

            $twigVariables['robots'] = $this->getRobots();
        }

        if ($this->getRequestUrl() === 'sitemap.xml') {
            header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
            header('Content-Type: application/xml; charset=utf-8');
            $twigTemplate = 'sitemap-index.twig';

            $twigVariables['sitemaps'] = $this->getSitemapIndex();
        }

        if ($this->getRequestUrl() === 'sitemap-pages.xml') {
            header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
            header('Content-Type: application/xml; charset=utf-8');
            $twigTemplate = 'sitemap.twig';

            $twigVariables['sitemap'] = $this->getPagesSitemap();
        }

        if ($this->getRequestUrl() === 'sitemap-posts.xml') {
            header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
            header('Content-Type: application/xml; charset=utf-8');
            $twigTemplate = 'sitemap.twig';

            $twigVariables['sitemap'] = $this->getPostsSitemap();
        }
    }

    /**
     * Returns the structured contents of robots.txt
     *
     * This method triggers the `onRobots` event when the contents of
     * `robots.txt` weren't assembled yet.
     *
     * @return array[] list of robots exclusion rules
     */
    public function getRobots()
    {
        if ($this->robots === null) {
            $this->robots = array();

            $robotsConfig = $this->getPluginConfig('robots', array());
            foreach ($robotsConfig as $rule) {
                $userAgents = !empty($rule['user_agents']) ? (array) $rule['user_agents'] : array();
                $disallow = !empty($rule['disallow']) ? (array) $rule['disallow'] : array();
                $allow = !empty($rule['allow']) ? (array) $rule['allow'] : array();

                $this->robots[] = array(
                    'user_agents' => $userAgents ?: array('*'),
                    'disallow' => $disallow ?: (!$allow ? array('/') : array()),
                    'allow' => $allow
                );
            }

            if (empty($this->robots)) {
                $this->robots = $this->getDefaultRobots();
            }

            $this->triggerEvent('onRobots', array(&$this->robots));
        }

        return $this->robots;
    }

    /**
     * Default robots.txt rules when no config is provided
     *
     * @return array[]
     */
    protected function getDefaultRobots()
    {
        return array(
            array('user_agents' => array('*'), 'disallow' => array(), 'allow' => array()),
            array('user_agents' => array('Googlebot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Googlebot-Image'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Googlebot-Video'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Googlebot-News'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('AdsBot-Google'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('AdsBot-Google-Mobile'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Storebot-Google'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Bingbot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('MSNBot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Slurp'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('DuckDuckBot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Baiduspider'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Baiduspider-image'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Baiduspider-video'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Baiduspider-news'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Baiduspider-favo'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Sogou spider'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('Sogou inst spider'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('360Spider'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('ShenmaSpider'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('YandexBot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('GPTBot'), 'disallow' => array(), 'allow' => array('/')),
            array('user_agents' => array('AhrefsBot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('SemrushBot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('MJ12bot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('DotBot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('BLEXBot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('Bytespider'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('PetalBot'), 'disallow' => array('/'), 'allow' => array()),
            array('user_agents' => array('SeznamBot'), 'disallow' => array('/'), 'allow' => array())
        );
    }

    /**
     * Returns the structure contents of sitemap.xml
     *
     * This method triggers the `onSitemap` event when the contents of
     * `sitemap.xml` weren't assembled yet.
     *
     * @return array[] list of sitemap records
     */
    public function getSitemap()
    {
        // Legacy compatibility: merge pages + posts sitemaps
        if ($this->sitemap === null) {
            $this->sitemap = array_merge($this->getPagesSitemap(), $this->getPostsSitemap());
            $this->triggerEvent('onSitemap', array(&$this->sitemap));
        }

        return $this->sitemap;
    }

    /**
     * Returns sitemap index entries
     *
     * @return array[] list of sitemap index records
     */
    public function getSitemapIndex()
    {
        if ($this->sitemapIndex === null) {
            $pages = $this->getPagesSitemap();
            $posts = $this->getPostsSitemap();

            $this->sitemapIndex = array();

            $pagesLastmod = $this->getLatestLastmod($pages);
            $postsLastmod = $this->getLatestLastmod($posts);

            $base = rtrim($this->getBaseUrl(), '/');

            $this->sitemapIndex[] = array(
                'url' => $base . '/sitemap-pages.xml',
                'modificationTime' => $pagesLastmod
            );

            if (!empty($posts)) {
                $this->sitemapIndex[] = array(
                    'url' => $base . '/sitemap-posts.xml',
                    'modificationTime' => $postsLastmod
                );
            }

            $this->triggerEvent('onSitemap', array(&$this->sitemapIndex));
        }

        return $this->sitemapIndex;
    }

    /**
     * Returns page sitemap entries
     *
     * @return array[]
     */
    public function getPagesSitemap()
    {
        if ($this->pagesSitemap === null) {
            $this->collectSitemaps();
        }

        return $this->pagesSitemap;
    }

    /**
     * Returns post sitemap entries
     *
     * @return array[]
     */
    public function getPostsSitemap()
    {
        if ($this->postsSitemap === null) {
            $this->collectSitemaps();
        }

        return $this->postsSitemap;
    }

    /**
     * Registers the Sitemap meta header
     *
     * @see DummyPlugin::onMetaHeaders()
     */
    public function onMetaHeaders(array &$headers)
    {
        $headers['Sitemap'] = 'sitemap';
    }

    /**
     * Adds the plugin's theme dir to Twig's template loader
     *
     * @see DummyPlugin::onTwigRegistered()
     */
    public function onTwigRegistered(Twig_Environment &$twig)
    {
        $twig->getLoader()->addPath(__DIR__ . '/theme');
    }

    /**
     * Collect and split sitemap data into pages/posts buckets
     */
    protected function collectSitemaps()
    {
        $this->pagesSitemap = array();
        $this->postsSitemap = array();

        $pages = $this->getPages();
        foreach ($pages as $pageData) {
            if (!$this->shouldIncludeInSitemap($pageData)) {
                continue;
            }

            $record = $this->buildSitemapRecord($pageData['url'], $this->resolveLastmod($pageData));
            $bucket = $this->classifyPageType($pageData);

            if ($bucket === 'post') {
                $this->postsSitemap[] = $record;
            } else {
                $this->pagesSitemap[] = $record;
            }
        }

        // Backward-compatible config additions
        $configPages = $this->getPluginConfig('sitemap_pages', array());
        foreach ($configPages as $record) {
            $built = $this->buildConfigRecord($record);
            if (!empty($built)) {
                $this->pagesSitemap[] = $built;
            }
        }

        $configPosts = $this->getPluginConfig('sitemap_posts', array());
        foreach ($configPosts as $record) {
            $built = $this->buildConfigRecord($record);
            if (!empty($built)) {
                $this->postsSitemap[] = $built;
            }
        }

        // Legacy single bucket config still accepted, routed to pages
        $legacy = $this->getPluginConfig('sitemap', array());
        foreach ($legacy as $record) {
            $built = $this->buildConfigRecord($record);
            if (!empty($built)) {
                $this->pagesSitemap[] = $built;
            }
        }

        $this->triggerEvent('onSitemap', array(&$this->pagesSitemap));
        $this->triggerEvent('onSitemap', array(&$this->postsSitemap));
    }

    /**
     * Decide whether a page should be in sitemap
     */
    protected function shouldIncludeInSitemap(array $pageData)
    {
        if (empty($pageData['meta']['sitemap'])) {
            return false;
        }

        $robots = explode(',', $pageData['meta']['robots']);
        $robots = array_map('strtolower', $robots);
        if (in_array('noindex', $robots)) {
            return false;
        }

        return true;
    }

    /**
     * Classify a page into page/post buckets
     */
    protected function classifyPageType(array $pageData)
    {
        if (!empty($pageData['meta']['sitemap']['type'])) {
            return strtolower($pageData['meta']['sitemap']['type']) === 'post' ? 'post' : 'page';
        }

        if (!empty($pageData['meta']['type']) && strtolower($pageData['meta']['type']) === 'post') {
            return 'post';
        }

        $id = isset($pageData['id']) ? $pageData['id'] : '';
        if (preg_match('/^(blog|post|posts|news|article|content)\//i', $id)) {
            return 'post';
        }

        return 'page';
    }

    /**
     * Normalize a sitemap record
     */
    protected function buildSitemapRecord($url, $modificationTime)
    {
        return array(
            'url' => $this->normalizeUrl($url),
            'modificationTime' => $modificationTime
        );
    }

    /**
     * Normalize config-provided records
     */
    protected function buildConfigRecord(array $record)
    {
        if (empty($record['url'])) {
            return array();
        }

        $modificationTime = !empty($record['lastmod']) ? $record['lastmod'] : null;
        if ($modificationTime && !is_int($modificationTime)) {
            $modificationTime = strtotime($modificationTime) ?: null;
        }

        return $this->buildSitemapRecord($this->substituteUrl($record['url']), $modificationTime);
    }

    /**
     * Resolve lastmod using real file mtime or metadata overrides
     */
    protected function resolveLastmod(array $pageData)
    {
        if (isset($pageData['meta']['sitemap']['lastmod']) && $pageData['meta']['sitemap']['lastmod']) {
            $lastmod = $pageData['meta']['sitemap']['lastmod'];
            return is_int($lastmod) ? $lastmod : (strtotime($lastmod) ?: null);
        }

        if (!empty($pageData['modificationTime'])) {
            return $pageData['modificationTime'];
        }

        $fileName = $this->getConfig('content_dir') . $pageData['id'] . $this->getConfig('content_ext');
        return file_exists($fileName) ? filemtime($fileName) : null;
    }

    /**
     * Get latest modification time from a sitemap array
     */
    protected function getLatestLastmod(array $records)
    {
        $lastmod = null;
        foreach ($records as $record) {
            if (!isset($record['modificationTime'])) {
                continue;
            }
            if ($record['modificationTime'] === null) {
                continue;
            }
            if ($lastmod === null || $record['modificationTime'] > $lastmod) {
                $lastmod = $record['modificationTime'];
            }
        }


        return $lastmod;

    }

    /**
     * Normalize URL: remove trailing slash except for root
     */
    protected function normalizeUrl($url)
    {
        $trimmed = rtrim($url, '/');
        if ($trimmed === '') {
            return '/';
        }

        return $trimmed . '/';
    }

    /**
     * Substitutes the placeholders %base_url% and %theme_url% in URLs
     *
     * @param string $url URL with (or without) placeholders
     *
     * @return string substituted URL
     */
    protected function substituteUrl($url)
    {
        $variables = array(
            '%base_url%?' => $this->getBaseUrl() . (!$this->isUrlRewritingEnabled() ? '?' : ''),
            '%base_url%' => rtrim($this->getBaseUrl(), '/'),
            '%theme_url%' => $this->getBaseThemeUrl() . $this->getConfig('theme')
        );

        return str_replace(array_keys($variables), $variables, $url);
    }
}
