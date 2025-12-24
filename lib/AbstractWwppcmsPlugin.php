<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/Wwppcms/blob/master/lib/AbstractWwppcmsPlugin.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Abstract class to extend from when implementing a Wwppcms plugin
 *
 * Please refer to {@see WwppcmsPluginInterface} for more information about how
 * to develop a plugin for Wwppcms.
 *
 * @see WwppcmsPluginInterface
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
abstract class AbstractWwppcmsPlugin implements WwppcmsPluginInterface
{
    /**
     * Current instance of Wwppcms
     *
     * @see WwppcmsPluginInterface::getWwppcms()
     * @var Wwppcms
     */
    protected $wwppcms;

    /**
     * Boolean indicating if this plugin is enabled (TRUE) or disabled (FALSE)
     *
     * @see WwppcmsPluginInterface::isEnabled()
     * @see WwppcmsPluginInterface::setEnabled()
     * @var bool|null
     */
    protected $enabled;

    /**
     * Boolean indicating if this plugin was ever enabled/disabled manually
     *
     * @see WwppcmsPluginInterface::isStatusChanged()
     * @var bool
     */
    protected $statusChanged = false;

    /**
     * Boolean indicating whether this plugin matches Wwppcms's API version
     *
     * @see AbstractWwppcmsPlugin::checkCompatibility()
     * @var bool|null
     */
    protected $nativePlugin;

    /**
     * List of plugins which this plugin depends on
     *
     * @see AbstractWwppcmsPlugin::checkDependencies()
     * @see WwppcmsPluginInterface::getDependencies()
     * @var string[]
     */
    protected $dependsOn = array();

    /**
     * List of plugin which depend on this plugin
     *
     * @see AbstractWwppcmsPlugin::checkDependants()
     * @see WwppcmsPluginInterface::getDependants()
     * @var object[]|null
     */
    protected $dependants;

    /**
     * Constructs a new instance of a Wwppcms plugin
     *
     * @param Wwppcms $wwppcms current instance of Wwppcms
     */
    public function __construct(Wwppcms $wwppcms)
    {
        $this->wwppcms = $wwppcms;
    }

    /**
     * {@inheritDoc}
     */
    public function handleEvent($eventName, array $params)
    {
        // plugins can be enabled/disabled using the config
        if ($eventName === 'onConfigLoaded') {
            $this->configEnabled();
        }

        if ($this->isEnabled() || ($eventName === 'onPluginsLoaded')) {
            if (method_exists($this, $eventName)) {
                call_user_func_array(array($this, $eventName), $params);
            }
        }
    }

