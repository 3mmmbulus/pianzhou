<?php
/**
 * PpwwcmsAds - Ads management plugin with compliance-first defaults.
 *
 * Features:
 * - Placements: header/footer/sidebar/content_top/content_bottom/after_paragraph(n)
 * - Shortcode in Markdown HTML output: [[ads:slot-name]] (handled in onContentParsed)
 * - Twig injection: pico_ads.{slot} and pico_ads._runtime
 * - Compliance: adds rel="sponsored" (+ optional nofollow) for ad links and label.
 * - Intrusive mode (guarded): overlay only on configured paths; forces noindex by default.
 * - Observability: daily JSON logs under plugins/PpwwcmsAds/logs/ (impressions + clicks)
 */
class PpwwcmsAds extends AbstractWwppcmsPlugin
{
    const API_VERSION = 3;

    /** @var bool */
    protected $hasRuntime = false;

    /** @var array */
    protected $runtimeFlags = array(
        'needsFrequency' => false,
        'needsDevice' => false,
        'needsLazy' => false,
        'needsIntrusive' => false,
    );

    /** @var string|null */
    protected $endpointMode = null; // 'imp'|'click'|'runtime'

    /** @var string */
    protected $endpointFile;

    /** @var string|null */
    protected $cspNonce = null;

    public function __construct($wwppcms, $deprecated = null)
    {
        parent::__construct($wwppcms, $deprecated);
        $this->endpointFile = __DIR__ . '/endpoint.md';
    }

    /**
     * Register optional Twig helpers.
     *
     * We intentionally keep the main integration as `pico_ads` globals.
     */
    public function onTwigRegistered(Twig_Environment &$twig)
    {
        // Register plugin templates (endpoints etc.)
        try {
            $loader = $twig->getLoader();
            if ($loader && method_exists($loader, 'addPath')) {
                $loader->addPath(__DIR__ . '/templates', 'ppww_ads');
            }
        } catch (\Exception $e) {
            // ignore
        }
    }

    /**
     * Route internal tracking endpoints before Wwppcms decides 404.
     */
    public function onRequestUrl(&$url)
    {
        $norm = $this->normalizeRequestUrl($url);
        if ($norm === 'ads/imp.gif') {
            $this->endpointMode = 'imp';
        } elseif ($norm === 'ads/runtime.js') {
            $this->endpointMode = 'runtime';
        } elseif ($norm === 'ads/click') {
            $this->endpointMode = 'click';
        }
    }

    /**
     * Ensure endpoint requests are served from an existing file (avoid 404 header).
     */
    public function onRequestFile(&$file)
    {
        if ($this->endpointMode && is_file($this->endpointFile)) {
            $file = $this->endpointFile;
        }
    }

