<?php

/**
 * This file has the hefty job of loading information for the forum.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use LightnCandy\LightnCandy;
use StoryBB\App;
use StoryBB\Container;
use StoryBB\Database\AdapterFactory;
use StoryBB\Database\Exception as DatabaseException;
use StoryBB\Model\Language;
use StoryBB\Helper\IP;
use StoryBB\Helper\Parser;
use StoryBB\Helper\BrowserDetect;
use StoryBB\Hook\Manager as HookManager;
use StoryBB\Hook\Mutatable;
use StoryBB\Model\Currency;
use StoryBB\Plugin\Manager as PluginManager;
use StoryBB\StringLibrary;

/**
 * Load the $modSettings array.
 */
function reloadSettings()
{
	global $modSettings, $boarddir, $smcFunc;
	global $cache_enable, $sourcedir, $context;

	// We need some caching support, maybe.
	StoryBB\Cache::initialize();

	// Try to load it from the cache first; it'll never get cached if the setting is off.
	if (($modSettings = cache_get_data('modSettings', 90)) == null)
	{
		try
		{
			$container = Container::instance();
			$site_settings = $container->get('sitesettings');
			$modSettings = $site_settings->get_all();
		}
		catch (RuntimeException $e)
		{
			display_db_error();
		}

		if (!is_array($modSettings['attachmentUploadDir']))
			$modSettings['attachmentUploadDir'] = sbb_json_decode($modSettings['attachmentUploadDir'], true);

		if (!empty($cache_enable))
			cache_put_data('modSettings', $modSettings, 90);
	}

	$context['enabled_plugins'] = [];
	if (!empty($modSettings['enabled_plugins']))
	{
		$context['enabled_plugins'] = PluginManager::get_enabled_plugins();

		$additional_autoload = PluginManager::get_plugin_sources();
		if (!empty($additional_autoload))
		{
			$autoloader = include(__DIR__ . '/../vendor/autoload.php');
			$autoloader->addPsr4('', $additional_autoload, false);
		}

		foreach ($context['enabled_plugins'] as $plugin)
		{
			foreach ($plugin['hooks'] as $hook => $hookable)
			{
				HookManager::register($hook, $hookable['priority'], $hookable['callable']);
			}
		}
	}

	// Let's make sure we have these settings set up.
	if (empty($modSettings['enable_immersive_mode']) || !in_array($modSettings['enable_immersive_mode'], ['user_on', 'user_off', 'on', 'off']))
	{
		$modSettings['enable_immersive_mode'] = 'user_on';
	}
	$modSettings['cache_enable'] = $cache_enable;

	// Setting the timezone is a requirement for some functions.
	if (isset($modSettings['default_timezone']) && in_array($modSettings['default_timezone'], timezone_identifiers_list()))
		date_default_timezone_set($modSettings['default_timezone']);
	else
	{
		// Get PHP's default timezone, if set
		$ini_tz = ini_get('date.timezone');
		if (!empty($ini_tz))
			$modSettings['default_timezone'] = $ini_tz;
		else
			$modSettings['default_timezone'] = '';

		// If date.timezone is unset, invalid, or just plain weird, make a best guess
		if (!in_array($modSettings['default_timezone'], timezone_identifiers_list()))
		{	
			$server_offset = @mktime(0, 0, 0, 1, 1, 1970);
			$modSettings['default_timezone'] = timezone_name_from_abbr('', $server_offset, 0);
		}

		date_default_timezone_set($modSettings['default_timezone']);
	}

	// Check the load averages?
	if (!empty($modSettings['loadavg_enable']))
	{
		if (($modSettings['load_average'] = cache_get_data('loadavg', 90)) == null)
		{
			$modSettings['load_average'] = @file_get_contents('/proc/loadavg');
			if (!empty($modSettings['load_average']) && preg_match('~^([^ ]+?) ([^ ]+?) ([^ ]+)~', $modSettings['load_average'], $matches) != 0)
				$modSettings['load_average'] = (float) $matches[1];
			elseif (($modSettings['load_average'] = @`uptime`) != null && preg_match('~load average[s]?: (\d+\.\d+), (\d+\.\d+), (\d+\.\d+)~i', $modSettings['load_average'], $matches) != 0)
				$modSettings['load_average'] = (float) $matches[1];
			else
				unset($modSettings['load_average']);

			if (!empty($modSettings['load_average']) || $modSettings['load_average'] === 0.0)
				cache_put_data('loadavg', $modSettings['load_average'], 90);
		}

		if (!empty($modSettings['load_average']) || $modSettings['load_average'] === 0.0)
			call_integration_hook('integrate_load_average', [$modSettings['load_average']]);

		if (!empty($modSettings['loadavg_forum']) && !empty($modSettings['load_average']) && $modSettings['load_average'] >= $modSettings['loadavg_forum'])
			display_loadavg_error();
	}

	// Here to justify the name of this function. :P
	// It should be added to the install and upgrade scripts.
	// But since the converters need to be updated also. This is easier.
	if (empty($modSettings['currentAttachmentUploadDir']))
	{
		updateSettings([
			'attachmentUploadDir' => json_encode([1 => $modSettings['attachmentUploadDir']]),
			'currentAttachmentUploadDir' => 1,
		]);
	}

	// This determines the server... not used in many places, except for login fixing.
	$context['server'] = [
		'is_iis' => isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false,
		'is_apache' => isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false,
		'is_litespeed' => isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false,
		'is_lighttpd' => isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'lighttpd') !== false,
		'is_nginx' => isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false,
		'is_cgi' => isset($_SERVER['SERVER_SOFTWARE']) && strpos(php_sapi_name(), 'cgi') !== false,
		'is_windows' => strpos(PHP_OS, 'WIN') === 0,
	];

	// Define an array for custom profile fields placements.
	$context['cust_profile_fields_placement'] = [
		'standard',
		'icons',
		'above_signature',
		'below_signature',
		'below_avatar',
		'above_member',
		'bottom_poster',
		'after_membergroup',
	];

	// Define an array for content-related <meta> elements (e.g. description, keywords, Open Graph) for the HTML head.
	$context['meta_tags'] = [];

	// Define an array of allowed HTML tags.
	$context['allowed_html_tags'] = [
		'<img>',
		'<div>',
	];

	// These are the only valid image types for StoryBB, by default anyway.
	$context['validImageTypes'] = [
		1 => 'gif',
		2 => 'jpeg',
		3 => 'png',
		5 => 'psd',
		6 => 'bmp',
		7 => 'tiff',
		8 => 'tiff',
		9 => 'jpeg',
		14 => 'iff'
	];

	// Define a list of allowed tags for descriptions.
	$context['description_allowed_tags'] = ['abbr', 'anchor', 'b', 'center', 'color', 'font', 'hr', 'i', 'img', 'iurl', 'left', 'li', 'list', 'ltr', 'pre', 'right', 's', 'sub', 'sup', 'table', 'td', 'tr', 'u', 'url',];

	// Call pre load integration functions.
	call_integration_hook('integrate_pre_load');
}

/**
 * Load all the important user information.
 * What it does:
 * 	- sets up the $user_info array
 * 	- assigns $user_info['query_wanna_see_board'] for what boards the user can see.
 * 	- first checks for cookie or integration validation.
 * 	- uses the current session if no integration function or cookie is found.
 * 	- checks password length, if member is activated and the login span isn't over.
 * 		- if validation fails for the user, $id_member is set to 0.
 * 		- updates the last visit time when needed.
 */
function loadUserSettings()
{
	global $modSettings, $user_settings, $sourcedir;
	global $cookiename, $user_info, $language, $context, $image_proxy_enabled, $image_proxy_secret, $boardurl;

	if (empty($_SERVER['BAN_CHECK_IP']))
	{
		$_SERVER['BAN_CHECK_IP'] = $_SERVER['REMOTE_ADDR'] ?? '';
	}

	$container = Container::instance();
	$db = $container->get('database');


	// Only load this stuff if the user isn't a guest.
	$user = $container->get('currentuser');
	$id_member = $user->get_id();
	if ($id_member != 0)
	{
		// Did we find 'im?  If not, junk it.
		if (!empty($user_settings))
		{
			// Wrong password or not activated - either way, you're going nowhere.
			$id_member = $user->is_activated() ? (int) $user_settings['id_member'] : 0;
		}
		else
			$id_member = 0;

	}

	// Found 'im, let's set up the variables.
	$user_info = [
		'groups' => $user->get_groups(),
	];
	if ($id_member != 0)
	{
		$user_settings['id_theme'] = $user_settings['char_theme'];
		$user_settings['avatar'] = $user_settings['char_avatar'];

		if (!empty($modSettings['force_ssl']) && $image_proxy_enabled && stripos($user_settings['avatar'], 'http://') !== false)
		{
			$user_settings['avatar'] = strtr($boardurl, ['http://' => 'https://']) . '/proxy.php?request=' . urlencode($user_settings['avatar']) . '&hash=' . md5($user_settings['avatar'] . $image_proxy_secret);
		}

		// Let's not update the last visit time in these cases...
		// 1. RSS feeds and XMLHTTP requests don't count either.
		// 2. If it was set within this session, no need to set it again.
		// 3. New session, yet updated < five hours ago? Maybe cache can help.
		// 4. We're still logging in or authenticating @todo reinstate?
		if (!isset($_REQUEST['xml']) && (!isset($_REQUEST['action']) || !in_array($_REQUEST['action'], ['.xml'])) && empty($_SESSION['id_msg_last_visit']) && (empty($modSettings['cache_enable']) || ($_SESSION['id_msg_last_visit'] = cache_get_data('user_last_visit-' . $id_member, 5 * 3600)) === null))
		{
			// @todo can this be cached?
			// Do a quick query to make sure this isn't a mistake.
			$result = $db->query('', '
				SELECT poster_time
				FROM {db_prefix}messages
				WHERE id_msg = {int:id_msg}
				LIMIT 1',
				[
					'id_msg' => $user_settings['id_msg_last_visit'],
				]
			);
			list ($visitTime) = $db->fetch_row($result);
			$db->free_result($result);

			$_SESSION['id_msg_last_visit'] = $user_settings['id_msg_last_visit'];

			// If it was *at least* five hours ago...
			if ($visitTime < time() - 5 * 3600)
			{
				updateMemberData($id_member, ['id_msg_last_visit' => (int) $modSettings['maxMsgID'], 'last_login' => time(), 'member_ip' => $_SERVER['REMOTE_ADDR'], 'member_ip2' => $_SERVER['BAN_CHECK_IP']]);
				$user_settings['last_login'] = time();

				if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 2)
					cache_put_data('user_settings-' . $id_member, $user_settings, 60);

				if (!empty($modSettings['cache_enable']))
					cache_put_data('user_last_visit-' . $id_member, $_SESSION['id_msg_last_visit'], 5 * 3600);
			}
		}
		elseif (empty($_SESSION['id_msg_last_visit']))
			$_SESSION['id_msg_last_visit'] = $user_settings['id_msg_last_visit'];

		$username = $user_settings['member_name'];


		// This is a logged in user, so definitely not a search robot.
		$user_info['possibly_robot'] = false;
	}
	// If the user is a guest, initialize all the critical user settings.
	else
	{
		// This is what a guest's variables should be.
		$username = '';
		$user_settings = [];

		// Do we perhaps think this is a search robot? Check every five minutes just in case...
		if (!isset($_SESSION['robot_check']) || $_SESSION['robot_check'] < time() - 300)
		{
			$robot = new \StoryBB\Model\Robot;
			$_SESSION['robot_check'] = time();
			$_SESSION['robot_name'] = $robot->identify_robot_from_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
		}
		$user_info['possibly_robot'] = !empty($_SESSION['robot_name']);

		$context['show_cookie_notice'] = !empty($modSettings['show_cookie_notice']) && empty($_COOKIE['cookies']);
	}

	// Set up the $user_info array.
	$user_info += [
		'id' => $id_member,
		'id_character' => isset($user_settings['id_character']) ? (int) $user_settings['id_character'] : 0,
		'character_name' => isset($user_settings['character_name']) ? $user_settings['character_name'] : (isset($user_settings['real_name']) ? $user_settings['real_name'] : ''),
		'char_avatar' => isset($user_settings['char_avatar']) ? $user_settings['char_avatar'] : '',
		'char_signature' => isset($user_settings['char_signature']) ? $user_settings['char_signature'] : '',
		'char_is_main' => !empty($user_settings['is_main']),
		'immersive_mode' => $user->is_immersive_mode(),
		'username' => $username,
		'name' => isset($user_settings['real_name']) ? $user_settings['real_name'] : '',
		'email' => isset($user_settings['email_address']) ? $user_settings['email_address'] : '',
		'passwd' => isset($user_settings['passwd']) ? $user_settings['passwd'] : '',
		'language' => empty($user_settings['lngfile']) || empty($modSettings['userLanguage']) ? $language : $user_settings['lngfile'],
		'is_guest' => !$user->is_authenticated(),
		'is_admin' => $user->is_site_admin(),
		'theme' => empty($user_settings['id_theme']) ? 0 : $user_settings['id_theme'],
		'last_login' => empty($user_settings['last_login']) ? 0 : $user_settings['last_login'],
		'ip' => $_SERVER['REMOTE_ADDR'],
		'ip2' => $_SERVER['BAN_CHECK_IP'],
		'posts' => empty($user_settings['posts']) ? 0 : $user_settings['posts'],
		'time_offset' => $user->get_time_offset(),
		'time_format' => empty($user_settings['time_format']) ? $modSettings['time_format'] : $user_settings['time_format'],
		'avatar' => [
			'url' => isset($user_settings['avatar']) ? $user_settings['avatar'] : '',
			'filename' => empty($user_settings['filename']) ? '' : $user_settings['filename'],
			'custom_dir' => !empty($user_settings['attachment_type']) && $user_settings['attachment_type'] == 1,
			'id_attach' => isset($user_settings['id_attach']) ? $user_settings['id_attach'] : 0
		],
		'messages' => empty($user_settings['instant_messages']) ? 0 : $user_settings['instant_messages'],
		'unread_messages' => empty($user_settings['unread_messages']) ? 0 : $user_settings['unread_messages'],
		'alerts' => empty($user_settings['alerts']) ? 0 : $user_settings['alerts'],
		'total_time_logged_in' => empty($user_settings['total_time_logged_in']) ? 0 : $user_settings['total_time_logged_in'],
		'buddies' => !empty($modSettings['enable_buddylist']) && !empty($user_settings['buddy_list']) ? explode(',', $user_settings['buddy_list']) : [],
		'ignoreboards' => !empty($user_settings['ignore_boards']) && !empty($modSettings['allow_ignore_boards']) ? explode(',', $user_settings['ignore_boards']) : [],
		'ignoreusers' => !empty($user_settings['pm_ignore_list']) ? explode(',', $user_settings['pm_ignore_list']) : [],
		'warning' => isset($user_settings['warning']) ? $user_settings['warning'] : 0,
		'permissions' => [],
		'policy_acceptance' => isset($user_settings['policy_acceptance']) ? $user_settings['policy_acceptance'] : 0,
	];

	if (isset($user_settings['ic_avatar']))
	{
		$user_info['ic_avatar'] = set_avatar_data($user_settings['ic_avatar']);
		$user_info['ooc_avatar'] = set_avatar_data($user_settings['ooc_avatar']);
	}
	else
	{
		$user_info['ic_avatar'] = set_avatar_data(['generic' => true]);
		$user_info['ooc_avatar'] = set_avatar_data(['generic' => true]);
	}

	$group_filter = function($main, $extras) {
		$return = [];
		if (!empty($main))
			$return[] = (int) $main;

		if (!empty($extras))
		{
			$groups = explode(',', $extras);
			foreach ($groups as $group)
			{
				$group = (int) $group;
				if ($group)
					$return[] = $group;
			}
		}

		return $return;
	};

	if ($user->is_immersive_mode())
	{
		// In immersive mode, we apply the groups for the current character.
		if (isset($user_settings['main_char_group']))
		{
			$user_info['groups'] = array_merge($user_info['groups'], $group_filter($user_settings['main_char_group'], $user_settings['char_groups']));
		}
	}
	$user_info['groups'] = array_unique($user_info['groups']);

	// Make sure that the last item in the ignore boards array is valid. If the list was too long it could have an ending comma that could cause problems.
	if (!empty($user_info['ignoreboards']) && empty($user_info['ignoreboards'][$tmp = count($user_info['ignoreboards']) - 1]))
		unset($user_info['ignoreboards'][$tmp]);

	// Allow the user to change their language.
	if (!empty($modSettings['userLanguage']))
	{
		$languages = getLanguages();

		// Is it valid?
		if (!empty($_GET['language']) && isset($languages[strtr($_GET['language'], './\\:', '____')]))
		{
			$user_info['language'] = strtr($_GET['language'], './\\:', '____');

			// Make it permanent for members.
			if (!empty($user_info['id']))
				updateMemberData($user_info['id'], ['lngfile' => $user_info['language']]);
			else
				$_SESSION['language'] = $user_info['language'];
		}
		elseif (!empty($_SESSION['language']) && isset($languages[strtr($_SESSION['language'], './\\:', '____')]))
			$user_info['language'] = strtr($_SESSION['language'], './\\:', '____');
	}

	$temp = build_query_board($user_info['id']);
	$user_info['query_see_board'] = $temp['query_see_board'];
	$user_info['query_wanna_see_board'] = $temp['query_wanna_see_board'];

	$db->register_replacement('query_see_board', $user_info['query_see_board']);
	$db->register_replacement('query_wanna_see_board', $user_info['query_wanna_see_board']);

	call_integration_hook('integrate_user_info');
}

