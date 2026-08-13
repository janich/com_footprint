<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Controller;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\ScanRunner;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

/**
 * JSON endpoint driving the chunked scan.
 *
 * The client calls task=scan.run repeatedly; each call advances the walk by
 * a time-boxed chunk and persists progress, until {done: true}.
 */
class ScanController extends BaseController
{
    /**
     * Seconds of filesystem walking per request. Kept short so the client
     * gets a progress update roughly every second — a long scan should feel
     * like it is moving, not hanging.
     */
    private const TIME_BUDGET = 1.0;

    public function run(): void
    {
        try {
            if (!Session::checkToken('request')) {
                throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }

            if (!$this->app->getIdentity()->authorise('core.admin', 'com_footprint')) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            @set_time_limit(60);

            $runner       = new ScanRunner(Factory::getContainer()->get(DatabaseInterface::class));
            [$id, $state] = $runner->startOrResume();
            $result       = $runner->stepChunk($id, $state, self::TIME_BUDGET);

            echo new JsonResponse($result);
            $this->app->close();
        } catch (\Throwable $exception) {
            $this->app->setHeader('status', $exception->getCode() >= 400 ? (string) $exception->getCode() : '500');

            echo new JsonResponse($exception);
            $this->app->close();
        }
    }
}
