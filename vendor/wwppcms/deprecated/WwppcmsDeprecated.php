<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/WwppcmsDeprecated.php>
 *
 * The file was previously part of the project's main repository; the version
 * control history of the original file applies accordingly, available from
 * the following original location:
 *
 * <https://github.com/wwppcms/Wwppcms/blob/82a342ba445122182b898a2c1800f03c8d16f18c/plugins/00-WwppcmsDeprecated.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Maintain backward compatibility to older Wwppcms releases
 *
 * `WwppcmsDeprecated`'s purpose is to maintain backward compatibility to older
 * versions of Wwppcms, by re-introducing characteristics that were removed from
 * Wwppcms's core.
 *
 * `WwppcmsDeprecated` is basically a mandatory plugin for all Wwppcms installs.
 * Without this plugin you can't use plugins which were written for other
 * API versions than the one of Wwppcms's core, even when there was just the
 * slightest change.
 *
 * {@see http://wwppcms.org/plugins/deprecated/} for a full list of features.
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
class WwppcmsDeprecated extends AbstractWwppcmsPlugin
{
    /**
     * API version used by this plugin
     *
     * @var int
     */
    const API_VERSION = 3;

    /**
     * API version 0, used by Wwppcms 0.9 and earlier
     *
     * @var int
     */
    const API_VERSION_0 = 0;

    /**
     * API version 1, used by Wwppcms 1.0
     *
     * @var int
     */
    const API_VERSION_1 = 1;

    /**
     * API version 2, used by Wwppcms 2.0
     *
     * @var int
     */
    const API_VERSION_2 = 2;

    /**
     * API version 3, used by Wwppcms 2.1
     *
     * @var int
     */
    const API_VERSION_3 = 3;

    /**
     * Loaded plugins, indexed by API version
     *
     * @see WwppcmsDeprecated::getPlugins()
     *
     * @var object[]
     */
    protected $plugins = array();

    /**
     * Loaded compatibility plugins
     *
     * @see WwppcmsDeprecated::getCompatPlugins()
     *
     * @var WwppcmsCompatPluginInterface[]
     */
    protected $compatPlugins = array();

    /**
     * {@inheritDoc}
     */
    public function __construct(Wwppcms $wwppcms)
    {
        parent::__construct($wwppcms);

        if (is_file(__DIR__ . '/vendor/autoload.php')) {
            require(__DIR__ . '/vendor/autoload.php');
        }

        if (!class_exists('WwppcmsMainCompatPlugin')) {
            die(
                "Cannot find WwppcmsDeprecated's 'vendor/autoload.php'. If you're using a composer-based Wwppcms install, "
                . "run `composer update`. If you're rather trying to use one of WwppcmsDeprecated's pre-built release "
                . "packages, make sure to download WwppcmsDeprecated's release package matching Wwppcms's version named "
                . "'wwppcms-deprecated-release-v*.tar.gz' (don't download a source code package)."
            );
        }

        if ($wwppcms::API_VERSION !== static::API_VERSION) {
            throw new RuntimeException(
                'WwppcmsDeprecated requires API version ' . static::API_VERSION . ', '
                . 'but Wwppcms is running API version ' . $wwppcms::API_VERSION
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function handleEvent($eventName, array $params)
    {
        parent::handleEvent($eventName, $params);

        // trigger events on compatibility plugins
        if ($this->isEnabled() || ($eventName === 'onPluginsLoaded')) {
            $isCoreEvent = in_array($eventName, $this->getCoreEvents());
            foreach ($this->compatPlugins as $plugin) {
                if ($isCoreEvent) {
                    if ($plugin->getApiVersion() === static::API_VERSION) {
                        $plugin->handleEvent($eventName, $params);
                    }
                } elseif ($plugin instanceof WwppcmsPluginApiCompatPluginInterface) {
                    $plugin->handleCustomEvent($eventName, $params);
                }
            }
        }
    }

    /**
     * Reads all loaded plugins and indexes them by API level, loads the
     * necessary compatibility plugins
     *
     * @see WwppcmsDeprecated::loadPlugin()
     *
     * @param object[] $plugins loaded plugin instances
     */
    public function onPluginsLoaded(array $plugins)
    {
        $this->loadCompatPlugin('WwppcmsMainCompatPlugin');

        foreach ($plugins as $plugin) {
            $this->loadPlugin($plugin);
        }

        $this->getWwppcms()->triggerEvent('onWwppcmsDeprecated', array($this));
    }

    /**
     * Adds a manually loaded plugin to WwppcmsDeprecated's plugin index, loads
     * the necessary compatibility plugins
     *
     * @see WwppcmsDeprecated::loadPlugin()
     *
     * @param object $plugin loaded plugin instance
     */
    public function onPluginManuallyLoaded($plugin)
    {
        $this->loadPlugin($plugin);
    }

    /**
     * Loads a compatibility plugin if Wwppcms's theme uses a old theme API
     *
     * @param string $theme           name of current theme
     * @param int    $themeApiVersion API version of the theme
     * @param array  $themeConfig     config array of the theme
     */
    public function onThemeLoaded($theme, $themeApiVersion, array &$themeConfig)
    {
        $this->loadThemeApiCompatPlugin($themeApiVersion);
    }

    /**
     * Adds a plugin to WwppcmsDeprecated's plugin index
     *
     * @see WwppcmsDeprecated::onPluginsLoaded()
     * @see WwppcmsDeprecated::onPluginManuallyLoaded()
     * @see WwppcmsDeprecated::getPlugins()
     *
     * @param object $plugin loaded plugin instance
     */
    protected function loadPlugin($plugin)
    {
        $pluginName = get_class($plugin);

        $apiVersion = $this->getPluginApiVersion($plugin);
        if (!isset($this->plugins[$apiVersion])) {
            $this->plugins[$apiVersion] = array();
            $this->loadPluginApiCompatPlugin($apiVersion);
        }

        $this->plugins[$apiVersion][$pluginName] = $plugin;
    }

    /**
     * Returns a list of all loaded Wwppcms plugins using the given API level
     *
     * @param int $apiVersion API version to match plugins
     *
     * @return object[] loaded plugin instances
     */
    public function getPlugins($apiVersion)
    {
        return isset($this->plugins[$apiVersion]) ? $this->plugins[$apiVersion] : array();
    }

    /**
     * Loads a compatibility plugin
     *
     * @param WwppcmsCompatPluginInterface|string $plugin either the class name of
     *     a plugin to instantiate or a plugin instance
     *
     * @return WwppcmsCompatPluginInterface instance of the loaded plugin
     */
    public function loadCompatPlugin($plugin)
    {
        if (!is_object($plugin)) {
            $className = (string) $plugin;
            if (class_exists($className)) {
                $plugin = new $className($this->getWwppcms(), $this);
            } else {
                throw new RuntimeException(
                    "Unable to load WwppcmsDeprecated compatibility plugin '" . $className . "': Class not found"
                );
            }
        }

        $className = get_class($plugin);
        if (isset($this->compatPlugins[$className])) {
            return $this->compatPlugins[$className];
        }

        if (!($plugin instanceof WwppcmsCompatPluginInterface)) {
            throw new RuntimeException(
                "Unable to load WwppcmsDeprecated compatibility plugin '" . $className . "': "
                . "Compatibility plugins must implement 'WwppcmsCompatPluginInterface'"
            );
        }

        $apiVersion = $plugin->getApiVersion();
        $this->loadPluginApiCompatPlugin($apiVersion);

        $dependsOn = $plugin->getDependencies();
        foreach ($dependsOn as $pluginDependency) {
            $this->loadCompatPlugin($pluginDependency);
        }

        $this->compatPlugins[$className] = $plugin;

        return $plugin;
    }

    /**
     * Loads a plugin API compatibility plugin
     *
     * @param int $apiVersion API version to load the compatibility plugin for
     */
    protected function loadPluginApiCompatPlugin($apiVersion)
    {
        if ($apiVersion !== static::API_VERSION) {
            $this->loadCompatPlugin('WwppcmsPluginApi' . $apiVersion . 'CompatPlugin');
        }
    }

    /**
     * Loads a theme API compatibility plugin
     *
     * @param int $apiVersion API version to load the compatibility plugin for
     */
    protected function loadThemeApiCompatPlugin($apiVersion)
    {
        if ($apiVersion !== static::API_VERSION) {
            $this->loadCompatPlugin('WwppcmsThemeApi' . $apiVersion . 'CompatPlugin');
        }
    }

    /**
     * Returns all loaded compatibility plugins
     *
     * @return WwppcmsCompatPluginInterface[] list of loaded compatibility plugins
     */
    public function getCompatPlugins()
    {
        return $this->compatPlugins;
    }

    /**
     * Triggers deprecated events on plugins of different API versions
     *
     * You can use this public method in other plugins to trigger custom events
     * on plugins using a particular API version. If you want to trigger a
     * custom event on all plugins, no matter their API version (except for
     * plugins using API v0, which can't handle custom events), use
     * {@see Wwppcms::triggerEvent()} instead.
     *
     * @see Wwppcms::triggerEvent()
     *
     * @param int    $apiVersion API version of the event
     * @param string $eventName  event to trigger
     * @param array  $params     optional parameters to pass
     */
    public function triggerEvent($apiVersion, $eventName, array $params = array())
    {
        foreach ($this->getPlugins($apiVersion) as $plugin) {
            $plugin->handleEvent($eventName, $params);
        }
    }

    /**
     * Returns the API version of a given plugin
     *
     * @param object $plugin plugin instance
     *
     * @return int API version used by the plugin
     */
    public function getPluginApiVersion($plugin)
    {
        $pluginApiVersion = self::API_VERSION_0;
        if ($plugin instanceof WwppcmsPluginInterface) {
            $pluginApiVersion = self::API_VERSION_1;
            if (defined(get_class($plugin) . '::API_VERSION')) {
                $pluginApiVersion = $plugin::API_VERSION;
            }
        }

        return $pluginApiVersion;
    }

    /**
     * Returns a list of the names of Wwppcms's core events
     *
     * @return string[] list of Wwppcms's core events
     */
    public function getCoreEvents()
    {
        return array(
            'onPluginsLoaded',
            'onPluginManuallyLoaded',
            'onConfigLoaded',
            'onThemeLoading',
            'onThemeLoaded',
            'onRequestUrl',
            'onRequestFile',
            'onContentLoading',
            'on404ContentLoading',
            'on404ContentLoaded',
            'onContentLoaded',
            'onMetaParsing',
            'onMetaParsed',
            'onContentParsing',
            'onContentPrepared',
            'onContentParsed',
            'onPagesLoading',
            'onSinglePageLoading',
            'onSinglePageContent',
            'onSinglePageLoaded',
            'onPagesDiscovered',
            'onPagesLoaded',
            'onCurrentPageDiscovered',
            'onPageTreeBuilt',
            'onPageRendering',
            'onPageRendered',
            'onMetaHeaders',
            'onYamlParserRegistered',
            'onParsedownRegistered',
            'onTwigRegistered'
        );
    }
}