/**
 * Check for moderators and see if they have access to the board.
 * What it does:
 * - sets up the $board_info array for current board information.
 * - if cache is enabled, the $board_info array is stored in cache.
 * - redirects to appropriate post if only message id is requested.
 * - is only used when inside a topic or board.
 * - determines the local moderators for the board.
 * - adds group id 3 if the user is a local moderator for the board they are in.
 * - prevents access if user is not in proper group nor a local moderator of the board.
 */
function loadBoard()
{
	global $txt, $scripturl, $context, $modSettings;
	global $board_info, $board, $topic, $user_info, $smcFunc;

	$url = App::container()->get('urlgenerator');

	// Assume they are not a moderator.
	$user_info['is_mod'] = false;
	$context['user']['is_mod'] = &$user_info['is_mod'];

	// Start the linktree off empty..
	$context['linktree'] = [];

	// Have they by chance specified a message id but nothing else?
	if (empty($_REQUEST['action']) && empty($topic) && empty($board) && !empty($_REQUEST['msg']))
	{
		// Make sure the message id is really an int.
		$_REQUEST['msg'] = (int) $_REQUEST['msg'];

		// Looking through the message table can be slow, so try using the cache first.
		if (($topic = cache_get_data('msg_topic-' . $_REQUEST['msg'], 120)) === null)
		{
			$request = $smcFunc['db']->query('', '
				SELECT id_topic
				FROM {db_prefix}messages
				WHERE id_msg = {int:id_msg}
				LIMIT 1',
				[
					'id_msg' => $_REQUEST['msg'],
				]
			);

			// So did it find anything?
			if ($smcFunc['db']->num_rows($request))
			{
				list ($topic) = $smcFunc['db']->fetch_row($request);
				$smcFunc['db']->free_result($request);
				// Save save save.
				cache_put_data('msg_topic-' . $_REQUEST['msg'], $topic, 120);
			}
		}

		// Remember redirection is the key to avoiding fallout from your bosses.
		if (!empty($topic))
			redirectexit('topic=' . $topic . '.msg' . $_REQUEST['msg'] . '#msg' . $_REQUEST['msg']);
		else
		{
			loadPermissions();
			loadTheme();
			fatal_lang_error('topic_gone', false);
		}
	}

	// Load this board only if it is specified.
	if (empty($board) && empty($topic))
	{
		$board_info = ['moderators' => [], 'moderator_groups' => []];
		return;
	}

	if (!empty($modSettings['cache_enable']) && (empty($topic) || $modSettings['cache_enable'] >= 3))
	{
		// @todo SLOW?
		if (!empty($topic))
			$temp = cache_get_data('topic_board-' . $topic, 120);
		else
			$temp = cache_get_data('board-' . $board, 120);

		if (!empty($temp))
		{
			$board_info = $temp;
			$board = $board_info['id'];
		}
	}

	if (empty($temp))
	{
		$request = $smcFunc['db']->query('load_board_info', '
			SELECT
				c.id_cat, b.name AS bname, b.slug AS bslug, b.description, b.num_topics, b.member_groups, b.deny_member_groups, COALESCE(p.slug, {empty}) AS parent_slug,
				b.id_parent, c.name AS cname, COALESCE(mg.id_group, 0) AS id_moderator_group, mg.group_name,
				COALESCE(mem.id_member, 0) AS id_moderator,
				mem.real_name' . (!empty($topic) ? ', b.id_board' : '') . ', b.child_level, b.in_character,
				b.id_theme, b.override_theme, b.count_posts, b.id_profile, b.redirect, b.board_sort,
				b.unapproved_topics, b.unapproved_posts' . (!empty($topic) ? ', t.approved, t.id_member_started' : '') . '
			FROM {db_prefix}boards AS b' . (!empty($topic) ? '
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:current_topic})' : '') . '
				LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
				LEFT JOIN {db_prefix}boards AS p ON (b.id_parent = p.id_board)
				LEFT JOIN {db_prefix}moderator_groups AS modgs ON (modgs.id_board = {raw:board_link})
				LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = modgs.id_group)
				LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = {raw:board_link})
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
			WHERE b.id_board = {raw:board_link}',
			[
				'current_topic' => $topic,
				'board_link' => empty($topic) ? $smcFunc['db']->quote('{int:current_board}', ['current_board' => $board]) : 't.id_board',
			]
		);
		// If there aren't any, skip.
		if ($smcFunc['db']->num_rows($request) > 0)
		{
			$row = $smcFunc['db']->fetch_assoc($request);

			// Set the current board.
			if (!empty($row['id_board']))
				$board = $row['id_board'];

			if (empty($row['board_sort']))
			{
				$row['board_sort'] = 'last_post;desc;0';
			}

			// Basic operating information. (globals... :/)
			$board_info = [
				'id' => $board,
				'moderators' => [],
				'moderator_groups' => [],
				'cat' => [
					'id' => $row['id_cat'],
					'name' => $row['cname']
				],
				'name' => $row['bname'],
				'slug' => $row['bslug'],
				'url' => $url->generate('board', ['board_slug' => $row['bslug']]),
				'description' => Parser::parse_bbc($row['description'], '', 'brd' . $board),
				'num_topics' => $row['num_topics'],
				'unapproved_topics' => $row['unapproved_topics'],
				'unapproved_posts' => $row['unapproved_posts'],
				'unapproved_user_topics' => 0,
				'parent_boards' => getBoardParents($row['id_parent']),
				'parent' => $row['id_parent'],
				'parent_slug' => $row['parent_slug'],
				'parent_url' => $row['parent_slug'] ? $url->generate('board', ['board_slug' => $row['parent_slug']]) : '',
				'child_level' => $row['child_level'],
				'theme' => $row['id_theme'],
				'override_theme' => !empty($row['override_theme']),
				'profile' => $row['id_profile'],
				'redirect' => $row['redirect'],
				'recycle' => !empty($modSettings['recycle_enable']) && !empty($modSettings['recycle_board']) && $modSettings['recycle_board'] == $board,
				'in_character' => !empty($row['in_character']),
				'posts_count' => empty($row['count_posts']),
				'cur_topic_approved' => empty($topic) || $row['approved'],
				'cur_topic_starter' => empty($topic) ? 0 : $row['id_member_started'],
				'board_sort' => $row['board_sort'],
			];

			$context['current_board_link'] = $board_info['url'];

			// Load the membergroups allowed, and check permissions.
			$board_info['groups'] = $row['member_groups'] == '' ? [] : explode(',', $row['member_groups']);
			$board_info['deny_groups'] = $row['deny_member_groups'] == '' ? [] : explode(',', $row['deny_member_groups']);

			do
			{
				if (!empty($row['id_moderator']))
					$board_info['moderators'][$row['id_moderator']] = [
						'id' => $row['id_moderator'],
						'name' => $row['real_name'],
						'href' => $scripturl . '?action=profile;u=' . $row['id_moderator'],
						'link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_moderator'] . '">' . $row['real_name'] . '</a>'
					];

				if (!empty($row['id_moderator_group']))
					$board_info['moderator_groups'][$row['id_moderator_group']] = [
						'id' => $row['id_moderator_group'],
						'name' => $row['group_name'],
						'href' => $scripturl . '?action=groups;sa=members;group=' . $row['id_moderator_group'],
						'link' => '<a href="' . $scripturl . '?action=groups;sa=members;group=' . $row['id_moderator_group'] . '">' . $row['group_name'] . '</a>'
					];
			}
			while ($row = $smcFunc['db']->fetch_assoc($request));

			// If the board only contains unapproved posts and the user isn't an approver then they can't see any topics.
			// If that is the case do an additional check to see if they have any topics waiting to be approved.
			if ($board_info['num_topics'] == 0 && !allowedTo('approve_posts'))
			{
				// Free the previous result
				$smcFunc['db']->free_result($request);

				// @todo why is this using id_topic?
				// @todo Can this get cached?
				$request = $smcFunc['db']->query('', '
					SELECT COUNT(id_topic)
					FROM {db_prefix}topics
					WHERE id_member_started={int:id_member}
						AND approved = {int:unapproved}
						AND id_board = {int:board}',
					[
						'id_member' => $user_info['id'],
						'unapproved' => 0,
						'board' => $board,
					]
				);

				list ($board_info['unapproved_user_topics']) = $smcFunc['db']->fetch_row($request);
			}

			if (!empty($modSettings['cache_enable']) && (empty($topic) || $modSettings['cache_enable'] >= 3))
			{
				// @todo SLOW?
				if (!empty($topic))
					cache_put_data('topic_board-' . $topic, $board_info, 120);
				cache_put_data('board-' . $board, $board_info, 120);
			}
		}
		else
		{
			// Otherwise the topic is invalid, there are no moderators, etc.
			$board_info = [
				'moderators' => [],
				'moderator_groups' => [],
				'error' => 'exist'
			];
			$topic = null;
			$board = 0;
		}
		$smcFunc['db']->free_result($request);
	}

	if (!empty($topic))
		$_GET['board'] = (int) $board;

	if (!empty($board))
	{
		// Get this into an array of keys for array_intersect
		$moderator_groups = array_keys($board_info['moderator_groups']);

		// Now check if the user is a moderator.
		$user_info['is_mod'] = isset($board_info['moderators'][$user_info['id']]) || count(array_intersect($user_info['groups'], $moderator_groups)) != 0;

		if (count(array_intersect($user_info['groups'], $board_info['groups'])) == 0 && !$user_info['is_admin'])
			$board_info['error'] = 'access';
		if (count(array_intersect($user_info['groups'], $board_info['deny_groups'])) != 0 && !$user_info['is_admin'])
			$board_info['error'] = 'access';

		// Build up the linktree.
		$context['linktree'] = array_merge(
			$context['linktree'],
			[[
				'url' => $scripturl . '#c' . $board_info['cat']['id'],
				'name' => $board_info['cat']['name']
			]],
			array_reverse($board_info['parent_boards']),
			[[
				'url' => $url->generate('board', ['board_slug' => $board_info['slug']]),
				'name' => $board_info['name']
			]]
		);
	}

	// Set the template contextual information.
	$context['user']['is_mod'] = &$user_info['is_mod'];
	$context['current_topic'] = $topic;
	$context['current_board'] = $board;

	// No posting in redirection boards!
	if (!empty($_REQUEST['action']) && $_REQUEST['action'] == 'post' && !empty($board_info['redirect']))
		$board_info['error'] == 'post_in_redirect';

	// Hacker... you can't see this topic, I'll tell you that. (but moderators can!)
	if (!empty($board_info['error']))
	{
		// The permissions and theme need loading, just to make sure everything goes smoothly.
		loadPermissions();
		loadTheme();

		$_GET['board'] = '';
		$_GET['topic'] = '';

		// The linktree should not give the game away mate!
		$context['linktree'] = [
			[
				'url' => $scripturl,
				'name' => $context['forum_name_html_safe']
			]
		];

		// If it's a prefetching agent or we're requesting an attachment.
		if ((isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch') || (!empty($_REQUEST['action']) && $_REQUEST['action'] === 'dlattach'))
		{
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die;
		}
		elseif ($board_info['error'] == 'post_in_redirect')
		{
			// Slightly different error message here...
			fatal_lang_error('cannot_post_redirect', false);
		}
		elseif ($user_info['is_guest'])
		{
			loadLanguage('Errors');
			is_not_guest($txt['topic_gone']);
		}
		else
			fatal_lang_error('topic_gone', false);
	}

	if ($user_info['is_mod'])
		$user_info['groups'][] = 3;
}

/**
 * Load this user's permissions.
 */
function loadPermissions()
{
	global $user_info, $board, $board_info, $modSettings, $smcFunc, $sourcedir;

	if ($user_info['is_admin'])
	{
		banPermissions();
		return;
	}

	if (!empty($modSettings['cache_enable']))
	{
		$cache_groups = $user_info['groups'];
		asort($cache_groups);
		$cache_groups = implode(',', $cache_groups);
		// If it's a robot then cache it different.
		if ($user_info['possibly_robot'])
			$cache_groups .= '-robot';

		if ($modSettings['cache_enable'] >= 2 && !empty($board) && ($temp = cache_get_data('permissions:' . $cache_groups . ':' . $board, 240)) != null && time() - 240 > $modSettings['settings_updated'])
		{
			list ($user_info['permissions']) = $temp;
			banPermissions();

			return;
		}
		elseif (($temp = cache_get_data('permissions:' . $cache_groups, 240)) != null && time() - 240 > $modSettings['settings_updated'])
			list ($user_info['permissions'], $removals) = $temp;
	}

	if (empty($user_info['permissions']))
	{
		// Get the general permissions.
		$request = $smcFunc['db']->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:member_groups})',
			[
				'member_groups' => $user_info['groups'],
			]
		);
		$removals = [];
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (empty($row['add_deny']))
				$removals[] = $row['permission'];
			else
				$user_info['permissions'][] = $row['permission'];
		}
		$smcFunc['db']->free_result($request);

		if (isset($cache_groups))
			cache_put_data('permissions:' . $cache_groups, [$user_info['permissions'], $removals], 240);
	}

	// Get the board permissions.
	if (!empty($board))
	{
		// Make sure the board (if any) has been loaded by loadBoard().
		if (!isset($board_info['profile']))
			fatal_lang_error('no_board');

		$request = $smcFunc['db']->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:member_groups})
				AND id_profile = {int:id_profile}',
			[
				'member_groups' => $user_info['groups'],
				'id_profile' => $board_info['profile'],
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (empty($row['add_deny']))
				$removals[] = $row['permission'];
			else
				$user_info['permissions'][] = $row['permission'];
		}
		$smcFunc['db']->free_result($request);
	}

	// Remove all the permissions they shouldn't have ;).
	$user_info['permissions'] = array_diff($user_info['permissions'], $removals);

	// And if this is an OOC board, we might have to remove some permissions.
	session_flash_empty('warning-secondary');
	$disable_posting = (!empty($board_info['in_character']) && $user_info['char_is_main']) || (empty($board_info['in_character']) && !$user_info['char_is_main']);
	if ($disable_posting)
	{
		if (!empty($board_info['in_character']) && $user_info['char_is_main'])
		{
			session_flash('warning-secondary', (string) new \StoryBB\Phrase('General:cannot_post_ooc'));
		}
		else
		{
			session_flash('warning-secondary', (string) new \StoryBB\Phrase('General:cannot_post_ic'));
		}
		$user_info['permissions'] = array_diff($user_info['permissions'], [
			'moderate_board',
			'approve_posts',
			'post_new',
			'post_unapproved_topics',
			'post_unapproved_replies_own',
			'post_unapproved_replies_any',
			'post_reply_own',
			'post_reply_any',
			'post_attachment',
			'modify_own',
			'modify_any',
			'modify_replies',
			'delete_own',
			'delete_any',
			'delete_replies',
			'merge_any',
			'split_any',
			'lock_own',
			'lock_any',
			'remove_own',
			'remove_any',
			'poll_vote',
			'poll_post',
			'poll_add_own',
			'poll_add_any',
			'poll_edit_own',
			'poll_edit_any',
			'poll_lock_own',
			'poll_lock_any',
			'poll_remove_own',
			'poll_remove_any',
			'post_unapproved_attachments',
			'post_attachment',
		]);
	}

	if (isset($cache_groups) && !empty($board) && $modSettings['cache_enable'] >= 2)
		cache_put_data('permissions:' . $cache_groups . ':' . $board, [$user_info['permissions'], null], 240);

	// Banned?  Watch, don't touch..
	banPermissions();

	// Load the mod cache so we can know what additional boards they should see, but no sense in doing it for guests
	if (!$user_info['is_guest'])
	{
		if (!isset($_SESSION['mc']) || $_SESSION['mc']['time'] <= $modSettings['settings_updated'])
		{
			require_once($sourcedir . '/Subs-Auth.php');
			rebuildModCache();
		}
		else
			$user_info['mod_cache'] = $_SESSION['mc'];

		// This is a useful phantom permission added to the current user, and only the current user while they are logged in.
		// For example this drastically simplifies certain changes to the profile area.
		$user_info['permissions'][] = 'is_not_guest';
	}
}

