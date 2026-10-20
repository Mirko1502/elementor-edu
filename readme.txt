=== Elementor Edu ===
Requires at least: 6.8
Requires PHP: 7.4
Stable tag: 4.3.2.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Elementor Edu combines Elementor 4.3.5 and Elementor Pro in one installable WordPress plugin for educational demonstrations and course exercises. The original engines are by Elementor Ltd.; their copyright notices and licenses are preserved.

This educational distribution is intended only for activities directly related to the course. It does not include an official Elementor Pro license or guarantee access to cloud services.

== Installation ==
1. Deactivate any separate Elementor and Elementor Pro installations.
2. In Plugins > Add New Plugin > Upload Plugin, upload the Elementor Edu ZIP file.
3. Activate Elementor Edu. Both included engines load together and reuse existing Elementor pages, settings and data.
4. In the setup dialog, select "Install the Hello Elementor theme" to install and activate the included Hello Elementor 3.5.1 theme, then return automatically to the dashboard. The dialog remains visible across admin pages until Hello is active and cannot be dismissed. Your current theme changes only after you click. An existing Hello installation is reused without being overwritten; an active Hello child theme is preserved. WordPress theme installation and activation permissions are required. On multisite, the change applies only to the current site.
5. When Elementor and Hello Elementor are both active, a one-time confirmation explains that you can start building with Elementor and links to create a page. It can be dismissed. If Hello is already active when Elementor Edu is activated, WordPress returns to Installed Plugins rather than Add Plugins.

For new Elementor pages, Page Settings > Hide Title defaults to Yes. A value already saved on a page is preserved. The separate Hello Theme welcome banner and Elementor's first-activation onboarding wizard are suppressed. Elementor Edu's Hello installation prompt remains available until the theme is active. Confirmation and setup text follow the WordPress user's profile language in English, Italian and Brazilian Portuguese.

== Languages ==
English is the source language. Italian (it_IT) and Brazilian Portuguese (pt_BR) translations are included for Elementor Edu and both Elementor engines, including PHP interfaces and JavaScript editor panels.

In the dashboard and editor, the language follows the current user's WordPress profile under Users > Profile > Language. With "Site Default", the site's language is used. Public pages use the site's language. Unsupported interface languages fall back to English. Your page content is not translated automatically, and this plugin does not change the WordPress dashboard language or other plugins' translations.

Translation catalogues are included and work offline. While the update period is active, WordPress Cron refreshes the Italian and Brazilian Portuguese catalogues daily from the public WordPress.org and Elementor translation projects. It also refreshes them when preparing a combined Elementor update, and schedules a refresh after a manually installed new version. No manual language-pack installation is needed.

Only translation data is downloaded; remote PHP files are never installed or executed. Supplementary translations for missing strings are preserved. If a source is unavailable, existing catalogues are retained and a later automatic attempt is scheduled silently. Refresh errors are recorded internally without a dashboard notice. Newly introduced strings without an available translation appear in English until a translated catalogue becomes available. WordPress Cron depends on site visits or a server cron job.

== Altervista presentation ==
Elementor Edu preserves the Altervista plugin and its runtime services, including Seamless theme initialization, authentication and background tasks. If an older Edu version disabled the installed integration, Edu restores it before the theme loads. Altervista screens, dashboard widgets, notices, menu entries, admin assets and branding are hidden so WordPress administration remains standard; the native plugin directory page and WordPress admin-bar logo are restored. These changes affect presentation only and do not remove hosting callbacks needed by the theme. Plugin files and data are preserved, and deactivating Edu leaves the hosting integration available.

== Updates ==
While the educational update period is active, the plugin checks daily for a newer Pro release at https://www.gpl-astra.com/elementor-pro/. Only a newer Pro release triggers an Elementor update offer. Clicking "Update Elementor now" downloads both current engines, validates the packages and installs the combined update through WordPress. A newer Elementor release alone does not trigger an update.

When the update period ends, the plugin keeps working and can still be reactivated. Automatic engine and translation checks stop, and this copy no longer installs updates. Existing translations remain available. A permanent dashboard notice explains the educational status and recommends replacing this copy with an official Elementor Pro installation to continue receiving updates: https://elementor.com/pro/.

Updates and manual deactivation preserve the educational period. Deleting the plugin removes its record; a later installation starts a new period. Elementor Edu does not install or use MU-plugins. Elementor Safe Mode is unavailable in this combined package because its upstream implementation requires an MU-plugin. If an earlier version already deactivated the plugin, reactivate it normally after replacing the package.

== Sources and remote services ==
This package includes customizations from an altered Pro distribution. It retains the proxy https://elementor.gpltimes.com for some Elementor service requests. It does not grant an official license or guarantee template or cloud-service availability. Features requiring other plugins, such as WooCommerce, retain those dependencies.

Translations are obtained from https://translate.wordpress.org/projects/wp-plugins/elementor/ and https://translate.elementor.com/projects/elementor-pro/. Translation requests contain no site content, personal data or license credentials. Public servers necessarily receive connection metadata such as the requesting IP address.

== Distribution changes ==
The combined package uses one plugin identity while preserving internal engine paths and APIs. Separate engine updates and rollbacks are disabled to protect the combined installation; database upgrades remain enabled. The builder retains Elementor branding. Local translation catalogues and supplementary entries are distributed under the corresponding component licenses.
