<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * All path and table knowledge Footprint ships with, in one place.
 *
 * Everything here is data, not code, so that adapting Footprint to a new
 * Joomla release or a site with unusual structure is a one-line edit rather
 * than a patch. The file is read once per request by [[Service\Layout]].
 *
 * ---------------------------------------------------------------------------
 * Group keys
 * ---------------------------------------------------------------------------
 * The 'extensions' and 'ownership' sections are keyed by GROUP KEY, never by
 * extension id — ids differ per installation, group keys do not:
 *
 *   component / module   the element              com_acym, mod_menu
 *                        admin modules get ".admin"   mod_menu.admin
 *   plugin               plg_<folder>_<element>   plg_system_t4
 *   template             tpl_<element>[.admin]    tpl_cassiopeia, tpl_atum.admin
 *   library              lib_<element>            lib_regularlabs
 *   language             lang_<tag>[.admin]       lang_da-DK.admin
 *   Joomla itself        joomla
 *
 * An entry naming a group that is not installed on this site is ignored
 * silently, so the shipped list can describe extensions this site never had.
 *
 * ---------------------------------------------------------------------------
 * Local overrides
 * ---------------------------------------------------------------------------
 * An optional sibling file "paths.local.php" with the same shape is merged
 * over this one: list-style keys append, map-style keys merge with the local
 * value winning. It is git-ignored, so site-specific knowledge survives an
 * update of the component. A malformed local file is logged and ignored.
 */

\defined('_JEXEC') or die;