/**
 * Loads an array of users' data by ID or member_name.
 *
 * @param array|string $users An array of users by id or name or a single username/id
 * @param bool $is_name Whether $users contains names
 * @param string $set What kind of data to load (normal, profile, minimal)
 * @return array The ids of the members loaded
 */
function loadMemberData($users, $is_name = false, $set = 'normal')
{
	global $user_profile, $modSettings, $board_info, $smcFunc, $context;
	global $image_proxy_enabled, $image_proxy_secret, $boardurl, $settings;

	// Can't just look for no users :P.
	if (empty($users))
		return [];

	// Pass the set value
	$context['loadMemberContext_set'] = $set;

	// Make sure it's an array.
	$users = !is_array($users) ? [$users] : array_unique($users);
	$loaded_ids = [];

	if (!$is_name && !empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 3)
	{
		$users = array_values($users);
		for ($i = 0, $n = count($users); $i < $n; $i++)
		{
			$data = cache_get_data('member_data-' . $set . '-' . $users[$i], 240);
			if ($data == null)
				continue;

			$loaded_ids[] = $data['id_member'];
			$user_profile[$data['id_member']] = $data;
			unset($users[$i]);
		}
	}

	// Used by default
	$select_columns = '
			COALESCE(lo.log_time, 0) AS is_online, COALESCE(a.id_attach, 0) AS id_attach, a.filename, a.attachment_type,
			mem.signature, mem.avatar, mem.id_member, mem.member_name,
			mem.real_name, mem.email_address, mem.date_registered,
			mem.birthdate, mem.birthday_visibility, mem.member_ip, mem.member_ip2, mem.posts, mem.last_login, mem.lngfile, mem.id_group, mem.time_offset, mem.show_online,
			mg.online_color AS member_group_color, COALESCE(mg.group_name, {string:blank_string}) AS member_group,
			mem.is_activated, mem.warning,
			CASE WHEN mem.id_group = 0 OR mg.icons = {empty} THEN {empty} ELSE mg.icons END AS icons';
	$select_tables = '
			LEFT JOIN {db_prefix}log_online AS lo ON (lo.id_member = mem.id_member)
			LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = mem.id_group)
			LEFT JOIN {db_prefix}characters AS chars ON (lo.id_character = chars.id_character)
			LEFT JOIN {db_prefix}attachments AS a ON (a.id_character = chars.id_character AND a.attachment_type = 1)
			LEFT JOIN {db_prefix}membergroups AS cg ON (chars.main_char_group = cg.id_group)';

	// We add or replace according the the set
	switch ($set)
	{
		case 'normal':
			$select_columns .= ', mem.buddy_list,  mem.additional_groups, lo.id_character AS online_character, chars.is_main,
			chars.main_char_group, chars.char_groups, cg.online_color AS char_group_color,
			COALESCE(cg.group_name, {string:blank_string}) AS character_group, chars.char_sheet, mem.immersive_mode, mem.currency';
			break;
		case 'profile':
			$select_columns .= ', mem.additional_groups, mem.id_theme, mem.pm_ignore_list, mem.pm_receive_from,
			mem.time_format, mem.timezone, mem.secret_question,
			mem.total_time_logged_in, lo.url, mem.ignore_boards, mem.password_salt, mem.pm_prefs, mem.buddy_list, mem.alerts,
			lo.id_character AS online_character, chars.is_main, chars.main_char_group, chars.char_groups,
			cg.online_color AS char_group_color, COALESCE(cg.group_name, {string:blank_string}) AS character_group,
			chars.char_sheet, mem.immersive_mode, mem.currency';
			break;
		case 'minimal':
			$select_columns = '
			mem.id_member, mem.member_name, mem.real_name, mem.email_address, mem.date_registered,
			mem.posts, mem.last_login, mem.member_ip, mem.member_ip2, mem.lngfile, mem.id_group';
			$select_tables = '';
			break;
		default:
			trigger_error('loadMemberData(): Invalid member data set \'' . $set . '\'', E_USER_WARNING);
	}

	// Allow mods to easily add to the selected member data
	call_integration_hook('integrate_load_member_data', [&$select_columns, &$select_tables, &$set]);

	if (!empty($users))
	{
		// Load the member's data.
		$request = $smcFunc['db']->query('', '
			SELECT' . $select_columns . '
			FROM {db_prefix}members AS mem' . $select_tables . '
			WHERE mem.' . ($is_name ? 'member_name' : 'id_member') . ' IN ({' . ($is_name ? 'array_string' : 'array_int') . ':users})',
			[
				'blank_string' => '',
				'users' => $users,
			]
		);
		$new_loaded_ids = [];
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			// If the image proxy is enabled, we still want the original URL when they're editing the profile...
			$row['avatar_original'] = !empty($row['avatar']) ? $row['avatar'] : '';

			// Take care of proxying avatar if required, do this here for maximum reach
			if ($image_proxy_enabled && !empty($row['avatar']) && stripos($row['avatar'], 'http://') !== false)
				$row['avatar'] = $boardurl . '/proxy.php?request=' . urlencode($row['avatar']) . '&hash=' . md5($row['avatar'] . $image_proxy_secret);

			// Keep track of the member's normal member group
			$row['primary_group_id'] = $row['id_group'];
			$row['primary_group'] = $row['member_group'];

			if (isset($row['member_ip']))
				$row['member_ip'] = IP::format($row['member_ip']);
			if (isset($row['member_ip2']))
				$row['member_ip2'] = IP::format($row['member_ip2']);
			$new_loaded_ids[] = $row['id_member'];
			$loaded_ids[] = $row['id_member'];
			$row['options'] = [];
			$user_profile[$row['id_member']] = $row;
		}
		$smcFunc['db']->free_result($request);
	}

	if (!empty($new_loaded_ids) && $set !== 'minimal')
	{
		foreach ($new_loaded_ids as $new_id)
		{
			// We might need to juggle the colours around - but only for
			// non main characters. Main will inherit the account so no
			// changes required. This is to fix things like who's online
			// colour; we'll have to fix icon display elsewhere separately.
			if (empty($user_profile[$new_id]['is_main']))
			{
				$user_profile[$new_id]['member_group_color'] = $user_profile[$new_id]['char_group_color'];
				$user_profile[$new_id]['member_group'] = $user_profile[$new_id]['character_group'];
			}
		}
		$characters_loaded = [];
		$request = $smcFunc['db']->query('', '
			SELECT chars.id_member, chars.id_character, chars.character_name, 
				a.filename, COALESCE(a.id_attach, 0) AS id_attach, chars.avatar, chars.signature, chars.id_theme,
				chars.posts, chars.date_created, chars.last_active, chars.is_main, chars.rotate_avatar,
				chars.main_char_group, chars.char_groups, chars.char_sheet, chars.retired,
				additional_a.filename AS add_filename, COALESCE(additional_a.id_attach, 0) AS add_id_attach, additional_a.attachment_type AS add_attach_type,
				additional_a.label AS add_label
			FROM {db_prefix}characters AS chars
			LEFT JOIN {db_prefix}attachments AS a ON (chars.id_character = a.id_character AND a.attachment_type = 1)
			LEFT JOIN {db_prefix}attachments AS additional_a ON (chars.id_character = additional_a.id_character AND (additional_a.attachment_type = 5 OR additional_a.attachment_type = 6))
			WHERE id_member IN ({array_int:loaded_ids})
			ORDER BY NULL',
			[
				'loaded_ids' => $new_loaded_ids,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			// Take care of proxying avatar if required, do this here for maximum reach
			$row['avatar_original'] = !empty($row['avatar']) ? $row['avatar'] : '';
			if ($image_proxy_enabled && !empty($row['avatar']) && stripos($row['avatar'], 'http://') !== false)
				$row['avatar'] = $boardurl . '/proxy.php?request=' . urlencode($row['avatar']) . '&hash=' . md5($row['avatar'] . $image_proxy_secret);

			if (!isset($user_profile[$row['id_member']]['characters'][$row['id_character']]))
			{
				$user_profile[$row['id_member']]['characters'][$row['id_character']] = [
					'id_character' => $row['id_character'],
					'character_name' => $row['character_name'],
					'character_url' => '?action=profile;u=' . $row['id_member'] . ';area=characters;char=' . $row['id_character'],
					'avatar' => $row['avatar'],
					'avatar_filename' => $row['filename'],
					'id_attach' => $row['id_attach'],
					'avatar_original' => $row['avatar_original'],
					'additional_avatars' => [],
					'signature' => $row['signature'],
					'sig_parsed' => !empty($row['signature']) ? Parser::parse_bbc($row['signature'], true, 'sig_char_' . $row['id_character']) : '',
					'id_theme' => $row['id_theme'],
					'posts' => $row['posts'],
					'date_created' => $row['date_created'],
					'last_active' => $row['last_active'],
					'is_main' => $row['is_main'],
					'rotate_avatar' => $row['rotate_avatar'],
					'main_char_group' => $row['main_char_group'],
					'char_groups' => $row['char_groups'],
					'char_sheet' => $row['char_sheet'],
					'retired' => $row['retired'],
				];
			}
			if ($row['is_main'])
			{
				$user_profile[$row['id_member']]['main_char'] = $row['id_character'];

				$user_profile[$row['id_member']]['avatar_original'] = $row['avatar_original'];
				$user_profile[$row['id_member']]['avatar'] = $row['avatar'];
				$user_profile[$row['id_member']]['filename'] = $row['filename'];
				$user_profile[$row['id_member']]['attachment_type'] = !empty($row['filename']) ? 1 : 0;
				$user_profile[$row['id_member']]['id_attach'] = $row['id_attach'];
			}
			$image = '';

			// We have some additional avatars?
			if (!empty($row['add_attach_type']))
			{
				if ($row['add_attach_type'] == 5)
				{
					$user_profile[$row['id_member']]['characters'][$row['id_character']]['additional_avatars'][$row['add_id_attach']] = [
						'id_attach' => $row['add_id_attach'],
						'avatar_filename' => $row['add_filename'],
						'label' => $row['add_label'],
						'avatar' => set_avatar_data([
							'filename' => $row['add_filename'],
						])['href'],
						'type' => $row['add_attach_type'],
					];
				}
				elseif ($row['add_attach_type'] == 6)
				{
					$row['additional_row_original'] = !empty($row['add_filename']) ? $row['add_filename'] : '';
					if ($image_proxy_enabled && !empty($row['add_filename']) && stripos($row['avatar'], 'http://') !== false)
					{
						$row['add_filename'] = $boardurl . '/proxy.php?request=' . urlencode($row['add_filename']) . '&hash=' . md5($row['add_filename'] . $image_proxy_secret);
					}
					$user_profile[$row['id_member']]['characters'][$row['id_character']]['additional_avatars'][$row['add_id_attach']] = [
						'additional_row_original' => $row['additional_row_original'],
						'add_filename' => $row['add_filename'],
						'label' => $row['add_label'],
						'avatar' => set_avatar_data([
							'avatar' => $row['add_filename'],
						])['href'],
						'type' => $row['add_attach_type'],
					];
				}
			}

			$characters_loaded[$row['id_character']] = $row['id_member'];

			$user_profile[$row['id_member']]['characters'][$row['id_character']]['avatar'] = set_avatar_data([
				'filename' => $row['filename'],
				'avatar' => $row['avatar'],
			])['href'];
		}
		$smcFunc['db']->free_result($request);

		foreach ($user_profile as $id_member => $member)
		{
			if (!isset($member['characters'])) {
				$user_profile[$id_member]['characters'] = [];
			} else {
				uasort($user_profile[$id_member]['characters'], function ($a, $b) {
					return $a['is_main'] ? -1 : ($a['character_name'] > $b['character_name'] ? 1 : ($a['character_name'] < $a['character_name'] ? -1 : 0));
				});
			}
		}

		$request = $smcFunc['db']->query('', '
			SELECT id_field, id_character, value
			FROM {db_prefix}custom_field_values
			WHERE id_character IN ({array_int:character_ids})',
			[
				'character_ids' => array_keys($characters_loaded),
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$user_profile[$characters_loaded[$row['id_character']]]['characters'][$row['id_character']]['cfraw'][$row['id_field']] = $row['value'];
		}
		$smcFunc['db']->free_result($request);

		$request = $smcFunc['db']->query('', '
			SELECT id_member, variable, value
			FROM {db_prefix}themes
			WHERE id_member IN ({array_int:loaded_ids})',
			[
				'loaded_ids' => $new_loaded_ids,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$user_profile[$row['id_member']]['options'][$row['variable']] = $row['value'];
		$smcFunc['db']->free_result($request);
	}

	$additional_mods = [];

	// Are any of these users in groups assigned to moderate this board?
	if (!empty($loaded_ids) && !empty($board_info['moderator_groups']) && $set === 'normal')
	{
		foreach ($loaded_ids as $a_member)
		{
			if (!empty($user_profile[$a_member]['additional_groups']))
				$groups = array_merge([$user_profile[$a_member]['id_group']], explode(',', $user_profile[$a_member]['additional_groups']));
			else
				$groups = [$user_profile[$a_member]['id_group']];

			$temp = array_intersect($groups, array_keys($board_info['moderator_groups']));

			if (!empty($temp))
			{
				$additional_mods[] = $a_member;
			}
		}
	}

	if (!empty($new_loaded_ids) && !empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 3)
	{
		for ($i = 0, $n = count($new_loaded_ids); $i < $n; $i++)
			cache_put_data('member_data-' . $set . '-' . $new_loaded_ids[$i], $user_profile[$new_loaded_ids[$i]], 240);
	}

	// Are we loading any moderators?  If so, fix their group data...
	if (!empty($loaded_ids) && (!empty($board_info['moderators']) || !empty($board_info['moderator_groups'])) && $set === 'normal' && count($temp_mods = array_merge(array_intersect($loaded_ids, array_keys($board_info['moderators'])), $additional_mods)) !== 0)
	{
		if (($row = cache_get_data('moderator_group_info', 480)) == null)
		{
			$request = $smcFunc['db']->query('', '
				SELECT group_name AS member_group, online_color AS member_group_color, icons
				FROM {db_prefix}membergroups
				WHERE id_group = {int:moderator_group}
				LIMIT 1',
				[
					'moderator_group' => 3,
				]
			);
			$row = $smcFunc['db']->fetch_assoc($request);
			$smcFunc['db']->free_result($request);

			cache_put_data('moderator_group_info', $row, 480);
		}

		foreach ($temp_mods as $id)
		{
			// By popular demand, don't show admins or global moderators as moderators.
			if ($user_profile[$id]['id_group'] != 1 && $user_profile[$id]['id_group'] != 2)
				$user_profile[$id]['member_group'] = $row['member_group'];

			// If the Moderator group has no color or icons, but their group does... don't overwrite.
			if (!empty($row['icons']))
				$user_profile[$id]['icons'] = $row['icons'];
			if (!empty($row['member_group_color']))
				$user_profile[$id]['member_group_color'] = $row['member_group_color'];
		}
	}

	return $loaded_ids;
}

/**
 * Loads the user's basic values... meant for template/theme usage.
 *
 * @param int $user The ID of a user previously loaded by {@link loadMemberData()}
 * @param bool $display_custom_fields Whether or not to display custom profile fields
 * @return boolean Whether or not the data was loaded successfully
 */
function loadMemberContext($user, $display_custom_fields = false)
{
	global $memberContext, $user_profile, $txt, $scripturl, $user_info;
	global $context, $modSettings, $settings;
	static $dataLoaded = [];
	static $loadedLanguages = [];

	// If this person's data is already loaded, skip it.
	if (isset($dataLoaded[$user]))
		return true;

	// We can't load guests or members not loaded by loadMemberData()!
	if ($user == 0)
		return false;
	if (!isset($user_profile[$user]))
	{
		trigger_error('loadMemberContext(): member id ' . $user . ' not previously loaded by loadMemberData()', E_USER_WARNING);
		return false;
	}

	// Well, it's loaded now anyhow.
	$dataLoaded[$user] = true;
	$profile = &$user_profile[$user];

	$profile['is_online'] = (!empty($profile['show_online']) || allowedTo('moderate_forum')) && $profile['is_online'] > 0;
	$profile['icons'] = empty($profile['icons']) ? ['', ''] : explode('#', $profile['icons']);
	// Setup the buddy status here (One whole in_array call saved :P)
	$profile['buddy'] = in_array($profile['id_member'], $user_info['buddies']);
	$buddy_list = !empty($profile['buddy_list']) ? explode(',', $profile['buddy_list']) : [];

	if (!isset($profile['ooc_group']) && isset($profile['primary_group_id'], $profile['additional_groups']))
	{
		$groups = [$profile['primary_group_id']];
		if (!empty($profile['additional_groups']))
		{
			$groups = array_merge($groups, explode(',', $profile['additional_groups']));
		}
		$profile['ooc_group'] = get_labels_and_badges($groups);
	}

	//We need a little fallback for the membergroup icons. If it doesn't exist in the current theme, fallback to default theme
	if (isset($profile['icons'][1]) && file_exists($settings['actual_theme_dir'] . '/images/membericons/' . $profile['icons'][1])) //icon is set and exists
		$group_icon_url = $settings['images_url'] . '/membericons/' . $profile['icons'][1];
	elseif (isset($profile['icons'][1])) //icon is set and doesn't exist, fallback to default
		$group_icon_url = $settings['default_images_url'] . '/membericons/' . $profile['icons'][1];
	else //not set, bye bye
		$group_icon_url = '';

	// These minimal values are always loaded
	$memberContext[$user] = [
		'username' => $profile['member_name'],
		'name' => $profile['real_name'],
		'id' => $profile['id_member'],
		'href' => $scripturl . '?action=profile;u=' . $profile['id_member'],
		'link' => '<a href="' . $scripturl . '?action=profile;u=' . $profile['id_member'] . '" title="' . $txt['profile_of'] . ' ' . $profile['real_name'] . '" class="pm_icon">' . $profile['real_name'] . '</a>',
		'email' => $profile['email_address'],
		'show_email' => !$user_info['is_guest'] && ($user_info['id'] == $profile['id_member'] || allowedTo('moderate_forum')),
		'registered' => empty($profile['date_registered']) ? $txt['not_applicable'] : timeformat($profile['date_registered']),
		'registered_timestamp' => empty($profile['date_registered']) ? 0 : forum_time(true, $profile['date_registered']),
		'characters' => !empty($profile['characters']) ? $profile['characters'] : [],
		'current_character' => !empty($profile['online_character']) ? $profile['online_character'] : 0,
		'avatar' => '',
	];

	// If the set isn't minimal then load the monstrous array.
	if ($context['loadMemberContext_set'] != 'minimal')
	{
		// Display the user's currency if we're doing that.
		if (!empty($modSettings['currency_enabled']))
		{
			$memberContext[$user]['currency'] = Currency::process((int) $profile['currency']);
		}

		// Go the extra mile and load the user's native language name.
		if (empty($loadedLanguages))
			$loadedLanguages = getLanguages();

		$memberContext[$user] += [
			'username_color' => '<span ' . (!empty($profile['member_group_color']) ? 'style="color:' . $profile['member_group_color'] . ';"' : '') . '>' . $profile['member_name'] . '</span>',
			'name_color' => '<span ' . (!empty($profile['member_group_color']) ? 'style="color:' . $profile['member_group_color'] . ';"' : '') . '>' . $profile['real_name'] . '</span>',
			'link_color' => '<a href="' . $scripturl . '?action=profile;u=' . $profile['id_member'] . '" title="' . $txt['profile_of'] . ' ' . $profile['real_name'] . '" ' . (!empty($profile['member_group_color']) ? 'style="color:' . $profile['member_group_color'] . ';"' : '') . '>' . $profile['real_name'] . '</a>',
			'is_buddy' => $profile['buddy'],
			'is_reverse_buddy' => in_array($user_info['id'], $buddy_list),
			'buddies' => $buddy_list,
			'birth_date' => empty($profile['birthdate']) ? '1004-01-01' : $profile['birthdate'],
			'birthday_visibility' => $profile['birthday_visibility'],
			'real_posts' => $profile['posts'],
			'posts' => comma_format($profile['posts']),
			'last_login' => empty($profile['last_login']) ? $txt['never'] : timeformat($profile['last_login']),
			'last_login_timestamp' => empty($profile['last_login']) ? 0 : forum_time(0, $profile['last_login']),
			'ip' => StringLibrary::escape($profile['member_ip'] ?? ''),
			'ip2' => StringLibrary::escape($profile['member_ip2'] ?? ''),
			'online' => [
				'is_online' => !empty($profile['is_online']),
				'text' => StringLibrary::escape($txt[$profile['is_online'] ? 'online' : 'offline']),
				'member_online_text' => sprintf($txt[$profile['is_online'] ? 'member_is_online' : 'member_is_offline'], StringLibrary::escape($profile['real_name'])),
				'href' => $scripturl . '?action=pm;sa=send;u=' . $profile['id_member'],
				'link' => '<a href="' . $scripturl . '?action=pm;sa=send;u=' . $profile['id_member'] . '">' . $txt[$profile['is_online'] ? 'online' : 'offline'] . '</a>',
				'label' => $txt[$profile['is_online'] ? 'online' : 'offline']
			],
			'language' => !empty($loadedLanguages[$profile['lngfile']]) && !empty($loadedLanguages[$profile['lngfile']]['name']) ? $loadedLanguages[$profile['lngfile']]['name'] : StringLibrary::ucwords(strtr($profile['lngfile'], ['_' => ' ', '-utf8' => ''])),
			'is_activated' => isset($profile['is_activated']) ? $profile['is_activated'] : 1,
			'is_banned' => isset($profile['is_activated']) ? $profile['is_activated'] >= 10 : 0,
			'options' => $profile['options'],
			'is_guest' => false,
			'primary_group' => $profile['primary_group'],
			'group' => $profile['member_group'],
			'group_color' => $profile['member_group_color'],
			'group_id' => $profile['id_group'],
			'group_icons' => str_repeat('<img src="' . str_replace('$language', $context['user']['language'], isset($profile['icons'][1]) ? $group_icon_url : '') . '" alt="*">', empty($profile['icons'][0]) || empty($profile['icons'][1]) ? 0 : $profile['icons'][0]),
			'warning' => $profile['warning'],
			'warning_status' => !empty($modSettings['warning_mute']) && $modSettings['warning_mute'] <= $profile['warning'] ? 'mute' : (!empty($modSettings['warning_moderate']) && $modSettings['warning_moderate'] <= $profile['warning'] ? 'moderate' : (!empty($modSettings['warning_watch']) && $modSettings['warning_watch'] <= $profile['warning'] ? 'watch' : (''))),
			'local_time' => timeformat(time() + ($profile['time_offset'] - $user_info['time_offset']) * 3600, false),
			'custom_fields' => [],
			'ooc_group' => !empty($profile['ooc_group']) ? $profile['ooc_group'] : [
				'title' => '',
				'color' => '',
				'badges' => '',
				'combined_badges' => '',
			],
		];
	}

	// If the set isn't minimal then load their avatar as well.
	if ($context['loadMemberContext_set'] != 'minimal')
	{
		// First, find their OOC character.
		foreach ($profile['characters'] as $character) {
			if ($character['is_main']) {
				$profile['signature'] = $character['signature'];
				$profile['avatar'] = $character['avatar'];
				$profile['filename'] = $character['avatar_filename'];
				$profile['id_attach'] = $character['id_attach'];
				break;
			}
		}

		// So it's stored in the member table?
		if (!empty($profile['avatar']))
		{
			$image = (stristr($profile['avatar'], 'http://') || stristr($profile['avatar'], 'https://')) ? $profile['avatar'] : '';
		}
		elseif (!empty($profile['filename']))
			$image = $modSettings['custom_avatar_url'] . '/' . $profile['filename'];
		// Right... no avatar...use the default one
		else
			$image = $settings['images_url'] . '/default.png';

		if (!empty($image))
			$memberContext[$user]['avatar'] = [
				'name' => $profile['avatar'],
				'image' => '<img class="avatar" src="' . $image . '" alt="avatar_' . $profile['member_name'] . '">',
				'href' => $image,
				'url' => $image,
			];
	}

	// Are we also loading the members custom fields into context?
	if ($display_custom_fields && !empty($modSettings['displayFields']))
	{
		if (!isset($context['display_fields']))
			$context['display_fields'] = sbb_json_decode($modSettings['displayFields'], true);

		foreach ($memberContext[$user]['characters'] as $id_character => $character)
		{
			$memberContext[$user]['characters'][$id_character]['custom_fields'] = [];
			$fieldset = $character['is_main'] ? 'ooc' : 'ic';

			if (!isset($context['display_fields'][$fieldset]))
			{
				continue;
			}

			foreach ($context['display_fields'][$fieldset] as $custom)
			{
				if (empty($character['cfraw'][$custom['id']]))
				{
					continue;
				}

				$value = $character['cfraw'][$custom['id']];

				$placement = !empty($custom['placement']) ? $context['cust_profile_fields_placement'][$custom['placement']] : 'standard';
				$memberContext[$user]['characters'][$id_character]['custom_fields'][$placement][] = [
					'title' => !empty($custom['title']) ? $custom['title'] : $custom['col_name'],
					'col_name' => $custom['col_name'],
					'value' => process_custom_field($custom, $value),
				];
			}
		}
	}

	call_integration_hook('integrate_member_context', [&$memberContext[$user], $user, $display_custom_fields]);
	return true;
}

function process_custom_field($custom, $value)
{
	global $txt, $scripturl, $settings;

	$fieldOptions = [];
	$currentKey = 0;

	// Create a key => value array for multiple options fields
	if (!empty($custom['options']))
		foreach ($custom['options'] as $k => $v)
		{
			$fieldOptions[] = $v;
			if (empty($currentKey))
				$currentKey = $v == $value ? $k : 0;
		}

	// BBC?
	if (!empty($custom['bbc']))
	{
		$value = Parser::parse_bbc($value);
	}
	// ... or checkbox?
	elseif (isset($custom['type']) && $custom['type'] == 'check')
	{
		$value = $value ? $txt['yes'] : $txt['no'];
	}

	// Enclosing the user input within some other text?
	if (!empty($custom['enclose']))
	{
		$value = strtr($custom['enclose'], [
			'{SCRIPTURL}' => $scripturl,
			'{IMAGES_URL}' => $settings['images_url'],
			'{DEFAULT_IMAGES_URL}' => $settings['default_images_url'],
			'{INPUT}' => $value,
			'{KEY}' => $currentKey,
		]);
	}

	return un_htmlspecialchars($value);
}

/**
 * Loads the user's custom profile fields
 *
 * @param integer|array $users A single user ID or an array of user IDs
 * @param string|array $params Either a string or an array of strings with profile field names
 * @return array|boolean An array of data about the fields and their values or false if nothing was loaded
 */
function loadMemberCustomFields($users, $params)
{
	global $smcFunc, $txt, $scripturl, $settings;

	// Do not waste my time...
	if (empty($users) || empty($params))
		return false;

	// Make sure it's an array.
	$users = !is_array($users) ? [$users] : array_unique($users);
	$params = !is_array($params) ? [$params] : array_unique($params);
	$return = [];

	$request = $smcFunc['db']->query('', '
		SELECT c.id_field, c.col_name, c.field_name, c.field_desc, c.field_type, c.field_order, c.field_length, c.field_options, c.mask, show_reg,
		c.show_display, c.show_profile, c.private, c.active, c.bbc, c.can_search, c.default_value, c.enclose, c.placement, t.variable, t.value, t.id_member
		FROM {db_prefix}themes AS t
			LEFT JOIN {db_prefix}custom_fields AS c ON (c.col_name = t.variable)
		WHERE id_member IN ({array_int:loaded_ids})
			AND variable IN ({array_string:params})
		ORDER BY field_order',
		[
			'loaded_ids' => $users,
			'params' => $params,
		]
	);

	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		$fieldOptions = [];
		$currentKey = 0;

		// Create a key => value array for multiple options fields
		if (!empty($row['field_options']))
			foreach (explode(',', $row['field_options']) as $k => $v)
			{
				$fieldOptions[] = $v;
				if (empty($currentKey))
					$currentKey = $v == $row['value'] ? $k : 0;
			}

		// BBC?
		if (!empty($row['bbc']))
			$row['value'] = Parser::parse_bbc($row['value']);

		// ... or checkbox?
		elseif (isset($row['type']) && $row['type'] == 'check')
			$row['value'] = !empty($row['value']) ? $txt['yes'] : $txt['no'];

		// Enclosing the user input within some other text?
		if (!empty($row['enclose']))
			$row['value'] = strtr($row['enclose'], [
				'{SCRIPTURL}' => $scripturl,
				'{IMAGES_URL}' => $settings['images_url'],
				'{DEFAULT_IMAGES_URL}' => $settings['default_images_url'],
				'{INPUT}' => un_htmlspecialchars($row['value']),
				'{KEY}' => $currentKey,
			]);

		// Send a simple array if there is just 1 param
		if (count($params) == 1)
			$return[$row['id_member']] = $row;

		// More than 1? knock yourself out...
		else
		{
			if (!isset($return[$row['id_member']]))
				$return[$row['id_member']] = [];

			$return[$row['id_member']][$row['variable']] = $row;
		}
	}

	$smcFunc['db']->free_result($request);

	return !empty($return) ? $return : false;
}

/**
 * Loads information about what browser the user is viewing with and places it in $context
 *  - uses the class from {@link StoryBB\Helper\BrowserDetect}
 */
function detectBrowser()
{
	// Load the current user's browser of choice
	$detector = new BrowserDetect;
	$detector->detectBrowser();
}

/**
 * Are we using this browser?
 *
 * Wrapper function for detectBrowser
 * @param string $browser The browser we are checking for.
 * @return bool Whether or not the current browser is what we're looking for
*/
function isBrowser($browser)
{
	global $context;

	// Don't know any browser!
	if (empty($context['browser']))
		detectBrowser();

	return !empty($context['browser'][$browser]) || !empty($context['browser']['is_' . $browser]) ? true : false;
}

/**
 * Load a theme, by ID.
 *
 * @param int $id_theme The ID of the theme to load
 * @param bool $initialize Whether or not to initialize a bunch of theme-related variables/settings
 */
function loadTheme($id_theme = 0, $initialize = true)
{
	global $user_info, $user_settings, $board_info, $boarddir;
	global $txt, $boardurl, $scripturl, $modSettings;
	global $context, $settings, $options, $sourcedir, $smcFunc, $language, $board, $image_proxy_enabled;

	if (!isset($context['current_action']))
	{
		// If we're here and this has happened, we probably hit an error early on.
		$context['current_action'] = 'error';
	}

	// The theme was specified by parameter.
	if (!empty($id_theme))
		$id_theme = (int) $id_theme;
	// The theme was specified by REQUEST.
	elseif (!empty($_REQUEST['theme']) && (!empty($modSettings['theme_allow']) || allowedTo('admin_forum')))
	{
		$id_theme = (int) $_REQUEST['theme'];
		$_SESSION['id_theme'] = $id_theme;
	}
	// The theme was specified by REQUEST... previously.
	elseif (!empty($_SESSION['id_theme']) && (!empty($modSettings['theme_allow']) || allowedTo('admin_forum')))
		$id_theme = (int) $_SESSION['id_theme'];
	// The theme is just the user's choice. (might use /board/xyz?theme=0 to force board theme.)
	elseif (!empty($user_info['theme']) && !isset($_REQUEST['theme']))
		$id_theme = $user_info['theme'];
	// The theme was specified by the board.
	elseif (!empty($board_info['theme']))
		$id_theme = $board_info['theme'];
	// The theme is the forum's default.
	else
		$id_theme = $modSettings['theme_guests'];

	// Verify the id_theme... no foul play.
	// Always allow the board specific theme, if they are overriding.
	if (!empty($board_info['theme']) && $board_info['override_theme'])
		$id_theme = $board_info['theme'];
	elseif (!empty($modSettings['enableThemes']) && !allowedTo('admin_forum'))
	{
		$themes = explode(',', $modSettings['enableThemes']);
		if (!in_array($id_theme, $themes))
			$id_theme = $modSettings['theme_guests'];
		else
			$id_theme = (int) $id_theme;
	}
	else
		$id_theme = (int) $id_theme;

	// Disable image proxy if we don't have SSL enabled
	if (empty($modSettings['force_ssl']) || $modSettings['force_ssl'] < 2)
		$image_proxy_enabled = false;

	if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 2 && ($temp = cache_get_data('theme_settings-' . $id_theme, 60)) != null && time() - 60 > $modSettings['settings_updated'])
	{
		$themeData = $temp;
		$flag = true;
	}
	elseif (($temp = cache_get_data('theme_settings-' . $id_theme, 90)) != null && time() - 60 > $modSettings['settings_updated'])
		$themeData = $temp;
	else
		$themeData = [-1 => [], 0 => []];

	if (empty($flag))
	{
		// Load variables from the current or default theme, global or this user's.
		$result = $smcFunc['db']->query('', '
			SELECT variable, value, id_member, id_theme
			FROM {db_prefix}themes
			WHERE id_member = 0
				AND id_theme' . ($id_theme == 1 ? ' = {int:id_theme}' : ' IN ({int:id_theme}, 1)'),
			[
				'id_theme' => $id_theme,
			]
		);
		// Pick between $settings and $options depending on whose data it is.
		while ($row = $smcFunc['db']->fetch_assoc($result))
		{
			// If this is the theme_dir of the default theme, store it.
			if (in_array($row['variable'], ['theme_dir', 'theme_url', 'images_url']) && $row['id_theme'] == '1')
			{
				$themeData[0]['default_' . $row['variable']] = $row['value'];
			}

			// If this isn't set yet, is a theme option, or is not the default theme..
			if (!isset($themeData[0][$row['variable']]) || $row['id_theme'] != '1')
			{
				$themeData[0][$row['variable']] = substr($row['variable'], 0, 5) == 'show_' ? $row['value'] == '1' : $row['value'];
			}
		}
		$smcFunc['db']->free_result($result);

		if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 2)
			cache_put_data('theme_settings-' . $id_theme, $themeData, 60);
		// Only if we didn't already load that part of the cache...
		elseif (!isset($temp))
			cache_put_data('theme_settings-' . $id_theme, $themeData, 90);
	}

	$settings = $themeData[0];

	// We may have user avatars to fix.
	foreach (['ic_avatar', 'ooc_avatar'] as $av)
	{
		if (isset($user_info[$av]))
		{
			foreach ($user_info[$av] as $k => $v)
			{
				$user_info[$av][$k] = str_replace('{IMAGES_URL}', $settings['images_url'], $v);
			}
		}
	}
	$container = Container::instance();
	$prefs_manager = $container->instantiate('StoryBB\\User\\PreferenceManager');
	$options = $prefs_manager->get_preferences_for_user($user_info['id'] ? (int) $user_info['id'] : 0);

	$settings['theme_id'] = $id_theme;

	$settings['actual_theme_url'] = $settings['theme_url'];
	$settings['actual_images_url'] = $settings['images_url'];
	$settings['actual_theme_dir'] = $settings['theme_dir'];

	$settings['template_dirs'] = [];
	// This theme first.
	$settings['template_dirs'][] = $settings['theme_dir'];

	// Based on theme (if there is one).
	if (!empty($settings['base_theme_dir']))
		$settings['template_dirs'][] = $settings['base_theme_dir'];

	// Lastly the default theme.
	if ($settings['theme_dir'] != $settings['default_theme_dir'])
		$settings['template_dirs'][] = $settings['default_theme_dir'];

	if (!$initialize)
		return;

	// Check to see if we're forcing SSL
	if (!empty($modSettings['force_ssl']) && $modSettings['force_ssl'] == 2 && !App::in_maintenance() && !httpsOn())
	{
		if (isset($_GET['sslRedirect']))
		{
			loadLanguage('Errors');
			fatal_lang_error($txt['login_ssl_required']);
		}

		redirectexit(strtr($_SERVER['REQUEST_URL'], ['http://' => 'https://']) . (strpos($_SERVER['REQUEST_URL'], '?') > 0 ? ';' : '?') . 'sslRedirect');
	}

	// Check to see if they're accessing it from the wrong place.
	if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME']))
	{
		$detected_url = httpsOn() ? 'https://' : 'http://';
		$detected_url .= empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST'];
		$temp = preg_replace('~/' . basename($scripturl) . '(/.+)?$~', '', strtr(dirname($_SERVER['PHP_SELF']), '\\', '/'));
		if ($temp != '/')
			$detected_url .= $temp;
	}
	if (isset($detected_url) && $detected_url != $boardurl)
	{
		// Hmm... check #2 - is it just different by a www?  Send them to the correct place!!
		if (empty($do_fix) && strtr($detected_url, ['://' => '://www.']) == $boardurl && (empty($_GET) || count($_GET) == 1))
		{
			// Okay, this seems weird, but we don't want an endless loop - this will make $_GET not empty ;).
			if (empty($_GET))
				redirectexit('wwwRedirect');
			else
			{
				$k = key($_GET);
				$v = current($_GET);

				if ($k != 'wwwRedirect')
					redirectexit('wwwRedirect;' . $k . '=' . $v);
			}
		}

		// #3 is just a check for SSL...
		if (strtr($detected_url, ['https://' => 'http://']) == $boardurl)
			$do_fix = true;

		// Okay, #4 - perhaps it's an IP address?  We're gonna want to use that one, then. (assuming it's the IP or something...)
		if (!empty($do_fix) || preg_match('~^http[s]?://(?:[\d\.:]+|\[[\d:]+\](?::\d+)?)(?:$|/)~', $detected_url) == 1)
		{
			// Caching is good ;).
			$oldurl = $boardurl;

			// Fix $boardurl and $scripturl.
			$boardurl = $detected_url;
			$scripturl = strtr($scripturl, [$oldurl => $boardurl]);
			$_SERVER['REQUEST_URL'] = strtr($_SERVER['REQUEST_URL'], [$oldurl => $boardurl]);

			// Fix the theme urls...
			$settings['theme_url'] = strtr($settings['theme_url'], [$oldurl => $boardurl]);
			$settings['default_theme_url'] = strtr($settings['default_theme_url'], [$oldurl => $boardurl]);
			$settings['actual_theme_url'] = strtr($settings['actual_theme_url'], [$oldurl => $boardurl]);
			$settings['images_url'] = strtr($settings['images_url'], [$oldurl => $boardurl]);
			$settings['default_images_url'] = strtr($settings['default_images_url'], [$oldurl => $boardurl]);
			$settings['actual_images_url'] = strtr($settings['actual_images_url'], [$oldurl => $boardurl]);

			// And just a few mod settings :).
			$modSettings['custom_avatar_url'] = strtr($modSettings['custom_avatar_url'], [$oldurl => $boardurl]);

			// Clean up after loadBoard().
			if (isset($board_info['moderators']))
			{
				foreach ($board_info['moderators'] as $k => $dummy)
				{
					$board_info['moderators'][$k]['href'] = strtr($dummy['href'], [$oldurl => $boardurl]);
					$board_info['moderators'][$k]['link'] = strtr($dummy['link'], ['"' . $oldurl => '"' . $boardurl]);
				}
			}
			foreach ($context['linktree'] as $k => $dummy)
				$context['linktree'][$k]['url'] = strtr($dummy['url'], [$oldurl => $boardurl]);
		}
	}
	// Set up the contextual user array.
	if (!empty($user_info))
	{
		$context['user'] = [
			'id' => $user_info['id'],
			'is_logged' => !$user_info['is_guest'],
			'is_guest' => &$user_info['is_guest'],
			'is_admin' => &$user_info['is_admin'],
			'is_mod' => &$user_info['is_mod'],
			// A user can mod if they have permission to see the mod center, or they are a board/group/approval moderator.
			'can_mod' => allowedTo('access_mod_center') || (!$user_info['is_guest'] && ($user_info['mod_cache']['gq'] != '0=1' || $user_info['mod_cache']['bq'] != '0=1' || (!empty($user_info['mod_cache']['ap'])))),
			'name' => $user_info['username'],
			'language' => $user_info['language'],
			'email' => $user_info['email'],
			'ignoreusers' => $user_info['ignoreusers'],
		];
		if (!$context['user']['is_guest'])
			$context['user']['name'] = $user_info['name'];
		elseif ($context['user']['is_guest'] && !empty($txt['guest_title']))
			$context['user']['name'] = $txt['guest_title'];
	}
	else
	{
		// What to do when there is no $user_info (e.g., an error very early in the login process)
		$context['user'] = [
			'id' => -1,
			'is_logged' => false,
			'is_guest' => true,
			'is_mod' => false,
			'can_mod' => false,
			'name' => $txt['guest_title'],
			'language' => $language,
			'email' => '',
			'ignoreusers' => [],
		];
		// Note we should stuff $user_info with some guest values also...
		$user_info = [
			'id' => 0,
			'is_guest' => true,
			'is_admin' => false,
			'is_mod' => false,
			'username' => $txt['guest_title'],
			'language' => $language,
			'email' => '',
			'permissions' => [],
			'groups' => [],
			'ignoreusers' => [],
			'possibly_robot' => true,
			'time_offset' => 0,
			'time_format' => $modSettings['time_format'],
		];
	}

	// Some basic information...
	if (!isset($context['html_headers']))
		$context['html_headers'] = '';
	if (!isset($context['javascript_files']))
		$context['javascript_files'] = [];
	if (!isset($context['css_files']))
		$context['css_files'] = [];
	if (!isset($context['css_header']))
		$context['css_header'] = [];
	if (!isset($context['javascript_inline']))
		$context['javascript_inline'] = ['standard' => [], 'defer' => []];
	if (!isset($context['javascript_vars']))
		$context['javascript_vars'] = [];

	$container = Container::instance();
	$urlgenerator = $container->get('urlgenerator');

	// Everyone loves favicons.
	if (!empty($modSettings['favicon_cache']))
	{
		$favicons = json_decode($modSettings['favicon_cache'], true);

		$types = [
			0 => ['rel' => 'icon', 'type' => 'image/png', 'sizes' => '16x16'],
			1 => ['rel' => 'icon', 'type' => 'image/png', 'sizes' => '32x32'],
			2 => ['rel' => 'apple-touch-icon'],
			3 => ['rel' => 'apple-touch-icon', 'sizes' => '180x180'],
			4 => ['rel' => 'apple-touch-icon', 'sizes' => '152x152'],
			5 => ['rel' => 'apple-touch-icon', 'sizes' => '167x167'],
			6 => ['rel' => 'shortcut icon', 'sizes' => '128x128'],
			7 => ['rel' => 'shortcut icon', 'sizes' => '192x192'],
		];

		foreach ($types as $id => $params)
		{
			if (empty($favicons['favicon_' . $id]))
			{
				continue;
			}

			$params['href'] = $urlgenerator->generate('favicon', ['id' => $id, 'timestamp' => $favicons['favicon_' . $id]]);

			$link = '<link';
			foreach ($params as $param => $value)
			{
				$link .= ' ' . $param . '="' . $value . '"';
			}
			$link .= '>';

			$context['html_headers'] .= $link;
		}
	}

	$context['login_url'] = $urlgenerator->generate('login_login');
	$context['menu_separator'] = ' ';

	$session = $container->get('session');
	$context['session_var'] = $session->get('session_var');
	$context['session_id'] = $session->get('session_value');
	$context['forum_name'] = $modSettings['forum_name'];
	$context['forum_name_html_safe'] = StringLibrary::escape($context['forum_name']);
	$context['header_logo_url_html_safe'] = empty($settings['header_logo_url']) ? '' : StringLibrary::escape($settings['header_logo_url']);
	$context['current_subaction'] = isset($_REQUEST['sa']) ? $_REQUEST['sa'] : null;
	$context['can_register'] = empty($modSettings['registration_method']) || $modSettings['registration_method'] != 3;
	if (isset($modSettings['load_average']))
		$context['load_average'] = $modSettings['load_average'];

	// Detect the browser. This is separated out because it's also used in attachment downloads
	detectBrowser();

	// Set the top level linktree up.
	// Note that if we're dealing with certain very early errors (e.g., login) the linktree might not be set yet...
	if (empty($context['linktree']))
		$context['linktree'] = [];
	array_unshift($context['linktree'], [
		'url' => $scripturl,
		'name' => $context['forum_name_html_safe']
	]);

	// This allows sticking some HTML on the page output - useful for controls.
	$context['insert_after_template'] = '';

	if (!isset($txt))
		$txt = [];

	$simpleActions = [
		'helpadmin',
	];

	// Parent action => array of areas
	$simpleAreas = [
		'profile' => ['popup', 'alerts_popup',],
	];

	// Parent action => array of subactions
	$simpleSubActions = [
		'pm' => ['popup',],
		'signup' => ['usernamecheck'],
	];

	// Extra params like ;preview ;js, etc.
	$extraParams = [
		'preview',
		'splitjs',
	];

	// Actions that specifically uses XML output.
	$xmlActions = [
		'quotefast',
		'jsmodify',
		'xmlhttp',
		'post2',
		'suggest',
		'stats',
		'notifytopic',
		'notifyboard',
	];

	call_integration_hook('integrate_simple_actions', [&$simpleActions, &$simpleAreas, &$simpleSubActions, &$extraParams, &$xmlActions]);

	$context['simple_action'] = in_array($context['current_action'], $simpleActions) ||
	(isset($simpleAreas[$context['current_action']]) && isset($_REQUEST['area']) && in_array($_REQUEST['area'], $simpleAreas[$context['current_action']])) ||
	(isset($simpleSubActions[$context['current_action']]) && in_array($context['current_subaction'], $simpleSubActions[$context['current_action']]));

	// See if theres any extra param to check.
	$requiresXML = false;
	foreach ($extraParams as $key => $extra)
		if (isset($_REQUEST[$extra]))
			$requiresXML = true;

	loadLanguage('General');

	// Output is fully XML, so no need for the index template.
	if (isset($_REQUEST['xml']) && (in_array($context['current_action'], $xmlActions) || $requiresXML))
	{
		StoryBB\Template::set_layout('raw');
	}

	// Initialize the theme.
	$theme_settings = App::container()->get('current_theme')->get_defaults();
	foreach ($theme_settings as $key => $value)
	{
		if (!isset($settings[$key]))
			$settings[$key] = $value;
	}

	// Allow overriding the board wide time/number formats.
	if (empty($user_settings['time_format']) && !empty($txt['time_format']))
		$user_info['time_format'] = $txt['time_format'];

	// Set the character set from the template.
	$context['right_to_left'] = !empty($txt['lang_rtl']);

	// Guests may still need a name.
	if ($context['user']['is_guest'] && empty($context['user']['name']))
		$context['user']['name'] = $txt['guest_title'];

	// Make a special URL for the language.
	$settings['lang_images_url'] = $settings['images_url'] . '/' . (!empty($txt['image_lang']) ? $txt['image_lang'] : $user_info['language']);

	// And of course, let's load the default CSS file.
	loadSCSSFile('index');

	loadCSSFile('fontawesome-free-5.15.1-web/css/all.min.css', ['default_theme' => true, 'minimize' => false, 'validate' => false], 'font-awesome');

	if (!empty($settings['additional_files']['css']))
	{
		foreach ($settings['additional_files']['css'] as $css_file)
		{
			if (is_string($css_file))
			{
				// Simple case, just load the file.
				loadCSSFile($css_file, [], str_replace('.', '_', $css_file));
			}
			elseif (is_array($css_file) && !empty($css_file[0]))
			{
				// If it's a more complex case, it should be an array containing the parameters here.
				loadCSSFile($css_file[0], !empty($css_file[1]) ? $css_file[1] : [], !empty($css_file[2]) ? $css_file[2] : '');
			}
		}
	}

	if ($context['right_to_left'])
		loadCSSFile('rtl.css', [], 'sbb_rtl');

	// We allow theme variants, because we're cool.
	$context['theme_variant'] = '';
	$context['theme_variant_url'] = '';
	if (!empty($settings['theme_variants']))
	{
		// Overriding - for previews and that ilk.
		if (!empty($_REQUEST['variant']))
			$_SESSION['id_variant'] = $_REQUEST['variant'];
		// User selection?
		if (empty($settings['disable_user_variant']) || allowedTo('admin_forum'))
			$context['theme_variant'] = !empty($_SESSION['id_variant']) ? $_SESSION['id_variant'] : (!empty($options['theme_variant']) ? $options['theme_variant'] : '');
		// If not a user variant, select the default.
		if ($context['theme_variant'] == '' || !in_array($context['theme_variant'], $settings['theme_variants']))
			$context['theme_variant'] = !empty($settings['default_variant']) && in_array($settings['default_variant'], $settings['theme_variants']) ? $settings['default_variant'] : $settings['theme_variants'][0];

		// Do this to keep things easier in the templates.
		$context['theme_variant'] = '_' . $context['theme_variant'];
		$context['theme_variant_url'] = $context['theme_variant'] . '/';

		if (!empty($context['theme_variant']))
		{
			loadCSSFile('index' . $context['theme_variant'] . '.css', [], 'sbb_index' . $context['theme_variant']);
			if ($context['right_to_left'])
				loadCSSFile('rtl' . $context['theme_variant'] . '.css', [], 'sbb_rtl' . $context['theme_variant']);
		}
	}

	$context['tabindex'] = 1;

	// Default JS variables for use in every theme
	$context['javascript_vars'] = [
		'sbb_theme_url' => '"' . $settings['theme_url'] . '"',
		'sbb_default_theme_url' => '"' . $settings['default_theme_url'] . '"',
		'sbb_images_url' => '"' . $settings['images_url'] . '"',
		'sbb_scripturl' => '"' . $scripturl . '"',
		'sbb_session_id' => '"' . $context['session_id'] . '"',
		'sbb_session_var' => '"' . $context['session_var'] . '"',
		'sbb_member_id' => $context['user']['id'],
		'ajax_notification_text' => JavaScriptEscape($txt['ajax_in_progress']),
		'help_popup_heading_text' => JavaScriptEscape($txt['help_popup']),
		'sbb_keepalive_url' => '"' . $urlgenerator->generate('login_keepalive') . '"',
	];

	// Load the JQuery library.
	loadJavaScriptFile('jquery-3.6.0.min.js', ['seed' => false], 'sbb_jquery');

	// Queue our JQuery plugins!
	loadJavaScriptFile('sbb_jquery_plugins.js', ['minimize' => true], 'sbb_jquery_plugins');
	if (!$user_info['is_guest'])
	{
		loadJavaScriptFile('jquery.custom-scrollbar.js', [], 'sbb_jquery_scrollbar');
		loadCSSFile('jquery.custom-scrollbar.css', ['force_current' => false, 'validate' => true], 'sbb_scrollbar');
	}

	// script.js and theme.js, always required, so always add them! Makes index.template.php cleaner and all.
	loadJavaScriptFile('script.js', ['defer' => false, 'minimize' => true], 'sbb_script');
	loadJavaScriptFile('theme.js', ['minimize' => true], 'sbb_theme');

	if (!empty($settings['additional_files']['js']))
	{
		foreach ($settings['additional_files']['js'] as $js_file)
		{
			if (is_string($js_file))
			{
				// Simple case, just load the file.
				loadJavaScriptFile($js_file, [], str_replace('.', '_', $js_file));
			}
			elseif (is_array($js_file) && !empty($js_file[0]))
			{
				// If it's a more complex case, it should be an array containing the parameters here.
				loadJavaScriptFile($js_file[0], !empty($js_file[1]) ? $js_file[1] : [], !empty($js_file[2]) ? $js_file[2] : '');
			}
		}
	}

	// If we think we have mail to send, let's offer up some possibilities... robots get pain (Now with scheduled task support!)
	if ((!empty($modSettings['mail_next_send']) && $modSettings['mail_next_send'] < time() && empty($modSettings['mail_queue_use_cron'])) || empty($modSettings['next_task_time']) || $modSettings['next_task_time'] < time())
	{
		if (isBrowser('possibly_robot'))
		{
			// @todo Maybe move this somewhere better?!
			require_once($sourcedir . '/ScheduledTasks.php');

			// What to do, what to do?!
			if (empty($modSettings['next_task_time']) || $modSettings['next_task_time'] < time())
				AutoTask();
			else
				ReduceMailQueue();
		}
		else
		{
			$type = empty($modSettings['next_task_time']) || $modSettings['next_task_time'] < time() ? 'task' : 'mailq';
			$ts = $type == 'mailq' ? $modSettings['mail_next_send'] : $modSettings['next_task_time'];

			addInlineJavaScript('
		function sbbAutoTask()
		{
			$.get(sbb_scripturl + "?scheduled=' . $type . ';ts=' . $ts . '");
		}
		window.setTimeout("sbbAutoTask();", 1);');
		}
	}

	// And we should probably trigger the cron too.
	if (empty($modSettings['cron_is_real_cron']))
	{
		$ts = time();
		$ts -= $ts % 15;
		addInlineJavaScript('
	function triggerCron()
	{
		$.get(' . JavaScriptEscape($boardurl) . ' + "/cron.php?ts=' . $ts . '");
	}
	window.setTimeout(triggerCron, 1);', true);
	}

	// Filter out the restricted boards from the linktree
	if (!$user_info['is_admin'] && !empty($board))
	{
		foreach ($context['linktree'] as $k => $element)
		{
			if (!empty($element['groups']) &&
				(count(array_intersect($user_info['groups'], $element['groups'])) == 0 ||
				(count(array_intersect($user_info['groups'], $element['deny_groups'])) != 0)))
			{
				$context['linktree'][$k]['name'] = $txt['restricted_board'];
				$context['linktree'][$k]['extra_before'] = '<i>';
				$context['linktree'][$k]['extra_after'] = '</i>';
				unset($context['linktree'][$k]['url']);
			}
		}
	}

	// We are ready to go.
	$context['theme_loaded'] = true;
}

function check_load_avg(string $category): void
{
	global $context, $modSettings;

	if (!empty($context['load_average']) && !empty($modSettings['loadavg_' . $category]) && $context['load_average'] >= $modSettings['loadavg_' . $category])
	{
		fatal_lang_error('loadavg_' . $category . '_disabled', false);
	}
}

function loadSCSSFile($scssfile)
{
	$container = Container::instance();
	$urlgenerator = $container->get('urlgenerator');
	$site_settings = $container->get('sitesettings');
	$theme = $container->get('current_theme');

	$options = [
		'theme' => $theme->id,
		'scssfile' => $scssfile,
		'timestamp' => time(),
	];

	if ($site_settings->minimize_css && ($timestamp = $theme->get_compiled_time($scssfile)))
	{
		$options['timestamp'] = $timestamp;
	}

	loadCSSFile($urlgenerator->generate('css', $options), ['external' => true], 'sbb_' . $scssfile);
}

/**
 * Add a CSS file for output later
 *
 * @param string $fileName The name of the file to load
 * @param array $params An array of parameters
 * Keys are the following:
 * 	- ['external'] (true/false): define if the file is a externally located file. Needs to be set to true if you are loading an external file
 * 	- ['default_theme'] (true/false): force use of default theme url
 * 	- ['force_current'] (true/false): if this is false, we will attempt to load the file from the default theme if not found in the current theme
 *  - ['validate'] (true/false): if true script will validate the local file exists
 *  - ['rtl'] (string): additional file to load in RTL mode
 *  - ['seed'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
 *  - ['minimize'] boolean to add your file to the main minimized file. Useful when you have a file thats loaded everywhere and for everyone.
 * @param string $id An ID to stick on the end of the filename for caching purposes
 */
function loadCSSFile($fileName, $params = [], $id = '')
{
	global $settings, $context, $modSettings;

	if (empty($modSettings))
	{
		reloadSettings();
	}

	$params['seed'] = (!array_key_exists('seed', $params) || (array_key_exists('seed', $params) && $params['seed'] === true)) ? (array_key_exists('browser_cache', $modSettings) ? $modSettings['browser_cache'] : '') : (is_string($params['seed']) ? ($params['seed'] = $params['seed'][0] === '?' ? $params['seed'] : '?' . $params['seed']) : '');
	$params['force_current'] = isset($params['force_current']) ? $params['force_current'] : false;
	$themeRef = !empty($params['default_theme']) ? 'default_theme' : 'theme';
	$params['minimize'] = isset($params['minimize']) ? $params['minimize'] : false;
	$params['external'] = isset($params['external']) ? $params['external'] : false;
	$params['validate'] = isset($params['validate']) ? $params['validate'] : true;

	// If this is an external file, automatically set this to false.
	if (!empty($params['external']))
		$params['minimize'] = false;

	// Account for shorthand like admin.css?alp21 filenames
	$has_seed = strpos($fileName, '.css?');
	$id = empty($id) ? strtr(basename(str_replace('.css', '', $fileName)), '?', '_') : $id;

	// Is this a local file?
	if (empty($params['external']))
	{
		// Are we validating the the file exists?
		if (!empty($params['validate']) && !file_exists($settings[$themeRef . '_dir'] . '/css/' . $fileName))
		{
			// Maybe the default theme has it?
			if ($themeRef === 'theme' && !$params['force_current'] && file_exists($settings['default_theme_dir'] . '/css/' . $fileName))
			{
				$fileUrl = $settings['default_theme_url'] . '/css/' . $fileName . ($has_seed ? '' : $params['seed']);
				$filePath = $settings['default_theme_dir'] . '/css/' . $fileName . ($has_seed ? '' : $params['seed']);
			}

			else
				$fileUrl = false;
		}

		else
		{
			$fileUrl = $settings[$themeRef . '_url'] . '/css/' . $fileName . ($has_seed ? '' : $params['seed']);
			$filePath = $settings[$themeRef . '_dir'] . '/css/' . $fileName . ($has_seed ? '' : $params['seed']);
		}
	}

	// An external file doesn't have a filepath. Mock one for simplicity.
	else
	{
		$fileUrl = $fileName;
		$filePath = $fileName;
	}

	// Add it to the array for use in the template
	if (!empty($fileName))
		$context['css_files'][$id] = ['fileUrl' => $fileUrl, 'filePath' => $filePath, 'fileName' => $fileName, 'options' => $params];

	if (!empty($context['right_to_left']) && !empty($params['rtl']))
		loadCSSFile($params['rtl'], array_diff_key($params, ['rtl' => 0]));
}

/**
 * Add a block of inline css code to be executed later
 *
 * - only use this if you have to, generally external css files are better, but for very small changes
 *   or for scripts that require help from PHP/whatever, this can be useful.
 * - all code added with this function is added to the same style tag so do make sure your css is valid!
 *
 * @param string $css Some css code
 * @return void|bool Adds the CSS to the $context['css_header'] array or returns if no CSS is specified
 */
function addInlineCss($css)
{
	global $context;

	// Gotta add something...
	if (empty($css))
		return false;

	$context['css_header'][] = $css;
}

/**
 * Add a Javascript file for output later
 *
 * @param string $fileName The name of the file to load
 * @param array $params An array of parameter info
 * Keys are the following:
 * 	- ['external'] (true/false): define if the file is a externally located file. Needs to be set to true if you are loading an external file
 * 	- ['default_theme'] (true/false): force use of default theme url
 * 	- ['defer'] (true/false): define if the file should load in <head> or before the closing <html> tag
 * 	- ['force_current'] (true/false): if this is false, we will attempt to load the file from the
 *	default theme if not found in the current theme
 *	- ['async'] (true/false): if the script should be loaded asynchronously (HTML5)
 *  - ['validate'] (true/false): if true script will validate the local file exists
 *  - ['seed'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
 *  - ['minimize'] boolean to add your file to the main minimized file. Useful when you have a file thats loaded everywhere and for everyone.
 *
 * @param string $id An ID to stick on the end of the filename
 */
function loadJavaScriptFile($fileName, $params = [], $id = '')
{
	global $settings, $context, $modSettings;

	if (empty($modSettings))
	{
		reloadSettings();
	}

	$params['seed'] = (!array_key_exists('seed', $params) || (array_key_exists('seed', $params) && $params['seed'] === true)) ? (array_key_exists('browser_cache', $modSettings) ? $modSettings['browser_cache'] : '') : (is_string($params['seed']) ? ($params['seed'] = $params['seed'][0] === '?' ? $params['seed'] : '?' . $params['seed']) : '');
	$params['force_current'] = isset($params['force_current']) ? $params['force_current'] : false;
	$themeRef = !empty($params['default_theme']) ? 'default_theme' : 'theme';
	$params['minimize'] = isset($params['minimize']) ? $params['minimize'] : false;
	$params['external'] = isset($params['external']) ? $params['external'] : false;
	$params['validate'] = isset($params['validate']) ? $params['validate'] : true;

	// If this is an external file, automatically set this to false.
	if (!empty($params['external']))
		$params['minimize'] = false;

	// Account for shorthand like admin.js?alp21 filenames
	$has_seed = strpos($fileName, '.js?');
	$id = empty($id) ? strtr(basename(str_replace('.js', '', $fileName)), '?', '_') : $id;

	// Is this a local file?
	if (empty($params['external']))
	{
		// Are we validating it exists on disk?
		if (!empty($params['validate']) && !file_exists($settings[$themeRef . '_dir'] . '/scripts/' . $fileName))
		{
			// Can't find it in this theme, how about the default?
			if ($themeRef === 'theme' && !$params['force_current'] && file_exists($settings['default_theme_dir'] . '/scripts/' . $fileName))
			{
				$fileUrl = $settings['default_theme_url'] . '/scripts/' . $fileName . ($has_seed ? '' : $params['seed']);
				$filePath = $settings['default_theme_dir'] . '/scripts/' . $fileName . ($has_seed ? '' : $params['seed']);
			}

			else
			{
				$fileUrl = false;
				$filePath = false;
			}
		}

		else
		{
			$fileUrl = $settings[$themeRef . '_url'] . '/scripts/' . $fileName . ($has_seed ? '' : $params['seed']);
			$filePath = $settings[$themeRef . '_dir'] . '/scripts/' . $fileName . ($has_seed ? '' : $params['seed']);
		}
	}

	// An external file doesn't have a filepath. Mock one for simplicity.
	else
	{
		$fileUrl = $fileName;
		$filePath = $fileName;
	}

	// Add it to the array for use in the template
	if (!empty($fileName))
		$context['javascript_files'][$id] = ['fileUrl' => $fileUrl, 'filePath' => $filePath, 'fileName' => $fileName, 'options' => $params];
}

/**
 * Add a Javascript variable for output later (for feeding text strings and similar to JS)
 * Cleaner and easier (for modders) than to use the function below.
 *
 * @param string $key The key for this variable
 * @param string $value The value
 * @param bool $escape Whether or not to escape the value
 */
function addJavaScriptVar($key, $value, $escape = false)
{
	global $context;

	if (!empty($key) && (!empty($value) || $value === '0'))
		$context['javascript_vars'][$key] = !empty($escape) ? JavaScriptEscape($value) : $value;
}

/**
 * Add a block of inline Javascript code to be executed later
 *
 * - only use this if you have to, generally external JS files are better, but for very small scripts
 *   or for scripts that require help from PHP/whatever, this can be useful.
 * - all code added with this function is added to the same script tag so do make sure your JS is clean!
 *
 * @param string $javascript Some JS code
 * @param bool $defer Whether the script should load in head or before the closing html tag
 * @return void|bool Adds the code to one of the $context['javascript_inline'] arrays or returns if no JS was specified
 */
function addInlineJavaScript($javascript, $defer = false)
{
	global $context;

	if (empty($javascript))
		return false;

	$context['javascript_inline'][($defer === true ? 'defer' : 'standard')][] = $javascript;
}

/**
 * Load a language file.  Tries the current and default themes as well as the user and global languages.
 *
 * @param string $template_name The name of a template file
 * @param string $lang A specific language to load this file from
 * @param bool $fatal Whether to die with an error if it can't be loaded
 * @param bool $force_reload Whether to load the file again if it's already loaded
 * @return string The language actually loaded.
 */
function loadLanguage($template_name, $lang = '', $fatal = true, $force_reload = false)
{
	global $user_info, $language, $context;
	global $db_show_debug, $sourcedir, $cachedir;
	global $txt, $helptxt, $txtBirthdayEmails, $editortxt;
	static $already_loaded = [];

	// Default to the user's language.
	if ($lang == '')
		$lang = isset($user_info['language']) ? $user_info['language'] : $language;

	if (!$force_reload && isset($already_loaded[$template_name]) && $already_loaded[$template_name] == $lang)
		return $lang;

	if (!is_array($txt))
	{
		$txt = [];
	}
	if (!is_array($helptxt))
	{
		$helptxt = [];
	}
	if (!is_array($txtBirthdayEmails))
	{
		$txtBirthdayEmails = [];
	}
	if (!is_array($editortxt))
	{
		$editortxt = [];
	}

	// For each file open it up and write it out!
	foreach (explode('+', $template_name) as $template)
	{
		$path = $cachedir . '/lang/' . $lang . '_' . $template . '.php';
		// If it doesn't exist, try to make it.
		if (!file_exists($path))
		{
			Language::cache_language($lang, $template);
		}

		// If it still doesn't exist, abort!
		if (!file_exists($path))
		{
			if ($fatal)
			{
				fatal_error('Language file missing ' . $template . ' for language ' . $lang, 'template');
			}
			else
			{
				log_error('Language file missing ' . $template . ' for language ' . $lang, 'template');
				return;
			}
		}

		@include($path);

		// setlocale is required for basename() & pathinfo() to work properly on the selected language
		if ($template == 'General')
		{
			try
			{
				if (!file_exists(App::get_languages_path() . '/' . $lang . '/' . $lang . '.json'))
				{
					throw new RuntimeException('Language ' . $lang . ' is missing its ' . $lang . '.json file');
				}
				$general = @json_decode(file_get_contents(App::get_languages_path() . '/' . $lang . '/' . $lang . '.json'), true);
				if (!is_array($general) || !isset($general['locale'], $general['native_name']))
				{
					throw new RuntimeException('Language ' . $file[2] . ' has an invalid ' . $file[2] . '.json file');
				}
				$txt['lang_locale'] = $general['locale'];
				$txt['lang_rtl'] = !empty($general['is_rtl']);
				$txt['native_name'] = $general['native_name'];
				$txt['english_name'] = !empty($general['english_name']) ? $general['english_name'] : $general['native_name'];
			}
			catch (Exception $e)
			{
				$txt['lang_locale'] = 'en-US';
				$txt['lang_rtl'] = false;
				$txt['native_name'] = 'English (debug)';
				$txt['english_name'] = 'English (debug)';
				log_error($e, 'template');
				break;
			}
			setlocale(LC_CTYPE, $txt['lang_locale'] . '.utf8', $txt['lang_locale'] . '.UTF-8');

			$context['locale'] = str_replace("_", "-", substr($txt['lang_locale'], 0, strcspn($txt['lang_locale'], ".")));
		}
	}

	// Keep track of what we're up to soldier.
	if ($db_show_debug === true)
		$context['debug']['language_files'][] = $template_name . ' (' . $lang . ')';

	// Remember what we have loaded, and in which language.
	$already_loaded[$template_name] = $lang;

	// Return the language actually loaded.
	return $lang;
}

/**
 * Get all parent boards (requires first parent as parameter)
 * It finds all the parents of id_parent, and that board itself.
 * Additionally, it detects the moderators of said boards.
 *
 * @param int $id_parent The ID of the parent board
 * @return array An array of information about the boards found.
 */
function getBoardParents($id_parent)
{
	global $scripturl, $smcFunc;

	$url = App::container()->get('urlgenerator');

	// First check if we have this cached already.
	if (($boards = cache_get_data('board_parents-' . $id_parent, 480)) === null)
	{
		$boards = [];
		$original_parent = $id_parent;

		// Loop while the parent is non-zero.
		while ($id_parent != 0)
		{
			$result = $smcFunc['db']->query('', '
				SELECT
					b.id_parent, b.name, b.slug, {int:board_parent} AS id_board, b.member_groups, b.deny_member_groups,
					b.child_level, COALESCE(mem.id_member, 0) AS id_moderator, mem.real_name,
					COALESCE(mg.id_group, 0) AS id_moderator_group, mg.group_name
				FROM {db_prefix}boards AS b
					LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board)
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
					LEFT JOIN {db_prefix}moderator_groups AS modgs ON (modgs.id_board = b.id_board)
					LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = modgs.id_group)
				WHERE b.id_board = {int:board_parent}',
				[
					'board_parent' => $id_parent,
				]
			);
			// In the EXTREMELY unlikely event this happens, give an error message.
			if ($smcFunc['db']->num_rows($result) == 0)
				fatal_lang_error('parent_not_found', 'critical');
			while ($row = $smcFunc['db']->fetch_assoc($result))
			{
				if (!isset($boards[$row['id_board']]))
				{
					$id_parent = $row['id_parent'];
					$boards[$row['id_board']] = [
						'url' => $url->generate('board', ['board_slug' => $row['slug']]),
						'name' => $row['name'],
						'level' => $row['child_level'],
						'groups' => explode(',', $row['member_groups']),
						'deny_groups' => explode(',', $row['deny_member_groups']),
						'moderators' => [],
						'moderator_groups' => []
					];
				}
				// If a moderator exists for this board, add that moderator for all children too.
				if (!empty($row['id_moderator']))
					foreach ($boards as $id => $dummy)
					{
						$boards[$id]['moderators'][$row['id_moderator']] = [
							'id' => $row['id_moderator'],
							'name' => $row['real_name'],
							'href' => $scripturl . '?action=profile;u=' . $row['id_moderator'],
							'link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_moderator'] . '">' . $row['real_name'] . '</a>'
						];
					}

				// If a moderator group exists for this board, add that moderator group for all children too
				if (!empty($row['id_moderator_group']))
					foreach ($boards as $id => $dummy)
					{
						$boards[$id]['moderator_groups'][$row['id_moderator_group']] = [
							'id' => $row['id_moderator_group'],
							'name' => $row['group_name'],
							'href' => $scripturl . '?action=groups;sa=members;group=' . $row['id_moderator_group'],
							'link' => '<a href="' . $scripturl . '?action=groups;sa=members;group=' . $row['id_moderator_group'] . '">' . $row['group_name'] . '</a>'
						];
					}
			}
			$smcFunc['db']->free_result($result);
		}

		cache_put_data('board_parents-' . $original_parent, $boards, 480);
	}

	return $boards;
}

