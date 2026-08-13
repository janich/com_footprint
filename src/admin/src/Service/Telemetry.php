<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Optional usage statistics, sent to the component's author.
 *
 * Opt-in only: nothing leaves the site until an administrator answers "yes",
 * and a "no" is permanent. The payload identifies the installation by a random
 * id — never by site name, URL or e-mail — and describes the newest completed
 * scan, at most once a week.
 *
 * Triggers just call maybeSend(); the rules live here. Because the payload
 * carries the scan's row id, re-sending a scan the endpoint already has is
 * harmless, so triggers never need to coordinate.
 *
 * Everything it sends is listed in the README; keep the two in step.
 */
class Telemetry
{
    public const CONSENT_UNASKED = '';
    public const CONSENT_YES = 'yes';
    public const CONSENT_NO = 'no';

    /**
     * Configuration keys worth knowing about, as an allowlist — never a
     * blocklist, so a future Joomla release cannot introduce a key that
     * leaks by default. Credentials, paths, e-mail and the site name are
     * deliberately absent.
     */
    private const CONFIG_KEYS = [
        'debug', 'debug_lang', 'error_reporting', 'log_deprecated',
        'offline', 'sef', 'caching', 'cache_handler', 'session_handler', 'gzip',
    ];

    public function __construct(private DatabaseInterface $db)
    {
    }

    public static function consent(): string
    {
        return (string) ComponentHelper::getParams('com_footprint')->get('stats_consent', self::CONSENT_UNASKED);
    }

