<?php

/**
 * This represents the current user.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\User;

use RuntimeException;
use StoryBB\Dependency\Database;
use StoryBB\Dependency\SiteSettings;

class CurrentUser
{
	const STATE_UNACTIVATED = 0;
	const STATE_ACTIVATED = 1;
	const STATE_PENDING_REACTIVATION = 2;
	const STATE_PENDING_ADMIN_ACTIVATION = 3;
	const STATE_PENDING_DELETION = 4;

	const STATE_BANNED = 10;

	const GROUP_GUEST = -1;
	const GROUP_UNALLOCATED = 0;
	const GROUP_ADMIN = 1;

	use Database;
	use SiteSettings;

	protected $user_data = [];

	public function load_user(int $userid)
	{
		$db = $this->db();

		if ($userid)
		{
			$request = $db->query('', '
				SELECT mem.*, chars.id_character, chars.character_name, chars.signature AS char_signature,
					chars.id_theme AS char_theme, chars.is_main, chars.main_char_group, chars.char_groups, COALESCE(a.id_attach, 0) AS id_attach, a.filename, ac.filename AS chars_filename, mainchar.avatar AS char_avatar, chars.avatar AS ic_avatar, mainchar.avatar AS ooc_avatar
				FROM {db_prefix}members AS mem
					LEFT JOIN {db_prefix}characters AS chars ON (chars.id_character = mem.current_character)
					LEFT JOIN {db_prefix}characters AS mainchar ON (mainchar.id_member = mem.id_member AND mainchar.is_main = 1)
					LEFT JOIN {db_prefix}attachments AS a ON (a.id_character = mainchar.id_character AND a.attachment_type = 1)
					LEFT JOIN {db_prefix}attachments AS ac ON (ac.id_character = chars.id_character AND ac.attachment_type = 1)
				WHERE mem.id_member = {int:id_member}
				LIMIT 1',
				[
					'id_member' => $userid,
				]
			);
			$user_data = $db->fetch_assoc($request);
			$db->free_result($request);
		}

		if (!empty($user_data))
		{
			$this->user_data = $user_data;
			$this->user_data['authenticated'] = true;
			$this->user_data['theme'] = (int) $user_data['char_theme'];

			$this->user_data['groups'] = array_merge([$this->user_data['id_group']], explode(',', $this->user_data['additional_groups']));
			$this->user_data['groups'] = array_unique(array_map('intval', $this->user_data['groups']));

			$this->user_data['ic_avatar'] = ['filename' => $user_data['chars_filename'], 'avatar' => $user_data['ic_avatar']];
			$this->user_data['ooc_avatar'] = ['filename' => $user_data['filename'], 'avatar' => $user_data['ooc_avatar']];
		}
		else
		{
			$this->user_data = [
				'authenticated' => false,
				'groups' => [self::GROUP_GUEST],
				'time_offset' => 0,
				'theme' => (int) $this->sitesettings()->theme_guests,
			];
		}

		$immersive = !empty($this->user_data['immersive_mode']);
		if ($this->sitesettings()->enable_immersive_mode == 'on')
		{
			$immersive = true;
		}
		elseif ($this->sitesettings()->enable_immersive_mode == 'off')
		{
			$immersive = false;
		}
		$this->user_data['in_immersive_mode'] = $immersive;

		if (empty($this->user_data['time_format']))
		{
			$this->user_data['time_format'] = $this->sitesettings()->time_format;
		}

		$GLOBALS['user_settings'] = $this->user_data; // @todo Dirty legacy hack.
	}

	public function get_id(): int
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call get_user_time_offset.');
		}

		return $this->user_data['id_member'] ?? 0;
	}

	public function get_theme(): int
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call get_user_time_offset.');
		}

		return $this->user_data['theme'];
	}

	public function get_time_offset(): int
	{
		if (isset($this->user_data['time_offset']))
		{
			return $this->user_data['time_offset'];
		}

		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call get_user_time_offset.');
		}

		if (!empty($this->user_data['timezone']))
		{
			// Get the offsets from UTC for the server, then for the user.
			$tz_system = new DateTimeZone(@date_default_timezone_get());
			$tz_user = new DateTimeZone($this->user_data['timezone']);
			$time_system = new DateTime('now', $tz_system);
			$time_user = new DateTime('now', $tz_user);
			$this->user_data['time_offset'] = ($tz_user->getOffset($time_user) - $tz_system->getOffset($time_system)) / 3600;
		}
		else
		{
			$this->user_data['time_offset'] = 0;
		}
	}

	public function is_immersive_mode(): bool
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call is_authenticated.');
		}
		return $this->user_data['in_immersive_mode'];
	}

	public function is_authenticated(): bool
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call is_authenticated.');
		}
		return $this->user_data['authenticated'];
	}

	public function is_activated(): bool
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call is_activated.');
		}
		return in_array($this->user_data['is_activated'], [self::STATE_ACTIVATED, self::STATE_BANNED + self::STATE_ACTIVATED]);
	}

	public function get_groups(): array
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call get_groups.');
		}
		return $this->user_data['groups'];
	}

	public function is_site_admin(): bool
	{
		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call is_site_admin.');
		}
		return in_array(self::GROUP_ADMIN, $this->user_data['groups']);
	}

	public function can($permission, string $type = 'general', int $perms_context = 0): bool
	{
		if ($this->is_site_admin())
		{
			return true;
		}

		if (empty($this->user_data))
		{
			throw new RuntimeException('Current user has not been loaded; cannot call permissions.');
		}

		if (!isset($this->user_data['permissions'][$type][$perms_context]))
		{
			switch ($type)
			{
				case 'general':
					$perms_context = 0; // Generic permissions have no contextual cue.
					$this->load_general_permissions();
					break;
				case 'board':
					$this->load_board_permissions();
					break;
			}
		}

		$permission = (array) $permission;
		if (!isset($this->user_data['permissions'][$type][$perms_context]))
		{
			throw new RuntimeException('Permissions not loaded (not found?) for type ' . $type . ' in context ' . $perms_context);
		}
		return count(array_intersect($this->user_data['permissions'][$type][$perms_context], $permission)) > 0;
	}

	public function must($permission, string $type = 'generic', int $perms_context = 0): void
	{
		if (!$this->can($permission, $type, $perms_context))
		{
			throw new RuntimeException('Insufficient permissions');
		}
	}

	protected function load_general_permissions(): void
	{
		if (isset($this->user_data['permissions']['general'][0]))
		{
			return;
		}

		$this->user_data['permissions']['general'][0] = [];

		$db = $this->db();
		$request = $db->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:member_groups})',
			[
				'member_groups' => $this->user_data['groups'],
			]
		);
		$removals = [];
		while ($row = $db->fetch_assoc($request))
		{
			if (empty($row['add_deny']))
			{
				$removals[] = $row['permission'];
			}
			else
			{
				$this->user_data['permissions']['general'][0][] = $row['permission'];
			}
		}
		$db->free_result($request);

		$this->user_data['permissions']['general'][0] = array_diff($this->user_data['permissions']['general'][0], $removals);
	}

	protected function load_board_permissions(): void
	{
		if (isset($this->user_data['permissions']['board']))
		{
			return;
		}

		$this->user_data['permissions']['board'] = [];
		$this->user_data['permissions']['board_profiles'] = [];

		$db = $this->db();

		$request = $db->query('', '
			SELECT id_profile, permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:member_groups})',
			[
				'member_groups' => $this->user_data['groups'],
			]
		);
		while ($row = $db->fetch_assoc($request))
		{
			if (empty($row['add_deny']))
			{
				$removals[] = $row['permission'];
			}
			else
			{
				$profiles[$row['id_profile']][] = $row['permission'];
			}
		}
		$db->free_result($request);

		foreach ($profiles as $id_profile => $profile)
		{
			$this->user_data['permissions']['board_profiles'][$id_profile] = array_diff($profile, $removals);
		}

		$request = $db->query('', '
			SELECT id_board, id_profile
			FROM {db_prefix}boards');
		while ($db->fetch_assoc($request))
		{
			$this->user_data['permissions']['board'][$row['id_board']] = $this->user_data['permissions']['board_profiles'][$row['id_profile']] ?? [];
		}
		$db->free_result($request);
	}
}
