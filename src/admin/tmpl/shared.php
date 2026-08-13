<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Shared template helpers for the Footprint views.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

if (!function_exists('footprintUrl')) {
    /**
     * Build a component URL from query parameters.
     */
    function footprintUrl(array $params): string
    {
        return Route::_('index.php?option=com_footprint&' . http_build_query($params));
    }

    /**
     * The view switcher. Every view is a peer — no hidden modes.
     */
    function footprintNav(string $view): string
    {
        $tabs = [
            ['dashboard', 'COM_FOOTPRINT_NAV_DASHBOARD', 'fas fa-gauge-high'],
            ['extensions', 'COM_FOOTPRINT_NAV_EXTENSIONS', 'fas fa-puzzle-piece'],
            ['folders', 'COM_FOOTPRINT_NAV_FOLDERS', 'fas fa-folder-tree'],
            ['tables', 'COM_FOOTPRINT_NAV_TABLES', 'fas fa-database'],
        ];

        $html = '<ul class="nav nav-pills mb-3">';

        foreach ($tabs as [$name, $key, $icon]) {
            $active = $name === $view ? ' active' : '';
            $html .= '<li class="nav-item"><a class="nav-link' . $active . '" href="'
                . footprintUrl(['view' => $name]) . '"><span class="' . $icon . ' me-1" aria-hidden="true"></span>'
                . Text::_($key) . '</a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * A sortable column header link. Clicking toggles direction when the
     * column is already active. All other parameters are preserved.
     */
    function footprintSortLink(
        array $params,
        string $currentSort,
        string $currentDir,
        string $key,
        string $labelKey,
        string $sortParam = 'sort',
        string $dirParam = 'dir'
    ): string {
        $isActive = $currentSort === $key;
        $nextDir  = $isActive && $currentDir === 'desc' ? 'asc' : 'desc';
        $icon     = '';

        if ($isActive) {
            $icon = $currentDir === 'desc'
                ? ' <span class="fas fa-caret-down" aria-hidden="true"></span>'
                : ' <span class="fas fa-caret-up" aria-hidden="true"></span>';
        }

        $url = footprintUrl(array_merge($params, [$sortParam => $key, $dirParam => $nextDir]));

        return '<a href="' . $url . '" class="text-decoration-none">' . Text::_($labelKey) . $icon . '</a>';
    }

    /**
     * A small share bar visualising a value against the column maximum,
     * coloured by entity ("files" = blue, "db" = teal).
     */
    function footprintBar(int|float $value, int|float $max, string $role = 'files'): string
    {
        if ($value <= 0) {
            return '';
        }

        $percent = $max > 0 ? max(0.5, round($value / $max * 100, 1)) : 0;

        return '<div class="footprint-bar" role="presentation"><div class="footprint-bar-fill footprint-bar-'
            . htmlspecialchars($role) . '" style="width:' . $percent . '%"></div></div>';
    }

    /**
     * Format a byte count.
     */
    function footprintBytes(int|float $bytes): string
    {
        return HTMLHelper::_('number.bytes', (float) $bytes, 'auto', 1);
    }

    /**
     * Format an integer count in the active locale.
     */
    function footprintNumber(int|float $number): string
    {
        return number_format((float) $number, 0, Text::_('DECIMALS_SEPARATOR'), Text::_('THOUSANDS_SEPARATOR'));
    }

    /**
     * Status badge for an extension row (enabled/disabled), empty for
     * non-extension rows.
     */
    function footprintStatusBadge(?int $enabled): string
    {
        if ($enabled === null) {
            return '';
        }

        return $enabled
            ? '<span class="badge bg-success">' . Text::_('JENABLED') . '</span>'
            : '<span class="badge bg-secondary">' . Text::_('JDISABLED') . '</span>';
    }

    /**
     * A small inline sparkline for a series of values.
     *
     * @param   array   $values  Numeric series, oldest first.
     * @param   string  $role    'files' or 'db' — fixed entity colors.
     */
    function footprintSparkline(array $values, string $role): string
    {
        $values = array_map('floatval', $values);

        if (\count($values) < 2) {
            return '';
        }

        $min   = min($values);
        $max   = max($values);
        $range = $max - $min ?: 1.0;
        $count = \count($values) - 1;
        $points = [];

        foreach ($values as $i => $value) {
            $x        = round($i / $count * 64, 1);
            $y        = round(16 - (($value - $min) / $range) * 12 + 1, 1);
            $points[] = $x . ',' . $y;
        }

        return '<svg class="footprint-spark" width="64" height="18" viewBox="0 0 64 18" role="presentation">'
            . '<polyline class="footprint-spark-' . htmlspecialchars($role) . '" points="' . implode(' ', $points) . '" fill="none" stroke-width="1.5"/>'
            . '</svg>';
    }

    /**
     * A signed delta badge (red = grew, green = shrank, muted = unchanged).
     */
    function footprintDelta(int|float $delta, string $format = 'bytes'): string
    {
        if ($delta == 0) {
            return '<span class="text-muted">±0</span>';
        }

        $formatted = $format === 'bytes' ? footprintBytes(abs($delta)) : footprintNumber(abs($delta));
        $class     = $delta > 0 ? 'footprint-delta-up' : 'footprint-delta-down';
        $arrow     = $delta > 0 ? '▲' : '▼';

        return '<span class="' . $class . '">' . $arrow . ' ' . ($delta > 0 ? '+' : '−') . $formatted . '</span>';
    }

    /**
     * A subtle "belongs to" link pointing at the extension drilldown.
     */
    function footprintOwner(?string $key, ?string $label): string
    {
        if ($key === null || $label === null || $label === '') {
            return '<span class="footprint-owner text-muted">—</span>';
        }

        return '<a class="footprint-owner" href="' . footprintUrl(['view' => 'extensions', 'group' => $key]) . '">'
            . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }

    /**
     * The scan progress bar with its live status line.
     */
    function footprintProgress(string $classes = 'mb-3'): string
    {
        return '<div class="footprint-progress d-none ' . $classes . '" data-footprint-progress>'
            . '<div class="progress" role="progressbar" aria-label="' . htmlspecialchars(Text::_('COM_FOOTPRINT_SCAN_PROGRESS'), ENT_QUOTES) . '"'
            . ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
            . '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>'
            . '</div>'
            . '<div class="footprint-progress-status">'
            . '<span data-footprint-progress-text>' . Text::_('COM_FOOTPRINT_SCAN_STARTING') . '</span>'
            . '<span class="footprint-progress-percent" data-footprint-progress-percent>0 %</span>'
            . '</div>'
            . '</div>';
    }

    /**
     * The component footer: name, version and copyright, read from the
     * manifest so a version bump never leaves stale text behind.
     */
    function footprintFooter(): string
    {
        static $manifest = null;

        if ($manifest === null) {
            $manifest = \Joomla\CMS\Installer\Installer::parseXMLInstallFile(
                JPATH_ADMINISTRATOR . '/components/com_footprint/footprint.xml'
            ) ?: [];
        }

        $name    = Text::_($manifest['name'] ?? 'COM_FOOTPRINT');
        $version = (string) ($manifest['version'] ?? '');
        $author  = (string) ($manifest['author'] ?? '');
        $url     = (string) ($manifest['authorUrl'] ?? '');
        $year    = substr((string) ($manifest['creationDate'] ?? ''), 0, 4);

        $credit = $url !== ''
            ? '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($author, ENT_QUOTES) . '</a>'
            : htmlspecialchars($author, ENT_QUOTES);

        return '<footer class="footprint-footer">'
            . '<div>' . htmlspecialchars($name, ENT_QUOTES) . ($version !== '' ? ' v' . htmlspecialchars($version, ENT_QUOTES) : '') . '</div>'
            . '<div>' . Text::sprintf('COM_FOOTPRINT_FOOTER_CREDIT', $year, $credit) . '</div>'
            . '</footer>';
    }

    /**
     * The "run a scan first" empty state.
     */
    function footprintNoScan(): string
    {
        return '<div class="card"><div class="card-body text-center py-5">'
            . '<h2>' . Text::_('COM_FOOTPRINT_DASHBOARD_NO_SCAN_TITLE') . '</h2>'
            . '<p class="text-muted">' . Text::_('COM_FOOTPRINT_DASHBOARD_NO_SCAN_DESC') . '</p>'
            . '<a class="btn btn-primary" href="' . footprintUrl(['view' => 'dashboard']) . '">'

            . Text::_('COM_FOOTPRINT_GO_TO_DASHBOARD') . '</a>'
            . '</div></div>';
    }
}