/**
 * Attempt to reload our known languages.
 * It will try to choose only utf8 or non-utf8 languages.
 *
 * @param bool $use_cache Whether or not to use the cache
 * @return array An array of information about available languages
 */
function getLanguages($use_cache = true)
{
	global $context, $settings, $modSettings, $language;

	// Either we don't use the cache, or its expired.
	if (!$use_cache || ($context['languages'] = cache_get_data('known_languages', !empty($modSettings['cache_enable']) && $modSettings['cache_enable'] < 1 ? 86400 : 3600)) === null)
	{
		// If we don't have our settings defined yet, let's load the settings data.
		if (empty($modSettings))
			reloadSettings();

		// Get a list of languages.
		$langList = !empty($modSettings['langList']) ? json_decode($modSettings['langList'], true) : [];
		$langList = is_array($langList) ? $langList : false;

		$catchLang = [];

		$language_dir = App::get_languages_path();

		$dir = dir($language_dir);
		while ($entry = $dir->read())
		{
			if ($entry[0] == '.')
			{
				continue;
			}
			// If the JSON doesn't exist, don't load it.
			if (!file_exists($language_dir . '/' . $entry . '/' . $entry . '.json'))
			{
				continue;
			}
			// If the language manifest JSON isn't valid, skip it.
			$language_manifest = @json_decode(file_get_contents($language_dir . '/' . $entry . '/' . $entry . '.json'), true);
			if (empty($language_manifest) || !is_array($language_manifest) || empty($language_manifest['native_name']))
			{
				continue;
			}

			$catchLang[$entry] = $language_manifest['native_name'];

			// Build this language entry.
			$context['languages'][$entry] = [
				'name' => $language_manifest['native_name'],
				'selected' => false,
				'filename' => $entry,
			];
		}
		$dir->close();

		// Do we need to store the lang list?
		if (empty($langList))
			updateSettings(['langList' => json_encode($catchLang)]);

		// Let's cash in on this deal.
		if (!empty($modSettings['cache_enable']))
			cache_put_data('known_languages', $context['languages'], !empty($modSettings['cache_enable']) && $modSettings['cache_enable'] < 1 ? 86400 : 3600);
	}

	if ($use_cache)
	{
		$langs = !empty($modSettings['languages_available']) ? explode(',', $modSettings['languages_available']) : [];
		if (empty($langs))
		{
			$langs[] = $language;
		}
		foreach (array_keys($context['languages']) as $lang)
		{
			if (!in_array($lang, $langs))
			{
				unset ($context['languages'][$lang]);
			}
		}
	}
	return $context['languages'];
}

