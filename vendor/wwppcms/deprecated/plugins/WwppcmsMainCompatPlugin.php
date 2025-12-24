<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/plugins/WwppcmsMainCompatPlugin.php>
 *
 * This file was created by splitting up an original file into multiple files,
 * which in turn was previously part of the project's main repository. The
 * version control history of these files apply accordingly, available from
 * the following original locations:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/90ea3d5a9767f1511f165e051dd7ffb8f1b3f92e/WwppcmsDeprecated.php>
 * <https://github.com/wwppcms/Wwppcms/blob/82a342ba445122182b898a2c1800f03c8d16f18c/plugins/00-WwppcmsDeprecated.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Maintains backward compatibility with older Wwppcms versions
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
class WwppcmsMainCompatPlugin extends AbstractWwppcmsCompatPlugin
{
    /**
     * Load's config.php from Wwppcms's root and config dir
     *
     * Since we want to utilize Wwppcms's own code dealing with particular config
     * settings (like making paths and URLs absolute), we must call this before
     * {@see Wwppcms::loadConfig()}. `onConfigLoaded` is triggered later, thus we
     * use the `onPluginsLoaded` event.
     *
     * @see WwppcmsMainCompatPlugin::loadScriptedConfig()
     *
     * @param object[] $plugins loaded plugin instances
     */
    public function onPluginsLoaded(array $plugins)
    {
        // deprecated since Wwppcms 1.0
        if (is_file($this->getWwppcms()->getRootDir() . 'config.php')) {
            $this->loadScriptedConfig($this->getWwppcms()->getRootDir() . 'config.php');
        }

        // deprecated since Wwppcms 2.0
        if (is_file($this->getWwppcms()->getConfigDir() . 'config.php')) {
            $this->loadScriptedConfig($this->getWwppcms()->getConfigDir() . 'config.php');
        }
    }

    /**
     * Reads a Wwppcms PHP config file and injects the config into Wwppcms
     *
     * This method injects the config into Wwppcms using PHP's Reflection API
     * (i.e. {@see ReflectionClass}). Even though the Reflection API was
     * created to aid development and not to do things like this, it's the best
     * solution. Otherwise we'd have to copy all of Wwppcms's code dealing with
     * special config settings (like making paths and URLs absolute).
     *
     * @see WwppcmsMainCompatPlugin::onConfigLoaded()
     * @see Wwppcms::loadConfig()
     *
     * @param string $configFile path to the config file to load
     */
    protected function loadScriptedConfig($configFile)
    {
        // scope isolated require()
        $includeConfigClosure = function ($configFile) {
            require($configFile);
            return (isset($config) && is_array($config)) ? $config : array();
        };
        if (PHP_VERSION_ID >= 50400) {
            $includeConfigClosure = $includeConfigClosure->bindTo(null);
        }

        $scriptedConfig = $includeConfigClosure($configFile);

        if (!empty($scriptedConfig)) {
            $wwppcmsReflector = new ReflectionObject($this->getWwppcms());
            $wwppcmsConfigReflector = $wwppcmsReflector->getProperty('config');
            $wwppcmsConfigReflector->setAccessible(true);

            $config = $wwppcmsConfigReflector->getValue($this->getWwppcms()) ?: array();
            $config += $scriptedConfig;

            $wwppcmsConfigReflector->setValue($this->getWwppcms(), $config);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getApiVersion()
    {
        return WwppcmsDeprecated::API_VERSION_3;
    }
}
