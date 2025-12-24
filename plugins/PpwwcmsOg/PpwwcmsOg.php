<?php
/**
 * Ppwwcms OG plugin - auto generate Open Graph meta
 */
class PpwwcmsOg extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /**
     * Inject og data into twig variables
     */
    public function onPageRendering(&$twigTemplate, array &$twigVariables)
    {
        $twigVariables['og'] = $this->buildOg($twigVariables);
    }

    protected function buildOg(array $twigVars)
    {
        $configOg = $this->getPluginConfig('og', array());
        $meta = isset($twigVars['meta']) ? $twigVars['meta'] : array();
        $current = isset($twigVars['current_page']) ? $twigVars['current_page'] : array();

        $siteTitle = $this->getConfig('site_title');
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        $currentUrl = isset($current['url']) ? $current['url'] : $baseUrl . '/';

        $og = array();
        $og['title'] = $this->pick($configOg, $meta, array('title'), $siteTitle);
        $og['description'] = $this->pick($configOg, $meta, array('description'), '');
        $og['url'] = isset($configOg['url']) ? $configOg['url'] : $currentUrl;
        $og['type'] = isset($configOg['type']) ? $configOg['type'] : 'website';
        $og['site_name'] = isset($configOg['site_name']) ? $configOg['site_name'] : $siteTitle;
        $og['image'] = $this->pickImage($configOg, $meta, $baseUrl);

        return $og;
    }

    protected function pick(array $configOg, array $meta, array $keys, $fallback)
    {
        foreach ($keys as $key) {
            if (!empty($configOg[$key])) {
                return $configOg[$key];
            }
            if (!empty($meta[$key])) {
                return $meta[$key];
            }
        }
        return $fallback;
    }

    protected function pickImage(array $configOg, array $meta, $baseUrl)
    {
        if (!empty($configOg['image'])) {
            return $configOg['image'];
        }
        if (!empty($meta['image'])) {
            return $meta['image'];
        }
        return $baseUrl . '/assets/og-default.png';
    }
}
