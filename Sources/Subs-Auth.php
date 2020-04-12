<?php

/**
 * This file has functions in it to do with authentication, user handling, and the like.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2020 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\Helper\IP;
use StoryBB\Hook\Observable;
use StoryBB\Hook\Mutatable;
use StoryBB\StringLibrary;

/**
 * Sets the StoryBB-style login cookie and session based on the id_member and password passed.
 * - password should be already encrypted with the cookie salt.
 * - logs the user out if id_member is zero.
 * - sets the cookie and session to last the number of seconds specified by cookie_length, or
 *   ends them if cookie_length is less than 0.
 * - when logging out, if the globalCookies setting is enabled, attempts to clear the subdomain's
 *   cookie too.
 *
 * @param int $cookie_length How many seconds the cookie should last. If negative, forces logout.

 * @param int $id The ID of the member to set the cookie for
 * @param string $password The hashed password
 */
function setLoginCookie($cookie_length, $id, $password = '')
{
	global $cookiename, $boardurl, $modSettings, $sourcedir;

	$id = (int) $id;

	$expiry_time = ($cookie_length >= 1 ? time() + $cookie_length : 0);

	// If changing state force them to re-address some permission caching.
	$_SESSION['mc']['time'] = 0;

	// Extract our cookie domain and path from $boardurl
	$cookie_url = url_parts(!empty($modSettings['localCookies']), !empty($modSettings['globalCookies']));

	// The cookie may already exist, and have been set with different options.
	if (isset($_COOKIE[$cookiename]))
	{
		if (preg_match('~^{"0":\d+,"1":"[0-9a-f]*","2":\d+,"3":"[^"]+","4":"[^"]+"~', $_COOKIE[$cookiename]) === 1)
			list(,,, $old_domain, $old_path) = sbb_json_decode($_COOKIE[$cookiename], true);

		// Out with the old, in with the new!
		if (isset($old_domain) && $old_domain != $cookie_url[0] || isset($old_path) && $old_path != $cookie_url[1])
		{
			//sbb_setcookie($cookiename, json_encode([0, '', 0, $old_domain, $old_path], JSON_FORCE_OBJECT), 1, $old_path, $old_domain);
		}
	}

	// Get the data and path to set it on.
	$data = empty($id) ? [0, '', 0, $cookie_url[0], $cookie_url[1]] : [$id, $password, $expiry_time, $cookie_url[0], $cookie_url[1]];

	// Allow mods to add custom info to the cookie
	$custom_data = [];
	call_integration_hook('integrate_cookie_data', [$data, &$custom_data]);

	$data = json_encode(array_merge($data, $custom_data), JSON_FORCE_OBJECT);

	// Set the cookie, $_COOKIE, and session variable.
	//sbb_setcookie($cookiename, $data, $expiry_time, $cookie_url[1], $cookie_url[0]);

	// If subdomain-independent cookies are on, unset the subdomain-dependent cookie too.
	if (empty($id) && !empty($modSettings['globalCookies']))
	{
		//sbb_setcookie($cookiename, $data, $expiry_time, $cookie_url[1], '');
	}

	// Any alias URLs?  This is mainly for use with frames, etc.
	if (!empty($modSettings['forum_alias_urls']))
	{
		$aliases = explode(',', $modSettings['forum_alias_urls']);

		$temp = $boardurl;
		foreach ($aliases as $alias)
		{
			// Fake the $boardurl so we can set a different cookie.
			$alias = strtr(trim($alias), ['http://' => '', 'https://' => '']);
			$boardurl = 'http://' . $alias;

			$cookie_url = url_parts(!empty($modSettings['localCookies']), !empty($modSettings['globalCookies']));

			if ($cookie_url[0] == '')
				$cookie_url[0] = strtok($alias, '/');

			$alias_data = sbb_json_decode($data);
			$alias_data[3] = $cookie_url[0];
			$alias_data[4] = $cookie_url[1];
			$alias_data = json_encode($alias_data, JSON_FORCE_OBJECT);

			//sbb_setcookie($cookiename, $alias_data, $expiry_time, $cookie_url[1], $cookie_url[0]);
		}

		$boardurl = $temp;
	}

	$_COOKIE[$cookiename] = $data;

	// Make sure the user logs in with a new session ID.
	if (!isset($_SESSION['login_' . $cookiename]) || $_SESSION['login_' . $cookiename] !== $data)
	{
		// We need to meddle with the session.
		require_once($sourcedir . '/Session.php');

		// Backup and remove the old session.
		$oldSessionData = $_SESSION;
		$_SESSION = [];
		// session_destroy();

		// // Recreate and restore the new session.
		// loadSession();
		// // @todo should we use session_regenerate_id(true); now that we are 5.1+
		// session_regenerate_id();
		$_SESSION = $oldSessionData;

		$_SESSION['login_' . $cookiename] = $data;
	}
}

