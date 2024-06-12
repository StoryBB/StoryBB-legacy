<?php

/**
 * This file has all the main functions in it that relate to, well, everything.
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
use StoryBB\Phrase;
use StoryBB\Model\Alert;
use StoryBB\Model\Character;
use StoryBB\Model\Policy;
use StoryBB\Helper\Parser;
use StoryBB\Helper\IP;
use StoryBB\Hook\Mutatable;
use StoryBB\StringLibrary;

/**
 * Update some basic statistics.
 *
 * 'member' statistic updates the latest member, the total member
 *  count, and the number of unapproved members.
 * 'member' also only counts approved members when approval is on, but
 *  is much more efficient with it off.
 *
 * 'message' changes the total number of messages, and the
 *  highest message id by id_msg - which can be parameters 1 and 2,
 *  respectively.
 *
 * 'topic' updates the total number of topics, or if parameter1 is true
 *  simply increments them.
 *
 * 'subject' updates the log_search_subjects in the event of a topic being
 *  moved, removed or split.  parameter1 is the topicid, parameter2 is the new subject
 *
 * @param string $type Stat type - can be 'member', 'message', 'topic', 'subject'
 * @param mixed $parameter1 A parameter for updating the stats
 * @param mixed $parameter2 A 2nd parameter for updating the stats
 */
function updateStats($type, $parameter1 = null, $parameter2 = null)
{
	global $modSettings, $smcFunc;

	switch ($type)
	{
		case 'member':
			$changes = [
				'memberlist_updated' => time(),
			];

			// #1 latest member ID, #2 the real name for a new registration.
			if (is_numeric($parameter1))
			{
				$changes['latestMember'] = $parameter1;
				$changes['latestRealName'] = $parameter2;

				updateSettings(['totalMembers' => true], true);
			}

			// We need to calculate the totals.
			else
			{
				// Update the latest activated member (highest id_member) and count.
				$result = $smcFunc['db']->query('', '
				SELECT COUNT(*), MAX(id_member)
				FROM {db_prefix}members
				WHERE is_activated = {int:is_activated}',
					[
						'is_activated' => 1,
					]
				);
				list ($changes['totalMembers'], $changes['latestMember']) = $smcFunc['db']->fetch_row($result);
				$smcFunc['db']->free_result($result);

				// Get the latest activated member's display name.
				$result = $smcFunc['db']->query('', '
				SELECT real_name
				FROM {db_prefix}members
				WHERE id_member = {int:id_member}
				LIMIT 1',
					[
						'id_member' => (int) $changes['latestMember'],
					]
				);
				list ($changes['latestRealName']) = $smcFunc['db']->fetch_row($result);
				$smcFunc['db']->free_result($result);

				// Update the amount of members awaiting approval (either new registration or deletion)
				$result = $smcFunc['db']->query('', '
				SELECT COUNT(*)
				FROM {db_prefix}members
				WHERE is_activated IN ({array_int:activation_status})',
					[
						'activation_status' => [3, 4],
					]
				);
				list ($changes['unapprovedMembers']) = $smcFunc['db']->fetch_row($result);
				$smcFunc['db']->free_result($result);
			}
			updateSettings($changes);
			break;

		case 'message':
			if ($parameter1 === true && $parameter2 !== null)
				updateSettings(['totalMessages' => true, 'maxMsgID' => $parameter2], true);
			else
			{
				// SUM and MAX on a smaller table is better for InnoDB tables.
				$result = $smcFunc['db']->query('', '
				SELECT SUM(num_posts + unapproved_posts) AS total_messages, MAX(id_last_msg) AS max_msg_id
				FROM {db_prefix}boards
				WHERE redirect = {string:blank_redirect}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
					AND id_board != {int:recycle_board}' : ''),
					[
						'recycle_board' => isset($modSettings['recycle_board']) ? $modSettings['recycle_board'] : 0,
						'blank_redirect' => '',
					]
				);
				$row = $smcFunc['db']->fetch_assoc($result);
				$smcFunc['db']->free_result($result);

				updateSettings([
					'totalMessages' => $row['total_messages'] === null ? 0 : $row['total_messages'],
					'maxMsgID' => $row['max_msg_id'] === null ? 0 : $row['max_msg_id']
				]);
			}
			break;

		case 'subject':
			// Remove the previous subject (if any).
			$smcFunc['db']->query('', '
			DELETE FROM {db_prefix}log_search_subjects
			WHERE id_topic = {int:id_topic}',
				[
					'id_topic' => (int) $parameter1,
				]
			);

			// Insert the new subject.
			if ($parameter2 !== null)
			{
				$parameter1 = (int) $parameter1;
				$parameter2 = text2words($parameter2);

				$inserts = [];
				foreach ($parameter2 as $word)
					$inserts[] = [$word, $parameter1];

				if (!empty($inserts))
					$smcFunc['db']->insert('ignore',
						'{db_prefix}log_search_subjects',
						['word' => 'string', 'id_topic' => 'int'],
						$inserts,
						['word', 'id_topic']
					);
			}
			break;

		case 'topic':
			if ($parameter1 === true)
				updateSettings(['totalTopics' => true], true);
			else
			{
				// Get the number of topics - a SUM is better for InnoDB tables.
				// We also ignore the recycle bin here because there will probably be a bunch of one-post topics there.
				$result = $smcFunc['db']->query('', '
				SELECT SUM(num_topics + unapproved_topics) AS total_topics
				FROM {db_prefix}boards' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				WHERE id_board != {int:recycle_board}' : ''),
					[
						'recycle_board' => !empty($modSettings['recycle_board']) ? $modSettings['recycle_board'] : 0,
					]
				);
				$row = $smcFunc['db']->fetch_assoc($result);
				$smcFunc['db']->free_result($result);

				updateSettings(['totalTopics' => $row['total_topics'] === null ? 0 : $row['total_topics']]);
			}
			break;

		default:
			trigger_error('updateStats(): Invalid statistic type \'' . $type . '\'', E_USER_NOTICE);
	}
}

/**
 * Updates the columns in the members table.
 * Assumes the data has been htmlspecialchar'd.
 * this function should be used whenever member data needs to be
 * updated in place of an UPDATE query.
 *
 * id_member is either an int or an array of ints to be updated.
 *
 * data is an associative array of the columns to be updated and their respective values.
 * any string values updated should be quoted and slashed.
 *
 * the value of any column can be '+' or '-', which mean 'increment'
 * and decrement, respectively.
 *
 * if the member's post number is updated, updates their post groups.
 *
 * @param mixed $members An array of member IDs, null to update this for all members or the ID of a single member
 * @param array $data The info to update for the members
 */
function updateMemberData($members, $data)
{
	global $modSettings, $user_info, $smcFunc, $sourcedir;

	$parameters = [];
	if (is_array($members))
	{
		$condition = 'mem.id_member IN ({array_int:members})';
		$parameters['members'] = $members;
	}
	elseif ($members === null)
		$condition = '1=1';
	else
	{
		$condition = 'mem.id_member = {int:member}';
		$parameters['member'] = $members;
	}

	// Everything is assumed to be a string unless it's in the below.
	$knownInts = [
		'date_registered', 'posts', 'id_group', 'last_login', 'instant_messages', 'unread_messages',
		'new_pm', 'pm_prefs', 'show_online', 'pm_receive_from', 'alerts',
		'id_theme', 'is_activated', 'id_msg_last_visit', 'total_time_logged_in', 'warning',
		'policy_acceptance',
	];
	$knownFloats = [
		'time_offset',
	];

	if (!empty($modSettings['integrate_change_member_data']))
	{
		// Only a few member variables are really interesting for integration.
		$integration_vars = [
			'member_name',
			'real_name',
			'email_address',
			'id_group',
			'birthdate',
			'location',
			'time_format',
			'time_offset',
			'avatar',
			'lngfile',
		];
		$vars_to_integrate = array_intersect($integration_vars, array_keys($data));

		// Only proceed if there are any variables left to call the integration function.
		if (count($vars_to_integrate) != 0)
		{
			// Fetch a list of member_names if necessary
			if ((!is_array($members) && $members === $user_info['id']) || (is_array($members) && count($members) == 1 && in_array($user_info['id'], $members)))
				$member_names = [$user_info['username']];
			else
			{
				$member_names = [];
				$request = $smcFunc['db']->query('', '
					SELECT member_name
					FROM {db_prefix}members
					WHERE ' . $condition,
					$parameters
				);
				while ($row = $smcFunc['db']->fetch_assoc($request))
					$member_names[] = $row['member_name'];
				$smcFunc['db']->free_result($request);
			}

			if (!empty($member_names))
				foreach ($vars_to_integrate as $var)
					call_integration_hook('integrate_change_member_data', [$member_names, $var, &$data[$var], &$knownInts, &$knownFloats]);
		}
	}

	$setString = '';
	foreach ($data as $var => $val)
	{
		$type = 'string';
		if (in_array($var, $knownInts))
			$type = 'int';
		elseif (in_array($var, $knownFloats))
			$type = 'float';
		elseif ($var == 'birthdate')
			$type = 'date';
		elseif ($var == 'member_ip')
			$type = 'inet';
		elseif ($var == 'member_ip2')
			$type = 'inet';

		$tableprefix = 'mem.';
		if ($var == 'signature')
		{
			$tableprefix = 'chars.';
		}

		// Doing an increment?
		if ($var == 'alerts' && ($val === '+' || $val === '-'))
		{
			if (is_array($members))
			{
				$val = 'CASE ';
				foreach ($members as $v)
					$val .= 'WHEN mem.id_member = ' . $v . ' THEN '. count(Alert::fetch_alerts($v, false, 0, [], false)) . ' ';
				$val = $val . ' END';
				$type = 'raw';
			}
			else
			{
				$blub = Alert::fetch_alerts($members, false, 0, [], false);
				$val = count($blub);
			}
		}
		elseif ($type == 'int' && ($val === '+' || $val === '-'))
		{
			$val = $tableprefix . $var . ' ' . $val . ' 1';
			$type = 'raw';
		}

		// Ensure posts, instant_messages, and unread_messages don't overflow or underflow.
		if (in_array($var, ['posts', 'instant_messages', 'unread_messages']))
		{
			if (preg_match('~^' . $var . ' (\+ |- |\+ -)([\d]+)~', $val, $match))
			{
				if ($match[1] != '+ ')
					$val = 'CASE WHEN ' . $tableprefix . $var . ' <= ' . abs($match[2]) . ' THEN 0 ELSE ' . $tableprefix . $val . ' END';
				else
					$val = $tableprefix . $val;
				$type = 'raw';
			}
		}

		$setString .= ' ' . $tableprefix . $var . ' = {' . $type . ':p_' . $var . '},';
		$parameters['p_' . $var] = $val;
	}

	if (trim($setString) == '')
	{
		return;
	}

	$smcFunc['db']->query('', '
		UPDATE {db_prefix}members mem, {db_prefix}characters chars
		SET' . substr($setString, 0, -1) . '
		WHERE (chars.id_member = mem.id_member AND chars.is_main = 1)
			AND ' . $condition,
		$parameters
	);

	// If we're updating the real name (aka display name), sync this
	// to the main/OOC character.
	if (isset($data['real_name']))
	{
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}characters AS chars, {db_prefix}members AS mem
			SET chars.character_name = {string:p_real_name}
			WHERE chars.is_main = 1
				AND chars.id_member = mem.id_member
				AND ' . $condition,
			$parameters
		);
	}

	// Clear any caching?
	if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 2 && !empty($members))
	{
		if (!is_array($members))
			$members = [$members];

		foreach ($members as $member)
		{
			if ($modSettings['cache_enable'] >= 3)
			{
				cache_put_data('member_data-profile-' . $member, null, 120);
				cache_put_data('member_data-normal-' . $member, null, 120);
				cache_put_data('member_data-minimal-' . $member, null, 120);
			}
			cache_put_data('user_settings-' . $member, null, 60);
		}
	}
}