    /**
     * Insert content-level ad placements and shortcodes.
     */
    public function onContentParsed(&$content)
    {
        // Do not alter endpoint content
        if ($this->endpointMode) {
            return;
        }

        $config = $this->getPluginConfig(null, array());
        $enabled = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'enabled', true));
        if (!$enabled) {
            return;
        }

        // Optional global disable on 404/noindex pages
        $wwppcms = $this->getWwppcms();
        if (method_exists($wwppcms, 'is404Content') && $wwppcms->is404Content()) {
            return;
        }
        $meta = $wwppcms->getFileMeta();
        if (!empty($meta['robots']) && stripos((string) $meta['robots'], 'noindex') !== false) {
            if (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'disable_on_noindex', true))) {
                return;
            }
        }

        // Replace shortcode markers [[ads:slot-name]]
        if (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'shortcode', true))) {
            $content = $this->replaceShortcodes($content, $config);
        }

        // Content placements
        $placements = (array) PpwwcmsAds_Config::get($config, 'placements', array());

        // content_top
        if (!empty($placements['content_top'])) {
            $slot = (string) $placements['content_top'];
            $html = $this->renderSlot($slot, $config, $meta, null);
            if ($html !== '') {
                $content = $html . $content;
            }
        }

        // content_bottom
        if (!empty($placements['content_bottom'])) {
            $slot = (string) $placements['content_bottom'];
            $html = $this->renderSlot($slot, $config, $meta, null);
            if ($html !== '') {
                $content = $content . $html;
            }
        }

        // after_paragraph
        if (!empty($placements['after_paragraph']) && is_array($placements['after_paragraph'])) {
            $rules = array();
            foreach ($placements['after_paragraph'] as $rule) {
                $n = isset($rule['n']) ? (int) $rule['n'] : 0;
                $slot = isset($rule['slot']) ? (string) $rule['slot'] : '';
                if ($n < 1 || $slot === '') {
                    continue;
                }
                $rules[] = array('n' => $n, 'slot' => $slot);
            }

            // Insert from bottom to top to avoid shifting paragraph indices.
            usort($rules, function ($a, $b) {
                return (int) $b['n'] - (int) $a['n'];
            });

            foreach ($rules as $r) {
                $n = (int) $r['n'];
                $slot = (string) $r['slot'];
                $html = $this->renderSlot($slot, $config, $meta, array('placement' => 'after_paragraph', 'n' => $n));
                if ($html !== '') {
                    $content = PpwwcmsAds_Util::insertAfterParagraph($content, $n, $html);
                }
            }
        }
    }

    /**
     * Prepare Twig variables for theme integration and handle endpoints.
     */
    public function onPageRendering(&$templateName, array &$twigVariables)
    {
        // Endpoint responses
        if ($this->endpointMode === 'imp') {
            $this->handleImpressionEndpoint($templateName, $twigVariables);
            return;
        }
        if ($this->endpointMode === 'runtime') {
            $this->handleRuntimeEndpoint($templateName, $twigVariables);
            return;
        }
        if ($this->endpointMode === 'click') {
            $this->handleClickEndpoint($templateName, $twigVariables);
            return;
        }

        $config = $this->getPluginConfig(null, array());
        $enabled = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'enabled', true));
        if (!$enabled) {
            return;
        }

        $current = isset($twigVariables['current_page']) ? (array) $twigVariables['current_page'] : array();
        $meta = isset($twigVariables['meta']) ? (array) $twigVariables['meta'] : $this->getWwppcms()->getFileMeta();

        // CSP nonce (must be generated before rendering creatives)
        if (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'security.csp_nonce.enabled', false))) {
            $this->cspNonce = PpwwcmsAds_Util::randomNonce(16);
        }

        // Global disable on 404/noindex
        if (!empty($twigVariables['is_404']) && $twigVariables['is_404']) {
            return;
        }
        if (!empty($meta['robots']) && stripos((string) $meta['robots'], 'noindex') !== false) {
            if (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'disable_on_noindex', true))) {
                return;
            }
        }

        // Intrusive mode guardrails
        if ($this->shouldApplyIntrusive($config, $current, $meta)) {
            $this->runtimeFlags['needsIntrusive'] = true;
            $this->enforceIntrusiveNoindex($config, $twigVariables);
            // Expose overlay slot as pico_ads._intrusive
            $overlaySlot = (string) PpwwcmsAds_Config::get($config, 'intrusive.overlay_slot', 'intrusive_overlay');
            $overlay = $this->renderIntrusiveOverlay($config, $overlaySlot);
            $twigVariables['pico_ads'] = isset($twigVariables['pico_ads']) && is_array($twigVariables['pico_ads']) ? $twigVariables['pico_ads'] : array();
            $twigVariables['pico_ads']['_intrusive'] = $this->asTwigHtml($overlay);
        }

        // Build placements for theme
        $placements = (array) PpwwcmsAds_Config::get($config, 'placements', array());
        $slots = array();
        foreach (array('header', 'footer', 'sidebar') as $p) {
            if (!empty($placements[$p])) {
                $slotName = (string) $placements[$p];
                $slots[$p] = $this->renderSlot($slotName, $config, $meta, array('placement' => $p));
            }
        }

        // Expose pico_ads globals
        $twigVariables['pico_ads'] = isset($twigVariables['pico_ads']) && is_array($twigVariables['pico_ads']) ? $twigVariables['pico_ads'] : array();
        foreach ($slots as $k => $v) {
            $twigVariables['pico_ads'][$k] = $this->asTwigHtml($v);
        }

        // Also expose per-slot direct access: pico_ads.slot_{name}
        // (slot name is user-defined; provide a flat map under pico_ads.slots)
        $twigVariables['pico_ads']['slots'] = isset($twigVariables['pico_ads']['slots']) && is_array($twigVariables['pico_ads']['slots']) ? $twigVariables['pico_ads']['slots'] : array();
        foreach ((array) PpwwcmsAds_Config::get($config, 'slots', array()) as $slotName => $slotCfg) {
            $rendered = $this->asTwigHtml($this->renderSlot((string) $slotName, $config, $meta, array('placement' => 'slot')));
            $twigVariables['pico_ads']['slots'][$slotName] = $rendered;

            // Also expose as `pico_ads.<slot_name>` (normalized to Twig-friendly identifier)
            $key = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $slotName);
            if ($key !== '' && !isset($twigVariables['pico_ads'][$key])) {
                $twigVariables['pico_ads'][$key] = $rendered;
            }
        }

        if ($this->cspNonce) {
            $twigVariables['pico_ads']['csp_nonce'] = $this->cspNonce;
        }

        // Runtime script (if needed)
        $runtime = $this->buildRuntimeScript($config);
        if ($runtime !== '') {
            $twigVariables['pico_ads']['_runtime'] = $this->asTwigHtml($runtime);
        } else {
            $twigVariables['pico_ads']['_runtime'] = $this->asTwigHtml('');
        }
    }

    /**
     * Post-render: auto-inject intrusive overlay/runtime if theme doesn't.
     */
    public function onPageRendered(&$output)
    {
        if ($this->endpointMode) {
            // Endpoint output is built in onPageRendering
            return;
        }

        $config = $this->getPluginConfig(null, array());
        $enabled = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'enabled', true));
        if (!$enabled) {
            return;
        }

        // If theme did not render pico_ads._runtime, we inject before </body>
        if (strpos($output, 'data-ppww-ads-runtime') === false) {
            $runtime = $this->buildRuntimeScript($config);
            if ($runtime !== '' && stripos($output, '</body>') !== false) {
                $output = preg_replace('~</body>~i', $runtime . "\n</body>", $output, 1);
            }
        }

        // Intrusive overlay auto-inject before </body> if it exists in vars but not in DOM
        if ($this->runtimeFlags['needsIntrusive'] && strpos($output, 'data-ppww-ads-intrusive') === false) {
            $overlay = $this->renderIntrusiveOverlay($config, (string) PpwwcmsAds_Config::get($config, 'intrusive.overlay_slot', 'intrusive_overlay'));
            if ($overlay !== '' && stripos($output, '</body>') !== false) {
                $output = preg_replace('~</body>~i', $overlay . "\n</body>", $output, 1);
            }
        }
    }

    // ------------------------- Rendering -------------------------

    protected function renderSlot($slotName, array $config, array $pageMeta, $context)
    {
        $slotName = trim((string) $slotName);
        if ($slotName === '') {
            return '';
        }

        $slotCfgAll = (array) PpwwcmsAds_Config::get($config, 'slots', array());
        $slotCfg = isset($slotCfgAll[$slotName]) && is_array($slotCfgAll[$slotName]) ? $slotCfgAll[$slotName] : null;
        if (!$slotCfg) {
            return '';
        }

        // Targeting
        if (!$this->matchTargeting($slotCfg, $pageMeta)) {
            return '';
        }

        // Schedule
        if (!PpwwcmsAds_Util::inSchedule((array) PpwwcmsAds_Config::get($slotCfg, 'schedule', array()))) {
            return '';
        }

        $creative = $this->selectCreative($slotName, $slotCfg);
        if (!$creative) {
            return '';
        }

        $html = $this->renderCreative($slotName, $creative, $config);
        if ($html === '') {
            return '';
        }

        // Add wrapper + label
        $labelText = (string) PpwwcmsAds_Config::get($config, 'compliance.label_text', '广告');
        $labelClass = (string) PpwwcmsAds_Config::get($config, 'compliance.label_class', 'ppww-ads__label');
        $wrapClass = (string) PpwwcmsAds_Config::get($config, 'compliance.wrapper_class', 'ppww-ads');

        $placement = is_array($context) && isset($context['placement']) ? (string) $context['placement'] : 'slot';
        $wrap = $wrapClass . ' ' . $wrapClass . '--' . PpwwcmsAds_Util::cssIdent($slotName) . ' ' . $wrapClass . '--p-' . PpwwcmsAds_Util::cssIdent($placement);

        $attrs = array(
            'class' => $wrap,
            'data-ppww-ads' => '1',
            'data-ppww-ads-slot' => $slotName,
            'data-ppww-ads-creative' => isset($creative['id']) ? (string) $creative['id'] : '',
        );

        // Device targeting (client-side)
        $device = (string) PpwwcmsAds_Config::get($slotCfg, 'targeting.device', 'all'); // all|mobile|desktop
        if ($device !== '' && $device !== 'all') {
            $attrs['data-ppww-ads-device'] = $device;
            $this->runtimeFlags['needsDevice'] = true;
        }

        // Frequency cap (client-side)
        $freq = (array) PpwwcmsAds_Config::get($slotCfg, 'frequency', array());
        if (!empty($freq['max'])) {
            $attrs['data-ppww-ads-freq'] = json_encode(array(
                'window' => !empty($freq['window']) ? (string) $freq['window'] : 'day',
                'max' => (int) $freq['max'],
                'scope' => !empty($freq['scope']) ? (string) $freq['scope'] : 'slot',
            ));
            $this->runtimeFlags['needsFrequency'] = true;
        }

        // Lazy (client-side)
        $lazy = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($slotCfg, 'lazy', false));
        if ($lazy) {
            $attrs['data-ppww-ads-lazy'] = '1';
            $this->runtimeFlags['needsLazy'] = true;
        }

        $labelHtml = '<div class="' . htmlspecialchars($labelClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8') . '</div>';

        $out = '<div' . PpwwcmsAds_Util::htmlAttrs($attrs) . '>' . $labelHtml . $html . '</div>';

        // Observability: log impression (rendered)
        $this->logImpression($slotName, isset($creative['id']) ? (string) $creative['id'] : '');

        return $out;
    }

    protected function renderCreative($slotName, array $creative, array $pluginConfig)
    {
        $type = isset($creative['type']) ? (string) $creative['type'] : 'html';
        $id = isset($creative['id']) ? (string) $creative['id'] : '';

        $nofollow = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($pluginConfig, 'compliance.nofollow', false));

        if ($type === 'image') {
            $src = (string) PpwwcmsAds_Config::get($creative, 'src', '');
            $href = (string) PpwwcmsAds_Config::get($creative, 'href', '');
            $alt = (string) PpwwcmsAds_Config::get($creative, 'alt', '');
            $w = (int) PpwwcmsAds_Config::get($creative, 'width', 0);
            $h = (int) PpwwcmsAds_Config::get($creative, 'height', 0);
            if ($src === '') {
                return '';
            }
            $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" loading="lazy"' . ($w > 0 ? ' width="' . $w . '"' : '') . ($h > 0 ? ' height="' . $h . '"' : '') . '>';
            if ($href !== '') {
                $href2 = $this->wrapClickUrl($slotName, $id, $href);
                $rel = $nofollow ? 'sponsored nofollow' : 'sponsored';
                return '<a class="ppww-ads__link" href="' . htmlspecialchars($href2, ENT_QUOTES, 'UTF-8') . '" rel="' . $rel . '" target="_blank">' . $img . '</a>';
            }
            return $img;
        }

        if ($type === 'links') {
            $items = (array) PpwwcmsAds_Config::get($creative, 'items', array());
            if (empty($items)) {
                return '';
            }
            $rel = $nofollow ? 'sponsored nofollow' : 'sponsored';
            $html = '<ul class="ppww-ads__links">';
            foreach ($items as $it) {
                $t = isset($it['text']) ? (string) $it['text'] : '';
                $u = isset($it['url']) ? (string) $it['url'] : '';
                if ($u === '') {
                    continue;
                }
                $u2 = $this->wrapClickUrl($slotName, $id, $u);
                $html .= '<li><a href="' . htmlspecialchars($u2, ENT_QUOTES, 'UTF-8') . '" rel="' . $rel . '" target="_blank">' . htmlspecialchars($t !== '' ? $t : $u, ENT_QUOTES, 'UTF-8') . '</a></li>';
            }
            $html .= '</ul>';
            return $html;
        }

        // html/js
        $raw = (string) PpwwcmsAds_Config::get($creative, 'html', '');
        if ($raw === '') {
            return '';
        }

        $mode = (string) PpwwcmsAds_Config::get($pluginConfig, 'security.html_mode', 'whitelist'); // whitelist|raw
        $allowScript = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($pluginConfig, 'security.allow_script', false));

        if ($mode !== 'raw') {
            $raw = PpwwcmsAds_Sanitizer::sanitizeHtml($raw, array(
                'allowScript' => false,
            ));
        } elseif (!$allowScript) {
            // raw but scripts disabled
            $raw = PpwwcmsAds_Sanitizer::sanitizeHtml($raw, array(
                'allowScript' => false,
            ));
        } else {
            // raw + scripts allowed: still strip dangerous event handlers
            $raw = PpwwcmsAds_Sanitizer::stripEventHandlers($raw);
        }

        // Compliance rel rewrite for links in HTML snippet
        $raw = PpwwcmsAds_Sanitizer::rewriteAnchorsRel($raw, $nofollow);

        // Add nonce to <script> tags if enabled
        if ($this->cspNonce) {
            $raw = PpwwcmsAds_Sanitizer::addScriptNonce($raw, $this->cspNonce);
        }

        return '<div class="ppww-ads__html">' . $raw . '</div>';
    }

    // ------------------------- Selection + Targeting -------------------------

    protected function selectCreative($slotName, array $slotCfg)
    {
        $creatives = (array) PpwwcmsAds_Config::get($slotCfg, 'creatives', array());
        if (empty($creatives)) {
            return null;
        }

        // Normalize creative IDs
        $norm = array();
        $idx = 0;
        foreach ($creatives as $c) {
            if (!is_array($c)) {
                continue;
            }
            $c['id'] = isset($c['id']) ? (string) $c['id'] : ('c' . (++$idx));
            $c['weight'] = isset($c['weight']) ? (int) $c['weight'] : 1;
            if ($c['weight'] < 1) {
                $c['weight'] = 1;
            }
            $norm[] = $c;
        }
        if (empty($norm)) {
            return null;
        }

        // A/B test (stable bucket)
        $ab = (array) PpwwcmsAds_Config::get($slotCfg, 'ab', array());
        if (!empty($ab['enabled']) && !empty($ab['variants']) && is_array($ab['variants'])) {
            $vid = $this->getVisitorId();
            $salt = !empty($ab['salt']) ? (string) $ab['salt'] : 'ppwwcms-ads';
            $bucket = PpwwcmsAds_Util::stableBucket($vid . '|' . $slotName . '|' . $salt);
            $variant = PpwwcmsAds_Util::pickWeightedVariant($ab['variants'], $bucket);
            if ($variant && !empty($variant['creative_ids']) && is_array($variant['creative_ids'])) {
                $allowed = array_flip($variant['creative_ids']);
                $norm = array_values(array_filter($norm, function ($c) use ($allowed) {
                    return isset($c['id']) && isset($allowed[$c['id']]);
                }));
            }
        }

        if (empty($norm)) {
            return null;
        }

        // Stable weighted selection per visitor + slot (avoid flicker)
        $vid = $this->getVisitorId();
        $seed = PpwwcmsAds_Util::stableBucket($vid . '|' . $slotName);
        return PpwwcmsAds_Util::pickWeightedCreative($norm, $seed);
    }

    protected function matchTargeting(array $slotCfg, array $pageMeta)
    {
        $t = (array) PpwwcmsAds_Config::get($slotCfg, 'targeting', array());

        // include/exclude paths (glob / regex)
        $path = $this->getCurrentPath();
        $inc = (array) PpwwcmsAds_Config::get($t, 'include', array());
        $exc = (array) PpwwcmsAds_Config::get($t, 'exclude', array());
        if (!PpwwcmsAds_Util::matchPathRules($path, $inc, true)) {
            return false;
        }
        if (PpwwcmsAds_Util::matchPathRules($path, $exc, false)) {
            return false;
        }

        // Meta flags
        if (isset($t['meta']) && is_array($t['meta'])) {
            foreach ($t['meta'] as $k => $expected) {
                $val = isset($pageMeta[$k]) ? $pageMeta[$k] : null;
                if ((string) $expected === 'any') {
                    if ($val === null || $val === '') {
                        return false;
                    }
                } else {
                    if ((string) $val !== (string) $expected) {
                        return false;
                    }
                }
            }
        }

        // Referrer allow/deny (not for crawler cloaking)
        $ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $refRules = (array) PpwwcmsAds_Config::get($t, 'referrer', array());
        if (!empty($refRules['allow']) && is_array($refRules['allow'])) {
            if (!PpwwcmsAds_Util::matchStringRules($ref, $refRules['allow'], true)) {
                return false;
            }
        }
        if (!empty($refRules['deny']) && is_array($refRules['deny'])) {
            if (PpwwcmsAds_Util::matchStringRules($ref, $refRules['deny'], false)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------- Shortcodes -------------------------

    protected function replaceShortcodes($html, array $config)
    {
        // Matches [[ads:slot-name]]
        return preg_replace_callback('/\\[\\[ads:([a-zA-Z0-9._-]{1,64})\\]\\]/', function ($m) use ($config) {
            $slot = $m[1];
            $meta = $this->getWwppcms()->getFileMeta();
            $out = $this->renderSlot($slot, $config, is_array($meta) ? $meta : array(), array('placement' => 'shortcode'));
            return $out !== '' ? $out : '';
        }, $html);
    }

    // ------------------------- Intrusive Mode -------------------------

    protected function shouldApplyIntrusive(array $config, array $currentPage, array $meta)
    {
        $mode = (string) PpwwcmsAds_Config::get($config, 'mode', 'compliant');
        if ($mode !== 'intrusive') {
            return false;
        }

        $intr = (array) PpwwcmsAds_Config::get($config, 'intrusive', array());
        if (empty($intr['enabled'])) {
            return false;
        }

        // Hard guardrail: only configured paths
        $paths = (array) PpwwcmsAds_Config::get($intr, 'paths', array());
        if (empty($paths)) {
            return false;
        }

        $path = $this->getCurrentPath();
        if (!PpwwcmsAds_Util::matchPathRules($path, $paths, true)) {
            return false;
        }

        // Additional opt-out via page meta
        if (!empty($meta['ads']) && (string) $meta['ads'] === 'off') {
            return false;
        }

        return true;
    }

    protected function enforceIntrusiveNoindex(array $config, array &$twigVariables)
    {
        $intr = (array) PpwwcmsAds_Config::get($config, 'intrusive', array());
        $force = PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($intr, 'noindex_force', true));
        if (!$force) {
            return;
        }

        // Set robots meta for themes that render it
        if (!isset($twigVariables['meta']) || !is_array($twigVariables['meta'])) {
            $twigVariables['meta'] = array();
        }
        $twigVariables['meta']['robots'] = 'noindex,nofollow';

        $proto = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($proto . ' 200 OK');
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    protected function renderIntrusiveOverlay(array $config, $slotName)
    {
        $intr = (array) PpwwcmsAds_Config::get($config, 'intrusive', array());
        $closeText = (string) PpwwcmsAds_Config::get($intr, 'close_text', '关闭');
        $ctaText = (string) PpwwcmsAds_Config::get($intr, 'cta_text', '继续');
        $ctaUrl = (string) PpwwcmsAds_Config::get($intr, 'cta_url', '');
        $countdown = (int) PpwwcmsAds_Config::get($intr, 'countdown', 3);
        if ($countdown < 0) {
            $countdown = 0;
        }

        // Use slot creatives as overlay content (optional)
        $body = $this->renderSlot($slotName, $config, $this->getWwppcms()->getFileMeta(), array('placement' => 'intrusive'));
        if ($body === '') {
            $body = '<div class="ppww-ads__intrusive-body">' . htmlspecialchars((string) PpwwcmsAds_Config::get($intr, 'text', '本页面包含赞助内容'), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $this->runtimeFlags['needsIntrusive'] = true;

        $attrs = array(
            'class' => 'ppww-ads-intrusive',
            'data-ppww-ads-intrusive' => '1',
            'data-ppww-ads-intrusive-countdown' => (string) $countdown,
        );
        if ($ctaUrl !== '') {
            $attrs['data-ppww-ads-intrusive-cta'] = $ctaUrl;
        }

        $html = '<div' . PpwwcmsAds_Util::htmlAttrs($attrs) . '>'
            . '<div class="ppww-ads-intrusive__overlay" role="dialog" aria-modal="true">'
            . '<div class="ppww-ads-intrusive__panel">'
            . $body
            . '<div class="ppww-ads-intrusive__actions">'
            . '<button type="button" class="ppww-ads-intrusive__close" data-ppww-ads-close disabled>' . htmlspecialchars($closeText, ENT_QUOTES, 'UTF-8') . '</button>'
            . ($ctaUrl !== '' ? '<a class="ppww-ads-intrusive__cta" href="#" data-ppww-ads-cta>' . htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8') . '</a>' : '')
            . '<span class="ppww-ads-intrusive__count" data-ppww-ads-count></span>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        return $html;
    }

    // ------------------------- Endpoints + Logging -------------------------

    protected function handleImpressionEndpoint(&$templateName, array &$twigVariables)
    {
        // This endpoint is requested by client-side beacons (optional).
        $proto = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($proto . ' 200 OK');
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $slot = isset($_GET['slot']) ? (string) $_GET['slot'] : '';
        $creative = isset($_GET['creative']) ? (string) $_GET['creative'] : '';
        if ($slot !== '') {
            $this->logEvent('imp', $slot, $creative);
        }

        // 1x1 transparent GIF (write & exit to avoid any theme/Twig output)
        $gif = base64_decode('R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        echo $gif;
        exit;
    }

    protected function handleClickEndpoint(&$templateName, array &$twigVariables)
    {
        $proto = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';

        $slot = isset($_GET['slot']) ? (string) $_GET['slot'] : '';
        $creative = isset($_GET['creative']) ? (string) $_GET['creative'] : '';
        $u = isset($_GET['u']) ? (string) $_GET['u'] : '';
        $url = $this->safeDecodeUrl($u);

        if ($slot !== '' && $url !== '') {
            $this->logEvent('click', $slot, $creative);
        }

        if ($url !== '') {
            header($proto . ' 302 Found');
            header('Location: ' . $url);
            exit;
        } else {
            header($proto . ' 400 Bad Request');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bad request';
            exit;
        }
    }

    protected function handleRuntimeEndpoint(&$templateName, array &$twigVariables)
    {
        $config = $this->getPluginConfig(null, array());

        $proto = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($proto . ' 200 OK');
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $bp = (int) PpwwcmsAds_Config::get($config, 'targeting.mobile_breakpoint', 768);
        if ($bp < 320) {
            $bp = 768;
        }

        echo $this->buildRuntimeJsSource($config, $bp);
        exit;
    }

    protected function logImpression($slot, $creative)
    {
        $config = $this->getPluginConfig(null, array());
        if (!PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'observability.enabled', true))) {
            return;
        }
        $this->logEvent('imp', $slot, $creative);
    }

    protected function logEvent($type, $slot, $creative)
    {
        $logger = new PpwwcmsAds_Logger(__DIR__ . '/logs');
        $logger->log(array(
            'type' => $type,
            'slot' => (string) $slot,
            'creative' => (string) $creative,
            'path' => $this->getCurrentPath(),
            'device' => $this->detectDevice(),
        ));
    }

    protected function detectDevice()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if ($ua === '') {
            return 'unknown';
        }
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) {
            return 'mobile';
        }
        return 'desktop';
    }

    protected function wrapClickUrl($slot, $creative, $url)
    {
        $config = $this->getPluginConfig(null, array());
        if (!PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'observability.click_endpoint', true))) {
            return $url;
        }

        // Build /ads/click?slot=...&creative=...&u=...
        $base = rtrim($this->getBaseUrl(), '/');
        $u = rawurlencode(base64_encode($url));
        $qs = 'slot=' . rawurlencode($slot) . '&creative=' . rawurlencode($creative) . '&u=' . $u;
        return $base . '/ads/click?' . $qs;
    }

    protected function safeDecodeUrl($encoded)
    {
        if ($encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            // maybe already url
            $raw = $encoded;
        }
        $raw = trim((string) $raw);
        if (!preg_match('#^https?://#i', $raw)) {
            return '';
        }
        return $raw;
    }

    // ------------------------- Runtime JS -------------------------

    protected function buildRuntimeScript(array $config)
    {
        $need = $this->runtimeFlags['needsFrequency'] || $this->runtimeFlags['needsDevice'] || $this->runtimeFlags['needsLazy'] || $this->runtimeFlags['needsIntrusive'];
        if (!$need) {
            // Still allow explicit enable
            if (!PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'runtime.force', false))) {
                return '';
            }
        }

        $bp = (int) PpwwcmsAds_Config::get($config, 'targeting.mobile_breakpoint', 768);
        if ($bp < 320) {
            $bp = 768;
        }

        $nonceAttr = $this->cspNonce ? ' nonce="' . htmlspecialchars($this->cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        // External runtime (hide inline JS in HTML)
        if (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'runtime.external.enabled', false))) {
            $base = rtrim($this->getBaseUrl(), '/');
            $v = substr(sha1(json_encode(array(
                'bp' => (int) $bp,
                'intr_cap_enabled' => (bool) PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'intrusive.frequency.enabled', true)),
                'intr_cap_ttl' => (int) PpwwcmsAds_Config::get($config, 'intrusive.frequency.ttl_seconds', 86400),
            ))), 0, 10);
            $src = $base . '/ads/runtime.js?v=' . rawurlencode($v);
            return '<script data-ppww-ads-runtime="1" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer' . $nonceAttr . '></script>';
        }

        $js = $this->buildRuntimeJsSource($config, $bp);
        return '<script data-ppww-ads-runtime="1"' . $nonceAttr . '>' . $js . '</script>';
    }

    protected function buildRuntimeJsSource(array $config, $bp)
    {
        $bp = (int) $bp;
        if ($bp < 320) {
            $bp = 768;
        }

        return "(function(){\n"
            . "  var bp=" . (int) $bp . ";\n"
            . "  function nowSec(){return Math.floor(Date.now()/1000);}\n"
            . "  function isMobile(){try{return window.matchMedia('(max-width:' + bp + 'px)').matches}catch(e){return false}}\n"
            . "  function keyFor(slot, win){var d=new Date();var p='ppww_ads:' + slot + ':' + win + ':';if(win==='hour'){return p + d.getFullYear()+('-'+(d.getMonth()+1))+('-'+d.getDate())+(':'+d.getHours())}return p + d.getFullYear()+('-'+(d.getMonth()+1))+('-'+d.getDate())}\n"
            . "  function getCount(k){try{var v=localStorage.getItem(k);return v?parseInt(v,10)||0:0}catch(e){return 0}}\n"
            . "  function setCount(k,v){try{localStorage.setItem(k,String(v))}catch(e){}}\n"
            . "  function applyAd(el){\n"
            . "    var slot=el.getAttribute('data-ppww-ads-slot')||'';\n"
            . "    var dev=el.getAttribute('data-ppww-ads-device');\n"
            . "    if(dev==='mobile' && !isMobile()){el.style.display='none';return}\n"
            . "    if(dev==='desktop' && isMobile()){el.style.display='none';return}\n"
            . "    var freq=el.getAttribute('data-ppww-ads-freq');\n"
            . "    if(freq){\n"
            . "      try{var o=JSON.parse(freq);var win=o.window||'day';var max=parseInt(o.max,10)||0; if(max>0){var k=keyFor(slot,win);var c=getCount(k); if(c>=max){el.style.display='none';return} setCount(k,c+1);} }catch(e){}\n"
            . "    }\n"
            . "    // lazy: basic (only show when near viewport)\n"
            . "    if(el.getAttribute('data-ppww-ads-lazy')==='1'){\n"
            . "      el.style.display='none';\n"
            . "      var io=null;\n"
            . "      var show=function(){el.style.display=''; if(io){io.disconnect();}};\n"
            . "      if('IntersectionObserver' in window){io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){show();}})},{rootMargin:'200px'}); io.observe(el);} else {show();}\n"
            . "    }\n"
            . "  }\n"
            . "  document.querySelectorAll('[data-ppww-ads]').forEach(applyAd);\n"
            . "  // intrusive overlay\n"
            . "  var intr=document.querySelector('[data-ppww-ads-intrusive]');\n"
            . "  if(intr){\n"
            . "    try{\n"
            . "      var capEnabled=" . (PpwwcmsAds_Config::boolVal(PpwwcmsAds_Config::get($config, 'intrusive.frequency.enabled', true)) ? 'true' : 'false') . ";\n"
            . "      var capTtl=" . (int) PpwwcmsAds_Config::get($config, 'intrusive.frequency.ttl_seconds', 86400) . ";\n"
            . "      if(capTtl<=0) capTtl=86400;\n"
            . "      if(capEnabled){\n"
            . "        var capKey='ppww_ads_intrusive_seen:' + (location.pathname||'/');\n"
            . "        var last=parseInt((localStorage.getItem(capKey)||'0'),10)||0;\n"
            . "        if(last && (nowSec()-last) < capTtl){ intr.style.display='none'; return; }\n"
            . "        localStorage.setItem(capKey, String(nowSec()));\n"
            . "      }\n"
            . "    }catch(e){}\n"
            . "    var closeBtn=intr.querySelector('[data-ppww-ads-close]');\n"
            . "    var countEl=intr.querySelector('[data-ppww-ads-count]');\n"
            . "    var cta=intr.querySelector('[data-ppww-ads-cta]');\n"
            . "    var sec=parseInt(intr.getAttribute('data-ppww-ads-intrusive-countdown')||'0',10)||0;\n"
            . "    var ctaUrl=intr.getAttribute('data-ppww-ads-intrusive-cta')||'';\n"
            . "    function close(){intr.style.display='none';}\n"
            . "    function tick(){if(sec<=0){if(closeBtn) closeBtn.disabled=false; if(countEl) countEl.textContent=''; return;} if(countEl) countEl.textContent='请等待 ' + sec + ' 秒'; sec--; setTimeout(tick,1000);}\n"
            . "    if(closeBtn){closeBtn.addEventListener('click', close);}\n"
            . "    if(cta && ctaUrl){cta.addEventListener('click', function(ev){ev.preventDefault(); window.location.href=ctaUrl;});}\n"
            . "    tick();\n"
            . "  }\n"
            . "})();";
    }

    // ------------------------- Helpers -------------------------

    protected function asTwigHtml($html)
    {
        if ($html === '') {
            return '';
        }
        if (class_exists('\\Twig\\Markup')) {
            return new \Twig\Markup($html, 'UTF-8');
        }
        return $html;
    }

    protected function getVisitorId()
    {
        $name = 'ppww_ads_vid';
        if (!empty($_COOKIE[$name]) && is_string($_COOKIE[$name]) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $_COOKIE[$name])) {
            return $_COOKIE[$name];
        }
        $vid = PpwwcmsAds_Util::randomId(16);
        // 180 days
        setcookie($name, $vid, time() + 180 * 86400, '/', '', false, true);
        $_COOKIE[$name] = $vid;
        return $vid;
    }

    protected function getCurrentPath()
    {
        $url = '';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $url = (string) $_SERVER['REQUEST_URI'];
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = '/';
        }
        // Normalize trailing slash like typical Wwppcms URLs
        if ($path === '') {
            $path = '/';
        }
        return $path;
    }

    protected function normalizeRequestUrl($url)
    {
        $u = trim((string) $url);
        $u = ltrim($u, '/');
        return $u;
    }
}

