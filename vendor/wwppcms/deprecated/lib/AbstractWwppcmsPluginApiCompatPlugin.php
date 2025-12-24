<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/lib/AbstractWwppcmsPluginApiCompatPlugin.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Abstract class to extend from when implementing a WwppcmsDeprecated plugin API
 * compatibility plugin
 *
 * Please refer to {@see WwppcmsPluginApiCompatPluginInterface} for more information about
 * how to develop a WwppcmsDeprecated plugin API compatibility plugin.
 *
 * @see     WwppcmsPluginApiCompatPluginInterface
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
abstract class AbstractWwppcmsPluginApiCompatPlugin extends AbstractWwppcmsCompatPlugin implements
    WwppcmsPluginApiCompatPluginInterface
{
    /**
     * Map of core events matching event signatures of older API versions
     *
     * @see AbstractWwppcmsPluginApiCompatPlugin::handleEvent()
     *
     * @var array<string,string>
     */
    protected $eventAliases = array();

    /**
     * {@inheritDoc}
     */
    public function handleEvent($eventName, array $params)
    {
        parent::handleEvent($eventName, $params);

        // trigger core events matching the event signatures of older API versions
        if (isset($this->eventAliases[$eventName])) {
            foreach ($this->eventAliases[$eventName] as $eventAlias) {
                $this->triggerEvent($eventAlias, $params);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function handleCustomEvent($eventName, array $params = array())
    {
        $this->getWwppcmsDeprecated()->triggerEvent($this->getApiVersionSupport(), $eventName, $params);
    }

    /**
     * Triggers deprecated events on plugins of the supported API version
     *
     * @param string $eventName name of the event to trigger
     * @param array  $params    optional parameters to pass
     */
    protected function triggerEvent($eventName, array $params = array())
    {
        $apiVersion = $this->getApiVersionSupport();
        $wwppcmsDeprecated = $this->getWwppcmsDeprecated();

        if ($apiVersion !== $wwppcmsDeprecated::API_VERSION) {
            foreach ($wwppcmsDeprecated->getCompatPlugins() as $compatPlugin) {
                if ($compatPlugin->getApiVersion() === $apiVersion) {
                    $compatPlugin->handleEvent($eventName, $params);
                }
            }
        }

        $wwppcmsDeprecated->triggerEvent($apiVersion, $eventName, $params);
    }
}