/**
 * Update data for a given character.
 *
 * @param int $char_id The character ID being updated.
 * @param array $data The fields being updated for the character.
 */
function updateCharacterData($char_id, $data)
{
	global $smcFunc;

	$setString = '';
	$condition = 'id_character = {int:id_character}';
	$parameters = ['id_character' => $char_id];
	foreach ($data as $var => $val)
	{
		$type = 'string';
		if (in_array($var, ['id_theme', 'posts', 'last_active', 'retired', 'rotate_avatar']))
			$type = 'int';

		// Doing an increment?
		if ($type == 'int' && ($val === '+' || $val === '-'))
		{
			$val = $var . ' ' . $val . ' 1';
			$type = 'raw';
		}

		// Ensure posts don't overflow or underflow.
		if (in_array($var, ['posts']))
		{
			if (preg_match('~^' . $var . ' (\+ |- |\+ -)([\d]+)~', $val, $match))
			{
				if ($match[1] != '+ ')
					$val = 'CASE WHEN ' . $var . ' <= ' . abs($match[2]) . ' THEN 0 ELSE ' . $val . ' END';
				$type = 'raw';
			}
		}

		$setString .= ' ' . $var . ' = {' . $type . ':p_' . $var . '},';
		$parameters['p_' . $var] = $val;
	}

	$smcFunc['db']->query('', '
		UPDATE {db_prefix}characters
		SET' . substr($setString, 0, -1) . '
		WHERE ' . $condition,
		$parameters
	);
}

/**
 * Updates the settings table as well as $modSettings... only does one at a time if $update is true.
 *
 * - updates both the settings table and $modSettings array.
 * - all of changeArray's indexes and values are assumed to have escaped apostrophes (')!
 * - if a variable is already set to what you want to change it to, that
 *   variable will be skipped over; it would be unnecessary to reset.
 * - When use_update is true, UPDATEs will be used instead of REPLACE.
 * - when use_update is true, the value can be true or false to increment
 *  or decrement it, respectively.
 *
 * @param array $changeArray An array of info about what we're changing in 'setting' => 'value' format
 * @param bool $update Whether to use an UPDATE query instead of a REPLACE query
 */
function updateSettings($changeArray, $update = false)
{
	global $modSettings, $smcFunc;

	if (empty($changeArray) || !is_array($changeArray))
		return;

	$toRemove = [];

	// Go check if there is any setting to be removed.
	foreach ($changeArray as $k => $v)
		if ($v === null)
		{
			// Found some, remove them from the original array and add them to ours.
			unset($changeArray[$k]);
			$toRemove[] = $k;
		}

	// Proceed with the deletion.
	if (!empty($toRemove))
		$smcFunc['db']->query('', '
			DELETE FROM {db_prefix}settings
			WHERE variable IN ({array_string:remove})',
			[
				'remove' => $toRemove,
			]
		);

	// In some cases, this may be better and faster, but for large sets we don't want so many UPDATEs.
	if ($update)
	{
		foreach ($changeArray as $variable => $value)
		{
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}settings
				SET value = {' . ($value === false || $value === true ? 'raw' : 'string') . ':value}
				WHERE variable = {string:variable}',
				[
					'value' => $value === true ? 'value + 1' : ($value === false ? 'value - 1' : $value),
					'variable' => $variable,
				]
			);
			$modSettings[$variable] = $value === true ? $modSettings[$variable] + 1 : ($value === false ? $modSettings[$variable] - 1 : $value);
		}

		// Clean out the cache and make sure the cobwebs are gone too.
		cache_put_data('modSettings', null, 90);

		return;
	}

	$replaceArray = [];
	foreach ($changeArray as $variable => $value)
	{
		// Don't bother if it's already like that ;).
		if (isset($modSettings[$variable]) && $modSettings[$variable] == $value)
			continue;
		// If the variable isn't set, but would only be set to nothing'ness, then don't bother setting it.
		elseif (!isset($modSettings[$variable]) && empty($value))
			continue;

		$replaceArray[] = [$variable, $value];

		$modSettings[$variable] = $value;
	}

	if (empty($replaceArray))
		return;

	$smcFunc['db']->insert('replace',
		'{db_prefix}settings',
		['variable' => 'string-255', 'value' => 'string-65534'],
		$replaceArray,
		['variable']
	);

	// Kill the cache - it needs redoing now, but we won't bother ourselves with that here.
	cache_put_data('modSettings', null, 90);
}

/**
 * Constructs a page list.
 *
 * - builds the page list, e.g. 1 ... 6 7 [8] 9 10 ... 15.
 * - flexible_start causes it to use "url.page" instead of "url;start=page".
 * - very importantly, cleans up the start value passed, and forces it to
 *   be a multiple of num_per_page.
 * - checks that start is not more than max_value.
 * - base_url should be the URL without any start parameter on it.
 *   setting to decide how to display the menu.
 *
 * an example is available near the function definition.
 * $pageindex = constructPageIndex($scripturl . '?topic=' . $topic, $_REQUEST['start'], $num_messages, $maxindex, true);
 *
 * @param string $base_url The basic URL to be used for each link.
 * @param int &$start The start position, by reference. If this is not a multiple of the number of items per page, it is sanitized to be so and the value will persist upon the function's return.
 * @param int $max_value The total number of items you are paginating for.
 * @param int $num_per_page The number of items to be displayed on a given page. $start will be forced to be a multiple of this value.
 * @param bool $flexible_start Whether a ;start=x component should be introduced into the URL automatically (see above)
 * @param bool $show_prevnext Whether the Previous and Next links should be shown (should be on only when navigating the list)
 *
 * @return string The complete HTML of the page index that was requested, formatted by the template.
 */
function constructPageIndex($base_url, &$start, $max_value, $num_per_page, $flexible_start = false, $show_prevnext = true)
{
	global $context, $txt, $scripturl;

	// Save whether $start was less than 0 or not.
	$start = (int) $start;
	$start_invalid = $start < 0;

	// Make sure $start is a proper variable - not less than 0.
	if ($start_invalid)
		$start = 0;
	// Not greater than the upper bound.
	elseif ($start >= $max_value)
		$start = max(0, (int) $max_value - (((int) $max_value % (int) $num_per_page) == 0 ? $num_per_page : ((int) $max_value % (int) $num_per_page)));
	// And it has to be a multiple of $num_per_page!
	else
		$start = max(0, (int) $start - ((int) $start % (int) $num_per_page));

	$context['current_page'] = $start / $num_per_page;

	// Number of items either side of the selected item.
	$PageContiguous = 2;

	$data = [
		'context' => $context,
		'scripturl' => $scripturl,
		'txt' => $txt,
		'base_url' => $flexible_start ? $base_url : strtr($base_url, ['%' => '%%']) . ';start=%1$d',
		'previous_page' => -1,
		'next_page' => -1,
		'start' => $start,
		'num_per_page' => $num_per_page,
		'continuous_numbers' => $PageContiguous,
		'range_before' => [],
		'range_after' => [],
		'range_all_except_ends' => [],
		'max_index' => (int) (($max_value - 1) / $num_per_page) * $num_per_page,
		'max_pages' => ceil($max_value / $num_per_page),
		'current_page' => $start / $num_per_page,
		'current_page_display' => $start / $num_per_page + 1,
		'actually_on_current_page' => !$start_invalid,
	];

	// Make some data available to the template: whether there are previous/next pages.
	if ($show_prevnext)
	{
		if (!empty($start))
			$data['previous_page'] = $start - $num_per_page;

		if ($start != $data['max_index'])
			$data['next_page'] = $start + $num_per_page;
	}

	// If there's only one page, or two pages, first/last are already covered.
	// But if not, we need to expose the rest to the template conveniently.
	if ($data['max_pages'] >= 3)
	{
		foreach(range(2, $data['max_pages'] - 1) as $page)
		{
			$data['range_all_except_ends'][$page] = $num_per_page * ($page - 1);
		}
	}

	// Assuming we're doing the 1 ... 6 7 [8] type stuff, we need to outline the links for 6 and 7. And the ones after the current page, too.
	for ($nCont = $PageContiguous; $nCont >= 1; $nCont--)
		if ($start >= $num_per_page * $nCont)
		{
			$tmpStart = $start - $num_per_page * $nCont;
			$data['range_before'][$tmpStart / $num_per_page + 1] = $tmpStart;
		}

	for ($nCont = 1; $nCont <= $PageContiguous; $nCont++)
		if ($start + $num_per_page * $nCont <= $data['max_index'])
		{
			$tmpStart = $start + $num_per_page * $nCont;
			$data['range_after'][$tmpStart / $num_per_page + 1] = $tmpStart;
		}

	$phpStr = StoryBB\Template::compile(StoryBB\Template::load_partial('pagination'), [], 'pagination' . StoryBB\Template::get_theme_id('partials', 'pagination'));
	return new \LightnCandy\SafeString(StoryBB\Template::prepare($phpStr, $data));
}

/**
 * - Formats a number.
 * - uses the format of number_format to decide how to format the number.
 *   for example, it might display "1 234,50".
 * - caches the formatting data from the setting for optimization.
 *
 * @param float $number A number
 * @param bool|int $override_decimal_count If set, will use the specified number of decimal places. Otherwise it's automatically determined
 * @return string A formatted number
 */
function comma_format($number, $override_decimal_count = false)
{
	global $txt;
	static $thousands_separator = null, $decimal_separator = null, $decimal_count = null;

	// Cache these values...
	if ($decimal_separator === null)
	{
		// Not set for whatever reason?
		if (empty($txt['number_format']) || preg_match('~^1([^\d]*)?234([^\d]*)(0*?)$~', $txt['number_format'], $matches) != 1)
			return $number;

		// Cache these each load...
		$thousands_separator = $matches[1];
		$decimal_separator = $matches[2];
		$decimal_count = strlen($matches[3]);
	}

	// Format the string with our friend, number_format.
	return number_format($number, (float) $number === $number ? ($override_decimal_count === false ? $decimal_count : $override_decimal_count) : 0, $decimal_separator, $thousands_separator);
}

/**
 * Retrieves the appropriate language string for a number, optionally comma-formatting it.
 *
 * It assumes the language string is really an array:
 *  $txt['language_string'][0] = 'There are no cookies.';
 *  $txt['language_string'][1] = 'There is one cookie.';
 *  $txt['language_string'][2] = 'There are two cookies.';
 *  $txt['language_string']['x'] = 'There are %1$s cookies.';
 *
 * For more complex language cases:
 *  $txt['ordinals'][0] = '0th';
 *  $txt['ordinals'][1] = '1st';
 *  $txt['ordinals'][2] = '2nd';
 *  $txt['ordinals'][3] = '3rd';
 *  $txt['ordinals']['x11'] = '%1$sth';
 *  $txt['ordinals']['x12'] = '%1$sth';
 *  $txt['ordinals']['x13'] = '%1$sth';
 *  $txt['ordinals']['x1'] = '%1$sst';
 *  $txt['ordinals']['x2'] = '%1$snd';
 *  $txt['ordinals']['x3'] = '%1$srd';
 *  $txt['ordinals']['x'] = '%1$sth';
 *
 * Match exact matches first (e.g. 1 in the above list)
 * Then match longer versions with an x, before finally matching on x as the general fallback position.
 *
 * This function isn't called frequently enough for its performance to be massively sensitive, but it needs to be reasonably fast.
 *
 * @param string $string The language string to look up
 * @param int $number The number to format into the string
 * @param bool $commaise Whether to push the number through comma_format or not
 * @return string The string, formatted correctly for the number
 */
