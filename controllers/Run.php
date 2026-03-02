<?php

namespace Rhymix\Modules\Mcpserver\Controllers;

use PhpMcp\Server\Server;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Rhymix\Modules\Mcpserver\Models\Config as ConfigModel;
use Rhymix\Modules\Mcpserver\Models\MCPCache;
use Rhymix\Modules\Mcpserver\Models\MCPLogger;
use Rhymix\Modules\Mcpserver\Models\MethodValidator;

if (PHP_SAPI === 'cli')
{
    require_once __DIR__ . '/../../../common/scripts/common.php';
}

class Run extends Base
{
	public static function start()
	{
		$config = ConfigModel::getConfig();
        $cache = MCPCache::getInstance($config);
        $logger = MCPLogger::getInstance($config);

        $server = Server::make()
            ->withServerInfo(
                $config->serverName,
                $config->serverVersion
            )
            ->withLogger($logger)
            ->withCache($cache)
            ->build();

        [$baseDir, $paths] = MethodValidator::getDiscoverDirs($logger);
        $server->discover($baseDir, $paths);

        MethodValidator::updateLockFile(); // Update the lock file with current method hashes

        $transport = new StreamableHttpServerTransport(
            $config->serverHost,
            $config->serverPort,
            $config->mcpPath,
            null, // sslContext
            !$config->mcpSSEEnable,
            $config->mcpStateless
        );

        $server->listen($transport);
	}
}

if (PHP_SAPI === 'cli')
{
    Run::start();
}