/**
 * Sets Two Factor Auth cookie
 *
 * @param int $cookie_length How long the cookie should last, in minutes
 * @param int $id The ID of the member
 * @param string $secret Should be a salted secret using hash_salt
 * @param bool $preserve Whether to preserve the cookie for 30 days
 */
function setTFACookie($cookie_length, $id, $secret, $preserve = false)
{
	global $modSettings, $cookiename;

	$identifier = $cookiename . '_tfa';
	$cookie_url = url_parts(!empty($modSettings['localCookies']), !empty($modSettings['globalCookies']));

	if ($preserve)
		$cookie_length = 81600 * 30;

	// Get the data and path to set it on.
	$data = json_encode(empty($id) ? [0, '', 0, $cookie_url[0], $cookie_url[1], false] : [$id, $secret, time() + $cookie_length, $cookie_url[0], $cookie_url[1], $preserve], JSON_FORCE_OBJECT);

	// Set the cookie, $_COOKIE, and session variable.
	sbb_setcookie($identifier, $data, time() + $cookie_length, $cookie_url[1], $cookie_url[0]);

	// If subdomain-independent cookies are on, unset the subdomain-dependent cookie too.
	if (empty($id) && !empty($modSettings['globalCookies']))
		sbb_setcookie($identifier, $data, time() + $cookie_length, $cookie_url[1], '');

	$_COOKIE[$identifier] = $data;
}

/**
 * Get the domain and path for the cookie
 * - normally, local and global should be the localCookies and globalCookies settings, respectively.
 * - uses boardurl to determine these two things.
 *
 * @param bool $local Whether we want local cookies
 * @param bool $global Whether we want global cookies
 * @return array An array to set the cookie on with domain and path in it, in that order
 */
function url_parts($local, $global)
{
	global $boardurl, $modSettings;

	// Parse the URL with PHP to make life easier.
	$parsed_url = parse_url($boardurl);

	// Is local cookies off?
	if (empty($parsed_url['path']) || !$local)
		$parsed_url['path'] = '';

	if (!empty($modSettings['globalCookiesDomain']) && strpos($boardurl, $modSettings['globalCookiesDomain']) !== false)
		$parsed_url['host'] = $modSettings['globalCookiesDomain'];

	// Globalize cookies across domains (filter out IP-addresses)?
	elseif ($global && !IP::is_valid_ipv4($parsed_url['host']) && preg_match('~(?:[^\.]+\.)?([^\.]{2,}\..+)\z~i', $parsed_url['host'], $parts) == 1)
		$parsed_url['host'] = '.' . $parts[1];

	// We shouldn't use a host at all if both options are off.
	elseif (!$local && !$global)
		$parsed_url['host'] = '';

	// The host also shouldn't be set if there aren't any dots in it.
	elseif (!isset($parsed_url['host']) || strpos($parsed_url['host'], '.') === false)
		$parsed_url['host'] = '';

	return [$parsed_url['host'], $parsed_url['path'] . '/'];
}

/**
 * Throws guests out to the login screen when guest access is off.
 * - sets $_SESSION['login_url'] to $_SERVER['REQUEST_URL'].
 * - uses the 'kick_guest' sub template found in Login.template.php.
 */
