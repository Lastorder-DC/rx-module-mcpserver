<?php

namespace Rhymix\Modules\Mcpserver\Models;

/**
 * OAuth 2.0 File-based Storage
 *
 * Manages persistent storage for OAuth clients, authorization codes, and tokens.
 * Uses file-based JSON storage in the Rhymix files directory.
 */
class OAuthStorage
{
	private string $storagePath;

	public function __construct()
	{
		$this->storagePath = \RX_BASEDIR . 'files/mcpserver/oauth/';
		if (!is_dir($this->storagePath))
		{
			if (!@mkdir($this->storagePath, 0700, true) && !is_dir($this->storagePath))
			{
				throw new \RuntimeException('Failed to create OAuth storage directory: ' . $this->storagePath);
			}
		}
	}

	// ========== Client Registration ==========

	public function saveClient(array $client): void
	{
		$clients = $this->loadJson('clients.json');
		$clients[$client['client_id']] = $client;
		$this->saveJson('clients.json', $clients);
	}

	public function getClient(string $clientId): ?array
	{
		$clients = $this->loadJson('clients.json');
		return $clients[$clientId] ?? null;
	}

	// ========== Authorization Codes ==========

	public function saveAuthorizationCode(string $code, array $data): void
	{
		$codes = $this->loadJson('codes.json');
		$data['created_at'] = time();
		$codes[$code] = $data;
		$this->saveJson('codes.json', $codes);
	}

	public function getAuthorizationCode(string $code): ?array
	{
		$codes = $this->loadJson('codes.json');
		$data = $codes[$code] ?? null;

		if ($data === null)
		{
			return null;
		}

		// Authorization codes expire after 10 minutes
		if (time() - $data['created_at'] > 600)
		{
			$this->deleteAuthorizationCode($code);
			return null;
		}

		return $data;
	}

	public function deleteAuthorizationCode(string $code): void
	{
		$codes = $this->loadJson('codes.json');
		unset($codes[$code]);
		$this->saveJson('codes.json', $codes);
	}

	// ========== Access Tokens ==========

	public function saveToken(string $token, array $data): void
	{
		$tokens = $this->loadJson('tokens.json');
		$data['created_at'] = time();
		$tokens[$token] = $data;
		$this->saveJson('tokens.json', $tokens);
	}

	public function getToken(string $token): ?array
	{
		$tokens = $this->loadJson('tokens.json');
		$data = $tokens[$token] ?? null;

		if ($data === null)
		{
			return null;
		}

		// Check token expiration
		if (isset($data['expires_in']) && time() - $data['created_at'] > $data['expires_in'])
		{
			$this->deleteToken($token);
			return null;
		}

		return $data;
	}

	public function deleteToken(string $token): void
	{
		$tokens = $this->loadJson('tokens.json');
		unset($tokens[$token]);
		$this->saveJson('tokens.json', $tokens);
	}

	// ========== Refresh Tokens ==========

	public function saveRefreshToken(string $refreshToken, array $data): void
	{
		$tokens = $this->loadJson('refresh_tokens.json');
		$data['created_at'] = time();
		$tokens[$refreshToken] = $data;
		$this->saveJson('refresh_tokens.json', $tokens);
	}

	public function getRefreshToken(string $refreshToken): ?array
	{
		$tokens = $this->loadJson('refresh_tokens.json');
		$data = $tokens[$refreshToken] ?? null;

		if ($data === null)
		{
			return null;
		}

		// Refresh tokens expire after 30 days
		if (time() - $data['created_at'] > 2592000)
		{
			$this->deleteRefreshToken($refreshToken);
			return null;
		}

		return $data;
	}

	public function deleteRefreshToken(string $refreshToken): void
	{
		$tokens = $this->loadJson('refresh_tokens.json');
		unset($tokens[$refreshToken]);
		$this->saveJson('refresh_tokens.json', $tokens);
	}

	// ========== File I/O Helpers ==========

	private function loadJson(string $filename): array
	{
		$path = $this->storagePath . $filename;
		if (!file_exists($path))
		{
			return [];
		}

		$content = file_get_contents($path);
		if ($content === false)
		{
			return [];
		}

		return json_decode($content, true) ?: [];
	}

	private function saveJson(string $filename, array $data): void
	{
		$path = $this->storagePath . $filename;
		$content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (file_put_contents($path, $content, LOCK_EX) === false)
		{
			throw new \RuntimeException('Failed to write OAuth data to: ' . $path);
		}
		chmod($path, 0600);
	}

	/**
	 * Clean up expired entries from all storage files.
	 */
	public function cleanup(): void
	{
		// Clean expired authorization codes (10 minutes)
		$codes = $this->loadJson('codes.json');
		$changed = false;
		foreach ($codes as $code => $data)
		{
			if (time() - ($data['created_at'] ?? 0) > 600)
			{
				unset($codes[$code]);
				$changed = true;
			}
		}
		if ($changed)
		{
			$this->saveJson('codes.json', $codes);
		}

		// Clean expired access tokens
		$tokens = $this->loadJson('tokens.json');
		$changed = false;
		foreach ($tokens as $token => $data)
		{
			if (isset($data['expires_in']) && time() - ($data['created_at'] ?? 0) > $data['expires_in'])
			{
				unset($tokens[$token]);
				$changed = true;
			}
		}
		if ($changed)
		{
			$this->saveJson('tokens.json', $tokens);
		}

		// Clean expired refresh tokens (30 days)
		$refreshTokens = $this->loadJson('refresh_tokens.json');
		$changed = false;
		foreach ($refreshTokens as $token => $data)
		{
			if (time() - ($data['created_at'] ?? 0) > 2592000)
			{
				unset($refreshTokens[$token]);
				$changed = true;
			}
		}
		if ($changed)
		{
			$this->saveJson('refresh_tokens.json', $refreshTokens);
		}
	}
}
