<?php

namespace Rhymix\Modules\Mcpserver\Controllers;

use PhpMcp\Server\Server;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Rhymix\Modules\Mcpserver\Models\Config as ConfigModel;
use Rhymix\Modules\Mcpserver\Models\MCPCache;
use Rhymix\Modules\Mcpserver\Models\MCPLogger;
use Rhymix\Modules\Mcpserver\Models\MethodValidator;
use Rhymix\Modules\Mcpserver\Models\OAuthServer;
use Rhymix\Modules\Mcpserver\Models\OAuthTransport;

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
                name: $config->serverName,
                version: $config->serverVersion,
            )
            ->withLogger($logger)
            ->withCache($cache)
            ->build();

        [$baseDir, $paths] = MethodValidator::getDiscoverDirs($logger);
        $server->discover($baseDir, $paths);

        MethodValidator::updateLockFile(); // Update the lock file with current method hashes

        if ($config->oauthEnabled && !empty($config->oauthPassword))
        {
            try
            {
                $internalBaseUrl = "http://{$config->serverHost}:{$config->serverPort}";
                $publicBaseUrl = !empty($config->oauthPublicUrl) ? rtrim($config->oauthPublicUrl, '/') : $internalBaseUrl;
                $oauthServer = new OAuthServer($publicBaseUrl, $config->mcpPath, $config->oauthPassword);

                $transport = new OAuthTransport(
                    host: $config->serverHost,
                    port: $config->serverPort,
                    mcpPath: $config->mcpPath,
                    enableJsonResponse: !$config->mcpSSEEnable,
                    stateless: $config->mcpStateless,
                    oauthServer: $oauthServer
                );
            }
            catch (\Throwable $e)
            {
                $logger->error('Failed to initialize OAuth transport, falling back to standard transport.', [
                    'error' => $e->getMessage(),
                ]);

                $transport = new StreamableHttpServerTransport(
                    host: $config->serverHost,
                    port: $config->serverPort,
                    mcpPath: $config->mcpPath,
                    enableJsonResponse: !$config->mcpSSEEnable,
                    stateless: $config->mcpStateless
                );
            }
        }
        else
        {
            $transport = new StreamableHttpServerTransport(
                host: $config->serverHost,
                port: $config->serverPort,
                mcpPath: $config->mcpPath,
                enableJsonResponse: !$config->mcpSSEEnable,
                stateless: $config->mcpStateless
            );
        }

        $server->listen($transport);
	}
}

if (PHP_SAPI === 'cli')
{
    Run::start();
}