=== Elementor Edu ===
Requires at least: 6.8
Requires PHP: 7.4
Stable tag: 4.3.2.22
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==

Elementor Edu installs the bundled engines as real plugins at elementor/elementor.php and elementor-pro/elementor-pro.php. Installed Plugins lists Elementor, Elementor Pro and Elementor Edu separately. WordPress dependency checks for Elementor work without aliases. Edu retains educational settings, translations, hosting controls and coordinated updates.

Activation installs both engines; an existing combined Edu installation migrates on its next request. Deployment verifies both full file trees before replacement, keeps a recovery journal and restores the previous pair after an interrupted replacement. Direct filesystem access is required. Unmanaged, locally modified or newer engine folders are preserved and reported, never overwritten. A failed initial migration can temporarily use the bundled engines until the filesystem problem is resolved.

The two engines can be deactivated independently. Ordinary Edu updates preserve their activation state. Deactivating or uninstalling Edu leaves the separate engines and site content in place; Edu settings and update controls apply only while Edu is active. On multisite, engine files are shared but activation follows the site or network scope of Edu. Installation after complete Edu removal starts with fresh Edu preferences.

One Edu ZIP supplies Elementor 4.3.5 and Elementor Pro for educational demonstrations and course exercises. The original engines are by Elementor Ltd.; their copyright notices and licenses are preserved.

This educational distribution is intended only for activities directly related to the course. It does not include an official Elementor Pro license or guarantee access to cloud services.

== Installation ==
1. Back up the site. Existing installations not managed by Edu are preserved and reported; do not delete them without a recoverable backup.
2. In Plugins > Add New Plugin > Upload Plugin, upload the Elementor Edu ZIP file.
3. Activate Elementor Edu. It installs and activates the two bundled engines as separate plugins, reusing existing Elementor pages, settings and data.
4. In the setup dialog, select "Install the Hello Elementor theme" to install and activate the included Hello Elementor 3.5.1 theme, then return automatically to the dashboard. The dialog remains visible across admin pages until Hello is active and cannot be dismissed. Your current theme changes only after you click. An existing Hello installation is reused without being overwritten; an active Hello child theme is preserved. WordPress theme installation and activation permissions are required. On multisite, the change applies only to the current site.
5. After activation, Elementor Edu confirms the installation in a dialog with a Continue button leading to Installed Plugins. Successful updates receive the same confirmation; the native post-update Add Plugins link is removed. If Hello still needs setup, its required dialog follows the confirmation. Completing Hello setup later shows the separate one-time ready message.

For new Elementor pages, Page Settings > Hide Title defaults to Yes. A value already saved on a page is preserved. The separate Hello Theme welcome banner and Elementor's first-activation onboarding wizard are suppressed. Elementor Edu's Hello installation prompt remains available until the theme is active. Confirmation and setup text follow the WordPress user's profile language in English, Italian and Brazilian Portuguese.

== Languages ==
English is the source language. Italian (it_IT) and Brazilian Portuguese (pt_BR) translations are included for Elementor Edu and both Elementor engines, including PHP interfaces and JavaScript editor panels.

In the dashboard and editor, the language follows the current user's WordPress profile under Users > Profile > Language. With "Site Default", the site's language is used. Public pages use the site's language. Unsupported interface languages fall back to English. Your page content is not translated automatically, and this plugin does not change the WordPress dashboard language or other plugins' translations.

Translation catalogues are included and work offline. While the update period is active, WordPress Cron refreshes the Italian and Brazilian Portuguese catalogues daily from the public WordPress.org and Elementor translation projects. It also refreshes them when preparing a combined Elementor update, and schedules a refresh after a manually installed new version. No manual language-pack installation is needed.

Only translation data is downloaded; remote PHP files are never installed or executed. Supplementary translations for missing strings are preserved. If a source is unavailable, existing catalogues are retained and a later automatic attempt is scheduled silently. Refresh errors are recorded internally without a dashboard notice. Newly introduced strings without an available translation appear in English until a translated catalogue becomes available. WordPress Cron depends on site visits or a server cron job.

