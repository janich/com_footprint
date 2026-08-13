<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Raised when a scan is asked for while another one is still walking.
 *
 * Carries HTTP 409 so the AJAX and cron endpoints can hand it straight to
 * the client, and a message naming when the running scan started — the one
 * fact that tells an administrator whether to wait or to worry.
 */
class ScanLockedException extends \RuntimeException
{
    public function __construct(public readonly string $startedAt)
    {
        // The cron endpoint is refused in the site application, which has no
        // reason to have loaded the component's strings. They ship inside the
        // component folder rather than in the shared language folder, so both
        // paths are tried — the same order the administrator dispatcher uses.
        $language = Factory::getApplication()->getLanguage();
        $language->load('com_footprint', JPATH_ADMINISTRATOR)
            || $language->load('com_footprint', JPATH_ADMINISTRATOR . '/components/com_footprint');

        parent::__construct(Text::sprintf('COM_FOOTPRINT_SCAN_RUNNING', self::when($startedAt)), 409);
    }

    /**
     * The start time in the site's own timezone and date format.
     */
    public static function when(string $startedAt): string
    {
        return (string) HTMLHelper::_('date', $startedAt, Text::_('DATE_FORMAT_LC5'));
    }
}
