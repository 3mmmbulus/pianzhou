<?php
/**
 * This file is part of Wwppcms. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/wwppcms/wwppcms-deprecated/blob/master/plugins/WwppcmsThemeApi0CompatPlugin.php>
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
 * Maintains backward compatibility with themes using API version 0, written
 * for Wwppcms 0.9 and earlier
 *
 * Since there were no theme-related changes between Wwppcms 0.9 and Wwppcms 1.0,
 * this compat plugin doesn't hold any code itself, it just depends on
 * {@see WwppcmsThemeApi1CompatPlugin}. Since themes didn't support API versioning
 * until Wwppcms 2.1 (i.e. API version 3), all older themes will appear to use API
 * version 0.
 *
 * @author  Daniel Rudolf
 * @link    http://wwppcms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 2.1
 */
class WwppcmsThemeApi0CompatPlugin extends AbstractWwppcmsCompatPlugin
{
    /**
     * This plugin extends {@see WwppcmsThemeApi1CompatPlugin}
     *
     * @var string[]
     */
    protected $dependsOn = array('WwppcmsThemeApi1CompatPlugin');

    /**
     * {@inheritDoc}
     */
    public function getApiVersion()
    {
        return WwppcmsDeprecated::API_VERSION_3;
    }
}
