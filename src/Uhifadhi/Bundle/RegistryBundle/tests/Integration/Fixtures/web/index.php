<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\StatementTimeoutKernel;

/*
 * A WEB REQUEST'S FRONT CONTROLLER, served by PHP's built-in server — a web
 * server API (`cli-server`), not the console's. It answers with the
 * statement_timeout its database connection opened with.
 *
 * The autoloader is named by the specification that starts the server, so the
 * fixture works in the monorepo and in the split package alike.
 */
$autoload = getenv('UHIFADHI_TEST_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload)) {
    http_response_code(500);
    echo 'UHIFADHI_TEST_AUTOLOAD names no autoloader';

    return;
}

require $autoload;

$kernel = new StatementTimeoutKernel('test', false);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
assert($doctrine instanceof Doctrine\Persistence\ConnectionRegistry);
$connection = $doctrine->getConnection();
assert($connection instanceof Doctrine\DBAL\Connection);

header('Content-Type: text/plain');
$timeout = $connection->fetchOne('SHOW statement_timeout');
echo \PHP_SAPI, ' ', is_string($timeout) ? $timeout : '';
