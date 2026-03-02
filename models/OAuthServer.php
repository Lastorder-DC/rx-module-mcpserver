<?php

namespace Rhymix\Modules\Mcpserver\Models;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

/**
 * OAuth 2.0 Authorization Server
 *
 * Implements OAuth 2.0 with Authorization Code + PKCE flow for MCP server.
 * Supports Protected Resource Metadata (RFC 9728), Authorization Server Metadata (RFC 8414),
 * Dynamic Client Registration (RFC 7591), and Bearer Token validation (RFC 6750).
 */
class OAuthServer
{
	private OAuthStorage $storage;
	private string $baseUrl;
	private string $mcpPath;
	private string $authPassword;

	private const ACCESS_TOKEN_TTL = 3600;
	private const CLIENT_CREDENTIALS_TOKEN_TTL = 3600;

	/**
	 * OAuth endpoints served by this server.
	 */
	private const OAUTH_PATHS = [
		'/.well-known/oauth-protected-resource',
		'/.well-known/oauth-authorization-server',
		'/authorize',
		'/token',
		'/register',
	];

	public function __construct(string $baseUrl, string $mcpPath, string $authPassword)
	{
		$this->storage = new OAuthStorage();
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->mcpPath = $mcpPath;
		$this->authPassword = $authPassword;
	}

	/**
	 * Check if the given path is an OAuth endpoint.
	 */
	public function isOAuthEndpoint(string $path): bool
	{
		return in_array($path, self::OAUTH_PATHS, true);
	}

