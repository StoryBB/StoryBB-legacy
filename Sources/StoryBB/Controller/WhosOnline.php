<?php

/**
 * The who's online handler.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller;

use StoryBB\App;
use StoryBB\Helper\IP;
use StoryBB\Helper\OnlineAction;
use StoryBB\Helper\Pagination;
use StoryBB\Helper\Parser;
use StoryBB\Model\Robot;
use StoryBB\Phrase;
use StoryBB\Routing\Behaviours\Routable;
use StoryBB\StringLibrary;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class WhosOnline implements Routable
{
	public static function register_own_routes(RouteCollection $routes): void
	{
		$routes->add('whosonline', new Route('/whos-online', ['_function' => [static::class, 'whos_online']]));
	}

	public static function whos_online()
	{
		global $context, $scripturl, $txt, $modSettings, $memberContext, $smcFunc;

		// Permissions, permissions, permissions.
		isAllowedTo('who_view');

		// You can't do anything if this is off.
		if (empty($modSettings['who_enabled']))
			fatal_lang_error('who_off', false);

		// Load the 'Who' template.
		$context['sub_template'] = 'whosonline';
		loadLanguage('Who');

		$urlgenerator = App::container()->get('urlgenerator');

		// Sort out... the column sorting.
		$sort_methods = [
			'user' => 'mem.real_name',
			'time' => 'lo.log_time'
		];

		$show_methods = [
			'members' => '(lo.id_member != 0)',
			'guests' => '(lo.id_member = 0 AND lo.robot_name = {empty})',
			'all' => '1=1',
			'robots' => '(lo.robot_name != {empty})',
		];

		// Store the sort methods and the show types for use in the template.
		$context['sort_methods'] = [
			'user' => $txt['who_user'],
			'time' => $txt['who_time'],
		];
		$context['show_methods'] = [
			'all' => $txt['who_show_all'],
			'members' => $txt['who_show_members_only'],
			'guests' => $txt['who_show_guests_only'],
			'robots' => $txt['who_show_robots_only'],
		];

		// Does the user prefer a different sort direction?
		if (isset($_REQUEST['sort']) && isset($sort_methods[$_REQUEST['sort']]))
		{
			$context['sort_by'] = $_SESSION['who_online_sort_by'] = $_REQUEST['sort'];
			$sort_method = $sort_methods[$_REQUEST['sort']];
		}
		// Did we set a preferred sort order earlier in the session?
		elseif (isset($_SESSION['who_online_sort_by']))
		{
			$context['sort_by'] = $_SESSION['who_online_sort_by'];
			$sort_method = $sort_methods[$_SESSION['who_online_sort_by']];
		}
		// Default to last time online.
		else
		{
			$context['sort_by'] = $_SESSION['who_online_sort_by'] = 'time';
			$sort_method = 'lo.log_time';
		}

		$context['sort_direction'] = isset($_REQUEST['direction']) && $_REQUEST['direction'] == 'asc' ? 'up' : 'down';

		$conditions = [];
		if (!allowedTo('moderate_forum'))
			$conditions[] = '(COALESCE(mem.show_online, 1) = 1)';

		// Does the user wish to apply a filter?
		if (isset($_REQUEST['show']) && isset($show_methods[$_REQUEST['show']]))
			$context['show_by'] = $_SESSION['who_online_filter'] = $_REQUEST['show'];
		// Perhaps we saved a filter earlier in the session?
		elseif (isset($_SESSION['who_online_filter']))
			$context['show_by'] = $_SESSION['who_online_filter'];
		else
			$context['show_by'] = 'members';

		$context['navigation_tabs'] = [];
		foreach ($context['show_methods'] as $key => $label)
		{
			$context['navigation_tabs'][] = [
				'label' => $label,
				'active' => $key == $context['show_by'],
				'url' => $key == $context['show_by'] ? '' : $urlgenerator->generate('whosonline', ['show' => $key]),
			];
		}

		$context['table_headers'] = [
			'user' => [
				'link' => $urlgenerator->generate('whosonline', [
					'show' => $context['show_by'],
					'sort' => 'user',
					'direction' => $context['sort_direction'] != 'down' && $context['sort_by'] == 'user' ? 'desc' : 'asc',
					'start' => 0,
				]),
				'label' => new Phrase('Who:who_user'),
			],
			'time' => [
				'link' => $urlgenerator->generate('whosonline', [
					'show' => $context['show_by'],
					'sort' => 'time',
					'direction' => $context['sort_direction'] == 'down' && $context['sort_by'] == 'time' ? 'asc' : 'desc',
					'start' => 0,
				]),
				'label' => new Phrase('Who:who_time'),
			],
			'action' => [
				'label' => new Phrase('Who:who_action')
			],
		];

		$conditions[] = $show_methods[$context['show_by']];

		// Get the total amount of members online.
		$request = $smcFunc['db']->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}log_online AS lo
				LEFT JOIN {db_prefix}members AS mem ON (lo.id_member = mem.id_member)' . (!empty($conditions) ? '
			WHERE ' . implode(' AND ', $conditions) : ''),
			[
			]
		);
		list ($totalMembers) = $smcFunc['db']->fetch_row($request);
		$smcFunc['db']->free_result($request);

		// Prepare some page index variables.
		$context['page_index'] = new Pagination($_REQUEST['start'], (int) $totalMembers, (int) $modSettings['defaultMaxMembers'], 'whosonline', [
			'sort' => $context['sort_by'],
			'direction' => $context['sort_direction'] == 'up' ? 'asc' : 'desc',
			'show' => $context['show_by']
		]);

		$context['start'] = $_REQUEST['start'];

		// Look for people online, provided they don't mind if you see they are.
		$request = $smcFunc['db']->query('', '
			SELECT
				lo.log_time, lo.id_member, lo.url, lo.ip AS ip, lo.id_character, COALESCE(chars.character_name, mem.real_name) AS real_name,
				lo.session, IF(chars.is_main, mg.online_color, cg.online_color) AS online_color, COALESCE(mem.show_online, 1) AS show_online,
				lo.robot_name, lo.route, lo.routeparams
			FROM {db_prefix}log_online AS lo
				LEFT JOIN {db_prefix}members AS mem ON (lo.id_member = mem.id_member)
				LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = mem.id_group)
				LEFT JOIN {db_prefix}characters AS chars ON (lo.id_character = chars.id_character)
				LEFT JOIN {db_prefix}membergroups AS cg ON (chars.main_char_group = cg.id_group)' . (!empty($conditions) ? '
			WHERE ' . implode(' AND ', $conditions) : '') . '
			ORDER BY {raw:sort_method} {raw:sort_direction}
			LIMIT {int:offset}, {int:limit}',
			[
				'regular_member' => 0,
				'sort_method' => $sort_method,
				'sort_direction' => $context['sort_direction'] == 'up' ? 'ASC' : 'DESC',
				'offset' => $context['start'],
				'limit' => $modSettings['defaultMaxMembers'],
			]
		);
		$context['members'] = [];
		$member_ids = [];
		$url_data = [];
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$actions = sbb_json_decode($row['url'], true);
			if ($actions === false)
				continue;

			// Send the information to the template.
			$context['members'][$row['session']] = [
				'id' => $row['id_member'],
				'id_character' => $row['id_character'],
				'ip' => allowedTo('moderate_forum') ? IP::format($row['ip']) : '',
				// It is *going* to be today or yesterday, so why keep that information in there?
				'time' => strtr(timeformat($row['log_time']), [$txt['today'] => '', $txt['yesterday'] => '']),
				'timestamp' => forum_time(true, $row['log_time']),
				'query' => $actions,
				'is_hidden' => $row['show_online'] == 0,
				'robot_name' => $row['robot_name'],
				'color' => empty($row['online_color']) ? '' : $row['online_color'],
				'user_agent' => !empty($actions['USER_AGENT']) ? $actions['USER_AGENT'] : '',
			];

			$url_data[$row['session']] = [
				'url' => $row['url'],
				'id_member' => $row['id_member'],
				'route' => $row['route'],
				'routeparams' => $row['routeparams'],
			];
			$member_ids[] = $row['id_member'];
		}
		$smcFunc['db']->free_result($request);

		// Load the user data for these members.
		loadMemberData($member_ids);

		// Load up the guest user.
		$memberContext[0] = [
			'id' => 0,
			'name' => $txt['guest_title'],
			'group' => $txt['guest_title'],
			'href' => '',
			'link' => $txt['guest_title'],
			'email' => $txt['guest_title'],
			'is_guest' => true
		];

		$online_helper = App::make(OnlineAction::class);
		$url_data = $online_helper->determine($url_data);

		// Setup the linktree and page title (do it down here because the language files are now loaded..)
		$context['page_title'] = $txt['who_title'];
		$context['linktree'][] = [
			'url' => $urlgenerator->generate('whosonline'),
			'name' => $txt['who_title']
		];

		// Put it in the context variables.
		$robot = App::make(Robot::class);
		foreach ($context['members'] as $i => $member)
		{
			if ($member['id'] != 0)
				$member['id'] = loadMemberContext($member['id']) ? $member['id'] : 0;

			// Keep the IP that came from the database.
			$memberContext[$member['id']]['ip'] = $member['ip'];
			$context['members'][$i]['action'] = isset($url_data[$i]) ? $url_data[$i] : $txt['who_hidden'];

			if ($member['id'] == 0 && !empty($member['robot_name']))
			{
				$robot_details = $robot->get_robot_info($member['robot_name']);
				if (!empty($robot_details))
				{
					$context['members'][$i] += [
						'id' => 0,
						'name' => $robot_details['title'],
						'group' => $txt['robots'],
						'href' => isset($robot_details['link']) && allowedTo('admin_forum') ? $robot_details['link'] : '',
						'link' => isset($robot_details['link']) && allowedTo('admin_forum') ? '<a href="' . $robot_details['link'] . '" target="_blank" rel="noopener">' . $robot_details['title'] . '</a>' : $robot_details['title'],
						'email' => $robot_details['title'],
						'is_guest' => true,
					];
					continue;
				}
			}
			elseif ($member['id'] == 0)
			{
				if (allowedTo('admin_forum'))
				{
					$context['members'][$i]['user_agent'] = StringLibrary::escape($member['user_agent'], ENT_QUOTES);
				}
			}

			$context['members'][$i] += $memberContext[$member['id']];

			if (!empty($member['id_character']) && !empty($context['members'][$i]['characters'][$member['id_character']]))
			{
				// Need to 'fix' a few things.
				$character = $context['members'][$i]['characters'][$member['id_character']];
				$context['members'][$i]['name'] = $character['character_name'];
				$context['members'][$i]['href'] .= ';area=characters;char=' . $member['id_character'];
			}
		}

		// Some people can't send personal messages...
		$context['can_send_pm'] = allowedTo('pm_send');

		// any profile fields disabled?
		$context['disabled_fields'] = isset($modSettings['disabled_profile_fields']) ? array_flip(explode(',', $modSettings['disabled_profile_fields'])) : [];

	}
}