    /**
     * Send if the site has opted in and the weekly cooldown has passed.
     *
     * Takes no arguments: the payload is read from the newest completed
     * scan, so any caller can simply say "now might be a good time" without
     * knowing anything about the rules. Safe to call often; never throws.
     */
    public function maybeSend(): void
    {
        if (!$this->isDue()) {
            return;
        }

        // Deliver the page first, then send. Under PHP-FPM the visitor never
        // waits for the request at all; elsewhere it runs inline, bounded by
        // the timeout, at most once a week.
        register_shutdown_function(function (): void {
            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $scan = $this->latestScan();

                if (!$scan) {
                    return;
                }

                $this->send($this->payload($scan));

                Params::save(['stats_last_sent' => (new Date())->toSql()]);
            } catch (\Throwable $exception) {
                // Statistics must never affect the page or scan that triggered them.
                Log::add('Footprint statistics not sent: ' . $exception->getMessage(), Log::DEBUG, 'com_footprint');
            }
        });
    }

    /**
     * Whether this site has opted in and is past the cooldown.
     */
    public function isDue(): bool
    {
        $params = ComponentHelper::getParams('com_footprint');

        if ($params->get('stats_consent') !== self::CONSENT_YES) {
            return false;
        }

        $last = (string) $params->get('stats_last_sent', '');

        return $last === ''
            || (new Date($last))->toUnix() <= (new Date())->toUnix() - Defaults::STATS_INTERVAL_DAYS * 86400;
    }

    /**
     * The newest completed scan, or null when the site has never scanned.
     */
    private function latestScan(): ?object
    {
        $state = ScanStore::STATE_DONE;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state)
            ->order([$this->db->quoteName('created') . ' DESC', $this->db->quoteName('id') . ' DESC']);

        return $this->db->setQuery($query, 0, 1)->loadObject() ?: null;
    }

    /**
     * The exact data that would be sent for this installation.
     *
     * @param   object  $scan  Row from #__footprint_scans.
     */
    public function payload(object $scan): array
    {
        $app = Factory::getApplication();

        $config = [];

        foreach (self::CONFIG_KEYS as $key) {
            $config[$key] = $app->get($key);
        }

        return [
            'schema'      => 1,
            'install_id'  => $this->installId(),
            'environment' => $this->environment(),
            'component'   => $this->componentVersion(),
            'joomla'      => JVERSION,
            'php'         => PHP_VERSION,
            'db'          => ['driver' => $this->db->getName(), 'version' => $this->db->getVersion()],
            'extensions'  => $this->extensions(),
            'config'      => $config,
            'disk'        => [
                'files'  => (int) $scan->total_files,
                'bytes'  => (int) $scan->total_bytes,
                'groups' => $this->groupCount((int) $scan->id),
            ],
            'database'    => [
                'tables' => (int) $scan->db_tables,
                'rows'   => (int) $scan->db_rows,
                'data'   => (int) $scan->db_data,
                'index'  => (int) $scan->db_index,
                'bytes'  => (int) $scan->db_bytes,
            ],
            'scan'        => [
                // The row id lets the endpoint discard a scan it already has,
                // so triggers never need to coordinate with each other.
                'id'          => (int) $scan->id,
                'created'     => $scan->created,
                'duration_ms' => (int) $scan->duration_ms,
                'history'     => $this->scanCount(),
                'recent'      => $this->recentScans(),
            ],
        ];
    }

    private function send(array $payload): void
    {
        HttpFactory::getHttp()->post(
            (string) ComponentHelper::getParams('com_footprint')->get('stats_endpoint', Defaults::STATS_ENDPOINT),
            json_encode($payload),
            ['Content-Type' => 'application/json', 'User-Agent' => 'com_footprint/' . $this->componentVersion()],
            Defaults::STATS_TIMEOUT
        );
    }

    /**
     * A random id for this installation, regenerated when the site URL
     * changes so a copy on a development domain counts separately. The URL
     * itself is hashed locally and never sent.
     */
    private function installId(): string
    {
        $params = ComponentHelper::getParams('com_footprint');
        $id     = (string) $params->get('stats_id', '');
        $hash   = hash('sha256', Uri::root());

        if ($id === '' || (string) $params->get('stats_url_hash', '') !== $hash) {
            $id = bin2hex(random_bytes(16));
            Params::save(['stats_id' => $id, 'stats_url_hash' => $hash]);
        }

        return $id;
    }

    /**
     * Coarse label so development copies can be filtered out, derived from
     * the URL without sending it.
     */
    private function environment(): string
    {
        $host = strtolower((string) parse_url(Uri::root(), PHP_URL_HOST));

        if ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/\.(test|local|localhost)$/', $host)) {
            return 'local';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            return 'private';
        }

        return 'public';
    }

    /**
     * Installed extensions: element, type, version and enabled state. Names
     * and versions are what make the data useful for deciding what the
     * component must keep working with.
     */
    private function extensions(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['element', 'type', 'folder', 'enabled', 'manifest_cache']))
            ->from($this->db->quoteName('#__extensions'));

        $list   = [];
        $byType = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $manifest = json_decode((string) $row->manifest_cache, true) ?: [];

            $list[] = [
                'e'  => $row->folder ? $row->folder . '/' . $row->element : $row->element,
                't'  => $row->type,
                'v'  => (string) ($manifest['version'] ?? ''),
                'en' => (int) $row->enabled,
            ];

            $byType[$row->type] = ($byType[$row->type] ?? 0) + 1;
        }

        return ['total' => \count($list), 'by_type' => $byType, 'list' => $list];
    }

    /**
     * Dates of the three most recent scans, so scan cadence is visible
     * without sending anything weekly.
     *
     * @return string[]
     */
    private function recentScans(): array
    {
        $state = ScanStore::STATE_DONE;
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('created'))
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state)
            ->order([$this->db->quoteName('created') . ' DESC', $this->db->quoteName('id') . ' DESC']);

        return array_map(
            static fn (string $created) => substr($created, 0, 10),
            $this->db->setQuery($query, 0, 3)->loadColumn()
        );
    }

    /**
     * Completed scans currently kept in history (bounded by the site's
     * retention settings).
     */
    private function scanCount(): int
    {
        $state = ScanStore::STATE_DONE;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function groupCount(int $scanId): int
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__footprint_groups'))
            ->where($this->db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, \Joomla\Database\ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function componentVersion(): string
    {
        $manifest = \Joomla\CMS\Installer\Installer::parseXMLInstallFile(
            JPATH_ADMINISTRATOR . '/components/com_footprint/footprint.xml'
        ) ?: [];

        return (string) ($manifest['version'] ?? '');
    }
}
