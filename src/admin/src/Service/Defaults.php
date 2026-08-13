<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Hardcoded behaviour defaults that deliberately have no UI.
 *
 * Each value can still be overridden per site by setting the named hidden
 * parameter in the component's params (no form field exists for them).
 */
final class Defaults
{
    /**
     * Hours before the dashboard flags the latest scan as possibly
     * outdated. Sized for a daily cron scan with a missed-run margin:
     * the warning appearing effectively means the scheduled scan broke.
     *
     * Hidden param override: "scan_ttl".
     */
    public const SCAN_STALE_HOURS = 48;

    /**
     * Folder levels cached in #__footprint_tree for browsing; deeper
     * levels are read live from disk. Purely a memory/DB-size versus
     * browse-speed tradeoff — not a user decision.
     *
     * Hidden param override: "scan_depth".
     */
    public const SCAN_TREE_DEPTH = 4;

    /**
     * Where opt-in usage statistics are sent.
     *
     * Hidden param override: "stats_endpoint" (for testing against a local
     * receiver without touching the shipped default).
     */
    public const STATS_ENDPOINT = 'https://janich.dk/api/footprint/stats';

    /**
     * Days between sends. Statistics go out after the first scan that
     * follows this cooldown, so a site scanned nightly still reports weekly.
     */
    public const STATS_INTERVAL_DAYS = 7;

    /**
     * Seconds before the request is abandoned. The send happens after the
     * response is delivered, so this bounds a background request rather
     * than anything the visitor waits for.
     */
    public const STATS_TIMEOUT = 10;

    private function __construct()
    {
    }
}