return [
    /*
     * Folders whose CHILDREN are the interesting rows in the Folders view.
     *
     * Listing "plugins" itself says nothing useful — the rows a human wants
     * are the individual plugin folders below it. Site-root-relative fnmatch
     * patterns; "*" stays inside one path segment (FNM_PATHNAME).
     */
    'containers' => [
        'administrator',
        'administrator/components',
        'administrator/language',
        'administrator/manifests',
        'administrator/modules',
        'administrator/templates',
        'api',
        'api/components',
        'components',
        'language',
        'libraries',
        'libraries/vendor',
        'media',
        'media/templates',
        'media/templates/*',
        'modules',
        'plugins',
        'plugins/*',
        'templates',
    ],

    /*
     * Folders that get their own row in the grouped views, excluded from the
     * extension that would otherwise claim them.
     *
     * These are the folders that grow on their own — caches, logs, backup
     * output — and hiding them inside their owner's total is exactly what
     * makes a site's disk usage inexplicable.
     *
     * Maps site-root-relative path => the element it relates to, or null when
     * it belongs to no single extension. The element is matched by name only.
     */
    'standouts' => [
        // Folders that get their own row and are excluded from the extension
        // that would otherwise claim them — data an extension *stores*, not
        // code it installs.
        //
        //   bucket  which row the folder counts into. Paths sharing a bucket
        //           are summed into one row, and each is still itemised in
        //           that row's drilldown.
        //   origin  optional: the group key whose extension produced the
        //           data. A label only — it never moves bytes into that
        //           extension, which is the whole point of a standout.
        //
        // A bare string is shorthand for ['bucket' => '<string>'].
        //
        // Bucket labels resolve via COM_FOOTPRINT_BUCKET_<BUCKET> when that
        // language key exists, otherwise the raw bucket name is shown — so
        // buckets added in paths.local.php work without translations.
        'cache'                                            => ['bucket' => 'cache'],
        'administrator/cache'                              => ['bucket' => 'cache'],
        'tmp'                                              => ['bucket' => 'temp'],
        'logs'                                             => ['bucket' => 'logs'],
        'administrator/logs'                               => ['bucket' => 'logs'],
        'administrator/components/com_akeebabackup/backup' => ['bucket' => 'backup', 'origin' => 'com_akeebabackup'],
    ],

    /*
     * Shipped knowledge: what belongs to which extension, for things the
     * automatic rules cannot see — folders outside an extension's
     * conventional directories, and tables created at runtime or named
     * nothing like their extension.
     *
     * Shape per group key:
     *   'dirs'   => site-root-relative directories or files. A directory
     *               claims everything beneath it. A trailing/embedded "*"
     *               is globbed against the filesystem once at scan start.
     *   'tables' => table names, with "#__", bare, or in full. A trailing
     *               "*" matches a whole family, e.g. "#__acym_*".
     */
    // Things Footprint pretends do not exist: pruned during the scan, so
    // they never appear in any list and never count toward any size, file
    // count or total. Use sparingly — a hidden 2 GB folder is still 2 GB.
    'excluded' => [
        // Site-root-relative folders. "*" is allowed and does not cross "/".
        'dirs' => [
            // '.git',
            // 'administrator/components/com_something/tmp',
        ],

        // Group keys. The extension's folders AND its database tables are
        // both excluded, and it disappears from the extensions list.
        'extensions' => [
            // 'plg_system_noisy',
        ],
    ],

    'extensions' => [
        'joomla' => [
            // Core directories, attributed to the "files_joomla" extension
            // row. A longer claim (an extension's own folder) always wins.
            'dirs' => [
                'administrator/includes',
                'api/includes',
                'cli',
                'includes',
                'installation',
                'layouts',
                'libraries',
                'media/legacy',
                'media/system',
                'media/vendor',
            ],

            // Core tables. Needed because Joomla's own CREATE statements
            // live in the installation folder, which most sites delete.
            'tables' => [
                'action_log_config', 'action_logs', 'action_logs_extensions', 'action_logs_users',
                'assets', 'associations', 'banner_clients', 'banner_tracks', 'banners',
                'categories', 'contact_details', 'content', 'content_frontpage', 'content_rating',
                'content_types', 'contentitem_tag_map', 'extensions', 'fields', 'fields_categories',
                'fields_groups', 'fields_values', 'finder_filters', 'finder_links', 'finder_links_terms',
                'finder_logging', 'finder_taxonomy', 'finder_taxonomy_map', 'finder_terms',
                'finder_terms_common', 'finder_tokens', 'finder_tokens_aggregate', 'finder_types',
                'guidedtour_steps', 'guidedtours', 'history', 'languages', 'mail_templates', 'menu',
                'menu_types', 'messages', 'messages_cfg', 'modules', 'modules_menu', 'newsfeeds',
                'overrider', 'postinstall_messages', 'privacy_consents', 'privacy_requests',
                'redirect_links', 'scheduler_tasks', 'schemaorg', 'schemas', 'session', 'tags',
                'template_overrides', 'template_styles', 'tuf_metadata', 'ucm_base', 'ucm_content',
                'update_sites', 'update_sites_extensions', 'updates', 'user_keys', 'user_mfa',
                'user_notes', 'user_profiles', 'user_usergroup_map', 'usergroups', 'users',
                'viewlevels', 'webauthn_credentials', 'workflow_associations', 'workflow_stages',
                'workflow_transitions', 'workflows',
            ],
        ],

        // Regular Labs ships each plugin's assets under a media folder named
        // after the product, not after the plugin, so nothing links them.
        'plg_system_sourcerer'       => ['dirs' => ['media/sourcerer']],
        'plg_system_articlesanywhere' => ['dirs' => ['media/articlesanywhere']],
        'plg_system_modals'          => ['dirs' => ['media/modals']],
        'plg_system_tooltips'        => ['dirs' => ['media/tooltips']],
        'plg_system_rereplacer'      => ['dirs' => ['media/rereplacer']],
        'plg_system_tabsaccordions'  => ['dirs' => ['media/tabsaccordions']],
        'com_conditions'             => ['dirs' => ['media/conditions']],

        // Loads the Dojo toolkit into a shared folder of its own.
        'plg_system_dojoloader'      => ['dirs' => ['media/dojo']],

        // mySites.guru creates its tables at runtime, without the site
        // prefix, so neither the SQL parser nor the heuristic sees them.
        'plg_system_bfnetwork'       => ['tables' => ['bf_*']],
    ],

    /*
     * Site-specific associations. Identical shape to 'extensions', loaded
     * afterwards so it wins on conflicts. Use this (or paths.local.php) for
     * the folders and tables only this installation knows about.
     */
    'ownership' => [
        // 'com_acym'    => ['dirs' => ['images/acymailing'], 'tables' => ['#__acy_*']],
        // 'com_custom'  => ['dirs' => ['media/custom-integration']],
    ],
];