== Altervista integration ==
Administrators can hide hosting screens, show only Server to server, or show all hosting screens. Hide mode deactivates the regular Altervista plugin when standalone Hello Elementor is active, unless another active plugin declares a dependency on Altervista. Deactivation takes full effect on the next request; plugin files and data are preserved without running provider teardown callbacks.

With other themes, child themes or declared plugin dependencies, Edu preserves or restores Altervista before the theme loads. This protects Seamless theme initialization. A site preference never deactivates a network-wide integration. Edu does not change the theme automatically. Selecting another mode or deactivating Edu restores the integration it suspended. The settings page shows the actual state.

Account, Earn and Promote menus, hosting dashboard cards and Altervista's Installed Plugins row are hidden in the first two modes and visible when all screens are selected. Cleanup also covers late menu entries and dashboard sections outside standard WordPress postboxes, including section bodies inserted after their headings. The native WordPress logo is restored at the beginning of the toolbar. Hosting notices, admin assets and branding are hidden in the reduced modes. Tracking remains disabled in every mode.

Code loaded by the hosting provider outside normal plugins, including must-use plugins and shared bootstrap files, is not deactivated. Compatibility checks use synthetic local fixtures, not the provider's private source code; unknown third-party dependencies are not covered.

Elementor's five-star review request is suppressed while Edu is active. The normal WordPress footer and footer content from other plugins are preserved.

== Updates ==
While the educational update period is active, the plugin checks daily for a newer Pro release at https://www.gpl-astra.com/elementor-pro/. Only a newer Pro release triggers an Elementor update offer. Clicking "Update Elementor now" downloads both current engines, validates the packages and installs the combined update through WordPress. A newer Elementor release alone does not trigger an update.

When the update period ends, the plugin keeps working and can still be reactivated. Automatic engine and translation checks stop, and this copy no longer installs updates. Existing translations remain available. A permanent dashboard notice explains the educational status and recommends replacing this copy with an official Elementor Pro installation to continue receiving updates: https://elementor.com/pro/.

Updates and manual deactivation preserve the educational period. Deleting the plugin removes its record; a later installation starts a new period. Elementor Edu does not install or use MU-plugins. Elementor Safe Mode is unavailable in this combined package because its upstream implementation requires an MU-plugin. If an earlier version already deactivated the plugin, reactivate it normally after replacing the package.

== Sources and remote services ==
This package includes customizations from an altered Pro distribution. It retains the proxy https://elementor.gpltimes.com for some Elementor service requests. It does not grant an official license or guarantee template or cloud-service availability. Features requiring other plugins, such as WooCommerce, retain those dependencies.

Translations are obtained from https://translate.wordpress.org/projects/wp-plugins/elementor/ and https://translate.elementor.com/projects/elementor-pro/. Translation requests contain no site content, personal data or license credentials. Public servers necessarily receive connection metadata such as the requesting IP address.

== Distribution changes ==
The package deploys two standard engine identities and keeps Edu as their separate manager. Separate engine updates and rollbacks are disabled to protect the combined installation; database upgrades remain enabled. The builder retains Elementor branding. Local translation catalogues and supplementary entries are distributed under the corresponding component licenses.

== Administrator preferences ==
Administrators can hide the educational-use notice persistently for their site. Unchecking the option restores it. The choice survives normal updates and deactivation; uninstalling Edu removes it.

A separate confirmed action enables updates and translation refreshes without a time limit, including for an already expired installation. On multisite only a network administrator can enable this shared setting. Existing dates are preserved for reference; ordinary updates and deactivation do not restore the deadline. The optional 90-day restart remains available until unlimited updates are enabled. Corrupt records are preserved and must be repaired before enabling updates. Availability of remote packages and services is still required.