function KickGuest()
{
	global $txt, $context;

	loadLanguage('Login');
	createToken('login');

	// Never redirect to an attachment
	if (strpos($_SERVER['REQUEST_URL'], 'dlattach') === false)
		$_SESSION['login_url'] = $_SERVER['REQUEST_URL'];

	$context['sub_template'] = 'login_kick_guest';
	$context['page_title'] = $txt['login'];
}

/**
 * Display a message about the forum being in maintenance mode.
 * - display a login screen with sub template 'maintenance'.
 * - sends a 503 header, so search engines don't bother indexing while we're in maintenance mode.
 */
function InMaintenance()
{
	global $txt, $mtitle, $mmessage, $context;

	loadLanguage('Login');
	createToken('login');

	// Send a 503 header, so search engines don't bother indexing while we're in maintenance mode.
	header('HTTP/1.1 503 Service Temporarily Unavailable');

	// Basic template stuff..
	$context['sub_template'] = 'login_maintenance';
	$context['title'] = StringLibrary::escape($mtitle);
	$context['description'] = &$mmessage;
	$context['page_title'] = $txt['maintain_mode'];
}

/**
 * Question the verity of the admin by asking for his or her password.
 * - loads Login.template.php and uses the admin_login sub template.
 * - sends data to template so the admin is sent on to the page they
 *   wanted if their password is correct, otherwise they can try again.
 *
 * @param string $type What login type is this - can be 'admin' or 'moderate'
 */
function adminLogin($type = 'admin')
{
	global $context, $txt, $user_info, $scripturl, $modSettings;

	loadLanguage('Admin');

	// Validate what type of session check this is.
	$types = [];
	call_integration_hook('integrate_validateSession', [&$types]);
	$type = in_array($type, $types) || $type == 'moderate' ? $type : 'admin';

	// They used a wrong password, log it and unset that.
	if (isset($_POST[$type . '_hash_pass']) || isset($_POST[$type . '_pass']))
	{
		$txt['security_wrong'] = sprintf($txt['security_wrong'], isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $txt['unknown'], $_SERVER['HTTP_USER_AGENT'], $user_info['ip']);
		log_error($txt['security_wrong'], 'critical');

		if (isset($_POST[$type . '_hash_pass']))
			unset($_POST[$type . '_hash_pass']);
		if (isset($_POST[$type . '_pass']))
			unset($_POST[$type . '_pass']);

		$context['incorrect_password'] = true;
	}

	createToken('admin-login');

	// Figure out the get data and post data.
	$context['get_data'] = '?' . construct_query_string($_GET);
	$context['post_data'] = '';

	// Now go through $_POST.  Make sure the session hash is sent.
	$_POST[$context['session_var']] = $context['session_id'];
	foreach ($_POST as $k => $v)
		$context['post_data'] .= adminLogin_outputPostVars($k, $v);

	// Now we'll use the admin_login sub template of the Login template.
	$context['form_scripturl'] = !empty($modSettings['force_ssl']) && $modSettings['force_ssl'] < 2 ? strtr($scripturl, ['http://' => 'https://']) : $scripturl;
	$context['sub_template'] = 'login_admin';

	// And title the page something like "Login".
	if (!isset($context['page_title']))
		$context['page_title'] = $txt['login'];

	// The type of action.
	$context['sessionCheckType'] = $type;

	obExit();

	// We MUST exit at this point, because otherwise we CANNOT KNOW that the user is privileged.
	trigger_error('Hacking attempt...', E_USER_ERROR);
}

/**
 * Used by the adminLogin() function.
 * if 'value' is an array, the function is called recursively.
 *
 * @param string $k The keys
 * @param string $v The values
 * @return string 'hidden' HTML form fields, containing key-value-pairs
 */
function adminLogin_outputPostVars($k, $v)
{
	if (!is_array($v))
		return '
<input type="hidden" name="' . StringLibrary::escape($k) . '" value="' . strtr($v, ['"' => '&quot;', '<' => '&lt;', '>' => '&gt;']) . '">';
	else
	{
		$ret = '';
		foreach ($v as $k2 => $v2)
			$ret .= adminLogin_outputPostVars($k . '[' . $k2 . ']', $v2);

		return $ret;
	}
}