function numeric_context(string $string, $number, bool $commaise = true): string
{
	global $txt;
	if (!isset($txt[$string]))
		return '';

	if (!is_array($txt[$string]))
		return sprintf($txt[$string], $commaise ? comma_format($number) : $number);

	if (isset($txt[$string][$number]))
		return sprintf($txt[$string][$number], $commaise ? comma_format($number) : $number);

	$numstring = (string) $number;
	for ($i = strlen($numstring); $i > 0; $i--)
	{
		$trunc = 'x' . substr($numstring, -$i);
		if (isset($txt[$string][$trunc]))
			return sprintf($txt[$string][$trunc], $commaise ? comma_format($number) : $number);
	}

	return sprintf($txt[$string]['x'], $commaise ? comma_format($number) : $number);
}

/**
 * Format a time to make it look purdy.
 *
 * - returns a pretty formatted version of time based on the user's format in $user_info['time_format'].
 * - applies all necessary time offsets to the timestamp, unless offset_type is set.
 * - if todayMod is set and show_today was not not specified or true, an
 *   alternate format string is used to show the date with something to show it is "today" or "yesterday".
 * - performs localization
 *
 * @param int $log_time A timestamp
 * @param bool $show_today Whether to show "Today"/"Yesterday" or just a date
 * @param bool|string $offset_type If false, uses both user time offset and forum offset. If 'forum', uses only the forum offset. Otherwise no offset is applied.
 * @return string A formatted timestamp
 */
function timeformat($log_time, $show_today = true, $offset_type = false)
{
	global $user_info, $txt, $modSettings, $sourcedir;

	if (!isset($txt['today']))
	{
		require_once($sourcedir . '/ScheduledTasks.php');
		loadEssentialThemeData();
	}

	// Offset the time.
	if (!$offset_type)
		$time = $log_time + ($user_info['time_offset'] + $modSettings['time_offset']) * 3600;
	// Just the forum offset?
	elseif ($offset_type == 'forum')
		$time = $log_time + $modSettings['time_offset'] * 3600;
	else
		$time = $log_time;

	// We can't have a negative date (on Windows, at least.)
	if ($log_time < 0)
		$log_time = 0;

	// Today and Yesterday?
	if ($modSettings['todayMod'] >= 1 && $show_today === true)
	{
		// Get the current time.
		$nowtime = forum_time();

		$then = @getdate($time);
		$now = @getdate($nowtime);

		// Try to make something of a time format string...
		$s = strpos($user_info['time_format'], '%S') === false ? '' : ':%S';
		if (strpos($user_info['time_format'], '%H') === false && strpos($user_info['time_format'], '%T') === false)
		{
			$h = strpos($user_info['time_format'], '%l') === false ? '%I' : '%l';
			$today_fmt = $h . ':%M' . $s . ' %p';
		}
		else
			$today_fmt = '%H:%M' . $s;

		// Same day of the year, same year.... Today!
		if ($then['yday'] == $now['yday'] && $then['year'] == $now['year'])
			return $txt['today'] . timeformat($log_time, $today_fmt, $offset_type);

		// Day-of-year is one less and same year, or it's the first of the year and that's the last of the year...
		if ($modSettings['todayMod'] == '2' && (($then['yday'] == $now['yday'] - 1 && $then['year'] == $now['year']) || ($now['yday'] == 0 && $then['year'] == $now['year'] - 1) && $then['mon'] == 12 && $then['mday'] == 31))
			return $txt['yesterday'] . timeformat($log_time, $today_fmt, $offset_type);
	}

	$str = !is_bool($show_today) ? $show_today : $user_info['time_format'];

	$timeobj = new DateTime('@' . $time);

	// Do-it-yourself time localization since we don't depend on intl library. First param is $txt[] entry, second is the format string to extract from DateTime.
	$tokens = [
		'%a' => ['days_short', 'w'],
		'%A' => ['days', 'w'],
		'%b' => ['months_short', 'n'],
		'%B' => ['months', 'n'],
	];

	foreach ($tokens as $token => $details)
	{
		[$text_label, $format_string] = $details;
		if (strpos($str, $token) !== false)
		{
			$str = str_replace($token, $txt[$text_label][$timeobj->format($format_string)], $str);
		}
	}

	if (strpos($str, '%p') !== false)
	{
		$str = str_replace('%p', ((int) $timeobj->format('H') < 12 ? $txt['time_am'] : $txt['time_pm']), $str);
	}

	// Format whatever is left.
	$replaces = [
		'%d' => $timeobj->format('d'),
		'%#d' => $timeobj->format('j'),
		'%e' => $timeobj->format('j'),
		'%y' => $timeobj->format('y'),
		'%Y' => $timeobj->format('Y'),
		'%I' => $timeobj->format('h'),
		'%H' => $timeobj->format('H'),
		'%M' => $timeobj->format('i'),
		'%S' => $timeobj->format('s'),
		'%R' => $timeobj->format('H\:i'),
		'%T' => $timeobj->format('H\:i\:s'),
	];
	return strtr($str, $replaces);
}

/**
 * Like timeformat, formats a specific date (only).
 *
 * @param int $year The year to format
 * @param int $month The month to format
 * @param int $day The day to format
 * @param string $format The format to use; empty string to use forum default
 * @return string The date formatted to a given user format
 */
function dateformat(int $year, int $month, int $day, string $format = ''): string
{
	global $modSettings, $txt;

	if (empty($format))
		$format = $modSettings['time_format'];

	$excluded_items = ['%a', '%A', '%H', '%k', '%I', '%l', '%M', '%p', '%P', '%r', '%R', '%S', '%T', '%X', '%z', '%Z'];

	if (empty($year))
	{
		$excluded_items[] = '%y';
		$excluded_items[] = '%Y';
	}

	// This gives us the format we care about.
	$format = str_replace($excluded_items, '', $format);
	$format = trim($format, ',: ');

	// Now we have to be a little more careful but ultimately we're building a find/replace list.
	$replaces = [
		'%d' => substr('00' . $day, -2),
		'%#d' => (int) $day,
		'%e' => (int) $day,
		'%b' => $txt['months_short'][$month],
		'%B' => $txt['months'][$month],
		'%y' => substr($year, -2),
		'%Y' => $year,
	];

	return strtr($format, $replaces);
}

/**
 * Formats a timestamp into yyyy-mm-dd format.
 *
 * @param int $timestamp The timestamp to format.
 * @return string The formatted timestamp.
 */
function dateformat_ymd(int $timestamp): string
{
	return (new DateTime('@' . $timestamp))->format('Y-m-d');
}

/**
 * Removes special entities from strings.  Compatibility...
 * Should be used instead of html_entity_decode for PHP version compatibility reasons.
 *
 * - removes the base entities (&lt;, &quot;, etc.) from text.
 * - additionally converts &nbsp; and &#039;.
 *
 * @param string $string A string
 * @return string The string without entities
 */
function un_htmlspecialchars($string)
{
	static $translation = [];

	if (empty($translation))
		$translation = array_flip(get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES, 'UTF-8')) + ['&#039;' => '\'', '&#39;' => '\'', '&nbsp;' => ' '];

	return strtr($string, $translation);
}

/**
 * Shorten a subject + internationalization concerns.
 *
 * - shortens a subject so that it is either shorter than length, or that length plus an ellipsis.
 * - respects internationalization characters and entities as one character.
 * - avoids trailing entities.
 * - returns the shortened string.
 *
 * @param string $subject The subject
 * @param int $len How many characters to limit it to
 * @return string The shortened subject - either the entire subject (if it's <= $len) or the subject shortened to $len characters with "..." appended
 */
function shorten_subject($subject, $len)
{
	// It was already short enough!
	if (StringLibrary::strlen($subject) <= $len)
		return $subject;

	// Shorten it by the length it was too long, and strip off junk from the end.
	return StringLibrary::substr($subject, 0, $len) . '...';
}

/**
 * Gets the current time with offset.
 *
 * - always applies the offset in the time_offset setting.
 *
 * @param bool $use_user_offset Whether to apply the user's offset as well
 * @param int $timestamp A timestamp (null to use current time)
 * @return int Seconds since the unix epoch, with forum time offset and (optionally) user time offset applied
 */
function forum_time($use_user_offset = true, $timestamp = null)
{
	global $user_info, $modSettings;

	if ($timestamp === null)
		$timestamp = time();
	elseif ($timestamp == 0)
		return 0;

	return $timestamp + ($modSettings['time_offset'] + ($use_user_offset ? $user_info['time_offset'] : 0)) * 3600;
}

/**
 * Make sure the browser doesn't come back and repost the form data.
 * Should be used whenever anything is posted.
 *
 * @param string $setLocation The URL to redirect them to
 * @param bool $permanent Whether to send a 301 Moved Permanently instead of a 302 Moved Temporarily
 */
function redirectexit($setLocation = '', $permanent = false)
{
	global $scripturl, $context, $db_show_debug, $db_cache;

	// In case we have mail to send, better do that - as obExit doesn't always quite make it...
	if (!empty($context['flush_mail']))
		// @todo this relies on 'flush_mail' being only set in AddMailQueue itself... :\
		AddMailQueue(true);

	$add = preg_match('~^(ftp|http)[s]?://~', $setLocation) == 0 && substr($setLocation, 0, 6) != 'about:';

	if ($add)
		$setLocation = $scripturl . ($setLocation != '' ? '?' . $setLocation : '');

	// Maybe integrations want to change where we are heading?
	call_integration_hook('integrate_redirect', [&$setLocation, &$permanent]);

	flush_response_headers_bucket();

	// Set the header.
	header('Location: ' . str_replace(' ', '%20', $setLocation), true, $permanent ? 301 : 302);

	// Debugging.
	if (isset($db_show_debug) && $db_show_debug === true)
		$_SESSION['debug_redirect'] = $db_cache;

	obExit(false);
}

/**
 * Ends execution.  Takes care of template loading and remembering the previous URL.
 * @param bool $header Whether to do the header
 * @param bool $do_footer Whether to do the footer
 * @param bool $from_index Whether we're coming from the board index
 * @param bool $from_fatal_error Whether we're coming from a fatal error
 */
