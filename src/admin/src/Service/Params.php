<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Reads and persists com_footprint's component parameters.
 */
class Params
{
    /**
     * Set parameter values and persist them to #__extensions.
     *
     * @param   array<string, mixed>  $values
     */
    public static function save(array $values): Registry
    {
        $params = ComponentHelper::getParams('com_footprint');

        foreach ($values as $key => $value) {
            $params->set($key, $value);
        }

        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $json = $params->toString();
        $type = 'component';
        $el   = 'com_footprint';

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':params', $json)
            ->bind(':type', $type)
            ->bind(':element', $el);

        $db->setQuery($query)->execute();

        // Component params are cached; flush so the new values are seen.
        $cacheFactory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);

        foreach (['_system', 'com_footprint'] as $group) {
            try {
                $cacheFactory->createCacheController('callback', ['defaultgroup' => $group])->cache->clean($group);
            } catch (\Throwable) {
                // Cache backend unavailable: params still saved.
            }
        }

        return $params;
    }

    /**
     * The secret cron key, generated and persisted on first use.
     */
    public static function cronKey(): string
    {
        $key = (string) ComponentHelper::getParams('com_footprint')->get('cron_key', '');

        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            self::save(['cron_key' => $key]);
        }

        return $key;
    }
}