/**
 * Properly urlencodes a string to be used in a query
 *
 * @param string $get
 * @return string Our query string
 */
function construct_query_string($get)
{
	global $scripturl;

	$query_string = '';

	// Awww, darn.  The $scripturl contains GET stuff!
	$q = strpos($scripturl, '?');
	if ($q !== false)
	{
		parse_str(preg_replace('/&(\w+)(?=&|$)/', '&$1=', strtr(substr($scripturl, $q + 1), ';', '&')), $temp);

		foreach ($get as $k => $v)
		{
			// Only if it's not already in the $scripturl!
			if (!isset($temp[$k]))
				$query_string .= urlencode($k) . '=' . urlencode($v) . ';';
			// If it changed, put it out there, but with an ampersand.
			elseif ($temp[$k] != $get[$k])
				$query_string .= urlencode($k) . '=' . urlencode($v) . '&amp;';
		}
	}
	else
	{
		// Add up all the data from $_GET into get_data.
		foreach ($get as $k => $v)
			$query_string .= urlencode($k) . '=' . urlencode($v) . ';';
	}

	$query_string = substr($query_string, 0, -1);
	return $query_string;
}

/**
 * Finds members by email address, username, or real name.
 * - searches for members whose username, display name, or e-mail address match the given pattern of array names.
 * - searches only buddies if buddies_only is set.
 *
 * @param array $names The names of members to search for
 * @param bool $use_wildcards Whether to use wildcards. Accepts wildcards ? and * in the pattern if true
 * @param bool $buddies_only Whether to only search for the user's buddies
 * @param int $max The maximum number of results
 * @return array An array containing information about the matching members
 */
function findMembers($names, $use_wildcards = false, $buddies_only = false, $max = 500)
{
	global $scripturl, $user_info, $smcFunc;

	// If it's not already an array, make it one.
	if (!is_array($names))
		$names = explode(',', $names);

	$maybe_email = false;
	$names_list = [];
	foreach ($names as $i => $name)
	{
		// Trim, and fix wildcards for each name.
		$names[$i] = trim(StringLibrary::toLower($name));

		$maybe_email |= strpos($name, '@') !== false;

		// Make it so standard wildcards will work. (* and ?)
		if ($use_wildcards)
			$names[$i] = strtr($names[$i], ['%' => '\%', '_' => '\_', '*' => '%', '?' => '_', '\'' => '&#039;']);
		else
			$names[$i] = strtr($names[$i], ['\'' => '&#039;']);
		
		$names_list[] = '{string:lookup_name_' . $i . '}';
		$where_params['lookup_name_' . $i] = $names[$i];
	}

	// What are we using to compare?
	$comparison = $use_wildcards ? 'LIKE' : '=';

	// Nothing found yet.
	$results = [];

	// This ensures you can't search someones email address if you can't see it.
	if (($use_wildcards || $maybe_email) && allowedTo('moderate_forum'))
		$email_condition = '
			OR (email_address ' . $comparison . ' \'' . implode('\') OR (email_address ' . $comparison . ' \'', $names) . '\')';
	else
		$email_condition = '';

	// Get the case of the columns right - but only if we need to as things like MySQL will go slow needlessly otherwise.
	$member_name = $smcFunc['db']->is_case_sensitive() ? 'LOWER(member_name)' : 'member_name';
	$real_name = $smcFunc['db']->is_case_sensitive() ? 'LOWER(real_name)' : 'real_name';

	// Searches.
	$member_name_search = $member_name . ' ' . $comparison . ' ' . implode( ' OR ' . $member_name . ' ' . $comparison . ' ', $names_list);
	$real_name_search = $real_name . ' ' . $comparison . ' ' . implode( ' OR ' . $real_name . ' ' . $comparison . ' ', $names_list);

	// Search by username, display name, and email address.
	$request = $smcFunc['db']->query('', '
		SELECT id_member, member_name, real_name, email_address
		FROM {db_prefix}members
		WHERE (' . $member_name_search . '
			OR ' . $real_name_search . ' ' . $email_condition . ')
			' . ($buddies_only ? 'AND id_member IN ({array_int:buddy_list})' : '') . '
			AND is_activated IN (1, 11)
		LIMIT {int:limit}',
		array_merge($where_params, [
			'buddy_list' => $user_info['buddies'],
			'limit' => $max,
		])
	);
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		$results[$row['id_member']] = [
			'id' => $row['id_member'],
			'name' => $row['real_name'],
			'username' => $row['member_name'],
			'email' => allowedTo('moderate_forum') ? $row['email_address'] : '',
			'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
			'link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>'
		];
	}
	$smcFunc['db']->free_result($request);

	// Return all the results.
	return $results;
}

