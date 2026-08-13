<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(DatabaseInterface::class)) implements InstallerScriptInterface {
                public function __construct(private DatabaseInterface $db)
                {
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    $this->runSqlFile(__DIR__ . '/sql/uninstall.mysql.utf8.sql');

                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    // Safety net: a discover-install does not always run the install SQL.
                    $this->runSqlFile(__DIR__ . '/sql/install.mysql.utf8.sql');

                    return true;
                }

                private function runSqlFile(string $path): void
                {
                    $sql = @file_get_contents($path);

                    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
                        $this->db->setQuery($statement)->execute();
                    }
                }
            }
        );
    }
};