/**
 * Replace all vulgar words with respective proper words. (substring or whole words..)
 * What this function does:
 *  - it censors the passed string.
 *  - if the theme setting allow_no_censored is on, and the theme option
 *	show_no_censored is enabled, does not censor, unless force is also set.
 *  - it caches the list of censored words to reduce parsing.
 *
 * @param string &$text The text to censor
 * @param bool $force Whether to censor the text regardless of settings
 * @return string The censored text
 */
function censorText(&$text, $force = false)
{
	global $modSettings, $options;
	static $censor_vulgar = null, $censor_proper;

	if ((!empty($options['show_no_censored']) && !empty($modSettings['allow_no_censored']) && !$force) || empty($modSettings['censor_vulgar']) || trim($text) === '')
		return $text;

	// If they haven't yet been loaded, load them.
	if ($censor_vulgar == null)
	{
		$censor_vulgar = explode("\n", $modSettings['censor_vulgar']);
		$censor_proper = explode("\n", $modSettings['censor_proper']);

		// Quote them for use in regular expressions.
		if (!empty($modSettings['censorWholeWord']))
		{
			for ($i = 0, $n = count($censor_vulgar); $i < $n; $i++)
			{
				$censor_vulgar[$i] = str_replace(['\\\\\\*', '\\*', '&', '\''], ['[*]', '[^\s]*?', '&amp;', '&#039;'], preg_quote($censor_vulgar[$i], '/'));
				$censor_vulgar[$i] = '/(?<=^|\W)' . $censor_vulgar[$i] . '(?=$|\W)/u' . (empty($modSettings['censorIgnoreCase']) ? '' : 'i');

				// @todo I'm thinking the old way is some kind of bug and this is actually fixing it.
				//if (strpos($censor_vulgar[$i], '\'') !== false)
					//$censor_vulgar[$i] = str_replace('\'', '&#039;', $censor_vulgar[$i]);
			}
		}
	}

	// Censoring isn't so very complicated :P.
	if (empty($modSettings['censorWholeWord']))
	{
		$func = !empty($modSettings['censorIgnoreCase']) ? 'str_ireplace' : 'str_replace';
		$text = $func($censor_vulgar, $censor_proper, $text);
	}
	else
		$text = preg_replace($censor_vulgar, $censor_proper, $text);

	return $text;
}