/**
 * Generates a random password for a user and emails it to them.
 * - called by Profile.php when changing someone's username.
 * - checks the validity of the new username.
 * - generates and sets a new password for the given user.
 * - mails the new password to the email address of the user.
 * - if username is not set, only a new password is generated and sent.
 *
 * @param int $memID The ID of the member
 * @param string $username The new username. If set, also checks the validity of the username
 */
function resetPassword($memID, $username = null)
{
	global $sourcedir, $modSettings, $smcFunc, $language;

	// Language... and a required file.
	loadLanguage('Login');
	require_once($sourcedir . '/Subs-Post.php');

	// Get some important details.
	$request = $smcFunc['db']->query('', '
		SELECT member_name, email_address, lngfile
		FROM {db_prefix}members
		WHERE id_member = {int:id_member}',
		[
			'id_member' => $memID,
		]
	);
	list ($user, $email, $lngfile) = $smcFunc['db']->fetch_row($request);
	$smcFunc['db']->free_result($request);

	if ($username !== null)
	{
		$old_user = $user;
		$user = trim($username);
	}

	// Generate a random password.
	$newPassword = substr(preg_replace('/\W/', '', md5(mt_rand())), 0, 10);
	$newPassword_sha1 = hash_password($user, $newPassword);

	// Do some checks on the username if needed.
	if ($username !== null)
	{
		validateUsername($memID, $user);

		// Update the database...
		updateMemberData($memID, ['member_name' => $user, 'passwd' => $newPassword_sha1]);
	}
	else
		updateMemberData($memID, ['passwd' => $newPassword_sha1]);

	(new Observable\Account\PasswordReset($old_user, $user, $newPassword))->execute();

	$replacements = [
		'USERNAME' => $user,
		'PASSWORD' => $newPassword,
	];

	$emaildata = loadEmailTemplate('change_password', $replacements, empty($lngfile) || empty($modSettings['userLanguage']) ? $language : $lngfile);

	// Send them the email informing them of the change - then we're done!
	StoryBB\Helper\Mail::send($email, $emaildata['subject'], $emaildata['body'], null, 'chgpass' . $memID, $emaildata['is_html'], 0);
}

/**
 * Checks a username obeys a load of rules
 *
 * @param int $memID The ID of the member
 * @param string $username The username to validate
 * @param boolean $return_error Whether to return errors
 * @param boolean $check_reserved_name Whether to check this against the list of reserved names
 * @return array|null Null if there are no errors, otherwise an array of errors if return_error is true
 */
