<?php

/**
 * Viewing the IP lookup for a user.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\App;
use StoryBB\Helper\IP;

/**
 * Gets the number of posts made from a particular IP
 *
 * @param string $where A query indicating which posts to count
 * @param array $where_vars The parameters for $where
 * @return int Count of messages matching the IP
 */
function list_getIPMessageCount($where, $where_vars = [])
{
	global $smcFunc;

	$request = $smcFunc['db']->query('', '
		SELECT COUNT(*) AS message_count
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE {query_see_board} AND ' . $where,
		$where_vars
	);
	list ($count) = $smcFunc['db']->fetch_row($request);
	$smcFunc['db']->free_result($request);

	return (int) $count;
}

/**
 * Gets all the posts made from a particular IP
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param string $where A query to filter which posts are returned
 * @param array $where_vars An array of parameters for $where
 * @return array An array containing information about the posts
 */
function list_getIPMessages($start, $items_per_page, $sort, $where, $where_vars = [])
{
	global $smcFunc, $scripturl;

	$url = App::container()->get('urlgenerator');

	// Get all the messages fitting this where clause.
	// @todo SLOW This query is using a filesort.
	$request = $smcFunc['db']->query('', '
		SELECT
			m.id_msg, m.poster_ip, COALESCE(mem.real_name, m.poster_name) AS display_name, mem.id_member,
			m.subject, m.poster_time, m.id_topic, m.id_board, b.slug AS board_slug
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE {query_see_board} AND ' . $where . '
		ORDER BY {raw:sort}
		LIMIT {int:start}, {int:max}',
		array_merge($where_vars, [
			'sort' => $sort,
			'start' => $start,
			'max' => $items_per_page,
		])
	);
	$messages = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
		$messages[] = [
			'ip' => IP::format($row['poster_ip']),
			'member_link' => empty($row['id_member']) ? $row['display_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['display_name'] . '</a>',
			'board' => [
				'id' => $row['id_board'],
				'href' => $url->generate('board', ['board_slug' => $row['board_slug']]),
			],
			'topic' => $row['id_topic'],
			'id' => $row['id_msg'],
			'subject' => $row['subject'],
			'time' => timeformat($row['poster_time']),
			'timestamp' => forum_time(true, $row['poster_time'])
		];
	$smcFunc['db']->free_result($request);

	return $messages;
}

/**
 * Handles tracking a particular IP address
 */
function TrackIP()
{
	global $user_profile, $scripturl, $txt, $user_info, $modSettings, $sourcedir;
	global $context, $smcFunc;

	// Can the user do this?
	isAllowedTo('moderate_forum');

	loadLanguage('Profile');

	$memID = 0;
	if (isset($_GET['u']))
	{
		if (loadMemberData((int) $_GET['u']))
		{
			$memID = (int) $_GET['u'];
		}
	}

	$context['base_url'] = $scripturl . '?action=admin;area=logs;sa=ip';

	if ($memID == 0)
	{
		$context['ip'] = $user_info['ip'];
	}
	else
	{
		$context['ip'] = $user_profile[$memID]['member_ip'];
		$context['base_url'] .= ';u=' . $memID;
	}

	// Searching?
	if (isset($_REQUEST['searchip']))
	{
		$context['ip'] = trim($_REQUEST['searchip']);
	}

	if (IP::is_valid($context['ip']) === false)
	{
		fatal_lang_error('invalid_tracking_ip', false);
	}

	//mysql didn't support like search with varbinary
	//$ip_var = str_replace('*', '%', $context['ip']);
	//$ip_string = strpos($ip_var, '%') === false ? '= {inet:ip_address}' : 'LIKE {string:ip_address}';
	$ip_var = $context['ip'];
	$ip_string = '= {inet:ip_address}';

	if (empty($context['tracking_area']))
		$context['page_title'] = $txt['trackIP'] . ' - ' . $context['ip'];

	$request = $smcFunc['db']->query('', '
		SELECT id_member, real_name AS display_name, member_ip
		FROM {db_prefix}members
		WHERE member_ip ' . $ip_string,
		[
			'ip_address' => $ip_var,
		]
	);
	$context['ips'] = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
		$context['ips'][IP::format($row['member_ip'])][] = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['display_name'] . '</a>';
	$smcFunc['db']->free_result($request);

	ksort($context['ips']);

	// For messages we use the "messages per page" option
	$maxPerPage = empty($modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : $modSettings['defaultMaxMessages'];

	// Gonna want this for the list.
	require_once($sourcedir . '/Subs-List.php');

	// Start with the user messages.
	$listOptions = [
		'id' => 'track_message_list',
		'title' => $txt['messages_from_ip'] . ' ' . $context['ip'],
		'start_var_name' => 'messageStart',
		'items_per_page' => $maxPerPage,
		'no_items_label' => $txt['no_messages_from_ip'],
		'base_href' => $context['base_url'] . ';searchip=' . $context['ip'],
		'default_sort_col' => 'date',
		'get_items' => [
			'function' => 'list_getIPMessages',
			'params' => [
				'm.poster_ip ' . $ip_string,
				['ip_address' => $ip_var],
			],
		],
		'get_count' => [
			'function' => 'list_getIPMessageCount',
			'params' => [
				'm.poster_ip ' . $ip_string,
				['ip_address' => $ip_var],
			],
		],
		'columns' => [
			'ip_address' => [
				'header' => [
					'value' => $txt['ip_address'],
				],
				'data' => [
					'sprintf' => [
						'format' => '<a href="' . $context['base_url'] . ';searchip=%1$s">%1$s</a>',
						'params' => [
							'ip' => false,
						],
					],
				],
				'sort' => [
					'default' => 'm.poster_ip',
					'reverse' => 'm.poster_ip DESC',
				],
			],
			'poster' => [
				'header' => [
					'value' => $txt['poster'],
				],
				'data' => [
					'db' => 'member_link',
				],
			],
			'subject' => [
				'header' => [
					'value' => $txt['subject'],
				],
				'data' => [
					'sprintf' => [
						'format' => '<a href="' . $scripturl . '?topic=%1$s.msg%2$s#msg%2$s" rel="nofollow">%3$s</a>',
						'params' => [
							'topic' => false,
							'id' => false,
							'subject' => false,
						],
					],
				],
			],
			'date' => [
				'header' => [
					'value' => $txt['date'],
				],
				'data' => [
					'db' => 'time',
				],
				'sort' => [
					'default' => 'm.id_msg DESC',
					'reverse' => 'm.id_msg',
				],
			],
		],
		'additional_rows' => [
			[
				'position' => 'after_title',
				'value' => $txt['messages_from_ip_desc'],
			],
		],
	];

	// Create the messages list.
	createList($listOptions);

	// Set the options for the error lists.
	$listOptions = [
		'id' => 'track_user_list',
		'title' => $txt['errors_from_ip'] . ' ' . $context['ip'],
		'start_var_name' => 'errorStart',
		'items_per_page' => $modSettings['defaultMaxListItems'],
		'no_items_label' => $txt['no_errors_from_ip'],
		'base_href' => $context['base_url'] . ';searchip=' . $context['ip'],
		'default_sort_col' => 'date2',
		'get_items' => [
			'function' => ['StoryBB\\Helper\\Profile', 'list_getUserErrors'],
			'params' => [
				'le.ip ' . $ip_string,
				['ip_address' => $ip_var],
			],
		],
		'get_count' => [
			'function' => ['StoryBB\\Helper\\Profile', 'list_getUserErrorCount'],
			'params' => [
				'ip ' . $ip_string,
				['ip_address' => $ip_var],
			],
		],
		'columns' => [
			'ip_address2' => [
				'header' => [
					'value' => $txt['ip_address'],
				],
				'data' => [
					'sprintf' => [
						'format' => '<a href="' . $context['base_url'] . ';searchip=%1$s">%1$s</a>',
						'params' => [
							'ip' => false,
						],
					],
				],
				'sort' => [
					'default' => 'le.ip',
					'reverse' => 'le.ip DESC',
				],
			],
			'display_name' => [
				'header' => [
					'value' => $txt['display_name'],
				],
				'data' => [
					'db' => 'member_link',
				],
			],
			'message' => [
				'header' => [
					'value' => $txt['message'],
				],
				'data' => [
					'sprintf' => [
						'format' => '%1$s<br><a href="%2$s">%2$s</a>',
						'params' => [
							'message' => false,
							'url' => false,
						],
					],
				],
			],
			'date2' => [
				'header' => [
					'value' => $txt['date'],
				],
				'data' => [
					'db' => 'time',
				],
				'sort' => [
					'default' => 'le.id_error DESC',
					'reverse' => 'le.id_error',
				],
			],
		],
		'additional_rows' => [
			[
				'position' => 'after_title',
				'value' => $txt['errors_from_ip_desc'],
			],
		],
	];

	// Create the error list.
	createList($listOptions);

	// Allow 3rd party integrations to add in their own lists or whatever.
	$context['additional_track_lists'] = [];
	call_integration_hook('integrate_profile_trackip', [$ip_string, $ip_var]);

	$context['single_ip'] = strpos($context['ip'], '*') === false;
	if ($context['single_ip'])
	{
		$context['whois_servers'] = [
			'apnic' => [
				'name' => $txt['whois_apnic'],
				'url' => 'https://wq.apnic.net/apnic-bin/whois.pl?searchtext=' . $context['ip'],
				'range' => [58, 59, 60, 61, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124,
					125, 126, 133, 150, 153, 163, 171, 202, 203, 210, 211, 218, 219, 220, 221, 222],
			],
			'arin' => [
				'name' => $txt['whois_arin'],
				'url' => 'https://whois.arin.net/rest/ip/' . $context['ip'],
				'range' => [7, 24, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 96, 97, 98, 99,
					128, 129, 130, 131, 132, 134, 135, 136, 137, 138, 139, 140, 142, 143, 144, 146, 147, 148, 149,
					152, 155, 156, 157, 158, 159, 160, 161, 162, 164, 165, 166, 167, 168, 169, 170, 172, 173, 174,
					192, 198, 199, 204, 205, 206, 207, 208, 209, 216],
			],
			'lacnic' => [
				'name' => $txt['whois_lacnic'],
				'url' => 'https://query.milacnic.lacnic.net/search?id=' . $context['ip'],
				'range' => [186, 187, 189, 190, 191, 200, 201],
			],
			'ripe' => [
				'name' => $txt['whois_ripe'],
				'url' => 'https://apps.db.ripe.net/db-web-ui/query?searchtext=' . $context['ip'],
				'range' => [62, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95,
					141, 145, 151, 188, 193, 194, 195, 212, 213, 217],
			],
		];

		foreach ($context['whois_servers'] as $whois)
		{
			// Strip off the "decimal point" and anything following...
			if (in_array((int) $context['ip'], $whois['range']))
				$context['auto_whois_server'] = $whois;
		}
	}

	$context['sub_template'] = 'profile_track_ip';
}
