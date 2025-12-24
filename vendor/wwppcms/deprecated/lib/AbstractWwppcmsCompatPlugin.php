<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/lib/AbstractWwppcmsCompatPlugin.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

/**
 * Abstract class to extend from when implementing a WwppcmsDeprecated
 * compatibility plugin
 *
 * Please refer to {@see WwppcmsCompatPluginInterface} for more information about
 * how to develop a WwppcmsDeprecated compatibility plugin.
 *
 * @see WwppcmsCompatPluginInterface
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
abstract class AbstractWwppcmsCompatPlugin implements WwppcmsCompatPluginInterface
{
    /**
     * Current instance of Wwppcms
     *
     * @see WwppcmsCompatPluginInterface::getWwppcms()
     *
     * @var Wwppcms
     */
    protected $wwppcms;

    /**
     * Instance of the main WwppcmsDeprecated plugin
     *
     * @see WwppcmsCompatPluginInterface::getWwppcmsDeprecated()
     *
     * @var WwppcmsDeprecated
     */
    protected $wwppcmsDeprecated;

    /**
     * List of plugins which this plugin depends on
     *
     * @see WwppcmsCompatPluginInterface::getDependencies()
     *
     * @var string[]
     */
    protected $dependsOn = array();

    /**
     * Constructs a new instance of a WwppcmsDeprecated compatibility plugin
     *
     * @param Wwppcms           $wwppcms           current instance of Wwppcms
     * @param WwppcmsDeprecated $wwppcmsDeprecated current instance of WwppcmsDeprecated
     */
    public function __construct(Wwppcms $wwppcms, WwppcmsDeprecated $wwppcmsDeprecated)
    {
        $this->wwppcms = $wwppcms;
        $this->wwppcmsDeprecated = $wwppcmsDeprecated;
    }

    /**
     * {@inheritDoc}
     */
    public function handleEvent($eventName, array $params)
    {
        if (method_exists($this, $eventName)) {
            call_user_func_array(array($this, $eventName), $params);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getWwppcms()
    {
        return $this->wwppcms;
    }

    /**
     * {@inheritDoc}
     */
    public function getWwppcmsDeprecated()
    {
        return $this->wwppcmsDeprecated;
    }

    /**
     * {@inheritDoc}
     */
    public function getDependencies()
    {
        return (array) $this->dependsOn;
    }
}