    /**
     * Enables or disables this plugin depending on Wwppcms's config
     */
    protected function configEnabled()
    {
        $pluginEnabled = $this->getWwppcms()->getConfig(get_called_class() . '.enabled');
        if ($pluginEnabled !== null) {
            $this->setEnabled($pluginEnabled);
        } else {
            $pluginEnabled = $this->getPluginConfig('enabled');
            if ($pluginEnabled !== null) {
                $this->setEnabled($pluginEnabled);
            } elseif ($this->enabled) {
                $this->setEnabled(true, true, true);
            } elseif ($this->enabled === null) {
                // make sure dependencies are already fulfilled,
                // otherwise the plugin needs to be enabled manually
                try {
                    $this->setEnabled(true, false, true);
                } catch (RuntimeException $e) {
                    $this->enabled = false;
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setEnabled($enabled, $recursive = true, $auto = false)
    {
        $this->statusChanged = (!$this->statusChanged) ? !$auto : true;
        $this->enabled = (bool) $enabled;

        if ($enabled) {
            $this->checkCompatibility();
            $this->checkDependencies($recursive);
        } else {
            $this->checkDependants($recursive);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * {@inheritDoc}
     */
    public function isStatusChanged()
    {
        return $this->statusChanged;
    }

    /**
     * {@inheritDoc}
     */
    public function getWwppcms()
    {
        return $this->wwppcms;
    }

    /**
     * Returns either the value of the specified plugin config variable or
     * the config array
     *
     * @param string $configName optional name of a config variable
     * @param mixed  $default    optional default value to return when the
     *     named config variable doesn't exist
     *
     * @return mixed if no name of a config variable has been supplied, the
     *     plugin's config array is returned; otherwise it returns either the
     *     value of the named config variable, or, if the named config variable
     *     doesn't exist, the provided default value or NULL
     */
    public function getPluginConfig($configName = null, $default = null)
    {
        $pluginConfig = $this->getWwppcms()->getConfig(get_called_class(), array());

        if ($configName === null) {
            return $pluginConfig;
        }

        return isset($pluginConfig[$configName]) ? $pluginConfig[$configName] : $default;
    }

    /**
     * Passes all not satisfiable method calls to Wwppcms
     *
     * @see WwppcmsPluginInterface::getWwppcms()
     *
     * @deprecated 2.1.0
     *
     * @param string $methodName name of the method to call
     * @param array  $params     parameters to pass
     *
     * @return mixed return value of the called method
     */
    public function __call($methodName, array $params)
    {
        if (method_exists($this->getWwppcms(), $methodName)) {
            return call_user_func_array(array($this->getWwppcms(), $methodName), $params);
        }

        throw new BadMethodCallException(
            'Call to undefined method ' . get_class($this->getWwppcms()) . '::' . $methodName . '() '
            . 'through ' . get_called_class() . '::__call()'
        );
    }

    /**
     * Enables all plugins which this plugin depends on
     *
     * @see WwppcmsPluginInterface::getDependencies()
     *
     * @param bool $recursive enable required plugins automatically
     *
     * @throws RuntimeException thrown when a dependency fails
     */
    protected function checkDependencies($recursive)
    {
        foreach ($this->getDependencies() as $pluginName) {
            try {
                $plugin = $this->getWwppcms()->getPlugin($pluginName);
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    "Unable to enable plugin '" . get_called_class() . "': "
                    . "Required plugin '" . $pluginName . "' not found"
                );
            }

            // plugins which don't implement WwppcmsPluginInterface are always enabled
            if (($plugin instanceof WwppcmsPluginInterface) && !$plugin->isEnabled()) {
                if ($recursive) {
                    if (!$plugin->isStatusChanged()) {
                        $plugin->setEnabled(true, true, true);
                    } else {
                        throw new RuntimeException(
                            "Unable to enable plugin '" . get_called_class() . "': "
                            . "Required plugin '" . $pluginName . "' was disabled manually"
                        );
                    }
                } else {
                    throw new RuntimeException(
                        "Unable to enable plugin '" . get_called_class() . "': "
                        . "Required plugin '" . $pluginName . "' is disabled"
                    );
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDependencies()
    {
        return (array) $this->dependsOn;
    }

    /**
     * Disables all plugins which depend on this plugin
     *
     * @see WwppcmsPluginInterface::getDependants()
     *
     * @param bool $recursive disabled dependant plugins automatically
     *
     * @throws RuntimeException thrown when a dependency fails
     */
    protected function checkDependants($recursive)
    {
        $dependants = $this->getDependants();
        if ($dependants) {
            if ($recursive) {
                foreach ($this->getDependants() as $pluginName => $plugin) {
                    if ($plugin->isEnabled()) {
                        if (!$plugin->isStatusChanged()) {
                            $plugin->setEnabled(false, true, true);
                        } else {
                            throw new RuntimeException(
                                "Unable to disable plugin '" . get_called_class() . "': "
                                . "Required by manually enabled plugin '" . $pluginName . "'"
                            );
                        }
                    }
                }
            } else {
                $dependantsList = 'plugin' . ((count($dependants) > 1) ? 's' : '') . ' '
                    . "'" . implode("', '", array_keys($dependants)) . "'";
                throw new RuntimeException(
                    "Unable to disable plugin '" . get_called_class() . "': "
                    . "Required by " . $dependantsList
                );
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDependants()
    {
        if ($this->dependants === null) {
            $this->dependants = array();
            foreach ($this->getWwppcms()->getPlugins() as $pluginName => $plugin) {
                // only plugins which implement WwppcmsPluginInterface support dependencies
                if ($plugin instanceof WwppcmsPluginInterface) {
                    $dependencies = $plugin->getDependencies();
                    if (in_array(get_called_class(), $dependencies)) {
                        $this->dependants[$pluginName] = $plugin;
                    }
                }
            }
        }

        return $this->dependants;
    }

    /**
     * Checks compatibility with Wwppcms's API version
     *
     * Wwppcms automatically adds a dependency to {@see WwppcmsDeprecated} when the
     * plugin's API is older than Wwppcms's API. {@see WwppcmsDeprecated} furthermore
     * throws a exception if it can't provide compatibility in such cases.
     * However, we still have to decide whether this plugin is compatible to
     * newer API versions, what requires some special (version specific)
     * precaution and is therefore usually not the case.
     *
     * @throws RuntimeException thrown when the plugin's and Wwppcms's API aren't
     *     compatible
     */
    protected function checkCompatibility()
    {
        if ($this->nativePlugin === null) {
            $wwppcmsClassName = get_class($this->wwppcms);
            $wwppcmsApiVersion = defined($wwppcmsClassName . '::API_VERSION') ? $wwppcmsClassName::API_VERSION : 1;
            $pluginApiVersion = defined('static::API_VERSION') ? static::API_VERSION : 1;

            $this->nativePlugin = ($pluginApiVersion === $wwppcmsApiVersion);

            if (!$this->nativePlugin && ($pluginApiVersion > $wwppcmsApiVersion)) {
                throw new RuntimeException(
                    "Unable to enable plugin '" . get_called_class() . "': The plugin's API (version "
                    . $pluginApiVersion . ") isn't compatible with Wwppcms's API (version " . $wwppcmsApiVersion . ")"
                );
            }
        }
    }
}