function validateUsername($memID, $username, $return_error = false, $check_reserved_name = true)
{
	global $sourcedir, $txt, $user_info;

	$errors = [];

	// Don't use too long a name.
	if (StringLibrary::strlen($username) > 25)
		$errors[] = ['lang', 'error_long_name'];

	// No name?!  How can you register with no name?
	if ($username == '')
		$errors[] = ['lang', 'need_username'];

	// Only these characters are permitted.
	if (in_array($username, ['_', '|']) || preg_match('~[<>&"\'=\\\\]~', preg_replace('~&#(?:\\d{1,7}|x[0-9a-fA-F]{1,6});~', '', $username)) != 0 || strpos($username, '[code') !== false || strpos($username, '[/code') !== false)
		$errors[] = ['lang', 'error_invalid_characters_username'];

	if (stristr($username, $txt['guest_title']) !== false)
		$errors[] = ['lang', 'username_reserved', 'general', [$txt['guest_title']]];

	if ($check_reserved_name)
	{
		require_once($sourcedir . '/Subs-Members.php');
		if (isReservedName($username, $memID, false))
			$errors[] = ['done', '(' . StringLibrary::escape($username) . ') ' . $txt['name_in_use']];
	}

	if ($return_error)
		return $errors;
	elseif (empty($errors))
		return null;

	loadLanguage('Errors');
	$error = $errors[0];

	$message = $error[0] == 'lang' ? (empty($error[3]) ? $txt[$error[1]] : vsprintf($txt[$error[1]], $error[3])) : $error[1];
	fatal_error($message, empty($error[2]) || $user_info['is_admin'] ? false : $error[2]);
}

/**
 * Checks whether a password meets the current forum rules
 * - called when registering/choosing a password.
 * - checks the password obeys the current forum settings for password strength.
 * - if password checking is enabled, will check that none of the words in restrict_in appear in the password.
 * - returns an error identifier if the password is invalid, or null.
 *
 * @param string $password The desired password
 * @param string $username The username
 * @param array $restrict_in An array of restricted strings that cannot be part of the password (email address, username, etc.)
 * @return null|string Null if valid or a string indicating what the problem was
 */
function validatePassword($password, $username, $restrict_in = [])
{
	global $modSettings;

	// Perform basic requirements first.
	if (StringLibrary::strlen($password) < (empty($modSettings['password_strength']) ? 4 : 8))
		return 'short';

	// Is this enough?
	if (empty($modSettings['password_strength']))
		return null;

	// Otherwise, perform the medium strength test - checking if password appears in the restricted string.
	if (preg_match('~\b' . preg_quote($password, '~') . '\b~', implode(' ', $restrict_in)) != 0)
		return 'restricted_words';
	elseif (StringLibrary::strpos($password, $username) !== false)
		return 'restricted_words';

	// If just medium, we're done.
	if ($modSettings['password_strength'] == 1)
		return null;

	// Otherwise, hard test next, check for numbers and letters, uppercase too.
	$good = preg_match('~(\D\d|\d\D)~', $password) != 0;
	$good &= StringLibrary::toLower($password) != $password;

	return $good ? null : 'chars';
}

/**
 * Quickly find out what moderation authority this user has
 * - builds the moderator, group and board level querys for the user
 * - stores the information on the current users moderation powers in $user_info['mod_cache'] and $_SESSION['mc']
 */