/**
 * Initialize a database connection.
 */
function loadDatabase()
{
	global $db_persist, $db_server, $db_user, $db_passwd;
	global $db_type, $db_name, $db_prefix, $db_port, $smcFunc;

	if (empty($smcFunc))
	{
		$smcFunc = [];
	}

	// Figure out what type of database we are using.
	if (empty($db_type))
	{
		$db_type = 'mysql';
	}

	$db_options = [];

	// Add in the port if needed
	if (!empty($db_port))
		$db_options['port'] = $db_port;

	try
	{
		$options = array_merge($db_options, ['persist' => $db_persist]);

		$smcFunc['db'] = AdapterFactory::get_adapter($db_type);
		$smcFunc['db']->set_prefix($db_prefix);
		$smcFunc['db']->set_server($db_server, $db_name, $db_user, $db_passwd);
		$smcFunc['db']->connect($options);
	}
	catch (DatabaseException $e)
	{
		display_db_error();
	}
}

/**
 * Try to retrieve a cache entry. On failure, call the appropriate function.
 *
 * @param string $key The key for this entry
 * @param string $file The file associated with this entry
 * @param string $function The function to call
 * @param array $params Parameters to be passed to the specified function
 * @param int $level The cache level
 * @return string The cached data
 */
function cache_quick_get($key, $file, $function, $params, $level = 1)
{
	global $modSettings, $sourcedir;

	// @todo Why are we doing this if caching is disabled?

	/* Refresh the cache if either:
		1. Caching is disabled.
		2. The cache level isn't high enough.
		3. The item has not been cached or the cached item expired.
		4. The cached item has a custom expiration condition evaluating to true.
		5. The expire time set in the cache item has passed (needed for Zend).
	*/
	if (empty($modSettings['cache_enable']) || $modSettings['cache_enable'] < $level || !is_array($cache_block = cache_get_data($key, 3600)) || (!empty($cache_block['refresh_eval']) && eval($cache_block['refresh_eval'])) || (!empty($cache_block['expires']) && $cache_block['expires'] < time()))
	{
		if (!empty($file))
		{
			require_once($sourcedir . '/' . $file);
		}
		$cache_block = call_user_func_array($function, $params);

		if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= $level)
			cache_put_data($key, $cache_block, $cache_block['expires'] - time());
	}

	// Some cached data may need a freshening up after retrieval.
	if (!empty($cache_block['post_retri_eval']))
		eval($cache_block['post_retri_eval']);

	return $cache_block['data'];
}

