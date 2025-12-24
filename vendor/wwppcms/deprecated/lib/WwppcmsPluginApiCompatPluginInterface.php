<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/lib/WwppcmsPluginApiCompatPluginInterface.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Common interface for WwppcmsDeprecated plugin API compatibility plugins
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
interface WwppcmsPluginApiCompatPluginInterface extends WwppcmsCompatPluginInterface
{
    /**
     * Handles custom events for plugins of the supported API version
     *
     * @param string $eventName name of the triggered event
     * @param array  $params    passed parameters
     */
    public function handleCustomEvent($eventName, array $params = array());

    /**
     * Returns the API version this plugin maintains backward compatibility for
     *
     * @return int
     */
    public function getApiVersionSupport();
}