function rebuildModCache()
{
	global $user_info, $smcFunc;

	// What groups can they moderate?
	$group_query = allowedTo('manage_membergroups') ? '1=1' : '0=1';

	if ($group_query == '0=1' && !$user_info['is_guest'])
	{
		$request = $smcFunc['db']->query('', '
			SELECT id_group
			FROM {db_prefix}group_moderators
			WHERE id_member = {int:current_member}',
			[
				'current_member' => $user_info['id'],
			]
		);
		$groups = [];
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$groups[] = $row['id_group'];
		$smcFunc['db']->free_result($request);

		if (empty($groups))
			$group_query = '0=1';
		else
			$group_query = 'id_group IN (' . implode(',', $groups) . ')';
	}

	// Then, same again, just the boards this time!
	$board_query = allowedTo('moderate_forum') ? '1=1' : '0=1';

	if ($board_query == '0=1' && !$user_info['is_guest'])
	{
		$boards = boardsAllowedTo('moderate_board', true);

		if (empty($boards))
			$board_query = '0=1';
		else
			$board_query = 'id_board IN (' . implode(',', $boards) . ')';
	}

	// What boards are they the moderator of?
	$boards_mod = [];
	if (!$user_info['is_guest'])
	{
		$request = $smcFunc['db']->query('', '
			SELECT id_board
			FROM {db_prefix}moderators
			WHERE id_member = {int:current_member}',
			[
				'current_member' => $user_info['id'],
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
				'groups' => $user_info['groups'],
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$boards_mod[] = $row['id_board'];
		$smcFunc['db']->free_result($request);

		// Just in case we've got duplicates here...
		$boards_mod = array_unique($boards_mod);
	}

	$mod_query = empty($boards_mod) ? '0=1' : 'b.id_board IN (' . implode(',', $boards_mod) . ')';

	$user_info['mod_cache'] = [
		'time' => time(),
		// This looks a bit funny but protects against the login redirect.
		'id' => $user_info['id'] && $user_info['name'] ? $user_info['id'] : 0,
		// If you change the format of 'gq' and/or 'bq' make sure to adjust 'can_mod' in Load.php.
		'gq' => $group_query,
		'bq' => $board_query,
		'ap' => boardsAllowedTo('approve_posts'),
		'mb' => $boards_mod,
		'mq' => $mod_query,
	];
	(new Mutatable\ModerationCache($user_info['mod_cache']))->execute();
	$_SESSION['mc'] = $user_info['mod_cache'];

	// Might as well clean up some tokens while we are at it.
	cleanTokens();
}

/**
 * A consistent cookie function that can be configured and integrated in
 * the wider environment, e.g. user configuration, plugins.
 *
 * @param string $name
 * @param string $value = ''
 * @param int $expire = 0
 * @param string $path = ''
 * @param string $domain = ''
 * @param bool $secure = false
 * @param bool $httponly = true
 */
function sbb_setcookie($name, $value = '', $expire = 0, $path = '', $domain = '', $secure = null, $httponly = true)
{
	global $modSettings;

	// In case a customization wants to override the default settings
	if ($httponly === null)
		$httponly = !empty($modSettings['httponlyCookies']);
	if ($secure === null)
		$secure = !empty($modSettings['secureCookies']);

	// Intercept cookie?
	call_integration_hook('integrate_cookie', [$name, $value, $expire, $path, $domain, $secure, $httponly]);

	// This function is pointless if we have PHP >= 5.2.
	return setcookie($name, $value, $expire, $path, $domain, $secure, $httponly);
}

/**
 * Hashes username with password
 *
 * @param string $username The username
 * @param string $password The unhashed password
 * @param int $cost The cost
 * @return string The hashed password
 */
function hash_password($username, $password, $cost = null)
{
	global $modSettings;

	$cost = empty($cost) ? (empty($modSettings['bcrypt_hash_cost']) ? 10 : $modSettings['bcrypt_hash_cost']) : $cost;

	return password_hash(StringLibrary::toLower($username) . $password, PASSWORD_BCRYPT, [
		'cost' => $cost,
	]);
}

/**
 * Hashes password with salt, this is solely used for cookies.
 *
 * @param string $password The password
 * @param string $salt The salt
 * @return string The hashed password
 */
function hash_salt($password, $salt)
{
	return hash('sha512', $password . $salt);
}

/**
 * Verifies a raw StoryBB password against the bcrypt'd string
 *
 * @param string $username The username
 * @param string $password The password
 * @param string $hash The hashed string
 * @return bool Whether the hashed password matches the string
 */
function hash_verify_password($username, $password, $hash)
{
	return password_verify(StringLibrary::toLower($username) . $password, $hash);
}

/**
 * Returns the length for current hash
 *
 * @return int The length for the current hash
 */
function hash_length()
{
	return 60;
}

/**
 * Benchmarks the server to figure out an appropriate cost factor (minimum 9)
 *
 * @param float $hashTime Time to target, in seconds
 * @return int The cost
 */
function hash_benchmark($hashTime = 0.2)
{
	$cost = 9;
	do {
		$timeStart = microtime(true);
		hash_password('test', 'thisisatestpassword', $cost);
		$timeTaken = microtime(true) - $timeStart;
		$cost++;
	} while ($timeTaken < $hashTime);

	return $cost;
}
