<?php

/**
 * The session database handler.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Session;

use StoryBB\Database\DatabaseAdapter;
use StoryBB\Dependency\Database;
use StoryBB\Dependency\SiteSettings;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;

/**
 * Session handler using a StoryBB connection. This is based on Symfony's PDO connector.
 */
class DatabaseHandler extends AbstractSessionHandler
{
	use Database;
	use SiteSettings;

	private const MAX_LIFETIME = 315576000;

	/**
	 * @var bool True when the current session exists but expired according to session.gc_maxlifetime
	 */
	private $sessionExpired = false;

	/**
	 * @var bool Whether gc() has been called
	 */
	private $gcCalled = false;


	public function isSessionExpired()
	{
		return $this->sessionExpired;
	}

	public function open(string $save_path, string $session_name): bool
	{
		parent::open($save_path, $session_name);
		$this->sessionExpired = false;

		return true;
	}

	public function gc(int $maxlifetime): int|false
	{
		// We delay gc() to close() so that it is executed outside the transactional and blocking read-write process.
		// This way, pruning expired sessions does not block them from being started while the current session is used.
		$this->gcCalled = true;

		return 0;
	}

	protected function doDestroy(#[\SensitiveParameter] string $session_id): bool
	{
		$this->db()->query('', '
			DELETE FROM {db_prefix}sessions
			WHERE session_id = {string:session_id}',
			[
				'session_id' => $session_id,
			]
		);

		return true;
	}

	protected function doWrite(#[\SensitiveParameter] string $session_id, string $data): bool
	{
		$maxlifetime = max((int) ini_get('session.gc_maxlifetime'), 2880);

		$db = $this->db();

		$db->query('', '
			UPDATE {db_prefix}sessions
			SET data = {string:data},
				lifetime = {int:expiry},
				session_time = {int:time}
			WHERE session_id = {string:session_id}',
			[
				'session_id' => $session_id,
				'data' => $data,
				'expiry' => time() + $maxlifetime,
				'time' => time(),
			]
		);

		if ($db->affected_rows() == 0)
		{
			$db->insert(DatabaseAdapter::INSERT_INSERT,
				'{db_prefix}sessions',
				['session_id' => 'string', 'data' => 'string', 'lifetime' => 'int', 'session_time' => 'int'],
				[$session_id, $data, time() + $maxlifetime, time()],
				['session_id'],
				DatabaseAdapter::RETURN_NOTHING
			);
		}

		return true;
	}

	#[\ReturnTypeWillChange]
	public function updateTimestamp($session_id, $data)
	{
		$expiry = (int) ini_get('session.gc_maxlifetime');

		$this->db()->query('', '
			UPDATE {db_prefix}sessions
			SET lifetime = {int:expiry},
				session_time = {int:time}
			WHERE session_id = {string:session_id}',
			[
				'session_id' => $session_id,
				'expiry' => time() + $expiry,
				'time' => time(),
			]
		);

		return true;
	}

	public function close(): bool
	{
		if ($this->gcCalled) {
			$this->gcCalled = false;

			$this->db()->query('', '
				DELETE FROM {db_prefix}sessions
				WHERE lifetime <= {int:time}
					AND lifetime > {int:min}',
				[
					'time' => time(),
					'min' => self::MAX_LIFETIME,
				]
			);

		}

		return true;
	}

	protected function doRead(#[\SensitiveParameter] string $session_id): string
	{
		$db = $this->db();
		$result = $db->query('', '
			SELECT data, lifetime
			FROM {db_prefix}sessions
			WHERE session_id = {string:session_id}
			LIMIT 1',
			[
				'session_id' => $session_id,
			]
		);
		list ($sess_data, $expiry) = $db->fetch_row($result);
		$db->free_result($result);

		if ($expiry <= time())
		{
			$this->sessionExpired = true;
			return '';
		}

		return $sess_data ?? '';
	}
}
