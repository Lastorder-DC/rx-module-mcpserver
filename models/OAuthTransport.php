<?php

namespace Rhymix\Modules\Mcpserver\Models;

use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\EventStoreInterface;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use PhpMcp\Schema\JsonRpc\Message;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use Throwable;

/**
 * OAuth-enabled Streamable HTTP Transport for MCP Server
 *
 * Wraps StreamableHttpServerTransport with OAuth 2.0 middleware.
 * Handles OAuth endpoints (metadata, registration, authorization, token)
 * and validates Bearer tokens on MCP requests.
 */
class OAuthTransport implements ServerTransportInterface, LoggerAwareInterface, LoopAwareInterface
{
	use EventEmitterTrait;

	private LoggerInterface $logger;
	private LoopInterface $loop;

	private StreamableHttpServerTransport $innerTransport;
	private OAuthServer $oauthServer;

	private ?SocketServer $socket = null;
	private ?HttpServer $http = null;
	private bool $closing = false;

	public function __construct(
		OAuthServer $oauthServer,
		private readonly string $host = '127.0.0.1',
		private readonly int $port = 8080,
		private string $mcpPath = '/mcp',
		private ?array $sslContext = null,
		bool $enableJsonResponse = true,
		bool $stateless = false,
		?EventStoreInterface $eventStore = null
	) {
		$this->logger = new NullLogger();
		$this->loop = Loop::get();
		$this->mcpPath = '/' . trim($mcpPath, '/');
		$this->oauthServer = $oauthServer;

		// Create inner transport for MCP handling (will not call its listen())
		$this->innerTransport = new StreamableHttpServerTransport(
			host: $host,
			port: $port,
			mcpPath: $mcpPath,
			sslContext: $sslContext,
			enableJsonResponse: $enableJsonResponse,
			stateless: $stateless,
			eventStore: $eventStore
		);
	}

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
		$this->innerTransport->setLogger($logger);
	}

	public function setLoop(LoopInterface $loop): void
	{
		$this->loop = $loop;
		$this->innerTransport->setLoop($loop);
	}

	public function listen(): void
	{
		if ($this->closing)
		{
			throw new TransportException('Cannot listen, transport is closing/closed.');
		}

		$listenAddress = "{$this->host}:{$this->port}";
		$protocol = $this->sslContext ? 'https' : 'http';

		try
		{
			$this->socket = new SocketServer(
				$listenAddress,
				$this->sslContext ?? [],
				$this->loop
			);

			// Get the MCP request handler from the inner transport via Reflection
			$refMethod = new \ReflectionMethod($this->innerTransport, 'createRequestHandler');
			$refMethod->setAccessible(true);
			$mcpHandler = $refMethod->invoke($this->innerTransport);

			// Create OAuth middleware
			$oauthMiddleware = $this->createOAuthMiddleware();

			// Create HTTP server with OAuth middleware + MCP handler
			$this->http = new HttpServer($this->loop, $oauthMiddleware, $mcpHandler);
			$this->http->listen($this->socket);

			// Forward events from inner transport to this transport
			foreach (['client_connected', 'message', 'client_disconnected', 'error'] as $event)
			{
				$this->innerTransport->on($event, function () use ($event) {
					$this->emit($event, func_get_args());
				});
			}

			$this->socket->on('error', function (Throwable $error) {
				$this->logger->error('Socket server error (OAuth Transport).', ['error' => $error->getMessage()]);
				$this->emit('error', [new TransportException("Socket server error: {$error->getMessage()}", 0, $error)]);
				$this->close();
			});

			$this->logger->info("OAuth MCP Server is up and listening on {$protocol}://{$listenAddress} 🚀");
			$this->logger->info("MCP Endpoint: {$protocol}://{$listenAddress}{$this->mcpPath}");
			$this->logger->info("OAuth endpoints available at {$protocol}://{$listenAddress}");

			// Schedule periodic cleanup of expired OAuth data (every hour)
			$this->loop->addPeriodicTimer(3600, function () {
				$this->oauthServer->cleanup();
			});

			$this->emit('ready');
		}
		catch (Throwable $e)
		{
			$this->logger->error("Failed to start OAuth Transport on {$listenAddress}", ['exception' => $e]);
			throw new TransportException("Failed to start OAuth Transport on {$listenAddress}: {$e->getMessage()}", 0, $e);
		}
	}

	public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
	{
		return $this->innerTransport->sendMessage($message, $sessionId, $context);
	}

	public function close(): void
	{
		if ($this->closing)
		{
			return;
		}

		$this->closing = true;
		$this->logger->info('Closing OAuth transport...');

		if ($this->socket)
		{
			$this->socket->close();
			$this->socket = null;
		}

		$this->innerTransport->close();

		$this->emit('close', ['Transport closed.']);
		$this->removeAllListeners();
	}

	/**
	 * Create the OAuth middleware callable for React HTTP Server.
	 */
	private function createOAuthMiddleware(): callable
	{
		return function (ServerRequestInterface $request, callable $next) {
			$path = $request->getUri()->getPath();
			$method = $request->getMethod();

			// Add CORS headers for OAuth endpoints
			$corsHeaders = [
				'Access-Control-Allow-Origin' => '*',
				'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
				'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID',
			];

			// Handle CORS preflight for OAuth endpoints
			if ($method === 'OPTIONS' && $this->oauthServer->isOAuthEndpoint($path))
			{
				return new HttpResponse(204, $corsHeaders);
			}

			// Route OAuth endpoints
			if ($this->oauthServer->isOAuthEndpoint($path))
			{
				$response = $this->oauthServer->handleRequest($request);
				foreach ($corsHeaders as $key => $value)
				{
					$response = $response->withHeader($key, $value);
				}
				return $response;
			}

			// For MCP endpoints, validate Bearer token
			if ($path === $this->mcpPath)
			{
				$errorResponse = $this->oauthServer->validateBearerToken($request);
				if ($errorResponse !== null)
				{
					foreach ($corsHeaders as $key => $value)
					{
						$errorResponse = $errorResponse->withHeader($key, $value);
					}
					return $errorResponse;
				}
			}

			// Pass through to MCP handler
			return $next($request);
		};
	}
}