/**
 * Puts value in the cache under key for ttl seconds.
 *
 * - It may "miss" so shouldn't be depended on
 * - Uses the cache engine chosen in the ACP and saved in settings.php
 * - It supports:
 *	 memcache: https://php.net/memcache
 *   APCu: https://php.net/book.apcu
 *	 Zend: http://files.zend.com/help/Zend-Platform/output_cache_functions.htm
 *	 Zend: http://files.zend.com/help/Zend-Platform/zend_cache_functions.htm
 *
 * @param string $key A key for this value
 * @param mixed $value The data to cache
 * @param int $ttl How long (in seconds) the data should be cached for
 */
function cache_put_data($key, $value, $ttl = 120)
{
	return StoryBB\Cache::put($key, $value, $ttl);
}

/**
 * Gets the value from the cache specified by key, so long as it is not older than ttl seconds.
 * - It may often "miss", so shouldn't be depended on.
 * - It supports the same as cache_put_data().
 *
 * @param string $key The key for the value to retrieve
 * @param int $ttl The maximum age of the cached data
 * @return string The cached data or null if nothing was loaded
 */
function cache_get_data($key, $ttl = 120)
{
	return StoryBB\Cache::get($key, $ttl);
}

/**
 * Empty out the cache in use as best it can
 *
 * It may only remove the files of a certain type (if the $type parameter is given)
 * Type can be user, data or left blank
 * 	- user clears out user data
 *  - data clears out system / opcode data
 *  - If no type is specified will perform a complete cache clearing
 * For cache engines that do not distinguish on types, a full cache flush will be done
 *
 * @param string $type The cache type ('memcached', 'apc', 'zend' or something else that maps to StoryBB\Cache\*)
 */