function obExit($header = null, $do_footer = null, $from_index = false, $from_fatal_error = false)
{
	global $context, $settings, $modSettings, $txt, $options, $scripturl, $user_info;
	static $header_done = false, $footer_done = false, $level = 0, $has_fatal_error = false;

	// Attempt to prevent a recursive loop.
	++$level;
	if ($level > 1 && !$from_fatal_error && !$has_fatal_error)
		exit;
	if ($from_fatal_error)
		$has_fatal_error = true;

	// Clear out the stat cache.
	trackStats();

	// If we have mail to send, send it.
	if (!empty($context['flush_mail']))
		// @todo this relies on 'flush_mail' being only set in AddMailQueue itself... :\
		AddMailQueue(true);

	$do_header = $header === null ? !$header_done : $header;
	if ($do_footer === null)
		$do_footer = $do_header;

	// Has the template/header been done yet?
	if ($do_header)
	{
		// Was the page title set last minute? Also update the HTML safe one.
		if (!empty($context['page_title']) && empty($context['page_title_html_safe']))
			$context['page_title_html_safe'] = StringLibrary::escape(un_htmlspecialchars($context['page_title'])) . (!empty($context['current_page']) ? ' - ' . $txt['page'] . ' ' . ($context['current_page'] + 1) : '');

		// Display the screen in the logical order.
		template_header();
		$header_done = true;
	}
	if ($do_footer)
	{
		$content = '';

		// Add the inner part
		if (empty($context['sub_template']))
		{
			$context['sub_template'] = 'main';
		}

		$context['session_flash'] = session_flash_retrieve();

		foreach ((array) $context['sub_template'] as $sub_template) {
			$phpStr = StoryBB\Template::compile(StoryBB\Template::load($sub_template), [], $settings['theme_id'] . '-' . $sub_template);
			$content .= StoryBB\Template::prepare($phpStr, [
				'context' => &$context,
				'txt' => $txt,
				'scripturl' => $scripturl,
				'settings' => $settings,
				'modSettings' => $modSettings,
				'options' => $options,
				'user_info' => $user_info,
			]);
		}
		StoryBB\Template::render_page($content);

		// Anything special to put out?
		if (!empty($context['insert_after_template']) && !isset($_REQUEST['xml']))
			echo $context['insert_after_template'];

		// Just so we don't get caught in an endless loop of errors from the footer...
		if (!$footer_done)
		{
			$footer_done = true;

			// (since this is just debugging... it's okay that it's after </html>.)
			if (!isset($_REQUEST['xml']))
				displayDebug();
		}
	}

	// Remember this URL in case someone doesn't like sending HTTP_REFERER.
	if (isset($_SERVER['REQUEST_URL']) && strpos($_SERVER['REQUEST_URL'], 'action=dlattach') === false)
		$_SESSION['old_url'] = $_SERVER['REQUEST_URL'];

	// For session check verification.... don't switch browsers...
	$_SESSION['USER_AGENT'] = empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'];

	// Hand off the output to the portal, etc. we're integrated with.
	call_integration_hook('integrate_exit', [$do_footer]);

	// Don't exit if we're coming from index.php; that will pass through normally.
	if (!$from_index)
		exit;
}

/**
 * Format the string for the header area when logged out.
 *
 * @param string $string The base language string
 * @param string $guest_title The name for guests
 * @param string $forum_name The forum name
 * @param string $scripturl The $scripturl to link to
 * @param string $login Title for the login popup modal
 * @return \LightnCandy\SafeString The login link, fully formatted
 */
function login_helper($string, $guest_title, $forum_name, $scripturl, $login) 
{
	$container = \StoryBB\Container::instance();
	return new \LightnCandy\SafeString(sprintf($string,
		$guest_title, 
		$forum_name, 
		$container->get('urlgenerator')->generate('login'), 
		'return reqOverlayDiv(this.href, ' . JavaScriptEscape($login) . ');', 
		$scripturl . '?action=signup'
	));
}

/**
 * Add a notification to the session to be shown on the next page the user sees.
 *
 * @param string $status The status of the message (success, warning, error)
 * @param string $message The message to show to the user
 */
function session_flash(string $status, string $message)
{
	$container = Container::instance();
	if (!in_array($status, ['success', 'warning', 'error', 'warning-secondary']))
	{
		fatal_error('Invalid session flash');
	}
	$flashbag = $container->get('session')->getFlashBag();
	$current_messages = $flashbag->peekAll();
	if (!isset($current_messages[$status]) || !in_array($message, $current_messages[$status]))
	{
		$flashbag->add($status, $message);
	}
}

/**
 * Retrieve all the queued notifications from the user's session for this page load.
 *
 * @return array A map of status -> messages to be shown
 */
function session_flash_retrieve()
{
	$container = Container::instance();
	$session = $container->get('session');

	$messages = [];
	foreach (['error', 'warning', 'success', 'warning-secondary'] as $status)
	{
		$messages[$status] = $session->getFlashBag()->get($status);
	}

	return $messages;
}

function session_flash_empty(string $status)
{
	$container = Container::instance();
	$flashbag = $container->get('session')->getFlashBag();
	$flashbag->get($status);
}

/**
 * Sets up the basic theme context stuff.
 * @param bool $forceload Whether to load the theme even if it's already loaded
 */
