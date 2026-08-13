<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Maps rows of #__extensions (installed or not enabled alike) to the
 * directories they own on disk.
 *
 * Group keys are derived from type/folder/element/client — never from
 * extension ids, which differ per installation.
 */
class ExtensionMap
{
    private Layout $layout;

    public function __construct(private DatabaseInterface $db)
    {
        $this->layout = new Layout();
    }

    /**
     * Build the extension map.
     *
     * @return array{claims: array<string, string>, meta: array<string, array>, skip: string[]}
     *         claims: relative dir => group key;
     *         meta:   group key => {name, type, element, folder, client_id, enabled, protected};
     *         skip:   directories to prune from the walk entirely
     */
    public function build(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'client_id', 'enabled', 'protected']))
            ->from($this->db->quoteName('#__extensions'))
            ->whereIn($this->db->quoteName('type'), ['component', 'module', 'plugin', 'template', 'library', 'language', 'file'], \Joomla\Database\ParameterType::STRING);

        $rows = $this->db->setQuery($query)->loadObjectList();

        $claims = [];
        $meta   = [];

        foreach ($rows as $row) {
            $dirs = $this->dirsFor($row);

            if (!$dirs) {
                continue;
            }

            $key = $this->keyFor($row);

            $meta[$key] = [
                'name'      => $row->name,
                'type'      => $row->type,
                'element'   => $row->element,
                'folder'    => $row->folder,
                'client_id' => (int) $row->client_id,
                'enabled'   => (int) $row->enabled,
                'protected' => (int) $row->protected,
                'dirs'      => $dirs,
            ];

            foreach ($dirs as $dir) {
                $claims[$dir] = $key;
            }
        }

        // Explicit config claims win over conventions; unknown groups are
        // ignored so a shared config can list paths for absent extensions.
        foreach ($this->layout->dirClaims() as $pattern => $key) {
            if (!isset($meta[$key])) {
                continue;
            }

            foreach ($this->expand($pattern) as $dir) {
                $claims[$dir] = $key;

                // Keep the claim visible to the SQL-file scan in
                // [[TableResolver]], which only looks at meta['dirs'].
                if (!\in_array($dir, $meta[$key]['dirs'], true)) {
                    $meta[$key]['dirs'][] = $dir;
                }
            }
        }

        // Excluded extensions keep their meta so [[TableResolver]] can still
        // recognise their tables — but they are flagged, their folders are
        // pruned from the walk, and the finalizer drops them entirely.
        $skipDirs = [];

        foreach ($this->layout->excludedExtensions() as $key) {
            if (!isset($meta[$key])) {
                continue;
            }

            $meta[$key]['excluded'] = true;
            $skipDirs               = array_merge($skipDirs, $meta[$key]['dirs'], array_keys($claims, $key, true));
        }

        foreach ($this->layout->excludedDirs() as $pattern) {
            // Keep the literal alongside the expansion: a pattern may name a
            // folder that does not exist yet, and should apply once it does.
            $skipDirs = array_merge($skipDirs, $this->expand($pattern), [trim($pattern, '/')]);
        }

        return [
            'claims' => $claims,
            'meta'   => $meta,
            'skip'   => array_values(array_unique($skipDirs)),
        ];
    }

    /**
     * Directories owned by Joomla itself, taken from the config's
     * "joomla" group. Longer claims (e.g. an extension's own library
     * folder) always win over these.
     *
     * @return string[]
     */
    private function coreDirs(): array
    {
        $dirs = [];

        foreach ($this->layout->dirClaims() as $pattern => $key) {
            if ($key === 'joomla') {
                $dirs = array_merge($dirs, $this->expand($pattern));
            }
        }

        return $dirs;
    }

    /**
     * Turn one configured claim into the concrete paths it covers.
     *
     * Wildcards are resolved against the filesystem here, once per scan,
     * and never inside the scanner's per-file loop: that loop walks tens of
     * thousands of files and has to stay a single hash lookup. Both
     * directories and files are supported.
     *
     * @return string[]  Site-root-relative paths (possibly empty).
     */
    private function expand(string $pattern): array
    {
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return [];
        }

        if (strpbrk($pattern, '*?[') === false) {
            return [$pattern];
        }

        $matches = glob(JPATH_ROOT . '/' . $pattern, GLOB_NOSORT) ?: [];
        $offset  = \strlen(JPATH_ROOT) + 1;
        $paths   = [];

        foreach ($matches as $match) {
            $paths[] = str_replace('\\', '/', substr($match, $offset));
        }

        return $paths;
    }

    /**
     * Stable, id-free group key for an extension row.
     */
    public function keyFor(object $row): string
    {
        return match ($row->type) {
            'plugin'   => 'plg_' . $row->folder . '_' . $row->element,
            'module'   => $row->element . ((int) $row->client_id === 1 ? '.admin' : ''),
            'template' => 'tpl_' . $row->element . ((int) $row->client_id === 1 ? '.admin' : ''),
            'library'  => 'lib_' . str_replace('/', '_', (string) $row->element),
            'language' => 'lang_' . $row->element . ((int) $row->client_id === 1 ? '.admin' : ''),
            default    => (string) $row->element,
        };
    }

    /**
     * The directories a single extension row owns (site-root-relative).
     *
     * @return string[]
     */
    private function dirsFor(object $row): array
    {
        $el     = (string) $row->element;
        $folder = (string) $row->folder;
        $admin  = (int) $row->client_id === 1;

        if ($el === '') {
            return [];
        }

        switch ($row->type) {
            case 'component':
                return [
                    'administrator/components/' . $el,
                    'components/' . $el,
                    'api/components/' . $el,
                    'media/' . $el,
                ];

            case 'module':
                return [
                    ($admin ? 'administrator/' : '') . 'modules/' . $el,
                    'media/' . $el,
                ];

            case 'plugin':
                if ($folder === '') {
                    return [];
                }

                return [
                    'plugins/' . $folder . '/' . $el,
                    'media/plg_' . $folder . '_' . $el,
                ];

            case 'template':
                return [
                    ($admin ? 'administrator/' : '') . 'templates/' . $el,
                    'media/templates/' . ($admin ? 'administrator' : 'site') . '/' . $el,
                ];

            case 'library':
                return [
                    'libraries/' . $el,
                    'media/lib_' . str_replace('/', '_', $el),
                    'media/' . $el,
                ];

            case 'language':
                return [
                    ($admin ? 'administrator/' : '') . 'language/' . $el,
                ];

            case 'file':
                // Joomla's own file extension owns the core directories,
                // which the config declares under the "joomla" group key.
                if ($el === 'joomla') {
                    return $this->coreDirs();
                }

                return [];
        }

        return [];
    }
}