function clean_cache($type = '')
{
	StoryBB\Cache::flush($type);
}

/**
 * Helper function to set an array of data for an user's avatar.
 *
 * Makes assumptions based on the data provided, the following keys are required:
 * - avatar The raw "avatar" column in members table
 * - filename The attachment filename
 *
 * @deprecated
 * @param array $data An array of raw info
 * @return array An array of avatar data
 */
function set_avatar_data($data = [])
{
	return App::container()->get('formatter')->avatar($data);
}

/**
 * Get the entire list of groups, their icons, color etc.
 *
 * @return array A list of groups, excluding hidden groups
 */
function get_char_membergroup_data()
{
	global $smcFunc, $settings, $context;
	static $groups = null;

	if ($groups !== null)
		return $groups;

	// We will want to get all the membergroups since potentially we're doing display
	// of multiple per character. We need to fetch them in the order laid down
	// by admins for display purposes and we will need to cache it.
	if (($groups = cache_get_data('char_membergroups', 300)) === null)
	{
		$groups = [];
		$request = $smcFunc['db']->query('', '
			SELECT id_group, group_name, online_color, icons, is_character
			FROM {db_prefix}membergroups
			WHERE hidden != 2
			ORDER BY badge_order');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$row['parsed_icons'] = '';
			if (!empty($row['icons']))
			{
				list($qty, $badge) = explode('#', $row['icons']);
				if ($qty > 0)
				{
					if (file_exists($settings['actual_theme_dir'] . '/images/membericons/' . $badge))
						$group_icon_url = $settings['images_url'] . '/membericons/' . $badge;
					elseif (file_exists($settings['default_images_url'] . '/membericons/' . $badge))
						$group_icon_url = $settings['default_images_url'] . '/membericons/' . $badge;
					else
						$group_icon_url = '';

					if (!empty($group_icon_url))
					{
						$row['parsed_icons'] = str_repeat('<img src="' . str_replace('$language', $context['user']['language'], $group_icon_url) . '" alt="*">', $qty);
					}
				}
			}

			$groups[$row['id_group']] = $row;
		}

		$smcFunc['db']->free_result($request);
		cache_put_data('char_membergroups', $groups, 300);
	}

	return $groups;
}

/**
 * Get the badge, colour and title based on the groups a poster is part of.
 *
 * @param array $group_list The list of groups an account or character contains.
 * @return array Title, color, badges for the group list
 */
function get_labels_and_badges($group_list)
{
	$group_title = null;
	$group_color = '';
	$groups = get_char_membergroup_data();
	$group_limit = 2;

	$badges = '';
	$combined_badges = [];
	$badges_done = 0;
	foreach ($group_list as $id_group) {
		if (empty($groups[$id_group]))
			continue;

		if ($group_title === null) {
			$group_title = $groups[$id_group]['group_name'];
			$group_color = $groups[$id_group]['online_color'];
		}

		if (empty($groups[$id_group]['parsed_icons']))
			continue;

		$badges .= '<div class="group_icons group_icons_' . $id_group . '">' . $groups[$id_group]['parsed_icons'] . '</div>';
		$combined_badges[] = '<div class="char_group_title"' . (!empty($groups[$id_group]['online_color']) ? ' style="color:' . $groups[$id_group]['online_color'] . '"' : '') . '>' . $groups[$id_group]['group_name'] . '</div><div class="char_group_badges">' . $groups[$id_group]['parsed_icons'] . '</div>';

		$badges_done++;
		if ($badges_done >= $group_limit) {
			break;
		}
	}

	return [
		'title' => $group_title,
		'color' => $group_color,
		'badges' => $badges,
		'combined_badges' => $combined_badges,
	];
}