// ------------------------- Internal Helpers -------------------------

class PpwwcmsAds_Config
{
    public static function get($arr, $path, $default = null)
    {
        if (!is_array($arr)) {
            return $default;
        }
        if ($path === null || $path === '') {
            return $arr;
        }
        $parts = explode('.', $path);
        $cur = $arr;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    public static function boolVal($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, array('1', 'true', 'yes', 'on'), true);
    }
}

class PpwwcmsAds_Util
{
    public static function cssIdent($s)
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $s);
    }

    public static function htmlAttrs(array $attrs)
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out .= ' ' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out;
    }

    public static function randomId($len)
    {
        $b = random_bytes(max(8, (int) $len));
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function randomNonce($len)
    {
        return substr(self::randomId($len), 0, 24);
    }

    public static function stableBucket($s)
    {
        // 0..1
        $v = sprintf('%u', crc32((string) $s));
        return ((float) $v) / 4294967295.0;
    }

    public static function pickWeightedCreative(array $items, $bucket)
    {
        $sum = 0;
        foreach ($items as $it) {
            $w = isset($it['weight']) ? (int) $it['weight'] : 1;
            if ($w < 1) {
                $w = 1;
            }
            $sum += $w;
        }
        if ($sum <= 0) {
            return $items[0];
        }
        $x = $bucket * $sum;
        $acc = 0;
        foreach ($items as $it) {
            $w = isset($it['weight']) ? (int) $it['weight'] : 1;
            if ($w < 1) {
                $w = 1;
            }
            $acc += $w;
            if ($x <= $acc) {
                return $it;
            }
        }
        return $items[count($items) - 1];
    }

    public static function pickWeightedVariant(array $variants, $bucket)
    {
        $items = array();
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $v['weight'] = isset($v['weight']) ? (int) $v['weight'] : 1;
            if ($v['weight'] < 1) {
                $v['weight'] = 1;
            }
            $items[] = $v;
        }
        if (empty($items)) {
            return null;
        }
        return self::pickWeightedCreative($items, $bucket);
    }

    public static function matchPathRules($path, $rules, $defaultWhenEmpty)
    {
        $path = (string) $path;
        if (!is_array($rules) || empty($rules)) {
            return (bool) $defaultWhenEmpty;
        }
        foreach ($rules as $r) {
            $r = (string) $r;
            if ($r === '') {
                continue;
            }
            if (@preg_match($r, '') !== false && strlen($r) > 2 && $r[0] === '/' && substr($r, -1) === '/') {
                if (@preg_match($r, $path)) {
                    return true;
                }
                continue;
            }
            // glob style
            $re = '#^' . str_replace(array('\\*', '\\?'), array('.*', '.'), preg_quote($r, '#')) . '$#';
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }

    public static function matchStringRules($value, $rules, $defaultWhenEmpty)
    {
        $value = (string) $value;
        if (!is_array($rules) || empty($rules)) {
            return (bool) $defaultWhenEmpty;
        }
        foreach ($rules as $r) {
            $r = (string) $r;
            if ($r === '') {
                continue;
            }
            if (@preg_match($r, '') !== false && strlen($r) > 2 && $r[0] === '/' && substr($r, -1) === '/') {
                if (@preg_match($r, $value)) {
                    return true;
                }
                continue;
            }
            if (stripos($value, $r) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function inSchedule(array $schedule)
    {
        $start = isset($schedule['start']) ? strtotime((string) $schedule['start']) : null;
        $end = isset($schedule['end']) ? strtotime((string) $schedule['end']) : null;
        $now = time();
        if ($start && $now < $start) {
            return false;
        }
        if ($end && $now > $end) {
            return false;
        }
        return true;
    }

    public static function insertAfterParagraph($html, $n, $insertHtml)
    {
        if ($insertHtml === '' || $n < 1) {
            return $html;
        }
        $count = 0;
        return preg_replace_callback('~</p>~i', function ($m) use (&$count, $n, $insertHtml) {
            $count++;
            if ($count === $n) {
                return $m[0] . $insertHtml;
            }
            return $m[0];
        }, $html, -1);
    }
}

class PpwwcmsAds_Sanitizer
{
    public static function stripEventHandlers($html)
    {
        // Remove on* attributes (basic)
        return preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $html);
    }

    public static function sanitizeHtml($html, array $opts)
    {
        $allowScript = !empty($opts['allowScript']);
        $html = (string) $html;

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $doc->getElementsByTagName('div')->item(0);

        $allowedTags = array('a', 'div', 'span', 'p', 'img', 'ul', 'ol', 'li', 'strong', 'em', 'br', 'small', 'h2', 'h3', 'h4');
        if ($allowScript) {
            $allowedTags[] = 'script';
        }

        $allowedAttrs = array(
            'a' => array('href', 'title', 'class', 'id', 'rel', 'target', 'aria-label'),
            'img' => array('src', 'alt', 'title', 'class', 'id', 'loading', 'width', 'height', 'aria-label'),
            '*' => array('class', 'id', 'aria-label', 'role')
        );

        self::sanitizeNode($root, $allowedTags, $allowedAttrs);

        $out = '';
        foreach ($root->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }
        libxml_clear_errors();
        return $out;
    }

    protected static function sanitizeNode(DOMNode $node, array $allowedTags, array $allowedAttrs)
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                // Replace node with its children
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    return;
                }
            }

            // Strip attrs
            $keep = array();
            if (isset($allowedAttrs[$tag])) {
                $keep = array_merge($keep, $allowedAttrs[$tag]);
            }
            $keep = array_merge($keep, $allowedAttrs['*']);

            $toRemove = array();
            foreach (iterator_to_array($node->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (strpos($name, 'on') === 0) {
                    $toRemove[] = $attr->name;
                    continue;
                }
                if (strpos($name, 'data-') === 0) {
                    continue;
                }
                if (!in_array($name, $keep, true)) {
                    $toRemove[] = $attr->name;
                    continue;
                }
                if (($tag === 'a') && ($name === 'href')) {
                    $v = (string) $attr->value;
                    if (preg_match('#^\s*javascript:#i', $v)) {
                        $toRemove[] = $attr->name;
                    }
                }
                if (($tag === 'img') && ($name === 'src')) {
                    $v = (string) $attr->value;
                    if (preg_match('#^\s*javascript:#i', $v)) {
                        $toRemove[] = $attr->name;
                    }
                }
            }
            foreach ($toRemove as $n) {
                $node->removeAttribute($n);
            }
        }

        // Recurse children safely
        $children = array();
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            self::sanitizeNode($c, $allowedTags, $allowedAttrs);
        }
    }

    public static function rewriteAnchorsRel($html, $nofollow)
    {
        $nofollow = (bool) $nofollow;
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . (string) $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $doc->getElementsByTagName('div')->item(0);
        $as = $root ? $root->getElementsByTagName('a') : null;
        if ($as) {
            foreach ($as as $a) {
                $rel = trim((string) $a->getAttribute('rel'));
                $parts = $rel !== '' ? preg_split('/\s+/', $rel) : array();
                $parts = array_filter($parts);
                if (!in_array('sponsored', $parts, true)) {
                    $parts[] = 'sponsored';
                }
                if ($nofollow && !in_array('nofollow', $parts, true)) {
                    $parts[] = 'nofollow';
                }
                $a->setAttribute('rel', implode(' ', $parts));
            }
        }
        $out = '';
        foreach ($root->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }
        libxml_clear_errors();
        return $out;
    }

    public static function addScriptNonce($html, $nonce)
    {
        $nonce = (string) $nonce;
        if ($nonce === '') {
            return $html;
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . (string) $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $doc->getElementsByTagName('div')->item(0);
        $scripts = $root ? $root->getElementsByTagName('script') : null;
        if ($scripts) {
            foreach ($scripts as $s) {
                if (!$s->hasAttribute('nonce')) {
                    $s->setAttribute('nonce', $nonce);
                }
                // force async for external scripts if present
                if ($s->hasAttribute('src') && !$s->hasAttribute('async')) {
                    $s->setAttribute('async', 'async');
                }
            }
        }
        $out = '';
        foreach ($root->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }
        libxml_clear_errors();
        return $out;
    }
}

class PpwwcmsAds_Logger
{
    protected $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim((string) $dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function log(array $event)
    {
        $day = date('Y-m-d');
        $file = $this->dir . '/' . $day . '.json';
        $data = array();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $type = isset($event['type']) ? (string) $event['type'] : 'imp';
        $slot = isset($event['slot']) ? (string) $event['slot'] : '';
        $creative = isset($event['creative']) ? (string) $event['creative'] : '';
        $path = isset($event['path']) ? (string) $event['path'] : '';

        if (!isset($data[$type])) {
            $data[$type] = array();
        }
        $k = $slot . '|' . $creative . '|' . $path;
        if (!isset($data[$type][$k])) {
            $data[$type][$k] = array(
                'slot' => $slot,
                'creative' => $creative,
                'path' => $path,
                'count' => 0,
            );
        }
        $data[$type][$k]['count']++;

        $data['_updated_at'] = date('c');
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
