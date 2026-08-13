<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Controller;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Params;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Regenerates the secret cron key. The old cron URL stops working
 * immediately.
 */
class CronkeyController extends BaseController
{
    public function regenerate(): void
    {
        try {
            if (!Session::checkToken('request')) {
                throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }

            if (!$this->app->getIdentity()->authorise('core.admin', 'com_footprint')) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $key = bin2hex(random_bytes(16));
            Params::save(['cron_key' => $key]);

            echo new JsonResponse([
                'url' => Uri::root() . 'index.php?option=com_footprint&task=cron.run&key=' . $key,
            ]);
            $this->app->close();
        } catch (\Throwable $exception) {
            $this->app->setHeader('status', $exception->getCode() >= 400 ? (string) $exception->getCode() : '500');

            echo new JsonResponse($exception);
            $this->app->close();
        }
    }
}