function setupThemeContext($forceload = false)
{
	global $modSettings, $user_info, $scripturl, $context, $settings, $txt;
	static $loaded = false;

	// Under some cases this function can be called more then once.  That can cause some problems.
	//   So only run the function once unless we are forced to run it again.
	if ($loaded && !$forceload)
		return;

	$loaded = true;

	$context['in_maintenance'] = App::in_maintenance();

	if (!$user_info['is_guest'])
	{
		$context['user']['messages'] = &$user_info['messages'];
		$context['user']['unread_messages'] = &$user_info['unread_messages'];
		$context['user']['alerts'] = &$user_info['alerts'];

		$_SESSION['unread_messages'] = $user_info['unread_messages'];

		if (allowedTo('moderate_forum'))
			$context['unapproved_members'] = !empty($modSettings['unapprovedMembers']) ? $modSettings['unapprovedMembers'] : 0;

		$context['user']['avatar'] = set_avatar_data(['filename' => $user_info['avatar']['filename'], 'avatar' => $user_info['avatar']['url']]);

		// Figure out how long they've been logged in.
		$context['user']['total_time_logged_in'] = [
			'days' => floor($user_info['total_time_logged_in'] / 86400),
			'hours' => floor(($user_info['total_time_logged_in'] % 86400) / 3600),
			'minutes' => floor(($user_info['total_time_logged_in'] % 3600) / 60)
		];
	}
	else
	{
		$context['user']['messages'] = 0;
		$context['user']['unread_messages'] = 0;
		$context['user']['avatar'] = [];
		$context['user']['total_time_logged_in'] = ['days' => 0, 'hours' => 0, 'minutes' => 0];
	}

	// Setup the main menu items.
	setupMenuContext();

	// Add a generic "Are you sure?" confirmation message.
	addInlineJavaScript('
	var sbb_you_sure =' . JavaScriptEscape($txt['quickmod_confirm']) .';');

	// This looks weird, but it's because BoardIndex.php references the variable.
	$context['common_stats']['latest_member'] = [
		'id' => $modSettings['latestMember'],
		'name' => $modSettings['latestRealName'],
		'href' => $scripturl . '?action=profile;u=' . $modSettings['latestMember'],
		'link' => '<a href="' . $scripturl . '?action=profile;u=' . $modSettings['latestMember'] . '">' . $modSettings['latestRealName'] . '</a>',
	];
	$context['common_stats'] = [
		'total_posts' => comma_format($modSettings['totalMessages']),
		'total_topics' => comma_format($modSettings['totalTopics']),
		'total_members' => comma_format($modSettings['totalMembers']),
		'latest_member' => $context['common_stats']['latest_member'],
	];
	$context['common_stats']['boardindex_total_posts'] = sprintf($txt['boardindex_total_posts'], $context['common_stats']['total_posts'], $context['common_stats']['total_topics'], $context['common_stats']['total_members']);

	if (!isset($context['page_title']))
		$context['page_title'] = '';

	// Set some specific vars.
	$context['page_title_html_safe'] = StringLibrary::escape(un_htmlspecialchars($context['page_title'])) . (!empty($context['current_page']) ? ' - ' . $txt['page'] . ' ' . ($context['current_page'] + 1) : '');
	$context['meta_keywords'] = !empty($modSettings['meta_keywords']) ? StringLibrary::escape($modSettings['meta_keywords']) : '';

	// Content related meta tags, including Open Graph
	$context['meta_tags'][] = ['property' => 'og:site_name', 'content' => $context['forum_name']];
	$context['meta_tags'][] = ['property' => 'og:title', 'content' => $context['page_title_html_safe']];

	if (!empty($context['meta_keywords']))
		$context['meta_tags'][] = ['name' => 'keywords', 'content' => $context['meta_keywords']];

	if (!empty($context['canonical_url']))
		$context['meta_tags'][] = ['property' => 'og:url', 'content' => $context['canonical_url']];

	if (!empty($settings['og_image']))
	{
		$context['meta_tags'][] = ['property' => 'og:image', 'content' => $settings['og_image']];
	}
	elseif (!empty($modSettings['favicon_cache']))
	{
		$favicons = json_decode($modSettings['favicon_cache'], true);

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		$sizes = [
			7 => [192, 192],
			3 => [180, 180],
			5 => [167, 167],
			4 => [152, 152],
			6 => [128, 128],
		];

		foreach ($sizes as $favicon_id => $size)
		{
			if (isset($favicons['favicon_' . $favicon_id]))
			{
				$context['meta_tags'][] = ['property' => 'og:image', 'content' => $urlgenerator->generate('favicon', ['id' => $favicon_id, 'timestamp' => $favicons['favicon_' . $favicon_id]])];
				$context['meta_tags'][] = ['property' => 'og:image:width', 'content' => $size[0]];
				$context['meta_tags'][] = ['property' => 'og:image:height', 'content' => $size[1]];
				break;
			}
		}
	}

	if (!empty($context['meta_description']))
	{
		$context['meta_tags'][] = ['property' => 'og:description', 'content' => $context['meta_description']];
		$context['meta_tags'][] = ['name' => 'description', 'content' => $context['meta_description']];
	}
	elseif (!empty($modSettings['meta_description']))
	{
		$context['meta_tags'][] = ['property' => 'og:description', 'content' => $modSettings['meta_description']];
		$context['meta_tags'][] = ['name' => 'description', 'content' => $modSettings['meta_description']];
	}
	else
	{
		$context['meta_tags'][] = ['property' => 'og:description', 'content' => $context['page_title_html_safe']];
		$context['meta_tags'][] = ['name' => 'description', 'content' => $context['page_title_html_safe']];
	}

	$blockmanager = \StoryBB\App::container()->get('blockmanager');

	call_integration_hook('integrate_theme_context');
}

function flush_response_headers_bucket()
{
	$container = Container::instance();
	$response_headers = $container->get('response_headers');
	if (!empty($response_headers))
	{
		foreach ($response_headers->all() as $header => $value)
		{
			if ($header == 'date' || $header == 'cache-control')
			{
				continue;
			}

			if ($header == 'set-cookie' || $header == 'Set-Cookie')
			{
				foreach ($response_headers->getCookies() as $cookie)
				{
					header($header . ':' . $cookie, false);
				}
				continue;
			}
			header($header . ':' . $value, true);
		}
	}
}

/**
 * The header template
 */
function template_header()
{
	global $txt, $modSettings, $context, $user_info, $boarddir, $cachedir;

	setupThemeContext();

	// Print stuff to prevent caching of pages (except on attachment errors, etc.)
	if (empty($context['no_last_modified']))
	{
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
	}

	header('Content-Type: text/' . (isset($_REQUEST['xml']) ? 'xml' : 'html') . '; charset=UTF-8');

	// Add any more things from the response header.
	flush_response_headers_bucket();

	$show_warnings = empty($context['layout_loaded']) || $context['layout_loaded'] == 'default';
	$show_warnings &= allowedTo('admin_forum');
	$show_warnings &= empty($user_info['is_guest']);
	if ($show_warnings)
	{
		// Check files that shouldn't be there for security reasons.
		$securityFiles = ['install.php', 'upgrade.php', 'convert.php', 'repair_settings.php', 'Settings.php~', 'Settings_bak.php~'];

		// Add your own files.
		call_integration_hook('integrate_security_files', [&$securityFiles]);
		foreach ($securityFiles as $i => $securityFile)
		{
			if (!file_exists($boarddir . '/' . $securityFile))
				unset($securityFiles[$i]);
		}
		if (!empty($securityFiles))
		{
			$warning = '<strong>' . $txt['security_risk'] . '</strong>';
			$warning .= '<br>' . $txt['not_removed'] . ': ' . implode(', ', $securityFiles);
			session_flash('error', $warning);
		}

		// We are already checking so many files...just few more doesn't make any difference!
		if (!empty($modSettings['currentAttachmentUploadDir']))
			$path = $modSettings['attachmentUploadDir'][$modSettings['currentAttachmentUploadDir']];
		else
			$path = $modSettings['attachmentUploadDir'];

		secureDirectory($path, true);
		secureDirectory($cachedir);

		// A few other minor checks we can make.
		if (!empty($modSettings['cache_enable']) && !is_writable($cachedir))
		{
			session_flash('error', $txt['cache_writable']);
		}
	}

	// Now we can warn people about being banned.
	if (isset($_SESSION['ban']['cannot_post'])) {
		$ban_message = sprintf($txt['you_are_post_banned'], $user_info['is_guest'] ? $txt['guest_title'] : $user_info['name']);

		if (!empty($_SESSION['ban']['cannot_post']['reason']))
		{
			$ban_message .= '<div class="ban_reason">' . $_SESSION['ban']['cannot_post']['reason'] . '</div>';
		}

		if (!empty($_SESSION['ban']['expire_time']))
		{
			$ban_message .= '<div class="ban_expiry">' . sprintf($txt['your_ban_expires'], timeformat($_SESSION['ban']['expire_time'], false)) . '</div>';
		}
		else
		{
			$ban_message .= '<div class="ban_expiry">' . $txt['your_ban_expires_never'] . '</div>';
		}
		session_flash('warning', $ban_message);
	}
}

/**
 * Output the Javascript files
 * 	- tabbing in this function is to make the HTML source look good proper
 *  - if defered is set function will output all JS (source & inline) set to load at page end
 *
 * @param bool $do_deferred If true will only output the deferred JS (the stuff that goes right before the closing body tag)
 */
function template_javascript($do_deferred = false)
{
	global $context, $modSettings, $settings;

	// Ugly hack for Lightncandy
	$do_deferred = !empty($do_deferred['hash']['deferred']);

	// Use this hook to minify/optimize Javascript files and vars
	call_integration_hook('integrate_pre_javascript_output', [&$do_deferred]);

	$toMinify = [];
	$toMinifyDefer = [];

	$return = '';

	// Ouput the declared Javascript variables.
	if (!empty($context['javascript_vars']) && !$do_deferred)
	{
		$return .= '
	<script>';

		foreach ($context['javascript_vars'] as $key => $value)
		{
			if (empty($value))
			{
				$return .= '
		var ' . $key . ';';
			}
			else
			{
				$return .= '
		var ' . $key . ' = ' . $value . ';';
			}
		}

		$return .= '
	</script>';
	}

	// While we have JavaScript files to place in the template.
	foreach ($context['javascript_files'] as $id => $js_file)
	{
		// Last minute call! allow theme authors to disable single files.
		if (!empty($settings['disable_files']) && in_array($id, $settings['disable_files']))
			continue;

		// By default all files don't get minimized unless the file explicitly says so!
		if (!empty($js_file['options']['minimize']) && !empty($modSettings['minimize_js']))
		{
			if ($do_deferred && !empty($js_file['options']['defer']))
				$toMinifyDefer[] = $js_file;

			elseif (!$do_deferred && empty($js_file['options']['defer']))
				$toMinify[] = $js_file;

			// Grab a random seed.
			if (!isset($minSeed))
				$minSeed = $js_file['options']['seed'];
		}

		elseif ((!$do_deferred && empty($js_file['options']['defer'])) || ($do_deferred && !empty($js_file['options']['defer'])))
			$return .= '
	<script src="' . $js_file['fileUrl'] . '"' . (!empty($js_file['options']['async']) ? ' async="async"' : '') . '></script>';
	}

	if ((!$do_deferred && !empty($toMinify)) || ($do_deferred && !empty($toMinifyDefer)))
	{
		$result = custMinify(($do_deferred ? $toMinifyDefer : $toMinify), 'js', $do_deferred);

		// Minify process couldn't work, print each individual files.
		if (!empty($result) && is_array($result))
			foreach ($result as $minFailedFile)
				$return .= '
	<script src="' . $minFailedFile['fileUrl'] . '"' . (!empty($minFailedFile['options']['async']) ? ' async="async"' : '') . '></script>';

		else
			$return .= '
	<script src="' . $settings['theme_url'] . '/scripts/minified' . ($do_deferred ? '_deferred' : '') . '.js' . $minSeed . '"></script>';
	}

	// Inline JavaScript - Actually useful some times!
	if (!empty($context['javascript_inline']))
	{
		if (!empty($context['javascript_inline']['defer']) && $do_deferred)
		{
			$return .= '
<script>';

			foreach ($context['javascript_inline']['defer'] as $js_code)
				$return .= $js_code;

			$return .= '
</script>';
		}

		if (!empty($context['javascript_inline']['standard']) && !$do_deferred)
		{
			$return .= '
	<script>';

			foreach ($context['javascript_inline']['standard'] as $js_code)
				$return .= $js_code;

			$return .= '
	</script>';
		}
	}

	return $return;
}

/**
 * Output the CSS files
 *
 */
function template_css()
{
	global $context, $db_show_debug, $boardurl, $settings, $modSettings;

	// Use this hook to minify/optimize CSS files
	call_integration_hook('integrate_pre_css_output');

	$toMinify = [];
	$normal = [];
	$return = '';

	foreach ($context['css_files'] as $id => $file)
	{
		// Last minute call! allow theme authors to disable single files.
		if (!empty($settings['disable_files']) && in_array($id, $settings['disable_files']))
			continue;

		// By default all files don't get minimized unless the file explicitly says so!
		if (!empty($file['options']['minimize']) && !empty($modSettings['minimize_css']))
		{
			$toMinify[] = $file;

			// Grab a random seed.
			if (!isset($minSeed))
				$minSeed = $file['options']['seed'];
		}

		else
			$normal[] = $file['fileUrl'];
	}

	if (!empty($toMinify))
	{
		$result = custMinify($toMinify, 'css');

		// Minify process couldn't work, print each individual files.
		if (!empty($result) && is_array($result))
			foreach ($result as $minFailedFile)
				$return .= '
	<link rel="stylesheet" href="' . $minFailedFile['fileUrl'] . '">';

		else
			$return .= '
	<link rel="stylesheet" href="' . $settings['theme_url'] . '/css/minified.css' . $minSeed . '">';
	}

	// Print the rest after the minified files.
	if (!empty($normal))
		foreach ($normal as $nf)
			$return .= '
	<link rel="stylesheet" href="' . $nf . '">';

	if ($db_show_debug === true)
	{
		// Try to keep only what's useful.
		$repl = [$boardurl . '/Themes/' => '', $boardurl . '/' => ''];
		foreach ($context['css_files'] as $file)
			$context['debug']['sheets'][] = strtr($file['fileName'], $repl);
	}

	if (!empty($context['css_header']))
	{
		$return .= '
	<style>';

		foreach ($context['css_header'] as $css)
			$return .= $css .'
	';

		$return .= '
	</style>';
	}
	return $return;
}

/**
 * Get an array of previously defined files and adds them to our main minified file.
 * Sets a one day cache to avoid re-creating a file on every request.
 *
 * @param array $data The files to minify.
 * @param string $type either css or js.
 * @param bool $do_deferred use for type js to indicate if the minified file will be deferred, IE, put at the closing </body> tag.
 * @return bool|array If an array the minify process failed and the data is returned intact.
 */
function custMinify($data, $type, $do_deferred = false)
{
	global $settings, $txt;

	$types = ['css', 'js'];
	$type = !empty($type) && in_array($type, $types) ? $type : false;
	$data = !empty($data) ? $data : false;

	if (empty($type) || empty($data))
		return false;

	// Did we already did this?
	$toCache = cache_get_data('minimized_'. $settings['theme_id'] .'_'. $type, 86400);

	// Already done?
	if (!empty($toCache))
		return true;

	// No namespaces, sorry!
	$classType = 'MatthiasMullie\\Minify\\'. strtoupper($type);

	// Temp path.
	$cTempPath = $settings['theme_dir'] .'/'. ($type == 'css' ? 'css' : 'scripts') .'/';

	// What kind of file are we going to create?
	$toCreate = $cTempPath .'minified'. ($do_deferred ? '_deferred' : '') .'.'. $type;

	// File has to exists, if it isn't try to create it.
	if ((!file_exists($toCreate) && @fopen($toCreate, 'w') === false) || !sbb_chmod($toCreate))
	{
		loadLanguage('Errors');
		log_error(sprintf($txt['file_not_created'], $toCreate), 'general');
		cache_put_data('minimized_'. $settings['theme_id'] .'_'. $type, null);

		// The process failed so roll back to print each individual file.
		return $data;
	}

	$minifier = new $classType();

	foreach ($data as $file)
	{
		$tempFile = str_replace($file['options']['seed'], '', $file['filePath']);
		$toAdd = file_exists($tempFile) ? $tempFile : false;

		// The file couldn't be located so it won't be added, log this error.
		if (empty($toAdd))
		{
			loadLanguage('Errors');
			log_error(sprintf($txt['file_minimize_fail'], $file['fileName']), 'general');
			continue;
		}

		// Add this file to the list.
		$minifier->add($toAdd);
	}

	// Create the file.
	$minifier->minify($toCreate);
	unset($minifier);
	clearstatcache();

	// Minify process failed.
	if (!filesize($toCreate))
	{
		loadLanguage('Errors');
		log_error(sprintf($txt['file_not_created'], $toCreate), 'general');
		cache_put_data('minimized_'. $settings['theme_id'] .'_'. $type, null);

		// The process failed so roll back to print each individual file.
		return $data;
	}

	// And create a long lived cache entry.
	cache_put_data('minimized_'. $settings['theme_id'] .'_'. $type, $toCreate, 86400);

	return true;
}

/**
 * Chops a string into words and prepares them to be inserted into (or searched from) the database.
 *
 * @param string $text The text to split into words
 * @param int $max_chars The maximum number of characters per word
 * @param bool $encrypt Whether to encrypt the results
 * @return array An array of ints or words depending on $encrypt
 */
function text2words($text, $max_chars = 20, $encrypt = false)
{
	// Step 1: Remove entities/things we don't consider words:
	$words = preg_replace('~(?:[\x0B\0\x{A0}\t\r\s\n(){}\\[\\]<>!@$%^*.,:+=`\~\?/\\\\]+|&(?:amp|lt|gt|quot);)+~u', ' ', strtr($text, ['<br>' => ' ']));

	// Step 2: Entities we left to letters, where applicable, lowercase.
	$words = un_htmlspecialchars(StringLibrary::toLower($words));

	// Step 3: Ready to split apart and index!
	$words = explode(' ', $words);

	if ($encrypt)
	{
		$possible_chars = array_flip(array_merge(range(46, 57), range(65, 90), range(97, 122)));
		$returned_ints = [];
		foreach ($words as $word)
		{
			if (($word = trim($word, '-_\'')) !== '')
			{
				$encrypted = substr(crypt($word, 'uk'), 2, $max_chars);
				$total = 0;
				for ($i = 0; $i < $max_chars; $i++)
					$total += $possible_chars[ord($encrypted[$i])] * pow(63, $i);
				$returned_ints[] = $max_chars == 4 ? min($total, 16777215) : $total;
			}
		}
		return array_unique($returned_ints);
	}
	else
	{
		// Trim characters before and after and add slashes for database insertion.
		$returned_words = [];
		foreach ($words as $word)
			if (($word = trim($word, '-_\'')) !== '')
				$returned_words[] = $max_chars === null ? $word : substr($word, 0, $max_chars);

		// Filter out all words that occur more than once.
		return array_unique($returned_words);
	}
}

/**
 * Creates an image/text button
 *
 * @param string $name The name of the button (should be a main_icons class or the name of an image)
 * @param string $alt The alt text
 * @param string $label The $txt string to use as the label
 * @return string The HTML to display the button
 */
function create_button($name, $alt, $label = '')
{
	global $txt;

	return '<span class="main_icons ' . $name . '" alt="' . $txt[$alt] . '"></span>' . ($label != '' ? '&nbsp;<strong>' . $txt[$label] . '</strong>' : '');
}

/**
 * Sets up all of the top menu buttons
 * Saves them in the cache if it is available and on
 * Places the results in $context
 *
 */
function setupMenuContext()
{
	global $context, $modSettings, $user_info, $txt, $scripturl, $sourcedir, $settings, $smcFunc;

	$container = \StoryBB\Container::instance();
	$urlgenerator = $container->get('urlgenerator');

	// Set up the menu privileges.
	$context['allow_search'] = !empty($modSettings['allow_guestAccess']) ? allowedTo('search_posts') : (!$user_info['is_guest'] && allowedTo('search_posts'));
	$context['allow_admin'] = allowedTo(['admin_forum', 'manage_boards', 'manage_permissions', 'moderate_forum', 'manage_membergroups', 'manage_bans', 'manage_attachments', 'manage_smileys']);

	$context['allow_memberlist'] = allowedTo('view_mlist');
	$context['allow_moderation_center'] = $context['user']['can_mod'];
	$context['allow_pm'] = allowedTo('pm_read');

	$cacheTime = $modSettings['lastActive'] * 60;

	// There is some menu stuff we need to do if we're coming at this from a non-guest perspective.
	if (!$context['user']['is_guest'])
	{
		addInlineJavaScript('
	var user_menus = new smc_PopupMenu();
	user_menus.add("alerts", "' . $scripturl . '?action=profile;area=alerts_popup;u='. $context['user']['id'] .'", false, true);
	user_menus.add("characters", "' . $scripturl . '?action=profile;area=characters_popup", false, true);', true);
		if ($context['allow_search'])
		{
			addInlineJavaScript('
	user_menus.add("search", "", true);', true);
		}
		if ($context['allow_pm'])
			addInlineJavaScript('
	user_menus.add("pm", "' . $scripturl . '?action=pm;sa=popup");', true);

		if (!empty($modSettings['enable_ajax_alerts']))
		{
			require_once($sourcedir . '/Subs-Notify.php');

			$timeout = getNotifyPrefs($context['user']['id'], 'alert_timeout', true);
			$timeout = empty($timeout) ? 10000 : $timeout[$context['user']['id']]['alert_timeout'] * 1000;

			addInlineJavaScript('
	var new_alert_title = "' . $context['forum_name'] . '";
	var alert_timeout = ' . $timeout . ';');
			loadJavaScriptFile('alerts.js', [], 'sbb_alerts');
		}

		if ($context['allow_admin'] || $context['allow_moderation_center'])
		{
			addInlineJavaScript('
	user_menus.add("manage", "", true);', true);
		}
	}

	$context['sidebar'] = [
		'home' => [
			'url' => $scripturl,
			'icon' => 'fas fa-home fa-fw',
			'label' => $txt['home'],
		],
		'search' => [
			'url' => $scripturl . '?action=search',
			'icon' => 'fas fa-search fa-fq',
			'label' => $txt['search'],
		],
	];

	if ($context['user']['is_logged'])
	{
		$context['sidebar'] += [
			'topictracker' => [
				'url' => $urlgenerator->generate('topictracker'),
				'icon' => 'fas fa-clipboard-check fa-fw',
				'label' => $txt['topic_tracker'],
				'position' => 'top',
			],
			'alerts' => [
				'url' => $scripturl . '?action=profile;area=alerts;u=' . $context['user']['id'],
				'icon' => 'far fa-bell fa-fw',
				'label' => $txt['alerts'],
				'popupmenu' => true,
				'amt' => $context['user']['alerts'],
				'position' => 'top',
			],
			'pm' => [
				'url' => $scripturl . '?action=pm',
				'icon' => 'far fa-comments fa-fw',
				'label' => $txt['personal_messages'],
				'popupmenu' => true,
				'position' => 'top',
				'amt' => $context['user']['unread_messages'],
				'visible' => $context['allow_pm'],
			],
			'characterlist' => [
				'url' => $urlgenerator->generate('characters'),
				'icon' => 'fas fa-users fa-fw',
				'label' => $txt['chars_menu_title'],
			],
			'shipper' => [
				'url' => $urlgenerator->generate('shippers'),
				'icon' => 'fas fa-ship fa-fw',
				'label' => $txt['shippers'],
				'visible' => !empty($modSettings['enable_shipper']),
			],
		];

		foreach (get_sidebar_page_items() as $page)
		{
			$context['sidebar']['page-' . $page['page_name']] = [
				'url' => $urlgenerator->generate('pages', ['page' => $page['page_name']]),
				'icon' => $page['sidebar_icon'] . ' fa-fw',
				'label' => $page['page_title'],
			];
		}

		$context['sidebar'] += [
			'manage' => [
				'url' => $scripturl,
				'icon' => 'fas fa-tools fa-fw',
				'label' => $txt['admin'],
				'visible' => $context['allow_admin'] || $context['user']['can_mod'],
				'subitems' => [
					'admin' => [
						'title' => $txt['admin'],
						'url' => $scripturl . '?action=admin',
						'visible' => $context['allow_admin'],
					],
					'errorlog' => [
						'title' => $txt['errlog'],
						'url' => $scripturl . '?action=admin;area=logs;sa=errorlog;desc',
						'visible' => allowedTo('admin_forum') && !empty($modSettings['enableErrorLogging']),
						'amt_callback' => function() use ($smcFunc) {
							return $smcFunc['db']->count('id_error', '{db_prefix}log_errors');
						}
					],
					'memberapprove' => [
						'title' => $txt['approve_members_waiting'],
						'url' => $scripturl . '?action=admin;area=viewmembers;sa=browse;type=approve',
						'visible' => !empty($context['unapproved_members']),
						'amt' => $context['unapproved_members'] ?? 0,
					],
					'pending_character_sheets' => [
						'title' => $txt['char_sheet_admin'],
						'url' => $scripturl . '?action=admin;area=sheets',
						'visible' => allowedTo('admin_forum'),
						'amt_callback' => function() {
							return Character::count_pending_character_sheets();
						}
					],
					'contactform' => [
						'title' => $txt['contact_us'],
						'url' => $scripturl . '?action=admin;area=contactform',
						'visible' => allowedTo('admin_forum'),
						'amt_callback' => function() use ($smcFunc) {
							$query = $smcFunc['db']->query('', '
								SELECT COUNT(id_message)
								FROM {db_prefix}contact_form
								WHERE status = 0');
							list($contactform) = $smcFunc['db']->fetch_row($query);
							$smcFunc['db']->free_result($query);

							return $contactform;
						}
					],
					'moderate' => [
						'title' => $txt['moderate'],
						'url' => $scripturl . '?action=moderate',
						'visible' => $context['allow_moderation_center'],
					],
					'modlog' => [
						'title' => $txt['modlog_view'],
						'url' => $scripturl . '?action=moderate;area=modlog',
						'visible' => !empty($user_info['mod_cache']) && $user_info['mod_cache']['bq'] != '0=1',
					],
					'poststopics' => [
						'title' => $txt['mc_unapproved_poststopics'],
						'url' => $scripturl . '?action=moderate;area=postmod;sa=posts',
						'visible' => !empty($user_info['mod_cache']['ap']),
					],
					'attachments' => [
						'title' => $txt['mc_unapproved_attachments'],
						'url' => $scripturl . '?action=moderate;area=attachmod;sa=attachments',
						'visible' => !empty($user_info['mod_cache']['ap']),
					],
					'reports' => [
						'title' => $txt['mc_reported_posts'],
						'url' => $scripturl . '?action=moderate;area=reportedposts',
						'visible' => !empty($user_info['mod_cache']) && $user_info['mod_cache']['bq'] != '0=1',
						'amt' => $context['open_mod_reports'],
					],
					'reported_members' => [
						'title' => $txt['mc_reported_members'],
						'url' => $scripturl . '?action=moderate;area=reportedmembers',
						'visible' => allowedTo('moderate_forum'),
						'amt' => $context['open_member_reports'],
					]
				],
			],
		];
	}
	else
	{
		foreach (get_sidebar_page_items() as $page)
		{
			$context['sidebar']['page-' . $page['page_name']] = [
				'url' => $urlgenerator->generate('pages', ['page' => $page['page_name']]),
				'icon' => $page['sidebar_icon'] . ' fa-fw',
				'label' => $page['page_title'],
			];
		}
	}

	(new Mutatable\SidebarMenu($context['sidebar']))->execute();

	foreach ($context['sidebar'] as $key => $item)
	{
		// Remove the top level items that aren't visible.
		if (isset($item['visible']) && !$item['visible'])
		{
			unset ($context['sidebar'][$key]);
			continue;
		}

		// If it has sub items, iterate over them.
		if (isset($item['subitems']))
		{
			foreach ($item['subitems'] as $subkey => $subitem)
			{
				// Remove subitems that aren't visible.
				if (isset($subitem['visible']) && !$subitem['visible'])
				{
					unset ($context['sidebar'][$key]['subitems'][$subkey]);
					continue;
				}

				// If this has an amount that isn't trivially calculable, go calculate it.
				if (!empty($subitem['amt_callback']))
				{
					$context['sidebar'][$key]['subitems'][$subkey]['amt'] = $subitem['amt'] = $subitem['amt_callback']();
				}

				// Take the counts of subitems and total them up for the parent.
				if (!empty($subitem['amt']) && is_numeric($subitem['amt']))
				{
					if (!isset($context['sidebar'][$key]['amt']))
					{
						$context['sidebar'][$key]['amt'] = 0;
					}

					$context['sidebar'][$key]['amt'] += $subitem['amt'];
				}
			}
		}

		// If it doesn't define a position, assign it left.
		if (!isset($item['position']))
		{
			$context['sidebar'][$key]['position'] = 'left';
		}
	}

	$policy = App::make('StoryBB\\Model\\Policy');
	$context['footer_links'] = $policy->get_footer_policies($user_info['language']);
}

/**
 * Returns the list of pages for the current user in a format suitable for the sidebar.
 *
 * @return array An array of items for the sidebar.
 */
function get_sidebar_page_items(): array
{
	global $smcFunc, $user_info;

	$pages = [];

	$base_access = allowedTo('admin_forum') ? 'a' : 'x';

	$request = $smcFunc['db']->query('', '
		SELECT id_page, page_name, page_title, sidebar_icon
		FROM {db_prefix}page
		WHERE show_sidebar = 1
		ORDER BY page_title');
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		$row['access'] = $base_access;

		if (empty($row['sidebar_icon']))
		{
			$row['sidebar_icon'] = 'far fa-question-circle';
		}
		$pages[$row['id_page']] = $row;
	}
	$smcFunc['db']->free_result($request);

	if (empty($pages))
	{
		return [];
	}

	// Admins don't need to check.
	if (allowedTo('admin_forum'))
	{
		return $pages;
	}

	$request = $smcFunc['db']->query('', '
		SELECT id_page, MAX(allow_deny) AS access
		FROM {db_prefix}page_access
		WHERE id_page IN ({array_int:pages})
			AND id_group IN ({array_int:groups})
		GROUP BY id_page',
		[
			'pages' => array_keys($pages),
			'groups' => $user_info['groups'],
		]
	);
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		$pages[$row['id_page']]['access'] = $row['access'] ? 'd' : 'a';
	}
	$smcFunc['db']->free_result($request);

	foreach ($pages as $id_page => $page)
	{
		if ($page['access'] != 'a')
		{
			unset($pages[$id_page]);
		}
	}

	return $pages;
}

/**
 * This functions exactly the same as call_integration_hook but allows for classifying hooks
 * that are for updating settings as opposed to routing or general runtime manipulation.
 * @param string $hook The hook name
 * @param array $parameters An array of parameters this hook implements
 * @return array The results of the functions
 */
function settings_integration_hook($hook, $parameters = [])
{
	return call_integration_hook($hook, $parameters);
}

/**
 * This functions exactly the same as call_integration_hook but allows for classifying hooks
 * that are for routing purposes rather than modifying runtime state.
 * @param string $hook The hook name
 * @param array $parameters An array of parameters this hook implements
 * @return array The results of the functions
 */
function routing_integration_hook($hook, $parameters = [])
{
	return call_integration_hook($hook, $parameters);
}

/**
 * Process functions of an integration hook.
 * calls all functions of the given hook.
 * supports static class method calls.
 *
 * @param string $hook The hook name
 * @param array $parameters An array of parameters this hook implements
 * @return array The results of the functions
 */
function call_integration_hook($hook, $parameters = [])
{
	global $modSettings, $settings, $boarddir, $sourcedir, $db_show_debug;
	global $context, $txt;

	if ($db_show_debug === true)
		$context['debug']['hooks'][] = $hook;

	// Need to have some control.
	if (!isset($context['instances']))
		$context['instances'] = [];

	$results = [];
	if (empty($modSettings[$hook]))
		return $results;

	$functions = explode(',', $modSettings[$hook]);
	// Loop through each function.
	foreach ($functions as $function)
	{
		// Hook has been marked as "disabled". Skip it!
		if (strpos($function, '!') !== false)
			continue;

		$call = call_helper($function, true);

		// Is it valid?
		if (!empty($call))
			$results[$function] = call_user_func_array($call, $parameters);

		// Whatever it was suppose to call, it failed :(
		elseif (!empty($function))
		{
			loadLanguage('Errors');

			// Get a full path to show on error.
			if (strpos($function, '|') !== false)
			{
				list ($file, $string) = explode('|', $function);
				$absPath = empty($settings['theme_dir']) ? (strtr(trim($file), ['$boarddir' => $boarddir, '$sourcedir' => $sourcedir])) : (strtr(trim($file), ['$boarddir' => $boarddir, '$sourcedir' => $sourcedir, '$themedir' => $settings['theme_dir']]));
				log_error(sprintf($txt['hook_fail_call_to'], $string, $absPath), 'general');
			}

			// "Assume" the file resides on $boarddir somewhere...
			else
				log_error(sprintf($txt['hook_fail_call_to'], $function, $boarddir), 'general');
		}
	}

	return $results;
}

/**
 * Receives a string and tries to figure it out if its a method or a function.
 * If a method is found, it looks for a "#" which indicates StoryBB should create a new instance of the given class.
 * Checks the string/array for is_callable() and return false/fatal_lang_error is the given value results in a non callable string/array.
 * Prepare and returns a callable depending on the type of method/function found.
 *
 * @param mixed $string The string containing a function name or a static call. The function can also accept a closure, object or a callable array (object/class, valid_callable)
 * @param boolean $return If true, the function will not call the function/method but instead will return the formatted string.
 * @return string|array|boolean Either a string or an array that contains a callable function name or an array with a class and method to call. Boolean false if the given string cannot produce a callable var.
 */
function call_helper($string, $return = false)
{
	global $context, $txt, $db_show_debug;

	// Really?
	if (empty($string))
		return false;

	// An array? should be a "callable" array IE array(object/class, valid_callable).
	// A closure? should be a callable one.
	if (is_array($string) || $string instanceof Closure)
		return $return ? $string : (is_callable($string) ? call_user_func($string) : false);

	// No full objects, sorry! pass a method or a property instead!
	if (is_object($string))
		return false;

	// Stay vitaminized my friends...
	$string = StringLibrary::escape(StringLibrary::htmltrim($string));

	// Loaded file failed
	if (empty($string))
		return false;

	// Found a method.
	if (strpos($string, '::') !== false)
	{
		list ($class, $method) = explode('::', $string);

		// Check if a new object will be created.
		if (strpos($method, '#') !== false)
		{
			// Need to remove the # thing.
			$method = str_replace('#', '', $method);

			// Don't need to create a new instance for every method.
			if (empty($context['instances'][$class]) || !($context['instances'][$class] instanceof $class))
			{
				$context['instances'][$class] = new $class;

				// Add another one to the list.
				if ($db_show_debug === true)
				{
					if (!isset($context['debug']['instances']))
						$context['debug']['instances'] = [];

					$context['debug']['instances'][$class] = $class;
				}
			}

			$func = [$context['instances'][$class], $method];
		}

		// Right then. This is a call to a static method.
		else
			$func = [$class, $method];
	}

	// Nope! just a plain regular function.
	else
		$func = $string;

	// Right, we got what we need, time to do some checks.
	if (!is_callable($func, false, $callable_name))
	{
		loadLanguage('Errors');
		log_error(sprintf($txt['subAction_fail'], $callable_name), 'general');

		// Gotta tell everybody.
		return false;
	}

	// Everything went better than expected.
	else
	{
		// What are we gonna do about it?
		if ($return)
			return $func;

		// If this is a plain function, avoid the heat of calling call_user_func().
		else
		{
			if (is_array($func))
				call_user_func($func);

			else
				$func();
		}
	}
}

/**
 * Decode numeric html entities to their ascii or UTF8 equivalent character.
 *
 * Callback function for preg_replace_callback in subs-members
 * Uses capture group 2 in the supplied array
 * Does basic scan to ensure characters are inside a valid range
 *
 * @param array $matches An array of matches (relevant info should be the 3rd item)
 * @return string A fixed string
 */
function replaceEntities__callback($matches)
{
	if (!isset($matches[2]))
		return '';

	$num = $matches[2][0] === 'x' ? hexdec(substr($matches[2], 1)) : (int) $matches[2];

	// remove left to right / right to left overrides
	if ($num === 0x202D || $num === 0x202E)
		return '';

	// Quote, Ampersand, Apostrophe, Less/Greater Than get html replaced
	if (in_array($num, [0x22, 0x26, 0x27, 0x3C, 0x3E]))
	{
		return '&#' . $num . ';';
	}

	// <0x20 are control characters, 0x20 is a space, > 0x10FFFF is past the end of the utf8 character set
	// 0xD800 >= $num <= 0xDFFF are surrogate markers (not valid for utf8 text)
	if ($num < 0x20 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF))
	{
		return '';
	}
	// <0x80 (or less than 128) are standard ascii characters a-z A-Z 0-9 and punctuation
	elseif ($num < 0x80)
	{
		return chr($num);
	}
	// <0x800 (2048)
	elseif ($num < 0x800)
	{
		return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
	}
	// < 0x10000 (65536)
	elseif ($num < 0x10000)
	{
		return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
	}
	// <= 0x10FFFF (1114111)
	else
	{
		return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
	}
}

/**
 * Converts html entities to utf8 equivalents
 *
 * Callback function for preg_replace_callback
 * Uses capture group 1 in the supplied array
 * Does basic checks to keep characters inside a viewable range.
 *
 * @param array $matches An array of matches (relevant info should be the 2nd item in the array)
 * @return string The fixed string
 */
function fixchar__callback($matches)
{
	if (!isset($matches[1]))
		return '';

	$num = $matches[1][0] === 'x' ? hexdec(substr($matches[1], 1)) : (int) $matches[1];

	// <0x20 are control characters, > 0x10FFFF is past the end of the utf8 character set
	// 0xD800 >= $num <= 0xDFFF are surrogate markers (not valid for utf8 text), 0x202D-E are left to right overrides
	if ($num < 0x20 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF) || $num === 0x202D || $num === 0x202E)
		return '';
	// <0x80 (or less than 128) are standard ascii characters a-z A-Z 0-9 and punctuation
	elseif ($num < 0x80)
		return chr($num);
	// <0x800 (2048)
	elseif ($num < 0x800)
		return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
	// < 0x10000 (65536)
	elseif ($num < 0x10000)
		return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
	// <= 0x10FFFF (1114111)
	else
		return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
}

/**
 * Tries different modes to make file/dirs writable. Wrapper function for chmod()

 * @param string $file The file/dir full path.
 * @return boolean  true if the file/dir is already writable or the function was able to make it writable, false if the function couldn't make the file/dir writable.
 */
function sbb_chmod($file)
{
	// No file? no checks!
	if (empty($file))
		return false;

	// Already writable?
	if (is_writable($file))
		return true;

	// Do we have a file or a dir?
	$isDir = is_dir($file);
	$isWritable = false;

	// Set different modes.
	$chmodValues = $isDir ? [0750, 0755, 0775, 0777] : [0644, 0664, 0666];

	foreach($chmodValues as $val)
	{
		// If it's writable, break out of the loop.
		if (is_writable($file))
		{
			$isWritable = true;
			break;
		}

		else
			@chmod($file, $val);
	}

	return $isWritable;
}

/**
 * Wrapper function for json_decode() with error handling.

 * @param string $json The string to decode.
 * @param bool $returnAsArray To return the decoded string as an array or an object, StoryBB only uses Arrays but to keep on compatibility with json_decode its set to false as default.
 * @param bool $logIt To specify if the error will be logged if theres any.
 * @return array Either an empty array or the decoded data as an array.
 */
function sbb_json_decode($json, $returnAsArray = false, $logIt = true)
{
	global $txt;

	// Come on...
	if (empty($json) || !is_string($json))
		return [];

	$returnArray = @json_decode($json, $returnAsArray);

	// PHP 5.3 so no json_last_error_msg()
	switch(json_last_error())
	{
		case JSON_ERROR_NONE:
			$jsonError = false;
			break;
		case JSON_ERROR_DEPTH:
			$jsonError = 'JSON_ERROR_DEPTH';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			$jsonError = 'JSON_ERROR_STATE_MISMATCH';
			break;
		case JSON_ERROR_CTRL_CHAR:
			$jsonError = 'JSON_ERROR_CTRL_CHAR';
			break;
		case JSON_ERROR_SYNTAX:
			$jsonError = 'JSON_ERROR_SYNTAX';
			break;
		case JSON_ERROR_UTF8:
			$jsonError = 'JSON_ERROR_UTF8';
			break;
		default:
			$jsonError = 'unknown';
			break;
	}

	// Something went wrong!
	if (!empty($jsonError) && $logIt)
	{
		// Being a wrapper means we lost our sbb_error_handler() privileges :(
		$jsonDebug = debug_backtrace();
		$jsonDebug = $jsonDebug[0];
		loadLanguage('Errors');

		if (!empty($jsonDebug))
			log_error($txt['json_'. $jsonError], 'critical', $jsonDebug['file'], $jsonDebug['line']);

		else
			log_error($txt['json_'. $jsonError], 'critical');

		// Everyone expects an array.
		return [];
	}

	return $returnArray;
}

/**
 * Outputs a response.
 * It assumes the data is already a string.
 * @param string $data The data to print
 * @param string $type The content type. Defaults to Json.
 * @return void
 */
function sbb_serverResponse($data = '', $type = 'Content-Type: application/json')
{
	global $db_show_debug;

	// Defensive programming anyone?
	if (empty($data))
		return false;

	// Don't need extra stuff...
	$db_show_debug = false;

	// Kill anything else.
	ob_end_clean();
	ob_start();

	// Set the header.
	header($type);

	// Echo!
	echo $data;

	// Done.
	obExit(false);
}

/**
 * Build query_wanna_see_board and query_see_board for a userid
 *
 * @param int $userid of the user
 * @return array Array with keys query_wanna_see_board and query_see_board
 */
function build_query_board($userid)
{
	global $user_info, $modSettings, $smcFunc;

	$query_part = [];
	$groups = [];
	$is_admin = false;
	$mod_cache = [];
	$ignoreboards = [];

	if (isset($user_info['id']) && $user_info['id'] == $userid)
	{
		$groups = $user_info['groups'];
		$is_admin = $user_info['is_admin'];
		$mod_cache = !empty($user_info['mod_cache']) ? $user_info['mod_cache'] : [];
		$ignoreboards = !empty($user_info['ignoreboards']) ? $user_info['ignoreboards'] : [];
	}
	else
	{
		$request = $smcFunc['db']->query('', '
				SELECT mem.ignore_boards, mem.id_group, mem.additional_groups
				FROM {db_prefix}members AS mem
				WHERE mem.id_member = {int:id_member}
				LIMIT 1',
				[
					'id_member' => $userid,
				]
			);

		$row = $smcFunc['db']->fetch_assoc($request);

		if (empty($row['additional_groups']))
			$groups = [$row['id_group']];
		else
			$groups = array_merge(
					[$row['id_group']],
					explode(',', $row['additional_groups'])
			);

		// Because history has proven that it is possible for groups to go bad - clean up in case.
		foreach ($groups as $k => $v)
			$groups[$k] = (int) $v;

		$is_admin = in_array(1, $groups);

		$ignoreboards = !empty($row['ignore_boards']) && !empty($modSettings['allow_ignore_boards']) ? explode(',', $row['ignore_boards']) : [];

		// What boards are they the moderator of?
		$boards_mod = [];

		$request = $smcFunc['db']->query('', '
			SELECT id_board
			FROM {db_prefix}moderators
			WHERE id_member = {int:current_member}',
			[
				'current_member' => $userid,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$boards_mod[] = $row['id_board'];
		$smcFunc['db']->free_result($request);

		// Can any of the groups they're in moderate any of the boards?
		$request = $smcFunc['db']->query('', '
			SELECT id_board
			FROM {db_prefix}moderator_groups
			WHERE id_group IN({array_int:groups})',
			[
				'groups' => $groups,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$boards_mod[] = $row['id_board'];
		$smcFunc['db']->free_result($request);

		// Just in case we've got duplicates here...
		$boards_mod = array_unique($boards_mod);

		$mod_cache['mq'] = empty($boards_mod) ? '0=1' : 'b.id_board IN (' . implode(',', $boards_mod) . ')';
	}
	
	// Just build this here, it makes it easier to change/use - administrators can see all boards.
	if ($is_admin)
		$query_part['query_see_board'] = '1=1';
	// Otherwise just the groups in $user_info['groups'].
	else
		$query_part['query_see_board'] = '(((FIND_IN_SET(' . implode(', b.member_groups) != 0 OR FIND_IN_SET(', $groups) . ', b.member_groups) != 0) AND (FIND_IN_SET(' . implode(', b.deny_member_groups) = 0 AND FIND_IN_SET(', $groups) . ', b.deny_member_groups) = 0))' . (isset($mod_cache['mq']) ? ' OR ' . $mod_cache['mq'] : '') . ')';

	// Build the list of boards they WANT to see.
	// This will take the place of query_see_boards in certain spots, so it better include the boards they can see also

	// If they aren't ignoring any boards then they want to see all the boards they can see
	if (empty($ignoreboards))
		$query_part['query_wanna_see_board'] = $query_part['query_see_board'];
	// Ok I guess they don't want to see all the boards
	else
		$query_part['query_wanna_see_board'] = '(' . $query_part['query_see_board'] . ' AND b.id_board NOT IN (' . implode(',', $ignoreboards) . '))';

	return $query_part;
}

/**
 * Return the list of character groups that have at least 1 character in them with an approved character seet.
 *
 * @return array An array of groups
 */
function get_main_menu_groups()
{
	global $smcFunc;
	if (($groups = cache_get_data('char_main_menu_groups', 300)) === null)
	{
		$groups = [];
		$request = $smcFunc['db']->query('', '
			SELECT mg.id_group, mg.group_name
			FROM {db_prefix}membergroups AS mg
			INNER JOIN {db_prefix}characters AS chars ON (chars.main_char_group = mg.id_group)
			WHERE chars.char_sheet != 0
			GROUP BY mg.id_group, mg.group_name, mg.badge_order
			ORDER BY mg.badge_order, mg.group_name');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$groups[$row['id_group']] = $row['group_name'];
		}
		$smcFunc['db']->free_result($request);

		cache_put_data('char_main_menu_groups', $groups, 300);
	}
	return $groups;
}

/**
 * Gets the list of possible characters applicable to a user right now.
 *
 * @param int $id_member The member whose characters we should look at.
 * @param int $board_id The board ID in which we want to look at relevant characters.
 * @return array An array of characters that could conceivably post in the current board based on IC/OOC rules.
 */
function get_user_possible_characters($id_member, $board_id = 0)
{
	global $settings, $board_info, $modSettings, $memberContext, $user_profile, $smcFunc;
	global $user_info, $scripturl;
	static $boards_ic = [], $announced = false;

	// First, some healthy defaults.
	if (empty($modSettings['characters_ic_may_post']))
		$modSettings['characters_ic_may_post'] = 'ic';
	if (empty($modSettings['characters_ooc_may_post']))
		$modSettings['characters_ooc_may_post'] = 'ooc';

	$characters = [];

	if (empty($id_member))
	{
		return [];
	}

	if (empty($user_profile[$id_member]))
		loadMemberData($id_member);
	if (empty($memberContext[$id_member]))
		loadMemberContext($id_member, true);

	if (empty($memberContext[$id_member]['characters']))
	{
		return [];
	}

	if (isset($boards_ic[$board_id]))
	{
		$board_in_character = $boards_ic[$board_id];
	}
	else
	{
		if (isset($board_info['id']) && $board_info['id'] == $board_id) {
			$board_in_character = !empty($board_info['in_character']);
		} else {
			$board_in_character = false;
			$request = $smcFunc['db']->query('', '
				SELECT id_board, in_character
				FROM {db_prefix}boards');
			while ($row = $smcFunc['db']->fetch_assoc($request))
			{
				$boards_ic[$row['id_board']] = $row['in_character'];
			}
			$smcFunc['db']->free_result($request);

			if (isset($boards_ic[$board_id]))
			{
				$board_in_character = $boards_ic[$board_id];
			}
		}
	}

	foreach ($memberContext[$id_member]['characters'] as $char_id => $character)
	{
		if (!allowedTo('admin_forum'))
		{
			if ($board_in_character)
			{
				if ($modSettings['characters_ic_may_post'] == 'ic' && $character['is_main'])
				{
					// IC board that requires IC only, and character is main and (not admin or no admin override)
					if (!$announced && $character['id_character'] == $user_info['id_character'])
					{
						session_flash('warning', (string) new Phrase('General:cannot_post_ooc'));
						$announced = true;
					}
					continue;
				}
			}
			else
			{
				if ($modSettings['characters_ooc_may_post'] == 'ooc' && !$character['is_main'])
				{
					// OOC board that requires OOC only, and character is not main and (not admin or no admin override)
					if (!$announced && $character['id_character'] == $user_info['id_character'])
					{
						session_flash('warning', (string) new Phrase('General:cannot_post_ic'));
						$announced = true;
					}
					continue;
				}
			}

			if (!$character['is_main'] && !empty($modSettings['characters_ic_require_sheet']) && empty($character['char_sheet']))
			{
				if ($char_id == $user_info['id_character'] && !$announced)
				{
					$announced = true;
					$link = $scripturl . '?action=profile;area=character_sheet;u=' . $user_info['id'] . ';char=' . $char_id;
					session_flash('warning', (string) new Phrase('General:cannot_post_ic_without_sheet', [$link]));
				}
				continue;
			}
		}

		$characters[$char_id] = [
			'name' => $character['character_name'],
			'avatar' => !empty($character['avatar']) ? $character['avatar'] : $settings['images_url'] . '/default.png',
		];
	}

	return $characters;
}

/**
 * Check if the connection is using HTTPS.
 * 
 * @return boolean true if connection used https
 */
function httpsOn()
{
	$container = Container::instance();
	$request = $container->get('requestvars');
	return $request->isSecure();
}

function get_avatar_choices($user_id = null, $character_id = null)
{
	global $user_info, $context, $memberContext, $txt;

	$avatar_choices = [];

	if (empty($user_id))
	{
		$user_id = $user_info['id'];
	}

	if (empty($character_id))
	{
		$character_id = $user_info['id_character'];
	}

	if (empty($character_id))
	{
		return $avatar_choices;
	}

	loadMemberData($user_id);
	loadMemberContext($user_id);

	if (!empty($memberContext[$user_id]['characters'][$character_id]))
	{
		$current_char = $memberContext[$user_id]['characters'][$character_id];
		$avatar_choices[0] = [
			'label' => $txt['default_avatar'],
			'url' => $current_char['avatar'],
			'name' => $current_char['character_name'],
		];

		if (empty($current_char['rotate_avatar']) && !empty($current_char['additional_avatars']))
		{
			foreach ($current_char['additional_avatars'] as $id_avatar => $avatar)
			{
				$avatar_choices[$id_avatar] = [
					'label' => $avatar['label'],
					'url' => $avatar['avatar'],
				];
			}
		}
	}

	return $avatar_choices;
}
