<?php

/**
 * This is perhaps the most important and probably most accessed file in all
 * of StoryBB.  This file controls topic, message, and attachment display.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\Helper\IP;
use StoryBB\Helper\Parser;
use StoryBB\Helper\Verification;
use StoryBB\Model\Bookmark;
use StoryBB\Model\TopicCollection;
use StoryBB\Model\TopicPrefix;
use StoryBB\StringLibrary;

/**
 * The central part of the board - topic display.
 * This function loads the posts in a topic up so they can be displayed.
 * It uses the main sub template of the Display template.
 * It requires a topic, and can go to the previous or next topic from it.
 * It jumps to the correct post depending on a number/time/IS_MSG passed.
 * It depends on the messages_per_page, defaultMaxMessages and enableAllMessages settings.
 * It is accessed by ?topic=id_topic.START.
 * @return void
 */
function Display()
{
	global $scripturl, $txt, $modSettings, $context, $settings;
	global $options, $sourcedir, $user_info, $board_info, $topic, $board;
	global $messages_request, $language, $smcFunc;
	global $memberContext;

	// What are you gonna display if these are empty?!
	if (empty($topic))
		fatal_lang_error('no_board', false);

	// Not only does a prefetch make things slower for the server, but it makes it impossible to know if they read it.
	if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
	{
		ob_end_clean();
		header('HTTP/1.1 403 Prefetch Forbidden');
		die;
	}

	// How much are we sticking on each page?
	$context['messages_per_page'] = empty($modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : $modSettings['defaultMaxMessages'];

	// Let's do some work on what to search index.
	if (count($_GET) > 2)
		foreach (array_keys($_GET) as $k)
		{
			if (!in_array($k, ['topic', 'board', 'start', session_name()]))
				$context['robot_no_index'] = true;
		}

	if (!empty($_REQUEST['start']) && (!is_numeric($_REQUEST['start']) || $_REQUEST['start'] % $context['messages_per_page'] != 0))
		$context['robot_no_index'] = true;

	// Add 1 to the number of views of this topic (except for robots).
	if (!$user_info['possibly_robot'] && (empty($_SESSION['last_read_topic']) || $_SESSION['last_read_topic'] != $topic))
	{
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}topics
			SET num_views = num_views + 1
			WHERE id_topic = {int:current_topic}',
			[
				'current_topic' => $topic,
			]
		);

		$_SESSION['last_read_topic'] = $topic;
	}

	$topic_parameters = [
		'current_member' => $user_info['id'],
		'current_topic' => $topic,
		'current_board' => $board,
	];
	$topic_selects = [];
	$topic_tables = [];
	$context['topicinfo'] = [];
	call_integration_hook('integrate_display_topic', [&$topic_selects, &$topic_tables, &$topic_parameters]);

	// @todo Why isn't this cached?
	// @todo if we get id_board in this query and cache it, we can save a query on posting
	// Get all the important topic info.
	$request = $smcFunc['db']->query('', '
		SELECT
			t.num_replies, t.num_views, t.locked, t.finished, ms.subject, ms.body, ms.smileys_enabled, t.is_sticky, t.id_poll,
			t.id_member_started, t.id_first_msg, t.id_last_msg, t.approved, t.unapproved_posts, t.id_redirect_topic,
			COALESCE(mem.real_name, ms.poster_name) AS topic_started_name, ms.poster_time AS topic_started_time,
			IFNULL(chars.character_name, IFNULL(mem.real_name, ms.poster_name)) AS topic_started_name,
			' . ($user_info['is_guest'] ? 't.id_last_msg + 1' : 'COALESCE(lt.id_msg, lmr.id_msg, -1) + 1') . ' AS new_from
			' . (!empty($board_info['recycle']) ? ', id_previous_board, id_previous_topic' : '') . '
			' . (!empty($topic_selects) ? (', ' . implode(', ', $topic_selects)) : '') . '
			' . (!$user_info['is_guest'] ? ', COALESCE(lt.unwatched, 0) as unwatched' : '') . '
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)
			LEFT JOIN {db_prefix}members AS mem on (mem.id_member = ms.id_member)' . ($user_info['is_guest'] ? '' : '
			LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = {int:current_topic} AND lt.id_member = {int:current_member})
			LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = {int:current_board} AND lmr.id_member = {int:current_member})') . '
			LEFT JOIN {db_prefix}characters AS chars ON (chars.id_character = ms.id_character)
			' . (!empty($topic_tables) ? implode("\n\t", $topic_tables) : '') . '
		WHERE t.id_topic = {int:current_topic}
		LIMIT 1',
			$topic_parameters
	);

	if ($smcFunc['db']->num_rows($request) == 0)
		fatal_lang_error('not_a_topic', false, 404);
	$context['topicinfo'] = $smcFunc['db']->fetch_assoc($request);
	$smcFunc['db']->free_result($request);

	$context['topicinfo']['prefixes'] = TopicPrefix::get_prefixes_for_topic((int) $topic);

	// Is this a moved or merged topic that we are redirecting to?
	if (!empty($context['topicinfo']['id_redirect_topic']))
	{
		// Mark this as read...
		if (!$user_info['is_guest'] && $context['topicinfo']['new_from'] != $context['topicinfo']['id_first_msg'])
		{
			// Mark this as read first
			$smcFunc['db']->insert($context['topicinfo']['new_from'] == 0 ? 'ignore' : 'replace',
				'{db_prefix}log_topics',
				[
					'id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'unwatched' => 'int',
				],
				[
					$user_info['id'], $topic, $context['topicinfo']['id_first_msg'], $context['topicinfo']['unwatched'],
				],
				['id_member', 'id_topic']
			);
		}
		redirectexit('topic=' . $context['topicinfo']['id_redirect_topic'] . '.0', true);
	}

	// Short-cut to know if this user can see unapproved messages.
	$approve_posts = (allowedTo('approve_posts') || $context['topicinfo']['id_member_started'] == $user_info['id']);

	$context['real_num_replies'] = $context['num_replies'] = $context['topicinfo']['num_replies'];
	$context['topic_started_time'] = timeformat($context['topicinfo']['topic_started_time']);
	$context['topic_started_timestamp'] = $context['topicinfo']['topic_started_time'];
	$context['topic_poster_name'] = $context['topicinfo']['topic_started_name'];
	$context['topic_first_message'] = $context['topicinfo']['id_first_msg'];
	$context['topic_last_message'] = $context['topicinfo']['id_last_msg'];
	$context['topic_unwatched'] = isset($context['topicinfo']['unwatched']) ? $context['topicinfo']['unwatched'] : 0;

	// Add up unapproved replies to get real number of replies...
	if ($approve_posts)
		$context['real_num_replies'] += $context['topicinfo']['unapproved_posts'] - ($context['topicinfo']['approved'] ? 0 : 1);

	// If this topic has unapproved posts, we need to work out how many posts the user can see, for page indexing.
	if ($context['topicinfo']['unapproved_posts'] && !$user_info['is_guest'] && !$approve_posts)
	{
		$request = $smcFunc['db']->query('', '
			SELECT COUNT(id_member) AS my_unapproved_posts
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}
				AND id_member = {int:current_member}
				AND approved = 0',
			[
				'current_topic' => $topic,
				'current_member' => $user_info['id'],
			]
		);
		list ($myUnapprovedPosts) = $smcFunc['db']->fetch_row($request);
		$smcFunc['db']->free_result($request);

		$context['total_visible_posts'] = $context['num_replies'] + $myUnapprovedPosts + ($context['topicinfo']['approved'] ? 1 : 0);
	}
	elseif ($user_info['is_guest'])
		$context['total_visible_posts'] = $context['num_replies'] + ($context['topicinfo']['approved'] ? 1 : 0);
	else
		$context['total_visible_posts'] = $context['num_replies'] + $context['topicinfo']['unapproved_posts'] + ($context['topicinfo']['approved'] ? 1 : 0);

	// The start isn't a number; it's information about what to do, where to go.
	if (!is_numeric($_REQUEST['start']))
	{
		// Redirect to the page and post with new messages, originally by Omar Bazavilvazo.
		if ($_REQUEST['start'] == 'new')
		{
			// Guests automatically go to the last post.
			if ($user_info['is_guest'])
			{
				$context['start_from'] = $context['total_visible_posts'] - 1;
				$_REQUEST['start'] = empty($options['view_newest_first']) ? $context['start_from'] : 0;
			}
			else
			{
				// Find the earliest unread message in the topic. (the use of topics here is just for both tables.)
				$request = $smcFunc['db']->query('', '
					SELECT COALESCE(lt.id_msg, lmr.id_msg, -1) + 1 AS new_from
					FROM {db_prefix}topics AS t
						LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = {int:current_topic} AND lt.id_member = {int:current_member})
						LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = {int:current_board} AND lmr.id_member = {int:current_member})
					WHERE t.id_topic = {int:current_topic}
					LIMIT 1',
					[
						'current_board' => $board,
						'current_member' => $user_info['id'],
						'current_topic' => $topic,
					]
				);
				list ($new_from) = $smcFunc['db']->fetch_row($request);
				$smcFunc['db']->free_result($request);

				// Fall through to the next if statement.
				$_REQUEST['start'] = 'msg' . $new_from;
			}
		}

		// Link to a message...
		if (substr($_REQUEST['start'], 0, 3) == 'msg')
		{
			$virtual_msg = (int) substr($_REQUEST['start'], 3);
			if (!$context['topicinfo']['unapproved_posts'] && $virtual_msg >= $context['topicinfo']['id_last_msg'])
				$context['start_from'] = $context['total_visible_posts'] - 1;
			elseif (!$context['topicinfo']['unapproved_posts'] && $virtual_msg <= $context['topicinfo']['id_first_msg'])
				$context['start_from'] = 0;
			else
			{
				// Find the start value for that message......
				$request = $smcFunc['db']->query('', '
					SELECT COUNT(*)
					FROM {db_prefix}messages
					WHERE id_msg < {int:virtual_msg}
						AND id_topic = {int:current_topic}' . ($context['topicinfo']['unapproved_posts'] && !allowedTo('approve_posts') ? '
						AND (approved = {int:is_approved}' . ($user_info['is_guest'] ? '' : ' OR id_member = {int:current_member}') . ')' : ''),
					[
						'current_member' => $user_info['id'],
						'current_topic' => $topic,
						'virtual_msg' => $virtual_msg,
						'is_approved' => 1,
						'no_member' => 0,
					]
				);
				list ($context['start_from']) = $smcFunc['db']->fetch_row($request);
				$smcFunc['db']->free_result($request);
			}

			// We need to reverse the start as well in this case.
			$_REQUEST['start'] = empty($options['view_newest_first']) ? $context['start_from'] : $context['total_visible_posts'] - $context['start_from'] - 1;
		}
	}

	// Do we need to show the visual verification image?
	$context['require_verification'] = !$user_info['is_mod'] && !$user_info['is_admin'] && !empty($modSettings['posts_require_captcha']) && ($user_info['posts'] < $modSettings['posts_require_captcha'] || ($user_info['is_guest'] && $modSettings['posts_require_captcha'] == -1));
	if ($context['require_verification'])
	{
		$context['visual_verification'] = Verification::get('post')->id();
	}

	// Are we showing signatures - or disabled fields?
	$context['signature_enabled'] = substr($modSettings['signature_settings'], 0, 1) == 1;
	$context['disabled_fields'] = isset($modSettings['disabled_profile_fields']) ? array_flip(explode(',', $modSettings['disabled_profile_fields'])) : [];

	// Censor the title...
	censorText($context['topicinfo']['subject']);
	$context['page_title'] = $context['topicinfo']['subject'];

	censorText($context['topicinfo']['body']);
	$context['topicinfo']['body'] = Parser::parse_bbc($context['topicinfo']['body'], $context['topicinfo']['smileys_enabled'], $context['topicinfo']['id_first_msg']);
	$context['meta_description'] = preg_replace('/<style.*?<\/style>/iu', '', $context['topicinfo']['body']);
	$context['meta_description'] = preg_replace('/<br ?\/?>/i', "\n", $context['meta_description']);
	$context['meta_description'] = shorten_subject(trim(preg_replace('/\n{3,}/i', "\n\n", strip_tags($context['meta_description']))), 500);

	// Default this topic to not marked for notifications... of course...
	$context['is_marked_notify'] = false;

	// Let's get nosey, who is viewing this topic?
	if (!empty($settings['display_who_viewing']))
	{
		// Start out with no one at all viewing it.
		$context['view_members'] = [];
		$context['view_members_list'] = [];
		$context['view_num_hidden'] = 0;

		// Search for members who have this topic set in their GET data.
		$request = $smcFunc['db']->query('', '
			SELECT
				lo.id_member, lo.log_time, chars.id_character, IFNULL(chars.character_name, mem.real_name) AS real_name, mem.member_name, mem.show_online,
				IF(chars.is_main, mg.online_color, cg.online_color) AS online_color, mg.id_group, mg.group_name
			FROM {db_prefix}log_online AS lo
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lo.id_member)
				LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = mem.id_group)
				LEFT JOIN {db_prefix}characters AS chars ON (lo.id_character = chars.id_character)
				LEFT JOIN {db_prefix}membergroups AS cg ON (cg.id_group = chars.main_char_group)
			WHERE INSTR(lo.url, {string:in_url_string}) > 0 OR lo.session = {string:session}',
			[
				'in_url_string' => '"topic":' . $topic,
				'session' => $user_info['is_guest'] ? 'ip' . $user_info['ip'] : session_id(),
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (empty($row['id_member']))
				continue;

			if (!empty($row['online_color']))
				$link = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . (!empty($row['id_character']) ? ';area=characters;char=' . $row['id_character'] : '') . '" style="color: ' . $row['online_color'] . ';">' . $row['real_name'] . '</a>';
			else
				$link = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . (!empty($row['id_character']) ? ';area=characters;char=' . $row['id_character'] : '') . '">' . $row['real_name'] . '</a>';

			$is_buddy = in_array($row['id_member'], $user_info['buddies']);
			if ($is_buddy)
				$link = '<strong>' . $link . '</strong>';

			// Add them both to the list and to the more detailed list.
			if (!empty($row['show_online']) || allowedTo('moderate_forum'))
				$context['view_members_list'][$row['log_time'] . $row['member_name']] = empty($row['show_online']) ? '<em>' . $link . '</em>' : $link;
			$context['view_members'][$row['log_time'] . $row['member_name']] = [
				'id' => $row['id_member'],
				'username' => $row['member_name'],
				'name' => $row['real_name'],
				'group' => $row['id_group'],
				'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
				'link' => $link,
				'is_buddy' => $is_buddy,
				'hidden' => empty($row['show_online']),
			];

			if (empty($row['show_online']))
				$context['view_num_hidden']++;
		}

		// The number of guests is equal to the rows minus the ones we actually used ;).
		$context['view_num_guests'] = $smcFunc['db']->num_rows($request) - count($context['view_members']);
		$smcFunc['db']->free_result($request);

		// Sort the list.
		krsort($context['view_members']);
		krsort($context['view_members_list']);
	}

	// If all is set, but not allowed... just unset it.
	$can_show_all = !empty($modSettings['enableAllMessages']) && $context['total_visible_posts'] > $context['messages_per_page'] && $context['total_visible_posts'] < $modSettings['enableAllMessages'];
	if (isset($_REQUEST['all']) && !$can_show_all)
		unset($_REQUEST['all']);
	// Otherwise, it must be allowed... so pretend start was -1.
	elseif (isset($_REQUEST['all']))
		$_REQUEST['start'] = -1;

	// Construct the page index, allowing for the .START method...
	$context['page_index'] = constructPageIndex($scripturl . '?topic=' . $topic . '.%1$d', $_REQUEST['start'], $context['total_visible_posts'], $context['messages_per_page'], true);
	$context['start'] = $_REQUEST['start'];

	// This is information about which page is current, and which page we're on - in case you don't like the constructed page index. (again, wireles..)
	$context['page_info'] = [
		'current_page' => $_REQUEST['start'] / $context['messages_per_page'] + 1,
		'num_pages' => floor(($context['total_visible_posts'] - 1) / $context['messages_per_page']) + 1,
	];

	// Figure out all the link to the next/prev/first/last/etc.
	if (!($can_show_all && isset($_REQUEST['all'])))
	{
		$context['links'] = [
			'first' => $_REQUEST['start'] >= $context['messages_per_page'] ? $scripturl . '?topic=' . $topic . '.0' : '',
			'last' => $_REQUEST['start'] + $context['messages_per_page'] < $context['total_visible_posts'] ? $scripturl . '?topic=' . $topic . '.' . (floor($context['total_visible_posts'] / $context['messages_per_page']) * $context['messages_per_page']) : '',
			'up' => $board_info['url'],
		];
	}

	// If they are viewing all the posts, show all the posts, otherwise limit the number.
	if ($can_show_all)
	{
		if (isset($_REQUEST['all']))
		{
			// No limit! (actually, there is a limit, but...)
			$context['messages_per_page'] = -1;
			$context['page_index'] .= '[<strong>' . $txt['all'] . '</strong>] ';

			// Set start back to 0...
			$_REQUEST['start'] = 0;
		}
		// They aren't using it, but the *option* is there, at least.
		else
			$context['page_index'] .= '&nbsp;<a href="' . $scripturl . '?topic=' . $topic . '.0;all">' . $txt['all'] . '</a> ';
	}

	// Build the link tree.
	$context['linktree'][] = [
		'url' => $scripturl . '?topic=' . $topic . '.0',
		'name' => $context['topicinfo']['subject'],
	];

	// Build a list of this board's moderators.
	$context['moderators'] = &$board_info['moderators'];
	$context['moderator_groups'] = &$board_info['moderator_groups'];
	$context['link_moderators'] = [];
	if (!empty($board_info['moderators']))
	{
		// Add a link for each moderator...
		foreach ($board_info['moderators'] as $mod)
			$context['link_moderators'][] = '<a href="' . $scripturl . '?action=profile;u=' . $mod['id'] . '" title="' . $txt['board_moderator'] . '">' . $mod['name'] . '</a>';
	}
	if (!empty($board_info['moderator_groups']))
	{
		// Add a link for each moderator group as well...
		foreach ($board_info['moderator_groups'] as $mod_group)
			$context['link_moderators'][] = '<a href="' . $scripturl . '?action=groups;sa=viewmemberes;group=' . $mod_group['id'] . '" title="' . $txt['board_moderator'] . '">' . $mod_group['name'] . '</a>';
	}

	if (!empty($context['link_moderators']))
	{
		// And show it after the board's name.
		$context['linktree'][count($context['linktree']) - 2]['extra_after'] = '<span class="board_moderators">(' . (count($context['link_moderators']) == 1 ? $txt['moderator'] : $txt['moderators']) . ': ' . implode(', ', $context['link_moderators']) . ')</span>';
	}

	// Information about the current topic...
	$context['is_locked'] = (bool) $context['topicinfo']['locked'];
	$context['is_sticky'] = (bool) $context['topicinfo']['is_sticky'];
	$context['is_finished'] = (bool) $context['topicinfo']['finished'];
	$context['is_approved'] = $context['topicinfo']['approved'];
	$context['is_poll'] = $context['topicinfo']['id_poll'] > 0 && $modSettings['pollMode'] == '1' && allowedTo('poll_view');

	// Did this user start the topic or not?
	$context['user']['started'] = $user_info['id'] == $context['topicinfo']['id_member_started'] && !$user_info['is_guest'];
	$context['topic_starter_id'] = $context['topicinfo']['id_member_started'];

	// Set the topic's information for the template.
	$context['subject'] = $context['topicinfo']['subject'];
	$context['num_views'] = comma_format($context['topicinfo']['num_views']);
	$context['num_views_text'] = numeric_context('read_times', $context['topicinfo']['num_views']);
	$context['mark_unread_time'] = !empty($virtual_msg) ? $virtual_msg : $context['topicinfo']['new_from'];

	if ($context['user']['is_guest'])
	{
		$context['bookmarked'] = false;
		$context['can_bookmark'] = false;
	}
	else
	{
		$context['bookmarked'] = Bookmark::is_bookmarked((int) $context['user']['id'], $topic);
		$context['can_bookmark'] = true;
	}

	// Set a canonical URL for this page.
	$context['canonical_url'] = $scripturl . '?topic=' . $topic . '.' . ($can_show_all ? '0;all' : $context['start']);

	// For quick reply we need a response prefix in the default forum language.
	if (!isset($context['response_prefix']) && !($context['response_prefix'] = cache_get_data('response_prefix', 600)))
	{
		if ($language === $user_info['language'])
			$context['response_prefix'] = $txt['response_prefix'];
		else
		{
			loadLanguage('General', $language, false);
			$context['response_prefix'] = $txt['response_prefix'];
			loadLanguage('General');
		}
		cache_put_data('response_prefix', $context['response_prefix'], 600);
	}

	// Create the poll info if it exists.
	if ($context['is_poll'])
	{
		// Get the question and if it's locked.
		$request = $smcFunc['db']->query('', '
			SELECT
				p.question, p.voting_locked, p.hide_results, p.expire_time, p.max_votes, p.change_vote,
				p.guest_vote, p.id_member, COALESCE(mem.real_name, p.poster_name) AS poster_name, p.num_guest_voters, p.reset_poll
			FROM {db_prefix}polls AS p
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = p.id_member)
			WHERE p.id_poll = {int:id_poll}
			LIMIT 1',
			[
				'id_poll' => $context['topicinfo']['id_poll'],
			]
		);
		$pollinfo = $smcFunc['db']->fetch_assoc($request);
		$smcFunc['db']->free_result($request);

		$request = $smcFunc['db']->query('', '
			SELECT COUNT(DISTINCT id_member) AS total
			FROM {db_prefix}log_polls
			WHERE id_poll = {int:id_poll}
				AND id_member != {int:not_guest}',
			[
				'id_poll' => $context['topicinfo']['id_poll'],
				'not_guest' => 0,
			]
		);
		list ($pollinfo['total']) = $smcFunc['db']->fetch_row($request);
		$smcFunc['db']->free_result($request);

		// Total voters needs to include guest voters
		$pollinfo['total'] += $pollinfo['num_guest_voters'];

		// Get all the options, and calculate the total votes.
		$request = $smcFunc['db']->query('', '
			SELECT pc.id_choice, pc.label, pc.votes, COALESCE(lp.id_choice, -1) AS voted_this
			FROM {db_prefix}poll_choices AS pc
				LEFT JOIN {db_prefix}log_polls AS lp ON (lp.id_choice = pc.id_choice AND lp.id_poll = {int:id_poll} AND lp.id_member = {int:current_member} AND lp.id_member != {int:not_guest})
			WHERE pc.id_poll = {int:id_poll}',
			[
				'current_member' => $user_info['id'],
				'id_poll' => $context['topicinfo']['id_poll'],
				'not_guest' => 0,
			]
		);
		$pollOptions = [];
		$realtotal = 0;
		$pollinfo['has_voted'] = false;
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			censorText($row['label']);
			$pollOptions[$row['id_choice']] = $row;
			$realtotal += $row['votes'];
			$pollinfo['has_voted'] |= $row['voted_this'] != -1;
		}
		$smcFunc['db']->free_result($request);
		
		// Got we multi choice?
		if ($pollinfo['max_votes'] > 1)
			$realtotal = $pollinfo['total'];

		// If this is a guest we need to do our best to work out if they have voted, and what they voted for.
		if ($user_info['is_guest'] && $pollinfo['guest_vote'] && allowedTo('poll_vote'))
		{
			if (!empty($_COOKIE['guest_poll_vote']) && preg_match('~^[0-9,;]+$~', $_COOKIE['guest_poll_vote']) && strpos($_COOKIE['guest_poll_vote'], ';' . $context['topicinfo']['id_poll'] . ',') !== false)
			{
				// ;id,timestamp,[vote,vote...]; etc
				$guestinfo = explode(';', $_COOKIE['guest_poll_vote']);
				// Find the poll we're after.
				foreach ($guestinfo as $i => $guestvoted)
				{
					$guestvoted = explode(',', $guestvoted);
					if ($guestvoted[0] == $context['topicinfo']['id_poll'])
						break;
				}
				// Has the poll been reset since guest voted?
				if ($pollinfo['reset_poll'] > $guestvoted[1])
				{
					// Remove the poll info from the cookie to allow guest to vote again
					unset($guestinfo[$i]);
					if (!empty($guestinfo))
						$_COOKIE['guest_poll_vote'] = ';' . implode(';', $guestinfo);
					else
						unset($_COOKIE['guest_poll_vote']);
				}
				else
				{
					// What did they vote for?
					unset($guestvoted[0], $guestvoted[1]);
					foreach ($pollOptions as $choice => $details)
					{
						$pollOptions[$choice]['voted_this'] = in_array($choice, $guestvoted) ? 1 : -1;
						$pollinfo['has_voted'] |= $pollOptions[$choice]['voted_this'] != -1;
					}
					unset($choice, $details, $guestvoted);
				}
				unset($guestinfo, $guestvoted, $i);
			}
		}

		// Set up the basic poll information.
		$context['poll'] = [
			'id' => $context['topicinfo']['id_poll'],
			'image' => 'normal_' . (empty($pollinfo['voting_locked']) ? 'poll' : 'locked_poll'),
			'question' => Parser::parse_bbc($pollinfo['question']),
			'total_votes' => $pollinfo['total'],
			'change_vote' => !empty($pollinfo['change_vote']),
			'is_locked' => !empty($pollinfo['voting_locked']),
			'options' => [],
			'lock' => allowedTo('poll_lock_any') || ($context['user']['started'] && allowedTo('poll_lock_own')),
			'edit' => allowedTo('poll_edit_any') || ($context['user']['started'] && allowedTo('poll_edit_own')),
			'remove' => allowedTo('poll_remove_any') || ($context['user']['started'] && allowedTo('poll_remove_own')),
			'allowed_warning' => $pollinfo['max_votes'] > 1 ? sprintf($txt['poll_options6'], min(count($pollOptions), $pollinfo['max_votes'])) : '',
			'is_expired' => !empty($pollinfo['expire_time']) && $pollinfo['expire_time'] < time(),
			'expire_time' => !empty($pollinfo['expire_time']) ? timeformat($pollinfo['expire_time']) : 0,
			'has_voted' => !empty($pollinfo['has_voted']),
			'starter' => [
				'id' => $pollinfo['id_member'],
				'name' => $row['poster_name'],
				'href' => $pollinfo['id_member'] == 0 ? '' : $scripturl . '?action=profile;u=' . $pollinfo['id_member'],
				'link' => $pollinfo['id_member'] == 0 ? $row['poster_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $pollinfo['id_member'] . '">' . $row['poster_name'] . '</a>'
			]
		];

		// Make the lock, edit and remove permissions defined above more directly accessible.
		$context['allow_lock_poll'] = $context['poll']['lock'];
		$context['allow_edit_poll'] = $context['poll']['edit'];
		$context['can_remove_poll'] = $context['poll']['remove'];

		// You're allowed to vote if:
		// 1. the poll did not expire, and
		// 2. you're either not a guest OR guest voting is enabled... and
		// 3. you're not trying to view the results, and
		// 4. the poll is not locked, and
		// 5. you have the proper permissions, and
		// 6. you haven't already voted before.
		$context['allow_vote'] = !$context['poll']['is_expired'] && (!$user_info['is_guest'] || ($pollinfo['guest_vote'] && allowedTo('poll_vote'))) && empty($pollinfo['voting_locked']) && allowedTo('poll_vote') && !$context['poll']['has_voted'];

		// You're allowed to view the results if:
		// 1. you're just a super-nice-guy, or
		// 2. anyone can see them (hide_results == 0), or
		// 3. you can see them after you voted (hide_results == 1), or
		// 4. you've waited long enough for the poll to expire. (whether hide_results is 1 or 2.)
		$context['allow_results_view'] = allowedTo('moderate_board') || $pollinfo['hide_results'] == 0 || ($pollinfo['hide_results'] == 1 && $context['poll']['has_voted']) || $context['poll']['is_expired'];

		// Show the results if:
		// 1. You're allowed to see them (see above), and
		// 2. $_REQUEST['viewresults'] or $_REQUEST['viewResults'] is set
		$context['poll']['show_results'] = $context['allow_results_view'] && (isset($_REQUEST['viewresults']) || isset($_REQUEST['viewResults']));

		// Show the button if:
		// 1. You can vote in the poll (see above), and
		// 2. Results are visible to everyone (hidden = 0), and
		// 3. You aren't already viewing the results
		$context['show_view_results_button'] = $context['allow_vote'] && $context['allow_results_view'] && !$context['poll']['show_results'];

		// You're allowed to change your vote if:
		// 1. the poll did not expire, and
		// 2. you're not a guest... and
		// 3. the poll is not locked, and
		// 4. you have the proper permissions, and
		// 5. you have already voted, and
		// 6. the poll creator has said you can!
		$context['allow_change_vote'] = !$context['poll']['is_expired'] && !$user_info['is_guest'] && empty($pollinfo['voting_locked']) && allowedTo('poll_vote') && $context['poll']['has_voted'] && $context['poll']['change_vote'];

		// You're allowed to return to voting options if:
		// 1. you are (still) allowed to vote.
		// 2. you are currently seeing the results.
		$context['allow_return_vote'] = $context['allow_vote'] && $context['poll']['show_results'];

		// Calculate the percentages and bar lengths...
		$divisor = $realtotal == 0 ? 1 : $realtotal;

		// Determine if a decimal point is needed in order for the options to add to 100%.
		$precision = $realtotal == 100 ? 0 : 1;

		// Now look through each option, and...
		foreach ($pollOptions as $i => $option)
		{
			// First calculate the percentage, and then the width of the bar...
			$bar = round(($option['votes'] * 100) / $divisor, $precision);
			$barWide = $bar == 0 ? 1 : floor(($bar * 8) / 3);

			// Now add it to the poll's contextual theme data.
			$context['poll']['options'][$i] = [
				'id' => 'options-' . $i,
				'percent' => $bar,
				'votes' => $option['votes'],
				'voted_this' => $option['voted_this'] != -1,
				'bar_ndt' => $bar > 0 ? '<div class="bar" style="width: ' . $bar . '%;"></div>' : '',
				'bar_width' => $barWide,
				'option' => Parser::parse_bbc($option['label']),
				'vote_button' => '<input type="' . ($pollinfo['max_votes'] > 1 ? 'checkbox' : 'radio') . '" name="options[]" id="options-' . $i . '" value="' . $i . '">'
			];
		}

		// Build the poll moderation button array.
		$context['poll_buttons'] = [];

		if ($context['allow_return_vote'])
			$context['poll_buttons']['vote'] = ['text' => 'poll_return_vote', 'image' => 'poll_options.png', 'url' => $scripturl . '?topic=' . $context['current_topic'] . '.' . $context['start']];

		if ($context['show_view_results_button'])
			$context['poll_buttons']['results'] = ['text' => 'poll_results', 'image' => 'poll_results.png', 'url' => $scripturl . '?topic=' . $context['current_topic'] . '.' . $context['start'] . ';viewresults'];

		if ($context['allow_change_vote'])
			$context['poll_buttons']['change_vote'] = ['text' => 'poll_change_vote', 'image' => 'poll_change_vote.png', 'url' => $scripturl . '?action=vote;topic=' . $context['current_topic'] . '.' . $context['start'] . ';poll=' . $context['poll']['id'] . ';' . $context['session_var'] . '=' . $context['session_id']];

		if ($context['allow_lock_poll'])
			$context['poll_buttons']['lock'] = ['text' => (!$context['poll']['is_locked'] ? 'poll_lock' : 'poll_unlock'), 'image' => 'poll_lock.png', 'url' => $scripturl . '?action=lockvoting;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

		if ($context['allow_edit_poll'])
			$context['poll_buttons']['edit'] = ['text' => 'poll_edit', 'image' => 'poll_edit.png', 'url' => $scripturl . '?action=editpoll;topic=' . $context['current_topic'] . '.' . $context['start']];

		if ($context['can_remove_poll'])
			$context['poll_buttons']['remove_poll'] = ['text' => 'poll_remove', 'image' => 'admin_remove_poll.png', 'custom' => 'data-confirm="' . $txt['poll_remove_warn'] . '"', 'class' => 'you_sure', 'url' => $scripturl . '?action=removepoll;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

		// Allow mods to add additional buttons here
		call_integration_hook('integrate_poll_buttons');
	}

	// Calculate the fastest way to get the messages!
	$ascending = empty($options['view_newest_first']);
	$start = $_REQUEST['start'];
	$limit = $context['messages_per_page'];
	$firstIndex = 0;
	if ($start >= $context['total_visible_posts'] / 2 && $context['messages_per_page'] != -1)
	{
		$ascending = !$ascending;
		$limit = $context['total_visible_posts'] <= $start + $limit ? $context['total_visible_posts'] - $start : $limit;
		$start = $context['total_visible_posts'] <= $start + $limit ? 0 : $context['total_visible_posts'] - $start - $limit;
		$firstIndex = $limit - 1;
	}

	// Get each post and poster in this topic.
	$request = $smcFunc['db']->query('', '
		SELECT id_msg, id_member, approved
		FROM {db_prefix}messages
		WHERE id_topic = {int:current_topic}' . ($approve_posts ? '' : '
		AND (approved = {int:is_approved}' . ($user_info['is_guest'] ? '' : ' OR id_member = {int:current_member}') . ')') . '
		ORDER BY id_msg ' . ($ascending ? '' : 'DESC') . ($context['messages_per_page'] == -1 ? '' : '
		LIMIT {int:start}, {int:max}'),
		[
			'current_member' => $user_info['id'],
			'current_topic' => $topic,
			'is_approved' => 1,
			'blank_id_member' => 0,
			'start' => $start,
			'max' => $limit,
		]
	);

	$messages = [];
	$all_posters = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		if (!empty($row['id_member']))
			$all_posters[$row['id_msg']] = $row['id_member'];
		$messages[] = $row['id_msg'];
	}
	$smcFunc['db']->free_result($request);
	$posters = array_unique($all_posters);
	if (!$user_info['is_guest'] && !in_array($context['user']['id'], $posters))
		$posters[] = $context['user']['id'];

	call_integration_hook('integrate_display_message_list', [&$messages, &$posters]);

	// Guests can't mark topics read or for notifications, just can't sorry.
	if (!$user_info['is_guest'] && !empty($messages))
	{
		// Additionally, if the current user has some alerts, try to mark any of these ones read.
		if (!empty($user_info['alerts']))
		{
			// Clear any pending alerts.
			$alerted = StoryBB\Model\Alert::find_alerts([
				'content_type' => 'msg',
				'content_id' => $messages,
				'id_member' => $context['user']['id'],
				'is_read' => 0
			]);
			if (!empty($alerted))
			{
				foreach ($alerted as $memID => $alerts)
				{
					StoryBB\Model\Alert::change_read($memID, $alerts, 1);
				}
			}
		}
		$mark_at_msg = max($messages);
		if ($mark_at_msg >= $context['topicinfo']['id_last_msg'])
			$mark_at_msg = $modSettings['maxMsgID'];
		if ($mark_at_msg >= $context['topicinfo']['new_from'])
		{
			$smcFunc['db']->insert($context['topicinfo']['new_from'] == 0 ? 'ignore' : 'replace',
				'{db_prefix}log_topics',
				[
					'id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'unwatched' => 'int',
				],
				[
					$user_info['id'], $topic, $mark_at_msg, $context['topicinfo']['unwatched'],
				],
				['id_member', 'id_topic']
			);
		}

		// Check for notifications on this topic OR board.
		$request = $smcFunc['db']->query('', '
			SELECT sent, id_topic
			FROM {db_prefix}log_notify
			WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
				AND id_member = {int:current_member}
			LIMIT 2',
			[
				'current_board' => $board,
				'current_member' => $user_info['id'],
				'current_topic' => $topic,
			]
		);
		$do_once = true;
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			// Find if this topic is marked for notification...
			if (!empty($row['id_topic']))
				$context['is_marked_notify'] = true;

			// Only do this once, but mark the notifications as "not sent yet" for next time.
			if (!empty($row['sent']) && $do_once)
			{
				$smcFunc['db']->query('', '
					UPDATE {db_prefix}log_notify
					SET sent = {int:is_not_sent}
					WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
						AND id_member = {int:current_member}',
					[
						'current_board' => $board,
						'current_member' => $user_info['id'],
						'current_topic' => $topic,
						'is_not_sent' => 0,
					]
				);
				$do_once = false;
			}
		}

		// Have we recently cached the number of new topics in this board, and it's still a lot?
		if (isset($_REQUEST['topicseen']) && isset($_SESSION['topicseen_cache'][$board]) && $_SESSION['topicseen_cache'][$board] > 5)
			$_SESSION['topicseen_cache'][$board]--;
		// Mark board as seen if this is the only new topic.
		elseif (isset($_REQUEST['topicseen']))
		{
			// Use the mark read tables... and the last visit to figure out if this should be read or not.
			$request = $smcFunc['db']->query('', '
				SELECT COUNT(*)
				FROM {db_prefix}topics AS t
					LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = {int:current_board} AND lb.id_member = {int:current_member})
					LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
				WHERE t.id_board = {int:current_board}
					AND t.id_last_msg > COALESCE(lb.id_msg, 0)
					AND t.id_last_msg > COALESCE(lt.id_msg, 0)' . (empty($_SESSION['id_msg_last_visit']) ? '' : '
					AND t.id_last_msg > {int:id_msg_last_visit}'),
				[
					'current_board' => $board,
					'current_member' => $user_info['id'],
					'id_msg_last_visit' => (int) $_SESSION['id_msg_last_visit'],
				]
			);
			list ($numNewTopics) = $smcFunc['db']->fetch_row($request);
			$smcFunc['db']->free_result($request);

			// If there're no real new topics in this board, mark the board as seen.
			if (empty($numNewTopics))
				$_REQUEST['boardseen'] = true;
			else
				$_SESSION['topicseen_cache'][$board] = $numNewTopics;
		}
		// Probably one less topic - maybe not, but even if we decrease this too fast it will only make us look more often.
		elseif (isset($_SESSION['topicseen_cache'][$board]))
			$_SESSION['topicseen_cache'][$board]--;

		// Mark board as seen if we came using last post link from BoardIndex. (or other places...)
		if (isset($_REQUEST['boardseen']))
		{
			$smcFunc['db']->insert('replace',
				'{db_prefix}log_boards',
				['id_msg' => 'int', 'id_member' => 'int', 'id_board' => 'int'],
				[$modSettings['maxMsgID'], $user_info['id'], $board],
				['id_member', 'id_board']
			);
		}
	}

	// Get notification preferences
	$context['topicinfo']['notify_prefs'] = [];
	if (!empty($user_info['id']))
	{
		require_once($sourcedir . '/Subs-Notify.php');
		$prefs = getNotifyPrefs($user_info['id'], ['topic_notify', 'topic_notify_' . $context['current_topic']], true);
		$pref = !empty($prefs[$user_info['id']]) && $context['is_marked_notify'] ? $prefs[$user_info['id']] : [];
		$context['topicinfo']['notify_prefs'] = [
			'is_custom' => isset($pref['topic_notify_' . $topic]),
			'pref' => isset($pref['topic_notify_' . $context['current_topic']]) ? $pref['topic_notify_' . $context['current_topic']] : (!empty($pref['topic_notify']) ? $pref['topic_notify'] : 0),
		];
	}

	$context['topic_notification'] = !empty($user_info['id']) ? $context['topicinfo']['notify_prefs'] : [];
	// 0 => unwatched, 1 => normal, 2 => receive alerts, 3 => receive emails
	$context['topic_notification_mode'] = !$user_info['is_guest'] ? ($context['topic_unwatched'] ? 0 : ($context['topicinfo']['notify_prefs']['pref'] & 0x02 ? 3 : ($context['topicinfo']['notify_prefs']['pref'] & 0x01 ? 2 : 1))) : 0;

	$context['loaded_attachments'] = [];

	// If there _are_ messages here... (probably an error otherwise :!)
	if (!empty($messages))
	{
		// Fetch attachments.
		if (!empty($modSettings['attachmentEnable']) && allowedTo('view_attachments'))
		{
			$request = $smcFunc['db']->query('', '
				SELECT
					a.id_attach, a.id_folder, a.id_msg, a.filename, a.file_hash, COALESCE(a.size, 0) AS filesize, a.downloads, a.approved,
					a.width, a.height' . (empty($modSettings['attachmentShowImages']) || empty($modSettings['attachmentThumbnails']) ? '' : ',
					COALESCE(thumb.id_attach, 0) AS id_thumb, thumb.width AS thumb_width, thumb.height AS thumb_height') . '
				FROM {db_prefix}attachments AS a' . (empty($modSettings['attachmentShowImages']) || empty($modSettings['attachmentThumbnails']) ? '' : '
					LEFT JOIN {db_prefix}attachments AS thumb ON (thumb.id_attach = a.id_thumb)') . '
				WHERE a.id_msg IN ({array_int:message_list})
					AND a.attachment_type = {int:attachment_type}',
				[
					'message_list' => $messages,
					'attachment_type' => 0,
					'is_approved' => 1,
				]
			);
			$temp = [];
			while ($row = $smcFunc['db']->fetch_assoc($request))
			{
				if (!$row['approved'] && !allowedTo('approve_posts') && (!isset($all_posters[$row['id_msg']]) || $all_posters[$row['id_msg']] != $user_info['id']))
					continue;

				$temp[$row['id_attach']] = $row;
				$temp[$row['id_attach']]['topic'] = $topic;
				$temp[$row['id_attach']]['board'] = $board;

				if (!isset($context['loaded_attachments'][$row['id_msg']]))
					$context['loaded_attachments'][$row['id_msg']] = [];
			}
			$smcFunc['db']->free_result($request);

			// This is better than sorting it with the query...
			ksort($temp);

			foreach ($temp as $row)
				$context['loaded_attachments'][$row['id_msg']][] = $row;
		}

		$msg_parameters = [
			'message_list' => $messages,
			'new_from' => $context['topicinfo']['new_from'],
		];
		$msg_selects = [];
		$msg_tables = [];
		call_integration_hook('integrate_query_message', [&$msg_selects, &$msg_tables, &$msg_parameters]);

		// What?  It's not like it *couldn't* be only guests in this topic...
		loadMemberData($posters);
		$messages_request = $smcFunc['db']->query('', '
			SELECT
				id_msg, subject, poster_time, poster_ip, id_member, modified_time, modified_name, modified_reason, body,
				smileys_enabled, poster_name, poster_email, approved, likes,
				id_msg_modified < {int:new_from} AS is_read, id_character, id_avatar_attach
				' . (!empty($msg_selects) ? (', ' . implode(', ', $msg_selects)) : '') . '
			FROM {db_prefix}messages
				' . (!empty($msg_tables) ? implode("\n\t", $msg_tables) : '') . '
			WHERE id_msg IN ({array_int:message_list})
			ORDER BY id_msg' . (empty($options['view_newest_first']) ? '' : ' DESC'),
			$msg_parameters
		);

		// And the likes
		if (!empty($modSettings['enable_likes']))
			$context['my_likes'] = $context['user']['is_guest'] ? [] : prepareLikesContext($topic);

		// Go to the last message if the given time is beyond the time of the last message.
		if (isset($context['start_from']) && $context['start_from'] >= $context['topicinfo']['num_replies'])
			$context['start_from'] = $context['topicinfo']['num_replies'];

		// Since the anchor information is needed on the top of the page we load these variables beforehand.
		$context['first_message'] = isset($messages[$firstIndex]) ? $messages[$firstIndex] : $messages[0];
		if (empty($options['view_newest_first']))
			$context['first_new_message'] = isset($context['start_from']) && $_REQUEST['start'] == $context['start_from'];
		else
			$context['first_new_message'] = isset($context['start_from']) && $_REQUEST['start'] == $context['topicinfo']['num_replies'] - $context['start_from'];
	}
	else
	{
		$messages_request = false;
		$context['first_message'] = 0;
		$context['first_new_message'] = false;

		$context['likes'] = [];
	}

	$context['jump_to'] = [
		'label' => addslashes(un_htmlspecialchars($txt['jump_to'])),
		'board_name' => StringLibrary::escape(strtr(strip_tags($board_info['name']), ['&amp;' => '&'])),
		'child_level' => $board_info['child_level'],
	];

	// Set the callback.  (do you REALIZE how much memory all the messages would take?!?)
	// This will be called from the template.
	$context['get_message'] = 'prepareDisplayContext';

	// Now set all the wonderful, wonderful permissions... like moderation ones...
	$common_permissions = [
		'can_approve' => 'approve_posts',
		'can_ban' => 'manage_bans',
		'can_sticky' => 'make_sticky',
		'can_merge' => 'merge_any',
		'can_split' => 'split_any',
		'can_send_pm' => 'pm_send',
		'can_report_moderator' => 'report_any',
		'can_moderate_forum' => 'moderate_forum',
		'can_issue_warning' => 'issue_warning',
		'can_restore_topic' => 'move_any',
		'can_restore_msg' => 'move_any',
		'can_see_likes' => 'likes_view',
		'can_like' => 'likes_like',
	];
	foreach ($common_permissions as $contextual => $perm)
		$context[$contextual] = allowedTo($perm);

	// Permissions with _any/_own versions.  $context[YYY] => ZZZ_any/_own.
	$anyown_permissions = [
		'can_move' => 'move',
		'can_lock' => 'lock',
		'can_delete' => 'remove',
		'can_add_poll' => 'poll_add',
		'can_remove_poll' => 'poll_remove',
		'can_reply' => 'post_reply',
		'can_reply_unapproved' => 'post_unapproved_replies',
		'can_view_warning' => 'profile_warning',
	];
	foreach ($anyown_permissions as $contextual => $perm)
		$context[$contextual] = allowedTo($perm . '_any') || ($context['user']['started'] && allowedTo($perm . '_own'));

	if (!$user_info['is_admin'] && $context['can_move'] && !$modSettings['topic_move_any'])
	{
		// We'll use this in a minute
		$boards_allowed = array_diff(boardsAllowedTo('post_new'), [$board]);

		/* You can't move this unless you have permission
			to start new topics on at least one other board */
		$context['can_move'] &= count($boards_allowed) > 1;
	}

	// If a topic is locked, you can't remove it unless it's yours and you locked it or you can lock_any
	if ($context['topicinfo']['locked'])
	{
		$context['can_delete'] &= (($context['topicinfo']['locked'] == 1 && $context['user']['started']) || allowedTo('lock_any'));
	}

	// Cleanup all the permissions with extra stuff...
	$context['can_mark_notify'] = !$context['user']['is_guest'];
	$context['can_add_poll'] &= $modSettings['pollMode'] == '1' && $context['topicinfo']['id_poll'] <= 0;
	$context['can_remove_poll'] &= $modSettings['pollMode'] == '1' && $context['topicinfo']['id_poll'] > 0;
	$context['can_reply'] &= empty($context['topicinfo']['locked']) || allowedTo('moderate_board');
	$context['can_reply_unapproved'] &= (empty($context['topicinfo']['locked']) || allowedTo('moderate_board'));
	$context['can_issue_warning'] &= $modSettings['warning_settings'][0] == 1;
	// Handle approval flags...
	$context['can_reply_approved'] = $context['can_reply'];
	$context['can_reply'] |= $context['can_reply_unapproved'];
	$context['can_quote'] = $context['can_reply'] && (empty($modSettings['disabledBBC']) || !in_array('quote', explode(',', $modSettings['disabledBBC'])));
	$context['can_mark_unread'] = !$user_info['is_guest'];
	$context['can_unwatch'] = !$user_info['is_guest'];
	$context['can_set_notify'] = !$user_info['is_guest'];

	// If we're posting, we need to make sure we have the character data.
	$context['post_characters'] = [];

	if (!$user_info['is_guest'] && $context['can_reply'])
	{
		$possible_characters = get_user_possible_characters($user_info['id'], $board);

		if (!isset($possible_characters[$user_info['id_character']]))
		{
			$context['can_reply'] = false;
			$context['can_reply_unapproved'] = false;
			$context['can_reply_approved'] = false;
			$context['can_quote'] = false;
		}
		else
		{
			// Make sure we have some avatar to work with.
			$context['current_avatar'] = '';
			foreach ($memberContext[$context['user']['id']]['characters'] as $char_id => $character)
			{
				if ($char_id == $user_info['id_character'])
				{
					$context['current_avatar'] = $character['avatar'];
					$context['current_groups'] = get_labels_and_badges(array_merge([$character['main_char_group']], explode(',', $character['char_groups'])));
				}
			}
		}
	}

	// Start this off for quick moderation - it will be or'd for each post.
	$context['can_remove_post'] = allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']);

	// Can restore topic?  That's if the topic is in the recycle board and has a previous restore state.
	$context['can_restore_topic'] &= !empty($board_info['recycle']) && !empty($context['topicinfo']['id_previous_board']);
	$context['can_restore_msg'] &= !empty($board_info['recycle']) && !empty($context['topicinfo']['id_previous_topic']);
	$context['can_finish_topic'] = $board_info['in_character'] && $context['user']['id'] && (allowedTo('admin_forum') || !empty(TopicCollection::is_participant($context['user']['id'], [$topic])[$topic]));

	// Check if the draft functions are enabled and that they have permission to use them (for quick reply.)
	$context['drafts_save'] = !empty($modSettings['drafts_post_enabled']) && $context['can_reply'];
	$context['drafts_autosave'] = !empty($context['drafts_save']) && !empty($modSettings['drafts_autosave_enabled']);
	if (!empty($context['drafts_save']))
		loadLanguage('Drafts');

	// When was the last time this topic was replied to?  Should we warn them about it?
	if (!empty($modSettings['oldTopicDays']) && ($context['can_reply'] || $context['can_reply_unapproved']) && empty($context['topicinfo']['is_sticky']))
	{
		$request = $smcFunc['db']->query('', '
			SELECT poster_time
			FROM {db_prefix}messages
			WHERE id_msg = {int:id_last_msg}
			LIMIT 1',
			[
				'id_last_msg' => $context['topicinfo']['id_last_msg'],
			]
		);

		list ($lastPostTime) = $smcFunc['db']->fetch_row($request);
		$smcFunc['db']->free_result($request);

		$context['oldTopicError'] = $lastPostTime + $modSettings['oldTopicDays'] * 86400 < time();
	}

	// Load up the "double post" sequencing magic.
	checkSubmitOnce('register');
	$context['name'] = isset($_SESSION['guest_name']) ? $_SESSION['guest_name'] : '';
	$context['email'] = isset($_SESSION['guest_email']) ? $_SESSION['guest_email'] : '';
	// Needed for the editor.
	require_once($sourcedir . '/Subs-Editor.php');

	// Now create the editor.
	$editorOptions = [
		'id' => 'quickReply',
		'value' => '',
		'labels' => [
			'post_button' => $txt['post'],
		],
		// add height and width for the editor
		'height' => '250px',
		'width' => '100%',
		// We do HTML preview here.
		'preview_type' => 1,
		// This is required
		'required' => true,
	];
	create_control_richedit($editorOptions);

	// Store the ID.
	$context['post_box_name'] = $editorOptions['id'];

	// Set a flag so the sub template knows what to do...
	$context['show_bbc'] = true;
	$context['attached'] = '';
	$context['make_poll'] = isset($_REQUEST['poll']);

	// Build the normal button array.
	$context['normal_buttons'] = [];

	if ($context['can_reply'])
		$context['normal_buttons']['reply'] = ['text' => 'reply', 'image' => 'reply.png', 'url' => $scripturl . '?action=post;topic=' . $context['current_topic'] . '.' . $context['start'] . ';last_msg=' . $context['topic_last_message'], 'active' => true];

	if ($context['can_add_poll'])
		$context['normal_buttons']['add_poll'] = ['text' => 'add_poll', 'image' => 'add_poll.png', 'url' => $scripturl . '?action=editpoll;add;topic=' . $context['current_topic'] . '.' . $context['start']];

	if ($context['can_bookmark'])
	{
		$context['normal_buttons']['bookmark'] = ['text' => $context['bookmarked'] ? 'unbookmark_topic' : 'bookmark_topic', 'image' => 'reply.png', 'url' => $scripturl . '?action=bookmark;topic=' . $context['current_topic'] . ';msg=' . $context['topic_last_message'] . ';' . $context['session_var'] . '=' . $context['session_id']];
	}

	if ($context['can_mark_unread'])
		$context['normal_buttons']['mark_unread'] = ['text' => 'mark_unread', 'image' => 'markunread.png', 'url' => $scripturl . '?action=markasread;sa=topic;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

	if ($context['can_set_notify'])
		$context['normal_buttons']['notify'] = [
			'text' => 'notify_topic_' . $context['topic_notification_mode'],
			'sub_buttons' => [
				[
					'test' => 'can_unwatch',
					'text' => 'notify_topic_0',
					'url' => $scripturl . '?action=notifytopic;topic=' . $context['current_topic'] . ';mode=0;' . $context['session_var'] . '=' . $context['session_id'],
				],
				[
					'text' => 'notify_topic_1',
					'url' => $scripturl . '?action=notifytopic;topic=' . $context['current_topic'] . ';mode=1;' . $context['session_var'] . '=' . $context['session_id'],
				],
				[
					'text' => 'notify_topic_2',
					'url' => $scripturl . '?action=notifytopic;topic=' . $context['current_topic'] . ';mode=2;' . $context['session_var'] . '=' . $context['session_id'],
				],
				[
					'text' => 'notify_topic_3',
					'url' => $scripturl . '?action=notifytopic;topic=' . $context['current_topic'] . ';mode=3;' . $context['session_var'] . '=' . $context['session_id'],
				],
			],
		];

	// Build the mod button array
	$context['mod_buttons'] = [];

	if ($context['can_move'])
		$context['mod_buttons']['move'] = ['text' => 'move_topic', 'image' => 'admin_move.png', 'url' => $scripturl . '?action=movetopic;current_board=' . $context['current_board'] . ';topic=' . $context['current_topic'] . '.0'];

	if ($context['can_delete'])
		$context['mod_buttons']['delete'] = ['text' => 'remove_topic', 'image' => 'admin_rem.png', 'custom' => 'data-confirm="' . $txt['are_sure_remove_topic'] . '"', 'class' => 'you_sure', 'url' => $scripturl . '?action=removetopic2;topic=' . $context['current_topic'] . '.0;' . $context['session_var'] . '=' . $context['session_id']];

	if ($context['can_lock'])
		$context['mod_buttons']['lock'] = ['text' => empty($context['is_locked']) ? 'set_lock' : 'set_unlock', 'image' => 'admin_lock.png', 'url' => $scripturl . '?action=lock;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

	if ($context['can_sticky'])
		$context['mod_buttons']['sticky'] = ['text' => empty($context['is_sticky']) ? 'set_sticky' : 'set_nonsticky', 'image' => 'admin_sticky.png', 'url' => $scripturl . '?action=sticky;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

	if ($context['can_merge'])
		$context['mod_buttons']['merge'] = ['text' => 'merge', 'image' => 'merge.png', 'url' => $scripturl . '?action=mergetopics;board=' . $context['current_board'] . '.0;from=' . $context['current_topic']];

	// Restore topic. eh?  No monkey business.
	if ($context['can_restore_topic'])
		$context['mod_buttons']['restore_topic'] = ['text' => 'restore_topic', 'image' => '', 'url' => $scripturl . '?action=restoretopic;topics=' . $context['current_topic'] . ';' . $context['session_var'] . '=' . $context['session_id']];

	if ($context['can_finish_topic'])
		$context['mod_buttons']['finish_topic'] = ['text' => empty($context['is_finished']) ? 'mark_as_finished' : 'reopen_topic', 'image' => '', 'url' => $scripturl . '?action=finish;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']];

	// Show a message in case a recently posted message became unapproved.
	$context['becomesUnapproved'] = !empty($_SESSION['becomesUnapproved']) ? true : false;

	// Don't want to show this forever...
	if ($context['becomesUnapproved'])
		unset($_SESSION['becomesUnapproved']);

	// Allow adding new mod buttons easily.
	call_integration_hook('integrate_display_buttons', [&$context['normal_buttons']]);
	call_integration_hook('integrate_mod_buttons', [&$context['mod_buttons']]);

	// Load the drafts js file
	if ($context['drafts_autosave'])
		loadJavaScriptFile('drafts.js', ['defer' => false], 'sbb_drafts');

	// topic.js
	loadJavaScriptFile('topic.js', ['defer' => false], 'sbb_topic');

	// quotedText.js
	loadJavaScriptFile('quotedText.js', ['defer' => true], 'sbb_quotedText');

	// Mentions
	if (!empty($modSettings['enable_mentions']) && allowedTo('mention'))
	{
		addJavaScriptVar('sbb_autocomplete_rawcharacter', \StoryBB\App::container()->get('urlgenerator')->generate('autocomplete', ['type' => 'rawcharacter', 'sessvar' => $context['session_var'], 'sessid' => $context['session_id']]), true);
		loadJavaScriptFile('jquery.atwho.min.js', ['defer' => true], 'sbb_atwho');
		loadJavaScriptFile('jquery.caret.min.js', ['defer' => true], 'sbb_caret');
		loadJavaScriptFile('mentions.js', ['defer' => true], 'sbb_mentions');
	}

	// Some convenient template setup.
	$context['sub_template'] = 'display_main';
	StoryBB\Template::add_helper([
		'getLikeText' => function($likes) {
			global $txt, $context, $scripturl;
			
			$base = 'likes_n';
			$count = $likes['count'];
			if ($likes['you'])
			{
				$base = 'you_' . $base;
				$count--;
			}
			return numeric_context($base, $count);
		}
	]);

	$context['viewing'] = '';
	if (!empty($settings['display_who_viewing']))
	{
		if ($settings['display_who_viewing'] == 1)
		{
			$context['viewing_members'] = numeric_context('num_members_online', count($context['view_members']));
		}
		else
		{
			$context['viewing_members'] = implode(', ', $context['view_members_list']);
		}
		if (!empty($context['view_num_hidden']) && $context['can_moderate_forum'])
		{
			$context['viewing_members'] .= ' ' . numeric_context('plus_num_hidden', $context['view_num_hidden']);
		}

		$context['viewing_guests'] = numeric_context('num_guests', $context['view_num_guests']);

		$context['viewing'] = sprintf($txt['who_viewing_topic'], $context['viewing_members'], $context['viewing_guests']);
	}
	$context['messages'] = [];
	$context['ignoredMsgs'] = [];
	$context['removableMessageIDs'] = [];
	while($message = $context['get_message']()) {
		$context['messages'][] = $message;
		if (!empty($message['is_ignored'])) $context['ignoredMsgs'][] = $message['id'];
		if ($message['can_remove']) $context['removableMessageIDs'][] = $message['id'];
	}

	$context['avatar_choices'] = get_avatar_choices();
}

/**
 * Collect the correct text for separating between two posts, e.g. if they are a year apart,
 * return '1 year later' or similar.
 *
 * @param int $previous The timestamp of the previous post to compare to the current post
 * @param int $current The timestamp of the current post
 * @return string Empty string if below threshold otherwise e.g. '1 year later'
 */
function display_get_separator_between(int $previous, int $current): string
{
	global $board_info, $modSettings;

	if (empty($modSettings['timeBetweenPosts']))
	{
		return '';
	}
	$mindays = (int) $modSettings['timeBetweenPosts'] * 86400;

	$return = '';
	$difference = $current - $previous;
	if ($difference < $mindays)
	{
		return '';
	}

	$board_type = !empty($modSettings['timeBetweenPostsBoards']) ? $modSettings['timeBetweenPostsBoards'] : 'ooc';
	if ($board_type == 'ic' && !$board_info['in_character'])
	{
		return '';
	}
	if ($board_type == 'ooc' && $board_info['in_character'])
	{
		return '';
	}

	// I could make this a loop over an array but this is, surprisingly, quicker.
	if ($difference > 31536000)
	{
		$years = round($difference / 31536000);
		return numeric_context('post_separator_year', $years);
	}

	if ($difference > 2592000)
	{
		$months = round($difference / 2592000);
		return numeric_context('post_separator_month', $months);
	}

	if ($difference > 604800)
	{
		$weeks = round($difference / 604800);
		return numeric_context('post_separator_week', $weeks);
	}

	if ($difference > 86400)
	{
		$days = round($difference / 86400);
		return numeric_context('post_separator_day', $days);
	}

	return $return;
}

/**
 * Callback for the message display.
 * It actually gets and prepares the message context.
 * This function will start over from the beginning if reset is set to true, which is
 * useful for showing an index before or after the posts.
 *
 * @param bool $reset Whether or not to reset the db seek pointer
 * @return array A large array of contextual data for the posts
 */
function prepareDisplayContext($reset = false)
{
	global $settings, $txt, $modSettings, $scripturl, $options, $user_info, $smcFunc;
	global $memberContext, $context, $messages_request, $topic, $board_info, $sourcedir;
	global $user_profile;

	static $counter = null;
	static $last_time = null;
	static $rotated_avatars = [];

	// If the query returned false, bail.
	if ($messages_request == false)
		return false;

	// Remember which message this is.  (ie. reply #83)
	if ($counter === null || $reset)
		$counter = empty($options['view_newest_first']) ? $context['start'] : $context['total_visible_posts'] - $context['start'];

	// Start from the beginning...
	if ($reset)
		return @$smcFunc['db']->seek($messages_request, 0);

	// Attempt to get the next message.
	$message = $smcFunc['db']->fetch_assoc($messages_request);
	if (!$message)
	{
		$smcFunc['db']->free_result($messages_request);
		return false;
	}

	$message_separator = null;
	if (!empty($last_time))
	{
		$message_separator = display_get_separator_between($last_time, (int) $message['poster_time']);
	}
	$last_time = (int) $message['poster_time'];

	// If you're a lazy bum, you probably didn't give a subject...
	$message['subject'] = $message['subject'] != '' ? $message['subject'] : $txt['no_subject'];

	// Are you allowed to remove at least a single reply?
	$context['can_remove_post'] |= allowedTo('delete_own') && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time()) && $message['id_member'] == $user_info['id'];

	// If the topic is locked, you might not be able to delete the post...
	if ($context['is_locked'])
	{
		$context['can_remove_post'] &= ($context['user']['started'] && $context['is_locked'] == 1) || allowedTo('lock_any');
	}

	// If it couldn't load, or the user was a guest.... someday may be done with a guest table.
	if (!loadMemberContext($message['id_member'], true))
	{
		// Notice this information isn't used anywhere else....
		$memberContext[$message['id_member']]['name'] = $message['poster_name'];
		$memberContext[$message['id_member']]['id'] = 0;
		$memberContext[$message['id_member']]['group'] = $txt['guest_title'];
		$memberContext[$message['id_member']]['link'] = $message['poster_name'];
		$memberContext[$message['id_member']]['email'] = $message['poster_email'];
		$memberContext[$message['id_member']]['show_email'] = allowedTo('moderate_forum');
		$memberContext[$message['id_member']]['is_guest'] = true;
	}
	else
	{
		// Define this here to make things a bit more readable
		$can_view_warning = $context['user']['can_mod'] || allowedTo('view_warning_any') || ($message['id_member'] == $user_info['id'] && allowedTo('view_warning_own'));

		$memberContext[$message['id_member']]['can_view_profile'] = allowedTo('profile_view') || ($message['id_member'] == $user_info['id'] && !$user_info['is_guest']);
		$memberContext[$message['id_member']]['is_topic_starter'] = $message['id_member'] == $context['topic_starter_id'];
		$memberContext[$message['id_member']]['can_see_warning'] = !isset($context['disabled_fields']['warning_status']) && $memberContext[$message['id_member']]['warning_status'] && $can_view_warning;
		// Show the email if it's your post...
		$memberContext[$message['id_member']]['show_email'] |= ($message['id_member'] == $user_info['id']);
	}

	$memberContext[$message['id_member']]['ip'] = IP::format($message['poster_ip']);
	$memberContext[$message['id_member']]['show_profile_buttons'] = !empty($modSettings['show_profile_buttons']) && (!empty($memberContext[$message['id_member']]['can_view_profile']) || $memberContext[$message['id_member']]['show_email'] || $context['can_send_pm']);

	// Do the censor thang.
	censorText($message['body']);
	censorText($message['subject']);

	// Run BBC interpreter on the message.
	$message['body'] = Parser::parse_bbc($message['body'], $message['smileys_enabled'], $message['id_msg']);

	require_once($sourcedir . '/Subs-Attachments.php');

	// Compose the memory eat- I mean message array.
	$output = [
		'attachment' => loadAttachmentContext($message['id_msg'], $context['loaded_attachments']),
		'id' => $message['id_msg'],
		'id_character' => $message['id_character'],
		'href' => $scripturl . '?topic=' . $topic . '.msg' . $message['id_msg'] . '#msg' . $message['id_msg'],
		'link' => '<a href="' . $scripturl . '?msg=' . $message['id_msg'] . '" rel="nofollow">' . $message['subject'] . '</a>',
		'member' => $memberContext[$message['id_member']],
		'subject' => $message['subject'],
		'time' => timeformat($message['poster_time']),
		'timestamp' => forum_time(true, $message['poster_time']),
		'counter' => $counter,
		'modified' => [
			'time' => timeformat($message['modified_time']),
			'timestamp' => forum_time(true, $message['modified_time']),
			'name' => $message['modified_name'],
			'reason' => $message['modified_reason']
		],
		'body' => $message['body'],
		'new' => empty($message['is_read']),
		'approved' => $message['approved'],
		'first_new' => isset($context['start_from']) && $context['start_from'] == $counter,
		'is_ignored' => !empty($modSettings['enable_buddylist']) && !empty($options['posts_apply_ignore_list']) && in_array($message['id_member'], $context['user']['ignoreusers']),
		'can_approve' => !$message['approved'] && $context['can_approve'],
		'can_unapprove' => $context['can_approve'] && $message['approved'],
		'can_modify' => (!$context['is_locked'] || allowedTo('moderate_board')) && (allowedTo('modify_any') || (allowedTo('modify_replies') && $context['user']['started']) || (allowedTo('modify_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || !$message['approved'] || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time()))),
		'can_remove' => allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']) || (allowedTo('delete_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time())),
		'can_see_ip' => allowedTo('moderate_forum'),
		'css_class' => $message['approved'] ? 'windowbg' : 'approvebg',
		'separator' => $message_separator,
	];

	// Getting the poster is a little tricky. Start with whatever we have
	// for the account as a whole and see if we can make a character out of it.
	$output['member'] = $memberContext[$message['id_member']];
	if (!empty($memberContext[$message['id_member']]['id']))
	{
		if (!empty($output['member']['characters'][$message['id_character']]))
		{
			$character = $output['member']['characters'][$message['id_character']];
			if (!empty($character['char_sheet']))
			{
				$output['member']['char_sheet_url'] = $scripturl . '?action=profile;u=' . $message['id_member'] . ';area=character_sheet;char=' . $output['id_character'];
			}
			if (!empty($character['avatar']))
			{
				if (!$character['is_main'])
				{
					$output['member']['avatar'] = [
						'name' => $character['avatar'],
						'image' => '<img class="avatar" src="' . $character['avatar'] . '" alt="">',
						'href' => $character['avatar'],
						'url' => $character['avatar'],
					];
					if (!empty($character['additional_avatars']) && !empty($character['rotate_avatar']))
					{
						if (empty($rotated_avatars[$message['id_character']]))
						{
							$avatars = [$character['avatar']];
							foreach ($character['additional_avatars'] as $avatar)
							{
								if (!empty($avatar['avatar']))
								{
									$avatars[] = $avatar['avatar'];
								}
							}
							$rotated_avatars[$message['id_character']] = $avatars[array_rand($avatars)];
						}

						$output['member']['avatar'] = [
							'name' => $rotated_avatars[$message['id_character']],
							'image' => '<img class="avatar" src="' . $rotated_avatars[$message['id_character']] . '" alt="">',
							'href' => $rotated_avatars[$message['id_character']],
							'url' => $rotated_avatars[$message['id_character']],
						];
					}
					elseif (!empty($message['id_avatar_attach']) && !empty($character['additional_avatars'][$message['id_avatar_attach']]['avatar']))
					{
						$output['member']['avatar'] = [
							'name' => $character['additional_avatars'][$message['id_avatar_attach']]['avatar'],
							'image' => '<img class="avatar" src="' . $character['additional_avatars'][$message['id_avatar_attach']]['avatar'] . '" alt="">',
							'href' => $character['additional_avatars'][$message['id_avatar_attach']]['avatar'],
							'url' => $character['additional_avatars'][$message['id_avatar_attach']]['avatar'],
						];
					}
				}
				else
				{
					$output['member']['avatar'] = [
						'name' => $character['avatar'],
						'image' => '<img class="avatar" src="' . $character['avatar'] . '" alt="">',
						'href' => $character['avatar'],
						'url' => $character['avatar'],
					];
				}
			}
			else
			{
				$output['member']['avatar'] = [
					'name' => '',
					'image' => '<img class="avatar" src="' . $settings['images_url'] . '/default.png" alt="">',
					'href' => $settings['images_url'] . '/default.png',
					'url' => $settings['images_url'] . '/default.png',
				];
			}
			// We need to fix display of badges and everything - for reasons
			// of online behaviour we can't trust what we might have now.
			// In any case this lets us handle multiple badges.
			if (!empty($character['is_main']))
			{
				// We use the main account groups for this.
				$group_list = array_merge(
					[$user_profile[$message['id_member']]['id_group']],
					!empty($user_profile[$message['id_member']]['additional_groups']) ? explode(',', $user_profile[$message['id_member']]['additional_groups']) : []
				);
			}
			else
			{
				// We use the character's group(s)
				$group_list = array_merge(
					[$character['main_char_group']],
					!empty($character['char_groups']) ? explode(',', $character['char_groups']) : []
				);
			}
			$group_info = get_labels_and_badges($group_list);
			$output['member']['username_color'] = '<span ' . (!empty($group_info['color']) ? 'style="color:' . $group_info['color'] . ';"' : '') . '>' . $character['character_name'] . '</span>';
			$output['member']['name_color'] = '<span ' . (!empty($group_info['color']) ? 'style="color:' . $group_info['color'] . ';"' : '') . '>' . $character['character_name'] . '</span>';
			$output['member']['group'] = $group_info['title'];
			$output['member']['group_color'] = $group_info['color'];
			$output['member']['group_icons'] = $group_info['badges'];
			$output['member']['link_color'] = '<a href="' . $scripturl . '?action=profile;u=' . $message['id_member'] . ';area=characters;char=' . $output['id_character'] . '"' . (!empty($group_info['color']) ? ' style="color:' . $group_info['color'] . ';"' : '') . '>' . $character['character_name'] . '</a>';

			$output['member']['href'] = $scripturl . '?action=profile;u=' . $message['id_member'] . ';area=characters;char=' . $output['id_character'];
			$output['member']['link'] = '<a href="' . $scripturl . '?action=profile;u=' . $message['id_member'] . ';area=characters;char=' . $output['id_character'] . '">' . $character['character_name'] . '</a>';
			$output['member']['signature'] = $character['sig_parsed'];
			$output['member']['posts'] = comma_format($character['posts']);
			$is_online = $message['id_character'] == $output['member']['current_character'];
			$output['member']['online'] = [
				'is_online' => $is_online,
				'text' => StringLibrary::escape($txt[$is_online ? 'online' : 'offline']),
				'member_online_text' => sprintf($txt[$is_online ? 'member_is_online' : 'member_is_offline'], StringLibrary::escape($character['character_name'])),
				'href' => $scripturl . '?action=pm;sa=send;u=' . $message['id_member'],
				'link' => '<a href="' . $scripturl . '?action=pm;sa=send;u=' . $message['id_member'] . '">' . $txt[$is_online ? 'online' : 'offline'] . '</a>',
				'label' => $txt[$is_online ? 'online' : 'offline']
			];

			$output['member']['custom_fields'] = $output['member']['characters'][$message['id_character']]['custom_fields'];
		}
	}

	// Now we indicate whether we can potentially migrate this to another character.
	// But that requires us having characters to migrate to, and follow the OOC/IC rules.
	if (!empty($output['member']['characters'])) {
		$output['possible_characters'] = get_user_possible_characters($message['id_member'], $board_info['id']);
		// And making sure we don't try to reassociate to the character it has already.
		unset ($output['possible_characters'][$message['id_character']]);

		if (!empty($output['possible_characters'])) {
			asort($output['possible_characters']);
		}
	}
	$output['can_switch_char'] = !empty($output['possible_characters']) && $output['can_modify'];
	if (!$output['can_switch_char']) {
		unset ($output['possible_characters']);
	}

	// Are likes enable?
	if (!empty($modSettings['enable_likes']))
		$output['likes'] = [
			'id' => $message['id_msg'],
			'count' => $message['likes'],
			'you' => in_array($message['id_msg'], $context['my_likes']),
			'can_like' => !$context['user']['is_guest'] && $message['id_member'] != $context['user']['id'] && !empty($context['can_like']),
		];

	// Is this user the message author?
	$output['is_message_author'] = $message['id_member'] == $user_info['id'];
	if (!empty($output['modified']['name']))
		$output['modified']['last_edit_text'] = sprintf($txt['last_edit_by'], $output['modified']['time'], $output['modified']['name']);

	// Did they give a reason for editing?
	if (!empty($output['modified']['name']) && !empty($output['modified']['reason']))
		$output['modified']['last_edit_text'] .= '&nbsp;' . sprintf($txt['last_edit_reason'], $output['modified']['reason']);

	// Add the moderation link if relevant.
	if ($context['can_report_moderator'])
	{
		$output += [
			'report_link' => $scripturl . '?action=reporttm;topic=' . $topic . '.' . $counter . ';msg='. $message['id_msg'],
			'report_title' => $txt['report_to_mod'],
		];
	}

	// Add the per-message buttons.
	$output['quickbuttons'] = [];
	if ($context['can_quote'])
	{
		$output['quickbuttons']['quote'] = [
			'url' => $scripturl . '?action=post;quote=' . $message['id_msg'] . ';topic=' . $topic . '.' . $context['start'] . ';last_msg=' . $context['topic_last_message'],
			'onclick' => 'return oQuickReply.quote(' . $message['id_msg'] . ');',
			'class' => 'main_icons quote',
			'label' => $txt['quote_action'],
		];
		$output['quickbuttons']['quote_selected'] = [
			'li_id' => 'quoteSelected_' . $message['id_msg'],
			'hidden' => true,
			'url' => 'javascript:void(0)',
			'class' => 'main_icons quote',
			'label' => $txt['quote_selected_action'],
		];
	}

	if ($output['can_modify'])
	{
		$output['quickbuttons']['quick_edit'] = [
			'li_class' => 'quick_edit',
			'url' => '',
			'title' => $txt['quick_edit'],
			'label' => $txt['quick_edit'],
			'class' => 'modifybutton main_icons quick_edit_button',
			'id' => 'modify_button_' . $message['id_msg'],
			'onclick' => "oQuickModify.modifyMsg('" . $message['id_msg'] . "', '" . (!empty($modSettings['toggle_subject']) ? 'true' : 'false') . "')",
		];
	}

	$output['quickbuttons']['more'] = [
		'li_class' => 'post_options',
		'url' => '#',
		'label' => $txt['post_options'],
		'onclick' => 'return false',
		'sub_items' => [],
	];

	if ($output['can_modify'])
	{
		$output['quickbuttons']['more']['sub_items'][] = [
			'url' => $scripturl . '?action=post;msg=' . $message['id_msg'] . ';topic=' . $topic . '.' . $context['start'],
			'label' => $txt['modify'],
			'class' => 'main_icons modify_button',
		];
	}

	if ($context['can_delete'] && $context['topic_first_message'] == $message['id_msg'])
	{
		$output['quickbuttons']['more']['sub_items'][] = [
			'url' => $scripturl . '?action=removetopic2;topic=' . $topic . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			'data-confirm' => $txt['are_sure_remove_topic'],
			'class' => 'you_sure main_icons remove_button',
			'label' => $txt['remove_topic'],
		];
	}
	elseif ($output['can_remove'])
	{
		$output['quickbuttons']['more']['sub_items'][] = [
			'url' => $scripturl . '?action=deletemsg;topic=' . $topic . '.' . $context['start'] . ';msg=' . $message['id_msg'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			'data-confirm' => $txt['remove_message_question'],
			'class' => 'you_sure main_icons remove_button',
			'label' => $txt['remove'],
		];
	}

	if ($output['can_switch_char'])
	{
		$subitems = [];
		foreach ($output['possible_characters'] as $id_character => $character)
		{
			$subitems[] = [
				'url' => $scripturl . '?action=reattributepost;topic=' . $topic . ';msg=' . $message['id_msg'] . ';char=' . $id_character . ';' . $context['session_var'] . '=' . $context['session_id'],
				'label' => $character['name'],
			];
		}
		if (!empty($subitems))
		{
			$output['quickbuttons']['more']['sub_items'][] = [
				'url' => '#',
				'onclick' => 'return false',
				'class' => 'main_icons people',
				'label' => $txt['switch_to_char_menu'],
				'sub_items' => $subitems,
			];
		}
	}

	if ($context['can_split'] && $context['real_num_replies'])
	{
		$output['quickbuttons']['more']['sub_items'][] = [
			'url' => $scripturl . '?action=splittopics;topic=' . $topic . '.0;at=' . $message['id_msg'],
			'class' => 'main_icons split_button',
			'label' => $txt['split'],
		];
	}

	if ($context['can_issue_warning'] && !$output['is_message_author'] && !$output['member']['is_guest'])
	{
		$output['quickbuttons']['more']['sub_items']['warning'] = [
			'url' => $scripturl . '?action=profile;area=issue_warning;u=' . $output['member']['id'] . ';msg=' . $message['id_msg'],
			'label' => $txt['issue_warning'],
			'class' => 'main_icons warn_button',
		];
	}

	if ($context['can_restore_msg'])
	{
		$output['quickbuttons']['restore'] = [
			'url' => $scripturl . '?action=restoretopic;msgs=' . $message['id_msg'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			'label' => $txt['restore_message'],
			'class' => 'main_icons restore_button',
		];
	}

	if ($output['can_approve'])
	{
		$output['quickbuttons']['approve'] = [
			'url' => $scripturl . '?action=moderate;area=postmod;sa=approve;topic=' . $topic . '.' . $context['start'] . ';msg=' . $message['id_msg'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			'label' => $txt['approve'],
			'class' => 'main_icons approve_button',
		];
	}

	if ($output['can_unapprove'])
	{
		$output['quickbuttons']['more']['sub_items']['unapprove'] = [
			'url' => $scripturl . '?action=moderate;area=postmod;sa=approve;topic=' . $topic . '.' . $context['start'] . ';msg=' . $message['id_msg'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			'label' => $txt['unapprove'],
			'class' => 'main_icons unapprove_button',
		];
	}

	if ($output['can_remove'] || ($context['can_split'] && $context['real_num_replies']))
	{
		$output['quickbuttons']['tickbox'] = [
			'hidden' => true,
			'li_id' => 'in_topic_mod_check_' . $message['id_msg'],
		];
	}

	// Lastly if no menu, clean it up.
	if (empty($output['quickbuttons']['more']['sub_items']))
	{
		unset ($output['quickbuttons']['more']);
	}

	if (empty($options['view_newest_first']))
		$counter++;

	else
		$counter--;

	call_integration_hook('integrate_prepare_display_context', [&$output, &$message, $counter]);

	return $output;
}

/**
 * Prepares an array of "likes" info for the topic specified by $topic
 * @param integer $topic The topic ID to fetch the info from.
 * @return array An array of IDs of messages in the specified topic that the current user likes
 */
function prepareLikesContext($topic)
{
	global $user_info, $smcFunc;

	// Make sure we have something to work with.
	if (empty($topic))
		return [];


	// We already know the number of likes per message, we just want to know whether the current user liked it or not.
	$user = $user_info['id'];
	$cache_key = 'likes_topic_' . $topic . '_' . $user;
	$ttl = 180;

	if (($temp = cache_get_data($cache_key, $ttl)) === null)
	{
		$temp = [];
		$request = $smcFunc['db']->query('', '
			SELECT content_id
			FROM {db_prefix}user_likes AS l
				INNER JOIN {db_prefix}messages AS m ON (l.content_id = m.id_msg)
			WHERE l.id_member = {int:current_user}
				AND l.content_type = {literal:msg}
				AND m.id_topic = {int:topic}',
			[
				'current_user' => $user,
				'topic' => $topic,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$temp[] = (int) $row['content_id'];

		cache_put_data($cache_key, $temp, $ttl);
	}

	return $temp;
}
