# wwppcms

Flat-file site package based on Wwppcms 2.1.4 with wwppcms branding. Vendor dependencies are bundled for PHP 8.0–8.4, so Composer is optional for end users.

## Requirements
- PHP 8.0–8.4
- PHP extensions: mbstring, dom
- Web server rewrite routing all requests to `index.php`

## Structure
- Content: `content/` (Markdown)
- Theme: `themes/default/`
- Config: `config/config.yml`
- Plugins: `plugins/` (WwppcmsDeprecated enabled)
- Assets: `assets/`

## Run
Point your web server document root to this directory and ensure rewrites send unknown paths to `index.php`.

## Branding
- Site title and tagline: `config/config.yml` and `content/_meta.md`
- Replace `themes/default/img/wwppcms-white.svg` with your wwppcms logo and update the `Logo` field in `_meta.md` if needed.

Official site: https://wwppcms.com/