	/**
	 * Route and handle an OAuth request.
	 */
	public function handleRequest(ServerRequestInterface $request): HttpResponse
	{
		$path = $request->getUri()->getPath();
		$method = $request->getMethod();

		return match ($path) {
			'/.well-known/oauth-protected-resource' => $this->handleProtectedResourceMetadata(),
			'/.well-known/oauth-authorization-server' => $this->handleAuthServerMetadata(),
			'/register' => $method === 'POST' ? $this->handleClientRegistration($request) : $this->methodNotAllowed(),
			'/authorize' => match ($method) {
				'GET' => $this->handleAuthorizeGet($request),
				'POST' => $this->handleAuthorizePost($request),
				default => $this->methodNotAllowed(),
			},
			'/token' => $method === 'POST' ? $this->handleTokenRequest($request) : $this->methodNotAllowed(),
			default => new HttpResponse(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'not_found'])),
		};
	}

	/**
	 * Validate Bearer token from the request.
	 * Returns null if valid, or an HttpResponse if authentication fails.
	 */
	public function validateBearerToken(ServerRequestInterface $request): ?HttpResponse
	{
		$authHeader = $request->getHeaderLine('Authorization');

		if (empty($authHeader))
		{
			return new HttpResponse(401, [
				'Content-Type' => 'application/json',
				'WWW-Authenticate' => 'Bearer resource_metadata="' . $this->baseUrl . '/.well-known/oauth-protected-resource"',
			], json_encode(['error' => 'unauthorized', 'error_description' => 'Bearer token required']));
		}

		if (!str_starts_with($authHeader, 'Bearer '))
		{
			return new HttpResponse(401, [
				'Content-Type' => 'application/json',
				'WWW-Authenticate' => 'Bearer error="invalid_token"',
			], json_encode(['error' => 'invalid_token', 'error_description' => 'Invalid authorization header format']));
		}

		$token = substr($authHeader, 7);
		$tokenData = $this->storage->getToken($token);

		if ($tokenData === null)
		{
			return new HttpResponse(401, [
				'Content-Type' => 'application/json',
				'WWW-Authenticate' => 'Bearer error="invalid_token"',
			], json_encode(['error' => 'invalid_token', 'error_description' => 'Token is invalid or expired']));
		}

		return null;
	}

	/**
	 * Clean up expired OAuth data.
	 */
	public function cleanup(): void
	{
		$this->storage->cleanup();
	}

	// ========== Metadata Endpoints ==========

	private function handleProtectedResourceMetadata(): HttpResponse
	{
		$metadata = [
			'resource' => $this->baseUrl . $this->mcpPath,
			'authorization_servers' => [$this->baseUrl],
			'bearer_methods_supported' => ['header'],
		];

		return new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode($metadata, JSON_UNESCAPED_SLASHES));
	}

	private function handleAuthServerMetadata(): HttpResponse
	{
		$metadata = [
			'issuer' => $this->baseUrl,
			'authorization_endpoint' => $this->baseUrl . '/authorize',
			'token_endpoint' => $this->baseUrl . '/token',
			'registration_endpoint' => $this->baseUrl . '/register',
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token'],
			'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
			'code_challenge_methods_supported' => ['S256'],
		];

		return new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode($metadata, JSON_UNESCAPED_SLASHES));
	}

	// ========== Dynamic Client Registration ==========

	private function handleClientRegistration(ServerRequestInterface $request): HttpResponse
	{
		$body = json_decode($request->getBody()->getContents(), true);

		if (!$body)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client_metadata',
				'error_description' => 'Invalid request body',
			]));
		}

		$grantTypes = $body['grant_types'] ?? ['authorization_code'];
		$authMethod = $body['token_endpoint_auth_method'] ?? 'none';
		$isClientCredentials = in_array('client_credentials', $grantTypes, true);

		// redirect_uris is required for authorization_code grant, optional for client_credentials
		if (!$isClientCredentials && (!isset($body['redirect_uris']) || !is_array($body['redirect_uris']) || empty($body['redirect_uris'])))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client_metadata',
				'error_description' => 'redirect_uris is required and must be a non-empty array for authorization_code grant',
			]));
		}

		$clientId = bin2hex(random_bytes(16));
		$clientSecret = null;

		// Generate client_secret for confidential clients
		if ($isClientCredentials || in_array($authMethod, ['client_secret_post', 'client_secret_basic'], true))
		{
			$clientSecret = bin2hex(random_bytes(32));
			$authMethod = $authMethod === 'none' ? 'client_secret_post' : $authMethod;
		}

		$client = [
			'client_id' => $clientId,
			'client_name' => $body['client_name'] ?? 'Unknown Client',
			'redirect_uris' => $body['redirect_uris'] ?? [],
			'grant_types' => $grantTypes,
			'response_types' => $body['response_types'] ?? ['code'],
			'token_endpoint_auth_method' => $authMethod,
			'created_at' => time(),
		];

		if ($clientSecret !== null)
		{
			$client['client_secret'] = password_hash($clientSecret, PASSWORD_BCRYPT);
		}

		$this->storage->saveClient($client);

		$response = [
			'client_id' => $clientId,
			'client_name' => $client['client_name'],
			'redirect_uris' => $client['redirect_uris'],
			'grant_types' => $client['grant_types'],
			'response_types' => $client['response_types'],
			'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
		];

		if ($clientSecret !== null)
		{
			$response['client_secret'] = $clientSecret;
		}

		return new HttpResponse(201, ['Content-Type' => 'application/json'], json_encode($response, JSON_UNESCAPED_SLASHES));
	}

	// ========== Authorization Endpoint ==========

	/**
	 * Check if the request comes from a logged-in Rhymix administrator.
	 * Reads the PHP session cookie and verifies admin status from session data.
	 */
	private function isRequestFromAdmin(ServerRequestInterface $request): bool
	{
		$cookieHeader = $request->getHeaderLine('Cookie');
		if (empty($cookieHeader))
		{
			return false;
		}

		$cookies = [];
		foreach (explode(';', $cookieHeader) as $cookie)
		{
			$cookie = trim($cookie);
			if (empty($cookie))
			{
				continue;
			}
			$eqPos = strpos($cookie, '=');
			if ($eqPos === false)
			{
				continue;
			}
			$name = urldecode(trim(substr($cookie, 0, $eqPos)));
			$value = urldecode(trim(substr($cookie, $eqPos + 1)));
			$cookies[$name] = $value;
		}

		$sessionName = ini_get('session.name') ?: 'PHPSESSID';
		$sessionId = $cookies[$sessionName] ?? null;
		if (empty($sessionId))
		{
			return false;
		}

		// Validate session ID format to prevent path traversal
		if (!preg_match('/^[a-zA-Z0-9,-]+$/', $sessionId))
		{
			return false;
		}

		$savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
		// Handle N;/path or N;MODE;/path formats
		if (preg_match('/^(?:\d+;)?(?:\d+;)?(.+)$/', $savePath, $matches))
		{
			$savePath = $matches[1];
		}

		$sessionFile = rtrim($savePath, '/') . '/sess_' . $sessionId;
		if (!file_exists($sessionFile))
		{
			return false;
		}

		$rawData = @file_get_contents($sessionFile);
		if ($rawData === false || empty($rawData))
		{
			return false;
		}

		// Find logged_info in session data
		$pos = strpos($rawData, 'logged_info|');
		if ($pos === false)
		{
			return false;
		}

		$serializedPart = substr($rawData, $pos + strlen('logged_info|'));
		$loggedInfo = @unserialize($serializedPart);

		if (!$loggedInfo || !is_object($loggedInfo))
		{
			return false;
		}

		return (isset($loggedInfo->is_admin) && $loggedInfo->is_admin === 'Y');
	}

	private function handleAuthorizeGet(ServerRequestInterface $request): HttpResponse
	{
		$params = $request->getQueryParams();

		$clientId = $params['client_id'] ?? '';
		$redirectUri = $params['redirect_uri'] ?? '';
		$responseType = $params['response_type'] ?? '';
		$codeChallenge = $params['code_challenge'] ?? '';
		$codeChallengeMethod = $params['code_challenge_method'] ?? '';
		$state = $params['state'] ?? '';
		$scope = $params['scope'] ?? '';

		// Validate required parameters
		if (empty($clientId) || empty($redirectUri) || $responseType !== 'code')
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'Missing required parameters: client_id, redirect_uri, response_type=code',
			]));
		}

		// PKCE is required
		if (empty($codeChallenge) || $codeChallengeMethod !== 'S256')
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'PKCE is required. code_challenge and code_challenge_method=S256 must be provided.',
			]));
		}

		// Verify admin login
		if (!$this->isRequestFromAdmin($request))
		{
			return new HttpResponse(403, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'access_denied',
				'error_description' => 'Administrator login is required. Please log in as an administrator first.',
			]));
		}

		// Validate client
		$client = $this->storage->getClient($clientId);
		if ($client === null)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client',
				'error_description' => 'Unknown client_id',
			]));
		}

		// Validate redirect_uri
		if (!in_array($redirectUri, $client['redirect_uris'], true))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'redirect_uri does not match registered URIs',
			]));
		}

		// Show authorization page
		$html = $this->renderAuthorizationPage($client['client_name'], $clientId, $redirectUri, $codeChallenge, $codeChallengeMethod, $state, $scope);

		return new HttpResponse(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
	}

	private function handleAuthorizePost(ServerRequestInterface $request): HttpResponse
	{
		$contentType = $request->getHeaderLine('Content-Type');
		if (str_contains($contentType, 'application/x-www-form-urlencoded'))
		{
			parse_str($request->getBody()->getContents(), $body);
		}
		else
		{
			$body = $request->getParsedBody() ?? [];
		}

		$password = $body['password'] ?? '';
		$clientId = $body['client_id'] ?? '';
		$redirectUri = $body['redirect_uri'] ?? '';
		$codeChallenge = $body['code_challenge'] ?? '';
		$codeChallengeMethod = $body['code_challenge_method'] ?? '';
		$state = $body['state'] ?? '';
		$action = $body['action'] ?? '';

		// Handle deny
		if ($action === 'deny')
		{
			$redirectTo = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
				'error' => 'access_denied',
				'error_description' => 'The resource owner denied the request.',
				'state' => $state,
			]);
			return new HttpResponse(302, ['Location' => $redirectTo]);
		}

		// Verify admin login
		if (!$this->isRequestFromAdmin($request))
		{
			return new HttpResponse(403, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'access_denied',
				'error_description' => 'Administrator login is required. Please log in as an administrator first.',
			]));
		}

		// Validate password
		if (!password_verify($password, $this->authPassword))
		{
			$client = $this->storage->getClient($clientId);
			$clientName = $client['client_name'] ?? 'Unknown Client';
			$html = $this->renderAuthorizationPage($clientName, $clientId, $redirectUri, $codeChallenge, $codeChallengeMethod, $state, '', 'Invalid password. Please try again.');

			return new HttpResponse(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
		}

		// Validate client
		$client = $this->storage->getClient($clientId);
		if ($client === null)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client',
				'error_description' => 'Unknown client_id',
			]));
		}

		// Generate authorization code
		$code = bin2hex(random_bytes(32));
		$this->storage->saveAuthorizationCode($code, [
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => $codeChallengeMethod,
		]);

		// Redirect with authorization code
		$redirectTo = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query(array_filter([
			'code' => $code,
			'state' => $state,
		]));

		return new HttpResponse(302, ['Location' => $redirectTo]);
	}

	// ========== Token Endpoint ==========

	private function handleTokenRequest(ServerRequestInterface $request): HttpResponse
	{
		$contentType = $request->getHeaderLine('Content-Type');
		if (str_contains($contentType, 'application/x-www-form-urlencoded'))
		{
			parse_str($request->getBody()->getContents(), $body);
		}
		else
		{
			$body = json_decode($request->getBody()->getContents(), true) ?? [];
		}

		// Extract client credentials from Authorization header (client_secret_basic)
		$authHeader = $request->getHeaderLine('Authorization');
		if (!empty($authHeader) && str_starts_with($authHeader, 'Basic '))
		{
			// RFC 6749 Section 2.3.1: Clients MUST NOT use more than one auth method
			if (!empty($body['client_secret']))
			{
				return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
					'error' => 'invalid_request',
					'error_description' => 'Multiple client authentication methods are not allowed',
				]));
			}

			$decoded = base64_decode(substr($authHeader, 6), true);
			if ($decoded !== false && str_contains($decoded, ':'))
			{
				[$basicClientId, $basicClientSecret] = explode(':', $decoded, 2);
				$body['client_id'] = urldecode($basicClientId);
				$body['client_secret'] = urldecode($basicClientSecret);
			}
		}

		$grantType = $body['grant_type'] ?? '';

		return match ($grantType) {
			'authorization_code' => $this->handleAuthorizationCodeGrant($body),
			'client_credentials' => $this->handleClientCredentialsGrant($body),
			'refresh_token' => $this->handleRefreshTokenGrant($body),
			default => new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'unsupported_grant_type',
				'error_description' => 'Supported grant types: authorization_code, client_credentials, refresh_token',
			])),
		};
	}

	private function handleAuthorizationCodeGrant(array $body): HttpResponse
	{
		$code = $body['code'] ?? '';
		$clientId = $body['client_id'] ?? '';
		$redirectUri = $body['redirect_uri'] ?? '';
		$codeVerifier = $body['code_verifier'] ?? '';

		if (empty($code) || empty($clientId) || empty($codeVerifier))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'Missing required parameters: code, client_id, code_verifier',
			]));
		}

		// Retrieve and validate authorization code
		$codeData = $this->storage->getAuthorizationCode($code);
		if ($codeData === null)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'Authorization code is invalid or expired',
			]));
		}

		// Verify client_id matches
		if ($codeData['client_id'] !== $clientId)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'client_id does not match',
			]));
		}

		// Verify redirect_uri matches (if provided)
		if (!empty($redirectUri) && $codeData['redirect_uri'] !== $redirectUri)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'redirect_uri does not match',
			]));
		}

		// Verify PKCE code_verifier
		$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
		if (!hash_equals($codeData['code_challenge'], $expectedChallenge))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'PKCE verification failed',
			]));
		}

		// Delete used authorization code (single use)
		$this->storage->deleteAuthorizationCode($code);

		// Generate tokens
		$accessToken = bin2hex(random_bytes(32));
		$refreshToken = bin2hex(random_bytes(32));

		$this->storage->saveToken($accessToken, [
			'client_id' => $clientId,
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'token_type' => 'Bearer',
		]);

		$this->storage->saveRefreshToken($refreshToken, [
			'client_id' => $clientId,
			'access_token' => $accessToken,
		]);

		return new HttpResponse(200, [
			'Content-Type' => 'application/json',
			'Cache-Control' => 'no-store',
		], json_encode([
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refreshToken,
		]));
	}

	private function handleClientCredentialsGrant(array $body): HttpResponse
	{
		$clientId = $body['client_id'] ?? '';
		$clientSecret = $body['client_secret'] ?? '';

		if (empty($clientId) || empty($clientSecret))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'Missing required parameters: client_id, client_secret',
			]));
		}

		// Validate client
		$client = $this->storage->getClient($clientId);
		if ($client === null)
		{
			return new HttpResponse(401, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client',
				'error_description' => 'Unknown client_id',
			]));
		}

		// Verify client_credentials grant is allowed
		if (!in_array('client_credentials', $client['grant_types'] ?? [], true))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'unauthorized_client',
				'error_description' => 'Client is not authorized for client_credentials grant',
			]));
		}

		// Verify client_secret
		if (!isset($client['client_secret']) || !password_verify($clientSecret, $client['client_secret']))
		{
			return new HttpResponse(401, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_client',
				'error_description' => 'Invalid client credentials',
			]));
		}

		// Generate access token (no refresh token for client_credentials)
		$accessToken = bin2hex(random_bytes(32));

		$this->storage->saveToken($accessToken, [
			'client_id' => $clientId,
			'expires_in' => self::CLIENT_CREDENTIALS_TOKEN_TTL,
			'token_type' => 'Bearer',
			'grant_type' => 'client_credentials',
		]);

		return new HttpResponse(200, [
			'Content-Type' => 'application/json',
			'Cache-Control' => 'no-store',
		], json_encode([
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => self::CLIENT_CREDENTIALS_TOKEN_TTL,
		]));
	}

	private function handleRefreshTokenGrant(array $body): HttpResponse
	{
		$refreshTokenStr = $body['refresh_token'] ?? '';
		$clientId = $body['client_id'] ?? '';

		if (empty($refreshTokenStr) || empty($clientId))
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_request',
				'error_description' => 'Missing required parameters: refresh_token, client_id',
			]));
		}

		$refreshData = $this->storage->getRefreshToken($refreshTokenStr);
		if ($refreshData === null)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'Refresh token is invalid or expired',
			]));
		}

		if ($refreshData['client_id'] !== $clientId)
		{
			return new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'client_id does not match',
			]));
		}

		// Revoke old tokens
		if (!empty($refreshData['access_token']))
		{
			$this->storage->deleteToken($refreshData['access_token']);
		}
		$this->storage->deleteRefreshToken($refreshTokenStr);

		// Generate new tokens
		$newAccessToken = bin2hex(random_bytes(32));
		$newRefreshToken = bin2hex(random_bytes(32));

		$this->storage->saveToken($newAccessToken, [
			'client_id' => $clientId,
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'token_type' => 'Bearer',
		]);

		$this->storage->saveRefreshToken($newRefreshToken, [
			'client_id' => $clientId,
			'access_token' => $newAccessToken,
		]);

		return new HttpResponse(200, [
			'Content-Type' => 'application/json',
			'Cache-Control' => 'no-store',
		], json_encode([
			'access_token' => $newAccessToken,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $newRefreshToken,
		]));
	}

	// ========== Helpers ==========

	private function methodNotAllowed(): HttpResponse
	{
		return new HttpResponse(405, ['Content-Type' => 'application/json'], json_encode(['error' => 'method_not_allowed']));
	}

	private function renderAuthorizationPage(string $clientName, string $clientId, string $redirectUri, string $codeChallenge, string $codeChallengeMethod, string $state, string $scope = '', string $error = ''): string
	{
		$clientNameEsc = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
		$clientIdEsc = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
		$redirectUriEsc = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');
		$codeChallengeEsc = htmlspecialchars($codeChallenge, ENT_QUOTES, 'UTF-8');
		$codeChallengeMethodEsc = htmlspecialchars($codeChallengeMethod, ENT_QUOTES, 'UTF-8');
		$stateEsc = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');
		$scopeEsc = htmlspecialchars($scope, ENT_QUOTES, 'UTF-8');
		$errorHtml = $error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCP Server - Authorization</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 40px; max-width: 420px; width: 100%; }
        h1 { font-size: 20px; margin-bottom: 8px; color: #333; }
        .client-info { background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 16px 0; }
        .client-info .label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        .client-info .value { font-size: 16px; font-weight: 600; color: #333; margin-top: 4px; }
        .description { color: #666; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: #333; }
        .form-group input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .form-group input[type="password"]:focus { outline: none; border-color: #4A90D9; box-shadow: 0 0 0 3px rgba(74,144,217,0.1); }
        .buttons { display: flex; gap: 12px; margin-top: 24px; }
        .btn { flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #4A90D9; color: white; }
        .btn-primary:hover { background: #357ABD; }
        .btn-secondary { background: #e9ecef; color: #495057; }
        .btn-secondary:hover { background: #dee2e6; }
        .error { background: #fff3f3; border: 1px solid #ffcdd2; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Authorization Request</h1>
        <p class="description">An application is requesting access to your MCP server.</p>
        
        <div class="client-info">
            <div class="label">Application</div>
            <div class="value">{$clientNameEsc}</div>
        </div>

        {$errorHtml}

        <form method="POST" action="/authorize">
            <input type="hidden" name="client_id" value="{$clientIdEsc}">
            <input type="hidden" name="redirect_uri" value="{$redirectUriEsc}">
            <input type="hidden" name="code_challenge" value="{$codeChallengeEsc}">
            <input type="hidden" name="code_challenge_method" value="{$codeChallengeMethodEsc}">
            <input type="hidden" name="state" value="{$stateEsc}">
            <input type="hidden" name="scope" value="{$scopeEsc}">

            <div class="form-group">
                <label for="password">Authorization Password</label>
                <input type="password" id="password" name="password" required autofocus placeholder="Enter the configured authorization password">
            </div>

            <div class="buttons">
                <button type="submit" name="action" value="deny" class="btn btn-secondary">Deny</button>
                <button type="submit" name="action" value="approve" class="btn btn-primary">Authorize</button>
            </div>
        </form>
    </div>
</body>
</html>
HTML;
	}
}
