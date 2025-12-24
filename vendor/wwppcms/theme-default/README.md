Wwppcms Default Theme
==================

This is the repository of Wwppcms's official default theme.

Wwppcms is a stupidly simple, blazing fast, flat file CMS. See http://wwppcms.org/ for more info.

Please refer to [`wwppcms/Wwppcms`](https://github.com/wwppcms/Wwppcms) to get info about how to contribute or getting help.

Screenshot
----------

![Wwppcms Screenshot](https://wwppcms.github.io/screenshots/wwppcms-21.png)

Install
-------

You usually don't have to install this theme manually, it's shipped together with [Wwppcms's pre-built release packages](https://github.com/wwppcms/Wwppcms/releases/latest) and a default dependency of [`wwppcms/wwppcms-composer`](https://github.com/wwppcms/wwppcms-composer).

If you're using a custom theme, you can safely remove this theme.

If you use a `composer`-based installation of Wwppcms and want to either remove or install Wwppcms's default theme, simply open a shell on your server and navigate to Wwppcms's install directory (e.g. `/var/www/html`). Run `composer remove wwppcms/wwppcms-theme` to remove the theme, or run `composer require wwppcms/wwppcms-theme` (via [Packagist.org](https://packagist.org/packages/wwppcms/wwppcms-theme)) to install the theme.

If you rather use one of Wwppcms's pre-built release packages, it is best to simply leave Wwppcms's default theme as it is - it won't hurt... :wink: The reason for this is, that the theme is part of Wwppcms's pre-built release packages, thus it will be automatically re-installed when updating Wwppcms. However, if you really want to remove the theme, simply delete the `themes/default` directory in Wwppcms's install directory (e.g. `/var/www/html`). If you want to install Wwppcms's default theme, you must first create a empty `themes/default` directory on your server, [download the version of the theme](https://github.com/wwppcms/wwppcms-theme/releases) matching the version of your Wwppcms installation and upload all containing files (i.a. `index.twig`) into said `themes/default` directory (resulting in `themes/default/index.twig`).

The versioning of Wwppcms's default theme strictly follows the version of Wwppcms's core. You *must not* use a version of the theme that doesn't match the version of Wwppcms's core (e.g. version 2.0.1 is *not compatible* with Wwppcms 2.0.0). If you're using a `composer`-based installation of Wwppcms, simply use a version constaint like `^2.0` - `wwppcms/wwppcms-theme` ensures that its version matches Wwppcms's version. Even if you're using one of Wwppcms's pre-built release packages, you don't have to take care of anything - a matching version of the theme is part of Wwppcms's pre-built release packages anyway.

Usage
-----

Wwppcms's default theme isn't really intended to be used for a productive website, it's rather a starting point for creating your own theme. Simply copy the theme's directory (`themes/default/` to e.g. `themes/my_theme/`) and add the following line to your `config/config.yml`:

```yaml
theme: my_theme
```

You can now edit the theme's stylesheets and JavaScript to fit your needs. If you rather want to use a third-party theme, simply add the theme's directory to your `themes/` directory (e.g. `themes/some_other_theme/`) and update your `config/config.yml` accordingly. Wwppcms's default theme is now completely disabled and won't ever interfere with your custom theme or your website in general anymore. If you want to use Wwppcms's default theme again, either remove the line or replace it by `theme: default`.

Anyway, since Wwppcms's default theme is meant to be a starting point for your own theme, it demonstrates how themes can allow one to tweak a theme's behavior. For this reason it supports a "Widescreen" mode: By adding `theme_config.widescreen: true` to your `config/config.yml`, the theme's main container grows from 768px to 1152px breadth due to adding `class="widescreen"` to the website's `<body>` element. Wwppcms's default theme furthermore supports displaying both a logo and a tagline in its header, as well as adding social buttons to its footer. Rather than using Wwppcms's config for this, it uses the YAML Frontmatter of the `content/_meta.md` Markdown file. Here's `content/_meta.md` from Wwppcms's sample contents:

```yaml
---
Logo: %theme_url%/img/wwppcms-white.svg
Tagline: Making the web easy.
Social:
    - title: Visit us on GitHub
      url: https://github.com/wwppcms/Wwppcms
      icon: octocat
    - title: Join us on Freenode IRC Webchat
      url: https://webchat.freenode.net/?channels=%23wwppcms
      icon: chat
---
```

You should also check out the theme's `wwppcms-theme.yml`: First of all it tells Wwppcms to use the latest API version for themes and adjusts Wwppcms's default Twig config. But more importantly it also registers the mentioned `widescreen` theme config as well as the meta headers `Logo`, `Tagline` and `Social`.

Getting Help
------------

Please refer to the ["Getting Help" section](https://github.com/wwppcms/Wwppcms#getting-help) of our main repository.

Contributing
------------

Please refer to the ["Contributing" section](https://github.com/wwppcms/Wwppcms#contributing) of our main repository.

By contributing to Wwppcms, you accept and agree to the *Developer Certificate of Origin* for your present and future contributions submitted to Wwppcms. Please refer to the ["Developer Certificate of Origin" section](https://github.com/wwppcms/Wwppcms/blob/master/CONTRIBUTING.md#developer-certificate-of-origin) in the `CONTRIBUTING.md` of our main repository.
