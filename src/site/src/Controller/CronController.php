<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Site\Controller;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\ScanRunner;
use Devtools\Component\Footprint\Administrator\Service\Telemetry;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Database\DatabaseInterface;

/**
 * Unattended scan endpoint for schedulers:
 *
 *   index.php?option=com_footprint&task=cron.run&key=<secret>
 *
 * Authenticated by the secret key from the component options — no session
 * involved, so it works from cron/curl/wget. Runs the whole scan in one
 * request and reports the resulting totals.
 */
class CronController extends BaseController
{
    public function run(): void
    {
        try {
            $secret = (string) ComponentHelper::getParams('com_footprint')->get('cron_key', '');
            $given  = (string) $this->input->get('key', '', 'alnum');

            if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
                throw new \RuntimeException('Forbidden', 403);
            }

            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $runner = new ScanRunner($db);
            $scan   = $runner->runFull();

            // Unattended path: nobody is waiting, so this is the best moment
            // to offer statistics. Does nothing unless opted in and due.
            (new Telemetry($db))->maybeSend();

            echo new JsonResponse([
                'scanned'     => $scan->created,
                'duration_ms' => (int) $scan->duration_ms,
                'files'       => (int) $scan->total_files,
                'bytes'       => (int) $scan->total_bytes,
                'db_tables'   => (int) $scan->db_tables,
                'db_bytes'    => (int) $scan->db_bytes,
            ]);
            $this->app->close();
        } catch (\Throwable $exception) {
            $code = $exception->getCode() >= 400 ? (int) $exception->getCode() : 500;
            $this->app->setHeader('status', (string) $code, true);
            http_response_code($code);

            echo new JsonResponse($exception);
            $this->app->close();
        }
    }
}
