<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/lib/WwppcmsCompatPluginInterface.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Common interface for WwppcmsDeprecated compatibility plugins
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
interface WwppcmsCompatPluginInterface
{
    /**
     * Handles a Wwppcms event
     *
     * @param string $eventName name of the triggered event
     * @param array  $params    passed parameters
     */
    public function handleEvent($eventName, array $params);

    /**
     * Returns a list of names of compat plugins required by this plugin
     *
     * @return string[] required plugins
     */
    public function getDependencies();

    /**
     * Returns the plugin's instance of Wwppcms
     *
     * @see Wwppcms
     *
     * @return Wwppcms the plugin's instance of Wwppcms
     */
    public function getWwppcms();

    /**
     * Returns the plugin's main WwppcmsDeprecated plugin instance
     *
     * @see WwppcmsDeprecated
     *
     * @return WwppcmsDeprecated the plugin's instance of Wwppcms
     */
    public function getWwppcmsDeprecated();

    /**
     * Returns the version of the API this plugin uses
     *
     * @return int the API version used by this plugin
     */
    public function getApiVersion();
}
