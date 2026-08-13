<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Human labels for group keys, shared by all models.
 */
class Labels
{
    public static function group(string $key, ?array $info): string
    {
        if ($key === Scanner::GROUP_OTHER) {
            return Text::_('COM_FOOTPRINT_GROUP_OTHER');
        }

        if (($info['type'] ?? '') === 'standout') {
            return self::bucket((string) $info['name']);
        }

        // Extension names may be language keys (e.g. COM_CONTENT); load the
        // extension's own sys.ini so the key resolves.
        if ($info) {
            self::loadExtensionLanguage($info);
        }

        $name       = (string) ($info['name'] ?? $key);
        $translated = html_entity_decode(strip_tags(Text::_($name)), ENT_QUOTES | ENT_HTML5);

        return $translated !== '' ? $translated : $key;
    }

    /**
     * Load an extension's sys.ini the same way com_installer does, so its
     * name key translates. Results are cached per extension.
     */
    private static function loadExtensionLanguage(array $info): void
    {
        static $loaded = [];

        $type    = (string) ($info['type'] ?? '');
        $element = (string) ($info['element'] ?? '');
        $folder  = (string) ($info['folder'] ?? '');
        $admin   = (int) ($info['client_id'] ?? 1) === 1;

        $cacheKey = $type . ':' . $folder . ':' . $element . ':' . (int) $admin;

        if ($element === '' || isset($loaded[$cacheKey])) {
            return;
        }

        $loaded[$cacheKey] = true;

        $lang = \Joomla\CMS\Factory::getApplication()->getLanguage();

        switch ($type) {
            case 'component':
                $lang->load($element . '.sys', JPATH_ADMINISTRATOR)
                    || $lang->load($element . '.sys', JPATH_ADMINISTRATOR . '/components/' . $element);
                break;

            case 'module':
                $base = $admin ? JPATH_ADMINISTRATOR : JPATH_SITE;
                $lang->load($element . '.sys', $base)
                    || $lang->load($element . '.sys', $base . '/modules/' . $element);
                break;

            case 'plugin':
                $name = 'plg_' . $folder . '_' . $element;
                $lang->load($name . '.sys', JPATH_ADMINISTRATOR)
                    || $lang->load($name . '.sys', JPATH_PLUGINS . '/' . $folder . '/' . $element);
                break;

            case 'template':
                $base = $admin ? JPATH_ADMINISTRATOR : JPATH_SITE;
                $lang->load('tpl_' . $element . '.sys', $base)
                    || $lang->load('tpl_' . $element . '.sys', $base . '/templates/' . $element);
                break;

            case 'library':
                $name = 'lib_' . str_replace('/', '_', $element);
                $lang->load($name . '.sys', JPATH_SITE)
                    || $lang->load($name . '.sys', JPATH_ADMINISTRATOR);
                break;

            case 'file':
                $lang->load('files_' . $element . '.sys', JPATH_SITE);
                break;
        }
    }

    /**
     * A standout bucket's label: the translated name when the component
     * ships a key for it, otherwise the bucket as written in the config —
     * so buckets invented in paths.local.php work without translations.
     */
    public static function bucket(string $bucket): string
    {
        $key        = 'COM_FOOTPRINT_BUCKET_' . strtoupper(preg_replace('/[^a-z0-9_]/i', '_', $bucket));
        $translated = Text::_($key);

        return $translated === $key ? $bucket : $translated;
    }

    /**
     * Language key for an extension type badge.
     */
    public static function typeKey(string $type): string
    {
        return match ($type) {
            'component', 'module', 'plugin', 'template', 'library', 'language', 'file', 'package'
                     => 'COM_FOOTPRINT_TYPE_' . strtoupper($type),
            'standout' => 'COM_FOOTPRINT_TYPE_STANDOUT',
            default    => 'COM_FOOTPRINT_TYPE_OTHER',
        };
    }
}
