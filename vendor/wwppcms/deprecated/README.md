Wwppcms Deprecated Plugin
======================

This is the repository of Wwppcms's official `WwppcmsDeprecated` plugin.

Wwppcms is a stupidly simple, blazing fast, flat file CMS. See http://wwppcms.org/ for more info.

`WwppcmsDeprecated`'s purpose is to maintain backward compatibility to older versions of Wwppcms, by re-introducing characteristics that were removed from Wwppcms's core. It for example triggers old events (like the `before_render` event used before Wwppcms 1.0) and reads config files that were written in PHP (`config/config.php`, used before Wwppcms 2.0).

Please refer to [`wwppcms/Wwppcms`](https://github.com/wwppcms/Wwppcms) to get info about how to contribute or getting help.

Install
-------

You usually don't have to install this plugin manually, it's shipped together with [Wwppcms's pre-built release packages](https://github.com/wwppcms/Wwppcms/releases/latest) and a default dependency of [`wwppcms/wwppcms-composer`](https://github.com/wwppcms/wwppcms-composer).

If you're using plugins and themes that are compatible with Wwppcms's latest API version only, you can safely remove `WwppcmsDeprecated` from your Wwppcms installation or disable the plugin (please refer to the "Usage" section below). However, if you're not sure about this, simply leave it as it is - it won't hurt... :wink:

If you use a `composer`-based installation of Wwppcms and want to either remove or install `WwppcmsDeprecated`, simply open a shell on your server and navigate to Wwppcms's install directory (e.g. `/var/www/html`). Run `composer remove wwppcms/wwppcms-deprecated` to remove `WwppcmsDeprecated`, or run `composer require wwppcms/wwppcms-deprecated` (via [Packagist.org](https://packagist.org/packages/wwppcms/wwppcms-deprecated)) to install `WwppcmsDeprecated`.

If you rather use one of Wwppcms's pre-built release packages, it is best to disable `WwppcmsDeprecated` and not to actually remove it. The reason for this is, that `WwppcmsDeprecated` is part of Wwppcms's pre-built release packages, thus it will be automatically re-installed when updating Wwppcms. However, if you really want to remove `WwppcmsDeprecated`, simply delete the `plugins/WwppcmsDeprecated` directory in Wwppcms's install directory (e.g. `/var/www/html`). If you want to install `WwppcmsDeprecated`, you must first create a empty `plugins/WwppcmsDeprecated` directory on your server, [download the version of `WwppcmsDeprecated`](https://github.com/wwppcms/wwppcms-deprecated/releases) matching the version of your Wwppcms installation and upload all containing files (esp. `WwppcmsDeprecated.php` and the `lib/`, `plugins/` and `vendor/` directories) into said `plugins/WwppcmsDeprecated` directory (resulting in `plugins/WwppcmsDeprecated/WwppcmsDeprecated.php`).

The versioning of `WwppcmsDeprecated` strictly follows the version of Wwppcms's core. You *must not* use a version of `WwppcmsDeprecated` that doesn't match the version of Wwppcms's core (e.g. WwppcmsDeprecated 2.0.1 is *not compatible* with Wwppcms 2.0.0). If you're using a `composer`-based installation of Wwppcms, simply use a version constaint like `^2.0` - `WwppcmsDeprecated` ensures that its version matches Wwppcms's version. Even if you're using one of Wwppcms's pre-built release packages, you don't have to take care of anything - a matching version of `WwppcmsDeprecated` is part of Wwppcms's pre-built release packages anyway.

Usage
-----

You can explicitly disable `WwppcmsDeprecated` by adding `WwppcmsDeprecated.enabled: false` to your `config/config.yml`. If you want to re-enable `WwppcmsDeprecated`, simply remove this line from your `config/config.yml`. `WwppcmsDeprecated` itself has no configuration options, it enables and disables all of its features depending on whether there are plugins and/or themes requiring said characteristics.

`WwppcmsDeprecated`'s functionality is split into various so-called "compatibility plugins". There are compatibility plugins for every old API version (Wwppcms 0.9 and earlier were using API version 0, Wwppcms 1.0 was using API version 1 and Wwppcms 2.0 was using API version 2; the current API version is version 3, used by Wwppcms 2.1), one for plugins and another one for themes. Their purpose is to re-introduce characteristics plugins and themes using said API version might rely on. For example, plugin API compatibility plugins are responsible for simulating old Wwppcms core events (like the `before_render` event used by Wwppcms 0.9 and earlier). Theme API compatibility plugins will e.g. register old Twig variables (like the `is_front_page` Twig variable used by Wwppcms 1.0). If you install a plugin using API version 2, the corresponding `WwppcmsPluginApi2CompatPlugin` will be loaded. All plugin API compatibility plugins also depend on their theme counterpart, thus `WwppcmsThemeApi2CompatPlugin` will be loaded, too. Furthermore all compatibility plugins depend on their respective API successors.

The plugin exposes a simple API to allow other plugins to load their own compatibility plugins. As a plugin developer you may use the `WwppcmsDeprecated::loadCompatPlugin(WwppcmsCompatPluginInterface $compatPlugin)` method to load a custom compatibility plugin. Use `WwppcmsDeprecated::getCompatPlugins()` to return a list of all loaded compatibility plugins. You can furthermore use the `WwppcmsDeprecated::getPlugins(int $apiVersion)` method to return a list of all loaded Wwppcms plugins using a particular API version. If you want to trigger a custom event on plugins using a particular API version only, use `WwppcmsDeprecated::triggerEvent(int $apiVersion, string $eventName, array $parameters = [])`. `WwppcmsDeprecated` furthermore triggers the custom `onWwppcmsDeprecated(WwppcmsDeprecated $wwppcmsDeprecated)` event.

Getting Help
------------

Please refer to the ["Getting Help" section](https://github.com/wwppcms/Wwppcms#getting-help) of our main repository.

Contributing
------------

Please refer to the ["Contributing" section](https://github.com/wwppcms/Wwppcms#contributing) of our main repository.

By contributing to Wwppcms, you accept and agree to the *Developer Certificate of Origin* for your present and future contributions submitted to Wwppcms. Please refer to the ["Developer Certificate of Origin" section](https://github.com/wwppcms/Wwppcms/blob/master/CONTRIBUTING.md#developer-certificate-of-origin) in the `CONTRIBUTING.md` of our main repository.
