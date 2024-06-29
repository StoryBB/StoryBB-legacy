<?php

/**
 * This file contains those functions pertaining to posting, and other such
 * operations, including sending emails, ims, blocking spam, preparsing posts,
 * and the post box.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\Helper\Bbcode\AbstractParser;
use StoryBB\Helper\Image;
use StoryBB\Helper\Parser;
use StoryBB\StringLibrary;

/**
 * Takes a message and parses it, returning nothing.
 * Cleans up links (javascript, etc.) and code/quote sections.
 * Won't convert \n's and a few other things if previewing is true.
 *
 * @param string $message The mesasge
 * @param bool $previewing Whether we're previewing
 */
function preparsecode(&$message, $previewing = false)
{
	global $user_info, $modSettings, $context, $sourcedir;

	// Clean up after nobbc ;).
	$message = preg_replace_callback('~\[nobbc\](.+?)\[/nobbc\]~is', function($a)
	{
		return '[nobbc]' . strtr($a[1], ['[' => '&#91;', ']' => '&#93;', ':' => '&#58;', '@' => '&#64;']) . '[/nobbc]';
	}, $message ?? '');

	// Remove \r's... they're evil!
	$message = strtr($message, ["\r" => '']);

	// You won't believe this - but too many periods upsets apache it seems!
	$message = preg_replace('~\.{100,}~', '...', $message);

	// Trim off trailing quotes - these often happen by accident.
	while (substr($message, -7) == '[quote]')
		$message = substr($message, 0, -7);
	while (substr($message, 0, 8) == '[/quote]')
		$message = substr($message, 8);

	// Find all code blocks, work out whether we'd be parsing them, then ensure they are all closed.
	$in_tag = false;
	$had_tag = false;
	$codeopen = 0;
	if (preg_match_all('~(\[(/)*code(?:=[^\]]+)?\])~is', $message, $matches))
		foreach ($matches[0] as $index => $dummy)
		{
			// Closing?
			if (!empty($matches[2][$index]))
			{
				// If it's closing and we're not in a tag we need to open it...
				if (!$in_tag)
					$codeopen = true;
				// Either way we ain't in one any more.
				$in_tag = false;
			}
			// Opening tag...
			else
			{
				$had_tag = true;
				// If we're in a tag don't do nought!
				if (!$in_tag)
					$in_tag = true;
			}
		}

	// If we have an open tag, close it.
	if ($in_tag)
		$message .= '[/code]';
	// Open any ones that need to be open, only if we've never had a tag.
	if ($codeopen && !$had_tag)
		$message = '[code]' . $message;

	// Now that we've fixed all the code tags, let's fix the img and url tags...
	$parts = preg_split('~(\[/code\]|\[code(?:=[^\]]+)?\])~i', $message, -1, PREG_SPLIT_DELIM_CAPTURE);

	// Replace code BBC with placeholders. We'll restore them at the end.
	for ($i = 0, $n = count($parts); $i < $n; $i++)
	{
		// It goes 0 = outside, 1 = begin tag, 2 = inside, 3 = close tag, repeat.
		if ($i % 4 == 2)
		{
			$code_tag = $parts[$i - 1] . $parts[$i] . $parts[$i + 1];
			$substitute = $parts[$i - 1] . $i . $parts[$i + 1];
			$code_tags[$substitute] = $code_tag;
			$parts[$i] = $i;
		}
	}

	$message = implode('', $parts);

	// The regular expression non breaking space has many versions.
	$non_breaking_space = '\x{A0}';

	fixTags($message);

	// Replace /me.+?\n with [me=name]dsf[/me]\n.
	if (strpos($user_info['name'], '[') !== false || strpos($user_info['name'], ']') !== false || strpos($user_info['name'], '\'') !== false || strpos($user_info['name'], '"') !== false)
		$message = preg_replace('~(\A|\n)/me(?: |&nbsp;)([^\n]*)(?:\z)?~i', '$1[me=&quot;' . $user_info['name'] . '&quot;]$2[/me]', $message);
	else
		$message = preg_replace('~(\A|\n)/me(?: |&nbsp;)([^\n]*)(?:\z)?~i', '$1[me=' . $user_info['name'] . ']$2[/me]', $message);

	if (!$previewing && strpos($message, '[html]') !== false)
	{
		if (allowedTo('admin_forum'))
			$message = preg_replace_callback('~\[html\](.+?)\[/html\]~is', function($m) {
				return '[html]' . strtr(un_htmlspecialchars($m[1]), ["\n" => '&#13;', '  ' => ' &#32;', '[' => '&#91;', ']' => '&#93;']) . '[/html]';
			}, $message);

		// We should edit them out, or else if an admin edits the message they will get shown...
		else
		{
			while (strpos($message, '[html]') !== false)
				$message = preg_replace('~\[[/]?html\]~i', '', $message);
		}
	}

	// Make sure all tags are lowercase.
	$message = preg_replace_callback('~\[([/]?)(list|li|table|tr|td)((\s[^\]]+)*)\]~i', function($m)
	{
		return "[$m[1]" . strtolower("$m[2]") . "$m[3]]";
	}, $message);

	$list_open = substr_count($message, '[list]') + substr_count($message, '[list ');
	$list_close = substr_count($message, '[/list]');
	if ($list_close - $list_open > 0)
		$message = str_repeat('[list]', $list_close - $list_open) . $message;
	if ($list_open - $list_close > 0)
		$message = $message . str_repeat('[/list]', $list_open - $list_close);

	$mistake_fixes = [
		// Find [table]s not followed by [tr].
		'~\[table\](?![\s' . $non_breaking_space . ']*\[tr\])~su' => '[table][tr]',
		// Find [tr]s not followed by [td].
		'~\[tr\](?![\s' . $non_breaking_space . ']*\[td\])~su' => '[tr][td]',
		// Find [/td]s not followed by something valid.
		'~\[/td\](?![\s' . $non_breaking_space . ']*(?:\[td\]|\[/tr\]|\[/table\]))~su' => '[/td][/tr]',
		// Find [/tr]s not followed by something valid.
		'~\[/tr\](?![\s' . $non_breaking_space . ']*(?:\[tr\]|\[/table\]))~su' => '[/tr][/table]',
		// Find [/td]s incorrectly followed by [/table].
		'~\[/td\][\s' . $non_breaking_space . ']*\[/table\]~su' => '[/td][/tr][/table]',
		// Find [table]s, [tr]s, and [/td]s (possibly correctly) followed by [td].
		'~\[(table|tr|/td)\]([\s' . $non_breaking_space . ']*)\[td\]~su' => '[$1]$2[_td_]',
		// Now, any [td]s left should have a [tr] before them.
		'~\[td\]~s' => '[tr][td]',
		// Look for [tr]s which are correctly placed.
		'~\[(table|/tr)\]([\s' . $non_breaking_space . ']*)\[tr\]~su' => '[$1]$2[_tr_]',
		// Any remaining [tr]s should have a [table] before them.
		'~\[tr\]~s' => '[table][tr]',
		// Look for [/td]s followed by [/tr].
		'~\[/td\]([\s' . $non_breaking_space . ']*)\[/tr\]~su' => '[/td]$1[_/tr_]',
		// Any remaining [/tr]s should have a [/td].
		'~\[/tr\]~s' => '[/td][/tr]',
		// Look for properly opened [li]s which aren't closed.
		'~\[li\]([^\[\]]+?)\[li\]~s' => '[li]$1[_/li_][_li_]',
		'~\[li\]([^\[\]]+?)\[/list\]~s' => '[_li_]$1[_/li_][/list]',
		'~\[li\]([^\[\]]+?)$~s' => '[li]$1[/li]',
		// Lists - find correctly closed items/lists.
		'~\[/li\]([\s' . $non_breaking_space . ']*)\[/list\]~su' => '[_/li_]$1[/list]',
		// Find list items closed and then opened.
		'~\[/li\]([\s' . $non_breaking_space . ']*)\[li\]~su' => '[_/li_]$1[_li_]',
		// Now, find any [list]s or [/li]s followed by [li].
		'~\[(list(?: [^\]]*?)?|/li)\]([\s' . $non_breaking_space . ']*)\[li\]~su' => '[$1]$2[_li_]',
		// Allow for sub lists.
		'~\[/li\]([\s' . $non_breaking_space . ']*)\[list\]~u' => '[_/li_]$1[list]',
		'~\[/list\]([\s' . $non_breaking_space . ']*)\[li\]~u' => '[/list]$1[_li_]',
		// Any remaining [li]s weren't inside a [list].
		'~\[li\]~' => '[list][li]',
		// Any remaining [/li]s weren't before a [/list].
		'~\[/li\]~' => '[/li][/list]',
		// Put the correct ones back how we found them.
		'~\[_(li|/li|td|tr|/tr)_\]~' => '[$1]',
		// Images with no real url.
		'~\[img\]https?://.{0,7}\[/img\]~' => '',
	];

	// Fix up some use of tables without [tr]s, etc. (it has to be done more than once to catch it all.)
	for ($j = 0; $j < 3; $j++)
		$message = preg_replace(array_keys($mistake_fixes), $mistake_fixes, $message);

	// Remove empty bbc from the sections outside the code tags
	$alltags = AbstractParser::get_allowed_empty_bbcodes();

	$alltags_regex = '\b' . implode("\b|\b", array_unique($alltags)) . '\b';

	while (preg_match('~\[(' . $alltags_regex . ')[^\]]*\]\s*\[/\1\]\s?~i', $message))
		$message = preg_replace('~\[(' . $alltags_regex . ')[^\]]*\]\s*\[/\1\]\s?~i', '', $message);

	// Restore code blocks
	if (!empty($code_tags))
		$message = str_replace(array_keys($code_tags), array_values($code_tags), $message);

	// Restore white space entities
	if (!$previewing)
		$message = strtr($message, ['  ' => '&nbsp; ', "\n" => '<br>', "\xC2\xA0" => '&nbsp;']);
	else
		$message = strtr($message, ['  ' => '&nbsp; ', "\xC2\xA0" => '&nbsp;']);

	// Now let's quickly clean up things that will slow our parser (which are common in posted code.)
	$message = strtr($message, ['[]' => '&#91;]', '[&#039;' => '&#91;&#039;']);
}

/**
 * This is very simple, and just removes things done by preparsecode.
 *
 * @param string $message The message
 */
function un_preparsecode($message)
{
	$parts = preg_split('~(\[/code\]|\[code(?:=[^\]]+)?\])~i', $message, -1, PREG_SPLIT_DELIM_CAPTURE);

	// We're going to unparse only the stuff outside [code]...
	for ($i = 0, $n = count($parts); $i < $n; $i++)
	{
		// If $i is a multiple of four (0, 4, 8, ...) then it's not a code section...
		if ($i % 4 == 2)
		{
			$code_tag = $parts[$i - 1] . $parts[$i] . $parts[$i + 1];
			$substitute = $parts[$i - 1] . $i . $parts[$i + 1];
			$code_tags[$substitute] = $code_tag;
			$parts[$i] = $i;
		}
	}

	$message = implode('', $parts);

	$message = preg_replace_callback('~\[html\](.+?)\[/html\]~i', function($m)
	{
		return "[html]" . strtr(StringLibrary::escape("$m[1]", ENT_QUOTES), ["\\&quot;" => "&quot;", "&amp;#13;" => "<br>", "&amp;#32;" => " ", "&amp;#91;" => "[", "&amp;#93;" => "]"]) . "[/html]";
	}, $message);

	if (!empty($code_tags))
		$message = str_replace(array_keys($code_tags), array_values($code_tags), $message);

	// Change breaks back to \n's and &nsbp; back to spaces.
	return preg_replace('~<br( /)?' . '>~', "\n", str_replace('&nbsp;', ' ', $message));
}

/**
 * Fix any URLs posted - ie. remove 'javascript:'.
 * Used by preparsecode, fixes links in message and returns nothing.
 *
 * @param string $message The message
 */
function fixTags(&$message)
{
	global $modSettings;

	// WARNING: Editing the below can cause large security holes in your forum.
	// Edit only if you are sure you know what you are doing.

	$fixArray = [
		// [img]http://...[/img] or [img width=1]http://...[/img]
		[
			'tag' => 'img',
			'protocols' => ['http', 'https'],
			'embeddedUrl' => false,
			'hasEqualSign' => false,
			'hasExtra' => true,
		],
		// [url]http://...[/url]
		[
			'tag' => 'url',
			'protocols' => ['http', 'https'],
			'embeddedUrl' => false,
			'hasEqualSign' => false,
		],
		// [url=http://...]name[/url]
		[
			'tag' => 'url',
			'protocols' => ['http', 'https'],
			'embeddedUrl' => true,
			'hasEqualSign' => true,
		],
		// [iurl]http://...[/iurl]
		[
			'tag' => 'iurl',
			'protocols' => ['http', 'https'],
			'embeddedUrl' => false,
			'hasEqualSign' => false,
		],
		// [iurl=http://...]name[/iurl]
		[
			'tag' => 'iurl',
			'protocols' => ['http', 'https'],
			'embeddedUrl' => true,
			'hasEqualSign' => true,
		],
		// [ftp]ftp://...[/ftp]
		[
			'tag' => 'ftp',
			'protocols' => ['ftp', 'ftps'],
			'embeddedUrl' => false,
			'hasEqualSign' => false,
		],
		// [ftp=ftp://...]name[/ftp]
		[
			'tag' => 'ftp',
			'protocols' => ['ftp', 'ftps'],
			'embeddedUrl' => true,
			'hasEqualSign' => true,
		],
	];

	// Fix each type of tag.
	foreach ($fixArray as $param)
		fixTag($message, $param['tag'], $param['protocols'], $param['embeddedUrl'], $param['hasEqualSign'], !empty($param['hasExtra']));

	// Now fix possible security problems with images loading links automatically...
	$message = preg_replace_callback('~(\[img.*?\])(.+?)\[/img\]~is', function($m)
	{
		return "$m[1]" . preg_replace("~action(=|%3d)(?!dlattach)~i", "action-", "$m[2]") . "[/img]";
	}, $message);

	// Limit the size of images posted?
	if (!empty($modSettings['max_image_width']) && !empty($modSettings['max_image_height']))
	{
		// Find all the img tags - with or without width and height.
		preg_match_all('~\[img(\s+width=\d+)?(\s+height=\d+)?(\s+width=\d+)?\](.+?)\[/img\]~is', $message, $matches, PREG_PATTERN_ORDER);

		$replaces = [];
		foreach ($matches[0] as $match => $dummy)
		{
			// If the width was after the height, handle it.
			$matches[1][$match] = !empty($matches[3][$match]) ? $matches[3][$match] : $matches[1][$match];

			// Now figure out if they had a desired height or width...
			$desired_width = !empty($matches[1][$match]) ? (int) substr(trim($matches[1][$match]), 6) : 0;
			$desired_height = !empty($matches[2][$match]) ? (int) substr(trim($matches[2][$match]), 7) : 0;

			// One was omitted, or both.  We'll have to find its real size...
			if (empty($desired_width) || empty($desired_height))
			{
				[$width, $height] = Image::get_size_from_url(un_htmlspecialchars($matches[4][$match])) ?? [$modSettings['max_image_width'], $modSettings['max_image_height']];

				// They don't have any desired width or height!
				if (empty($desired_width) && empty($desired_height))
				{
					$desired_width = $width;
					$desired_height = $height;
				}
				// Scale it to the width...
				elseif (empty($desired_width) && !empty($height))
					$desired_width = (int) (($desired_height * $width) / $height);
				// Scale if to the height.
				elseif (!empty($width))
					$desired_height = (int) (($desired_width * $height) / $width);
			}

			// If the width and height are fine, just continue along...
			if ($desired_width <= $modSettings['max_image_width'] && $desired_height <= $modSettings['max_image_height'])
				continue;

			// Too bad, it's too wide.  Make it as wide as the maximum.
			if ($desired_width > $modSettings['max_image_width'] && !empty($modSettings['max_image_width']))
			{
				$desired_height = (int) (($modSettings['max_image_width'] * $desired_height) / $desired_width);
				$desired_width = $modSettings['max_image_width'];
			}

			// Now check the height, as well.  Might have to scale twice, even...
			if ($desired_height > $modSettings['max_image_height'] && !empty($modSettings['max_image_height']))
			{
				$desired_width = (int) (($modSettings['max_image_height'] * $desired_width) / $desired_height);
				$desired_height = $modSettings['max_image_height'];
			}

			$replaces[$matches[0][$match]] = '[img' . (!empty($desired_width) ? ' width=' . $desired_width : '') . (!empty($desired_height) ? ' height=' . $desired_height : '') . ']' . $matches[4][$match] . '[/img]';
		}

		// If any img tags were actually changed...
		if (!empty($replaces))
			$message = strtr($message, $replaces);
	}
}

/**
 * Fix a specific class of tag - ie. url with =.
 * Used by fixTags, fixes a specific tag's links.
 *
 * @param string $message The message
 * @param string $myTag The tag
 * @param string $protocols The protocols
 * @param bool $embeddedUrl Whether it *can* be set to something
 * @param bool $hasEqualSign Whether it *is* set to something
 * @param bool $hasExtra Whether it can have extra cruft after the begin tag.
 */
function fixTag(&$message, $myTag, $protocols, $embeddedUrl = false, $hasEqualSign = false, $hasExtra = false)
{
	global $boardurl, $scripturl;

	if (preg_match('~^([^:]+://[^/]+)~', $boardurl, $match) != 0)
		$domain_url = $match[1];
	else
		$domain_url = $boardurl . '/';

	$replaces = [];

	if ($hasEqualSign && $embeddedUrl)
	{
		$quoted = preg_match('~\[(' . $myTag . ')=&quot;~', $message);
		preg_match_all('~\[(' . $myTag . ')=' . ($quoted ? '&quot;(.*?)&quot;' : '([^\]]*?)') . '\](?:(.+?)\[/(' . $myTag . ')\])?~is', $message, $matches);
	}
	elseif ($hasEqualSign)
		preg_match_all('~\[(' . $myTag . ')=([^\]]*?)\](?:(.+?)\[/(' . $myTag . ')\])?~is', $message, $matches);
	else
		preg_match_all('~\[(' . $myTag . ($hasExtra ? '(?:[^\]]*?)' : '') . ')\](.+?)\[/(' . $myTag . ')\]~is', $message, $matches);

	foreach ($matches[0] as $k => $dummy)
	{
		// Remove all leading and trailing whitespace.
		$replace = trim($matches[2][$k]);
		$this_tag = $matches[1][$k];
		$this_close = $hasEqualSign ? (empty($matches[4][$k]) ? '' : $matches[4][$k]) : $matches[3][$k];

		$found = false;
		foreach ($protocols as $protocol)
		{
			$found = strncasecmp($replace, $protocol . '://', strlen($protocol) + 3) === 0;
			if ($found)
				break;
		}

		if (!$found && $protocols[0] == 'http')
		{
			if (substr($replace, 0, 1) == '/' && substr($replace, 0, 2) != '//')
				$replace = $domain_url . $replace;
			elseif (substr($replace, 0, 1) == '?')
				$replace = $scripturl . $replace;
			elseif (substr($replace, 0, 1) == '#' && $embeddedUrl)
			{
				$replace = '#' . preg_replace('~[^A-Za-z0-9_\-#]~', '', substr($replace, 1));
				$this_tag = 'iurl';
				$this_close = 'iurl';
			}
			elseif (substr($replace, 0, 2) != '//')
				$replace = $protocols[0] . '://' . $replace;
		}
		elseif (!$found && $protocols[0] == 'ftp')
			$replace = $protocols[0] . '://' . preg_replace('~^(?!ftps?)[^:]+://~', '', $replace);
		elseif (!$found)
			$replace = $protocols[0] . '://' . $replace;

		if ($hasEqualSign && $embeddedUrl)
			$replaces[$matches[0][$k]] = '[' . $this_tag . '=&quot;' . $replace . '&quot;]' . (empty($matches[4][$k]) ? '' : $matches[3][$k] . '[/' . $this_close . ']');
		elseif ($hasEqualSign)
			$replaces['[' . $matches[1][$k] . '=' . $matches[2][$k] . ']'] = '[' . $this_tag . '=' . $replace . ']';
		elseif ($embeddedUrl)
			$replaces['[' . $matches[1][$k] . ']' . $matches[2][$k] . '[/' . $matches[3][$k] . ']'] = '[' . $this_tag . '=' . $replace . ']' . $matches[2][$k] . '[/' . $this_close . ']';
		else
			$replaces['[' . $matches[1][$k] . ']' . $matches[2][$k] . '[/' . $matches[3][$k] . ']'] = '[' . $this_tag . ']' . $replace . '[/' . $this_close . ']';
	}

	foreach ($replaces as $k => $v)
	{
		if ($k == $v)
			unset($replaces[$k]);
	}

	if (!empty($replaces))
		$message = strtr($message, $replaces);
}

/**
 * Add an email to the mail queue.
 *
 * @param bool $flush Whether to flush the queue
 * @param array $to_array An array of recipients
 * @param string $subject The subject of the message
 * @param string $message The message
 * @param string $headers The headers
 * @param bool $send_html Whether to send in HTML format
 * @param int $priority The priority
 * @param bool $is_private Whether this is private
 * @return boolean Whether the message was added
 */
function AddMailQueue($flush = false, $to_array = [], $subject = '', $message = '', $headers = '', $send_html = false, $priority = 3, $is_private = false)
{
	global $context, $smcFunc;

	static $cur_insert = [];
	static $cur_insert_len = 0;

	if ($cur_insert_len == 0)
		$cur_insert = [];

	// If we're flushing, make the final inserts - also if we're near the MySQL length limit!
	if (($flush || $cur_insert_len > 800000) && !empty($cur_insert))
	{
		// Only do these once.
		$cur_insert_len = 0;

		// Dump the data...
		$smcFunc['db']->insert('',
			'{db_prefix}mail_queue',
			[
				'time_sent' => 'int', 'recipient' => 'string-255', 'body' => 'string', 'subject' => 'string-255',
				'headers' => 'string-65534', 'send_html' => 'int', 'priority' => 'int', 'private' => 'int',
			],
			$cur_insert,
			['id_mail']
		);

		$cur_insert = [];
		$context['flush_mail'] = false;
	}

	// If we're flushing we're done.
	if ($flush)
	{
		$nextSendTime = time() + 10;

		$smcFunc['db']->query('', '
			UPDATE {db_prefix}settings
			SET value = {string:nextSendTime}
			WHERE variable = {literal:mail_next_send}
				AND value = {string:no_outstanding}',
			[
				'nextSendTime' => $nextSendTime,
				'no_outstanding' => '0',
			]
		);

		return true;
	}

	// Ensure we tell obExit to flush.
	$context['flush_mail'] = true;

	foreach ($to_array as $to)
	{
		// Will this insert go over MySQL's limit?
		$this_insert_len = strlen($to) + strlen($message) + strlen($headers) + 700;

		// Insert limit of 1M (just under the safety) is reached?
		if ($this_insert_len + $cur_insert_len > 1000000)
		{
			// Flush out what we have so far.
			$smcFunc['db']->insert('',
				'{db_prefix}mail_queue',
				[
					'time_sent' => 'int', 'recipient' => 'string-255', 'body' => 'string', 'subject' => 'string-255',
					'headers' => 'string-65534', 'send_html' => 'int', 'priority' => 'int', 'private' => 'int',
				],
				$cur_insert,
				['id_mail']
			);

			// Clear this out.
			$cur_insert = [];
			$cur_insert_len = 0;
		}

		// Now add the current insert to the array...
		$cur_insert[] = [time(), (string) $to, (string) $message, (string) $subject, (string) $headers, ($send_html ? 1 : 0), $priority, (int) $is_private];
		$cur_insert_len += $this_insert_len;
	}

	// If they are in background, there is a good chance obExit will never be called.  So lets be nice and flush it for them.
	if (STORYBB === 'BACKGROUND')
		return AddMailQueue(true);

	return true;
}

/**
 * Sends an personal message from the specified person to the specified people
 * ($from defaults to the user)
 *
 * @param array $recipients An array containing the array 'to', containing id_member's.
 * @param string $subject Should have no slashes and no html entities
 * @param string $message Should have no slashes and no html entities
 * @param bool $store_outbox Whether to store it in the sender's outbox
 * @param array $from An array with the id, name, and username of the member.
 * @param int $pm_head The ID of the chain being replied to - if any.
 * @return array An array with log entries telling how many recipients were successful and which recipients it failed to send to.
 */
function sendpm($recipients, $subject, $message, $store_outbox = false, $from = null, $pm_head = 0)
{
	global $scripturl, $txt, $user_info, $language, $sourcedir;
	global $modSettings, $smcFunc;

	// Make sure the PM language file is loaded, we might need something out of it.
	loadLanguage('PersonalMessage');

	// Initialize log array.
	$log = [
		'failed' => [],
		'sent' => []
	];

	if ($from === null)
		$from = [
			'id' => $user_info['id'],
			'name' => $user_info['name'],
			'username' => $user_info['username']
		];

	// This is the one that will go in their inbox.
	$htmlmessage = StringLibrary::escape($message, ENT_QUOTES);
	preparsecode($htmlmessage);
	$htmlsubject = strtr(StringLibrary::escape($subject), ["\r" => '', "\n" => '', "\t" => '']);
	if (StringLibrary::strlen($htmlsubject) > 100)
		$htmlsubject = StringLibrary::substr($htmlsubject, 0, 100);

	// Make sure is an array
	if (!is_array($recipients))
		$recipients = [$recipients];

	// Integrated PMs
	call_integration_hook('integrate_personal_message', [&$recipients, &$from, &$subject, &$message]);

	// Get a list of usernames and convert them to IDs.
	$usernames = [];
	foreach ($recipients as $rec_type => $rec)
	{
		foreach ($rec as $id => $member)
		{
			if (!is_numeric($recipients[$rec_type][$id]))
			{
				$recipients[$rec_type][$id] = StringLibrary::toLower(trim(preg_replace('~[<>&"\'=\\\]~', '', $recipients[$rec_type][$id])));
				$usernames[$recipients[$rec_type][$id]] = 0;
			}
		}
	}
	if (!empty($usernames))
	{
		$request = $smcFunc['db']->query('pm_find_username', '
			SELECT id_member, member_name
			FROM {db_prefix}members
			WHERE ' . ($smcFunc['db']->is_case_sensitive() ? 'LOWER(member_name)' : 'member_name') . ' IN ({array_string:usernames})',
			[
				'usernames' => array_keys($usernames),
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			if (isset($usernames[StringLibrary::toLower($row['member_name'])]))
				$usernames[StringLibrary::toLower($row['member_name'])] = $row['id_member'];
		$smcFunc['db']->free_result($request);

		// Replace the usernames with IDs. Drop usernames that couldn't be found.
		foreach ($recipients as $rec_type => $rec)
			foreach ($rec as $id => $member)
			{
				if (is_numeric($recipients[$rec_type][$id]))
					continue;

				if (!empty($usernames[$member]))
					$recipients[$rec_type][$id] = $usernames[$member];
				else
				{
					$log['failed'][$id] = sprintf($txt['pm_error_user_not_found'], $recipients[$rec_type][$id]);
					unset($recipients[$rec_type][$id]);
				}
			}
	}

	// Make sure there are no duplicate 'to' members.
	$recipients['to'] = array_unique($recipients['to']);

	// Check no-one will want it deleted right away!
	$request = $smcFunc['db']->query('', '
		SELECT
			id_member, criteria, is_or
		FROM {db_prefix}pm_rules
		WHERE id_member IN ({array_int:to_members})
			AND delete_pm = {int:delete_pm}',
		[
			'to_members' => $recipients['to'],
			'delete_pm' => 1,
		]
	);
	$deletes = [];
	// Check whether we have to apply anything...
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		$criteria = sbb_json_decode($row['criteria'], true);
		// Note we don't check the buddy status, cause deletion from buddy = madness!
		$delete = false;
		foreach ($criteria as $criterium)
		{
			if (($criterium['t'] == 'mid' && $criterium['v'] == $from['id']) || ($criterium['t'] == 'gid' && in_array($criterium['v'], $user_info['groups'])) || ($criterium['t'] == 'sub' && strpos($subject, $criterium['v']) !== false) || ($criterium['t'] == 'msg' && strpos($message, $criterium['v']) !== false))
				$delete = true;
			// If we're adding and one criteria don't match then we stop!
			elseif (!$row['is_or'])
			{
				$delete = false;
				break;
			}
		}
		if ($delete)
			$deletes[$row['id_member']] = 1;
	}
	$smcFunc['db']->free_result($request);

	// Load the membergrounp message limits.
	// @todo Consider caching this?
	static $message_limit_cache = [];
	if (!allowedTo('moderate_forum') && empty($message_limit_cache))
	{
		$request = $smcFunc['db']->query('', '
			SELECT id_group, max_messages
			FROM {db_prefix}membergroups',
			[
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$message_limit_cache[$row['id_group']] = $row['max_messages'];
		$smcFunc['db']->free_result($request);
	}

	// Load the groups that are allowed to read PMs.
	require_once($sourcedir . '/Subs-Members.php');
	$pmReadGroups = groupsAllowedTo('pm_read');

	// Load their alert preferences
	require_once($sourcedir . '/Subs-Notify.php');
	$notifyPrefs = getNotifyPrefs($recipients['to'], ['pm_new', 'pm_reply', 'pm_notify'], true);

	$request = $smcFunc['db']->query('', '
		SELECT
			member_name, real_name, id_member, email_address, lngfile
			instant_messages,' . (allowedTo('moderate_forum') ? ' 0' : '
			(pm_receive_from = {int:admins_only}' . (empty($modSettings['enable_buddylist']) ? '' : ' OR
			(pm_receive_from = {int:buddies_only} AND FIND_IN_SET({string:from_id}, buddy_list) = 0) OR
			(pm_receive_from = {int:not_on_ignore_list} AND FIND_IN_SET({string:from_id}, pm_ignore_list) != 0)') . ')') . ' AS ignored,
			FIND_IN_SET({string:from_id}, buddy_list) != 0 AS is_buddy, is_activated,
			additional_groups, id_group
		FROM {db_prefix}members
		WHERE id_member IN ({array_int:recipients})
		ORDER BY lngfile
		LIMIT {int:count_recipients}',
		[
			'not_on_ignore_list' => 1,
			'buddies_only' => 2,
			'admins_only' => 3,
			'recipients' => $recipients['to'],
			'count_recipients' => count($recipients['to']),
			'from_id' => $from['id'],
		]
	);
	$notifications = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		// Don't do anything for members to be deleted!
		if (isset($deletes[$row['id_member']]))
			continue;

		// Load the preferences for this member (if any)
		$prefs = !empty($notifyPrefs[$row['id_member']]) ? $notifyPrefs[$row['id_member']] : [];
		$prefs = array_merge([
			'pm_new' => 0,
			'pm_reply' => 0,
			'pm_notify' => 0,
		], $prefs);

		// We need to know this members groups.
		$groups = explode(',', $row['additional_groups']);
		$groups[] = $row['id_group'];

		$message_limit = -1;
		// For each group see whether they've gone over their limit - assuming they're not an admin.
		if (!in_array(1, $groups))
		{
			foreach ($groups as $id)
			{
				if (isset($message_limit_cache[$id]) && $message_limit != 0 && $message_limit < $message_limit_cache[$id])
					$message_limit = $message_limit_cache[$id];
			}

			if ($message_limit > 0 && $message_limit <= $row['instant_messages'])
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_data_limit_reached'], $row['real_name']);
				unset($recipients['to'][array_search($row['id_member'], $recipients['to'])]);
				continue;
			}

			// Do they have any of the allowed groups?
			if (count(array_intersect($pmReadGroups['allowed'], $groups)) == 0 || count(array_intersect($pmReadGroups['denied'], $groups)) != 0)
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
				unset($recipients['to'][array_search($row['id_member'], $recipients['to'])]);
				continue;
			}
		}

		if (!empty($row['ignored']) && $row['id_member'] != $from['id'])
		{
			$log['failed'][$row['id_member']] = sprintf($txt['pm_error_ignored_by_user'], $row['real_name']);
			unset($recipients['to'][array_search($row['id_member'], $recipients['to'])]);
			continue;
		}

		// If the receiving account is banned (>=10) or pending deletion (4), refuse to send the PM.
		if ($row['is_activated'] >= 10 || ($row['is_activated'] == 4 && !$user_info['is_admin']))
		{
			$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
			unset($recipients['to'][array_search($row['id_member'], $recipients['to'])]);
			continue;
		}

		// Send a notification, if enabled - taking the buddy list into account.
		if (!empty($row['email_address'])
			&& ((empty($pm_head) && $prefs['pm_new'] & 0x02) || (!empty($pm_head) && $prefs['pm_reply'] & 0x02))
			&& ($prefs['pm_notify'] <= 1 || ($prefs['pm_notify'] > 1 && (!empty($modSettings['enable_buddylist']) && $row['is_buddy']))) && $row['is_activated'] == 1)
		{
			$notifications[empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']][] = $row['email_address'];
		}

		$log['sent'][$row['id_member']] = sprintf(isset($txt['pm_successfully_sent']) ? $txt['pm_successfully_sent'] : '', $row['real_name']);
	}
	$smcFunc['db']->free_result($request);

	// Only 'send' the message if there are any recipients left.
	if (empty($recipients['to']))
		return $log;

	// Insert the message itself and then grab the last insert id.
	$id_pm = $smcFunc['db']->insert('',
		'{db_prefix}personal_messages',
		[
			'id_pm_head' => 'int', 'id_member_from' => 'int', 'deleted_by_sender' => 'int',
			'from_name' => 'string-255', 'msgtime' => 'int', 'subject' => 'string-255', 'body' => 'string-65534',
		],
		[
			$pm_head, $from['id'], ($store_outbox ? 0 : 1),
			$from['username'], time(), $htmlsubject, $htmlmessage,
		],
		['id_pm'],
		1
	);

	// Add the recipients.
	if (!empty($id_pm))
	{
		// If this is new we need to set it part of it's own conversation.
		if (empty($pm_head))
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}personal_messages
				SET id_pm_head = {int:id_pm_head}
				WHERE id_pm = {int:id_pm_head}',
				[
					'id_pm_head' => $id_pm,
				]
			);

		// Some people think manually deleting personal_messages is fun... it's not. We protect against it though :)
		$smcFunc['db']->query('', '
			DELETE FROM {db_prefix}pm_recipients
			WHERE id_pm = {int:id_pm}',
			[
				'id_pm' => $id_pm,
			]
		);

		$insertRows = [];
		$to_list = [];
		foreach ($recipients['to'] as $to)
		{
			$insertRows[] = [$id_pm, $to, isset($deletes[$to]) ? 1 : 0, 1];
			$to_list[] = $to;
		}

		$smcFunc['db']->insert('insert',
			'{db_prefix}pm_recipients',
			[
				'id_pm' => 'int', 'id_member' => 'int', 'deleted' => 'int', 'is_new' => 'int'
			],
			$insertRows,
			['id_pm', 'id_member']
		);
	}

	censorText($subject);
	if (empty($modSettings['disallow_sendBody']))
	{
		censorText($message);
		$message = trim(un_htmlspecialchars(strip_tags(strtr(Parser::parse_bbc(StringLibrary::escape($message), false), ['<br>' => "\n", '</div>' => "\n", '</li>' => "\n", '&#91;' => '[', '&#93;' => ']']))));
	}
	else
		$message = '';

	$to_names = [];
	if (count($to_list) > 1)
	{
		$request = $smcFunc['db']->query('', '
			SELECT real_name
			FROM {db_prefix}members
			WHERE id_member IN ({array_int:to_members})',
			[
				'to_members' => $to_list,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$to_names[] = un_htmlspecialchars($row['real_name']);
		$smcFunc['db']->free_result($request);
	}
	$replacements = [
		'SUBJECT' => $subject,
		'MESSAGE' => $message,
		'SENDER' => un_htmlspecialchars($from['name']),
		'READLINK' => $scripturl . '?action=pm;pmsg=' . $id_pm . '#msg' . $id_pm,
		'REPLYLINK' => $scripturl . '?action=pm;sa=send;f=inbox;pmsg=' . $id_pm . ';quote;u=' . $from['id'],
		'TOLIST' => implode(', ', $to_names),
	];
	$email_template = 'new_pm' . (empty($modSettings['disallow_sendBody']) ? '_body' : '') . (!empty($to_names) ? '_tolist' : '');

	foreach ($notifications as $lang => $notification_list)
	{
		$emaildata = loadEmailTemplate($email_template, $replacements, $lang);

		// Off the notification email goes!
		StoryBB\Helper\Mail::send($notification_list, $emaildata['subject'], $emaildata['body'], null, 'p' . $id_pm, $emaildata['is_html'], 2, null, true);
	}

	// Integrated After PMs
	call_integration_hook('integrate_personal_message_after', [&$id_pm, &$log, &$recipients, &$from, &$subject, &$message]);

	// Back to what we were on before!
	loadLanguage('General+PersonalMessage');

	// Add one to their unread and read message counts.
	foreach ($recipients['to'] as $k => $id)
		if (isset($deletes[$id]))
			unset($recipients['to'][$k]);
	if (!empty($recipients['to']))
		updateMemberData($recipients['to'], ['instant_messages' => '+', 'unread_messages' => '+', 'new_pm' => 1]);

	return $log;
}

/**
 * Sends a notification to members who have elected to receive emails
 * when things happen to a topic, such as replies are posted.
 * The function automatically finds the subject and its board, and
 * checks permissions for each member who is "signed up" for notifications.
 * It will not send 'reply' notifications more than once in a row.
 *
 * @param array $topics Represents the topics the action is happening to.
 * @param string $type Can be any of reply, sticky, lock, unlock, remove, move, merge, and split.  An appropriate message will be sent for each.
 * @param array $exclude Members in the exclude array will not be processed for the topic with the same key.
 * @param array $members_only Are the only ones that will be sent the notification if they have it on.
 * @uses Post language file
 */
function sendNotifications($topics, $type, $exclude = [], $members_only = [])
{
	global $user_info, $smcFunc;

	// Can't do it if there's no topics.
	if (empty($topics))
		return;
	// It must be an array - it must!
	if (!is_array($topics))
		$topics = [$topics];

	// Get the subject and body...
	$result = $smcFunc['db']->query('', '
		SELECT mf.subject, ml.body, ml.id_member, t.id_last_msg, t.id_topic, t.id_board,
			COALESCE(mem.real_name, ml.poster_name) AS poster_name, mf.id_msg
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS mf ON (mf.id_msg = t.id_first_msg)
			INNER JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = ml.id_member)
		WHERE t.id_topic IN ({array_int:topic_list})
		LIMIT 1',
		[
			'topic_list' => $topics,
		]
	);
	$task_rows = [];
	while ($row = $smcFunc['db']->fetch_assoc($result))
	{
		// Clean it up.
		censorText($row['subject']);
		censorText($row['body']);
		$row['subject'] = un_htmlspecialchars($row['subject']);
		$row['body'] = trim(un_htmlspecialchars(strip_tags(strtr(Parser::parse_bbc($row['body'], false, $row['id_last_msg']), ['<br>' => "\n", '</div>' => "\n", '</li>' => "\n", '&#91;' => '[', '&#93;' => ']']))));

		StoryBB\Task::batch_queue_adhoc('StoryBB\\Task\\Adhoc\\CreatePostNotify', [
			'msgOptions' => [
				'id' => $row['id_msg'],
				'subject' => $row['subject'],
				'body' => $row['body'],
			],
			'topicOptions' => [
				'id' => $row['id_topic'],
				'board' => $row['id_board'],
			],
			// Kinda cheeky, but for any action the originator is usually the current user
			'posterOptions' => [
				'id' => $user_info['id'],
				'name' => $user_info['name'],
			],
			'type' => $type,
			'members_only' => $members_only,
		]);
	}
	$smcFunc['db']->free_result($result);

	StoryBB\Task::commit_batch_queue();
}

/**
 * Create a post, either as new topic (id_topic = 0) or in an existing one.
 * The input parameters of this function assume:
 * - Strings have been escaped.
 * - Integers have been cast to integer.
 * - Mandatory parameters are set.
 *
 * @param array $msgOptions An array of information/options for the post
 * @param array $topicOptions An array of information/options for the topic
 * @param array $posterOptions An array of information/options for the poster
 * @return bool Whether the operation was a success
 */
function createPost(&$msgOptions, &$topicOptions, &$posterOptions)
{
	return StoryBB\Model\Post::create($msgOptions, $topicOptions, $posterOptions);
}

/**
 * Modifying a post...
 *
 * @param array &$msgOptions An array of information/options for the post
 * @param array &$topicOptions An array of information/options for the topic
 * @param array &$posterOptions An array of information/options for the poster
 * @return bool Whether the post was modified successfully
 */
function modifyPost(&$msgOptions, &$topicOptions, &$posterOptions)
{
	return StoryBB\Model\Post::modify($msgOptions, $topicOptions, $posterOptions);
}

/**
 * Approve (or not) some posts... without permission checks...
 *
 * @param array $msgs Array of message ids
 * @param bool $approve Whether to approve the posts (if false, posts are unapproved)
 * @param bool $notify Whether to notify users
 * @return bool Whether the operation was successful
 */
function approvePosts($msgs, $approve = true, $notify = true)
{
	global $smcFunc;

	if (!is_array($msgs))
		$msgs = [$msgs];

	if (empty($msgs))
		return false;

	// May as well start at the beginning, working out *what* we need to change.
	$request = $smcFunc['db']->query('', '
		SELECT m.id_msg, m.approved, m.id_topic, m.id_board, t.id_first_msg, t.id_last_msg,
			m.body, m.subject, COALESCE(mem.real_name, m.poster_name) AS poster_name, m.id_member,
			t.approved AS topic_approved, b.count_posts, m.id_character
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE m.id_msg IN ({array_int:message_list})
			AND m.approved = {int:approved_state}',
		[
			'message_list' => $msgs,
			'approved_state' => $approve ? 0 : 1,
		]
	);
	$msgs = [];
	$topics = [];
	$topic_changes = [];
	$board_changes = [];
	$notification_topics = [];
	$notification_posts = [];
	$member_post_changes = [];
	$char_post_changes = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
	{
		// Easy...
		$msgs[] = $row['id_msg'];
		$topics[] = $row['id_topic'];

		// Ensure our change array exists already.
		if (!isset($topic_changes[$row['id_topic']]))
			$topic_changes[$row['id_topic']] = [
				'id_last_msg' => $row['id_last_msg'],
				'approved' => $row['topic_approved'],
				'replies' => 0,
				'unapproved_posts' => 0,
			];
		if (!isset($board_changes[$row['id_board']]))
			$board_changes[$row['id_board']] = [
				'posts' => 0,
				'topics' => 0,
				'unapproved_posts' => 0,
				'unapproved_topics' => 0,
			];

		// If it's the first message then the topic state changes!
		if ($row['id_msg'] == $row['id_first_msg'])
		{
			$topic_changes[$row['id_topic']]['approved'] = $approve ? 1 : 0;

			$board_changes[$row['id_board']]['unapproved_topics'] += $approve ? -1 : 1;
			$board_changes[$row['id_board']]['topics'] += $approve ? 1 : -1;

			// Note we need to ensure we announce this topic!
			$notification_topics[] = [
				'body' => $row['body'],
				'subject' => $row['subject'],
				'name' => $row['poster_name'],
				'board' => $row['id_board'],
				'topic' => $row['id_topic'],
				'msg' => $row['id_first_msg'],
				'poster' => $row['id_member'],
				'character' => $row['id_character'],
				'new_topic' => true,
			];
		}
		else
		{
			$topic_changes[$row['id_topic']]['replies'] += $approve ? 1 : -1;

			// This will be a post... but don't notify unless it's not followed by approved ones.
			if ($row['id_msg'] > $row['id_last_msg'])
				$notification_posts[$row['id_topic']] = [
					'id' => $row['id_msg'],
					'body' => $row['body'],
					'subject' => $row['subject'],
					'name' => $row['poster_name'],
					'topic' => $row['id_topic'],
					'board' => $row['id_board'],
					'poster' => $row['id_member'],
					'character' => $row['id_character'],
					'new_topic' => false,
					'msg' => $row['id_msg'],
				];
		}

		// If this is being approved and id_msg is higher than the current id_last_msg then it changes.
		if ($approve && $row['id_msg'] > $topic_changes[$row['id_topic']]['id_last_msg'])
			$topic_changes[$row['id_topic']]['id_last_msg'] = $row['id_msg'];
		// If this is being unapproved, and it's equal to the id_last_msg we need to find a new one!
		elseif (!$approve)
			// Default to the first message and then we'll override in a bit ;)
			$topic_changes[$row['id_topic']]['id_last_msg'] = $row['id_first_msg'];

		$topic_changes[$row['id_topic']]['unapproved_posts'] += $approve ? -1 : 1;
		$board_changes[$row['id_board']]['unapproved_posts'] += $approve ? -1 : 1;
		$board_changes[$row['id_board']]['posts'] += $approve ? 1 : -1;

		// Post count for the user?
		if ($row['id_member'] && empty($row['count_posts']))
		{
			$member_post_changes[$row['id_member']] = isset($member_post_changes[$row['id_member']]) ? $member_post_changes[$row['id_member']] + 1 : 1;
			$char_post_changes[$row['id_character']] = isset($char_post_changes[$row['id_character']]) ? $char_post_changes[$row['id_character']] + 1 : 1;
		}
	}
	$smcFunc['db']->free_result($request);

	if (empty($msgs))
		return;

	// Now we have the differences make the changes, first the easy one.
	$smcFunc['db']->query('', '
		UPDATE {db_prefix}messages
		SET approved = {int:approved_state}
		WHERE id_msg IN ({array_int:message_list})',
		[
			'message_list' => $msgs,
			'approved_state' => $approve ? 1 : 0,
		]
	);

	// If we were unapproving find the last msg in the topics...
	if (!$approve)
	{
		$request = $smcFunc['db']->query('', '
			SELECT id_topic, MAX(id_msg) AS id_last_msg
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topic_list})
				AND approved = {int:approved}
			GROUP BY id_topic',
			[
				'topic_list' => $topics,
				'approved' => 1,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$topic_changes[$row['id_topic']]['id_last_msg'] = $row['id_last_msg'];
		$smcFunc['db']->free_result($request);
	}

	// ... next the topics...
	foreach ($topic_changes as $id => $changes)
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}topics
			SET approved = {int:approved}, unapproved_posts = unapproved_posts + {int:unapproved_posts},
				num_replies = num_replies + {int:num_replies}, id_last_msg = {int:id_last_msg}
			WHERE id_topic = {int:id_topic}',
			[
				'approved' => $changes['approved'],
				'unapproved_posts' => $changes['unapproved_posts'],
				'num_replies' => $changes['replies'],
				'id_last_msg' => $changes['id_last_msg'],
				'id_topic' => $id,
			]
		);

	// ... finally the boards...
	foreach ($board_changes as $id => $changes)
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}boards
			SET num_posts = num_posts + {int:num_posts}, unapproved_posts = unapproved_posts + {int:unapproved_posts},
				num_topics = num_topics + {int:num_topics}, unapproved_topics = unapproved_topics + {int:unapproved_topics}
			WHERE id_board = {int:id_board}',
			[
				'num_posts' => $changes['posts'],
				'unapproved_posts' => $changes['unapproved_posts'],
				'num_topics' => $changes['topics'],
				'unapproved_topics' => $changes['unapproved_topics'],
				'id_board' => $id,
			]
		);

	// Finally, least importantly, notifications!
	if ($approve)
	{
		$task_rows = [];
		foreach (array_merge($notification_topics, $notification_posts) as $topic)
		{
			StoryBB\Task::batch_queue_adhoc('StoryBB\\Task\\Adhoc\\CreatePostNotify', [
				'msgOptions' => [
					'id' => $topic['msg'],
					'body' => $topic['body'],
					'subject' => $topic['subject'],
				],
				'topicOptions' => [
					'id' => $topic['topic'],
					'board' => $topic['board'],
				],
				'posterOptions' => [
					'id' => $topic['poster'],
					'name' => $topic['name'],
				],
				'type' => $topic['new_topic'] ? 'topic' : 'reply',
			]);
		}

		foreach ($notification_topics as $topic)
		{
			StoryBB\Task::batch_queue_adhoc('StoryBB\\Task\\Adhoc\\Achievement\\TopicStarter', [
				'account' => $topic['poster'],
				'character' => $topic['character'],
			]);
		}

		if ($notify)
			StoryBB\Task::commit_batch_queue();

		$smcFunc['db']->query('', '
			DELETE FROM {db_prefix}approval_queue
			WHERE id_msg IN ({array_int:message_list})
				AND id_attach = {int:id_attach}',
			[
				'message_list' => $msgs,
				'id_attach' => 0,
			]
		);
	}
	// If unapproving add to the approval queue!
	else
	{
		$msgInserts = [];
		foreach ($msgs as $msg)
			$msgInserts[] = [$msg];

		$smcFunc['db']->insert('ignore',
			'{db_prefix}approval_queue',
			['id_msg' => 'int'],
			$msgInserts,
			['id_msg']
		);
	}

	// Update the last messages on the boards...
	updateLastMessages(array_keys($board_changes));

	// Post count for the members?
	if (!empty($member_post_changes))
		foreach ($member_post_changes as $id_member => $count_change)
			updateMemberData($id_member, ['posts' => 'posts ' . ($approve ? '+' : '-') . ' ' . $count_change]);
	if (!empty($char_post_changes))
		foreach ($char_post_changes as $id_char => $count_change)
			updateCharacterData($id_char, ['posts' => 'posts ' . ($approve ? '+' : '-') . ' ' . $count_change]);

	return true;
}

/**
 * Approve topics?
 * @todo shouldn't this be in topic
 *
 * @param array $topics Array of topic ids
 * @param bool $approve Whether to approve the topics. If false, unapproves them instead
 * @return bool Whether the operation was successful
 */
function approveTopics($topics, $approve = true)
{
	global $smcFunc;

	if (!is_array($topics))
		$topics = [$topics];

	if (empty($topics))
		return false;

	$approve_type = $approve ? 0 : 1;

	// Just get the messages to be approved and pass through...
	$request = $smcFunc['db']->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_topic IN ({array_int:topic_list})
			AND approved = {int:approve_type}',
		[
			'topic_list' => $topics,
			'approve_type' => $approve_type,
		]
	);
	$msgs = [];
	while ($row = $smcFunc['db']->fetch_assoc($request))
		$msgs[] = $row['id_msg'];
	$smcFunc['db']->free_result($request);

	return approvePosts($msgs, $approve);
}

/**
 * Takes an array of board IDs and updates their last messages.
 * If the board has a parent, that parent board is also automatically
 * updated.
 * The columns updated are id_last_msg and last_updated.
 * Note that id_last_msg should always be updated using this function,
 * and is not automatically updated upon other changes.
 *
 * @param array $setboards An array of board IDs
 * @param int $id_msg The ID of the message
 * @return void|false Returns false if $setboards is empty for some reason
 */
function updateLastMessages($setboards, $id_msg = 0)
{
	global $board_info, $board, $smcFunc;

	// Please - let's be sane.
	if (empty($setboards))
		return false;

	if (!is_array($setboards))
		$setboards = [$setboards];

	// If we don't know the id_msg we need to find it.
	if (!$id_msg)
	{
		// Find the latest message on this board (highest id_msg.)
		$request = $smcFunc['db']->query('', '
			SELECT id_board, MAX(id_last_msg) AS id_msg
			FROM {db_prefix}topics
			WHERE id_board IN ({array_int:board_list})
				AND approved = {int:approved}
			GROUP BY id_board',
			[
				'board_list' => $setboards,
				'approved' => 1,
			]
		);
		$lastMsg = [];
		while ($row = $smcFunc['db']->fetch_assoc($request))
			$lastMsg[$row['id_board']] = $row['id_msg'];
		$smcFunc['db']->free_result($request);
	}
	else
	{
		// Just to note - there should only be one board passed if we are doing this.
		foreach ($setboards as $id_board)
			$lastMsg[$id_board] = $id_msg;
	}

	$parent_boards = [];
	// Keep track of last modified dates.
	$lastModified = $lastMsg;
	// Get all the child boards for the parents, if they have some...
	foreach ($setboards as $id_board)
	{
		if (!isset($lastMsg[$id_board]))
		{
			$lastMsg[$id_board] = 0;
			$lastModified[$id_board] = 0;
		}

		if (!empty($board) && $id_board == $board)
			$parents = $board_info['parent_boards'];
		else
			$parents = getBoardParents($id_board);

		// Ignore any parents on the top child level.
		// @todo Why?
		foreach ($parents as $id => $parent)
		{
			if ($parent['level'] != 0)
			{
				// If we're already doing this one as a board, is this a higher last modified?
				if (isset($lastModified[$id]) && $lastModified[$id_board] > $lastModified[$id])
					$lastModified[$id] = $lastModified[$id_board];
				elseif (!isset($lastModified[$id]) && (!isset($parent_boards[$id]) || $parent_boards[$id] < $lastModified[$id_board]))
					$parent_boards[$id] = $lastModified[$id_board];
			}
		}
	}

	// Note to help understand what is happening here. For parents we update the timestamp of the last message for determining
	// whether there are child boards which have not been read. For the boards themselves we update both this and id_last_msg.

	$board_updates = [];
	$parent_updates = [];
	// Finally, to save on queries make the changes...
	foreach ($parent_boards as $id => $msg)
	{
		if (!isset($parent_updates[$msg]))
			$parent_updates[$msg] = [$id];
		else
			$parent_updates[$msg][] = $id;
	}

	foreach ($lastMsg as $id => $msg)
	{
		if (!isset($board_updates[$msg . '-' . $lastModified[$id]]))
			$board_updates[$msg . '-' . $lastModified[$id]] = [
				'id' => $msg,
				'updated' => $lastModified[$id],
				'boards' => [$id]
			];

		else
			$board_updates[$msg . '-' . $lastModified[$id]]['boards'][] = $id;
	}

	// Now commit the changes!
	foreach ($parent_updates as $id_msg => $boards)
	{
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}boards
			SET id_msg_updated = {int:id_msg_updated}
			WHERE id_board IN ({array_int:board_list})
				AND id_msg_updated < {int:id_msg_updated}',
			[
				'board_list' => $boards,
				'id_msg_updated' => $id_msg,
			]
		);
	}
	foreach ($board_updates as $board_data)
	{
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}boards
			SET id_last_msg = {int:id_last_msg}, id_msg_updated = {int:id_msg_updated}
			WHERE id_board IN ({array_int:board_list})',
			[
				'board_list' => $board_data['boards'],
				'id_last_msg' => $board_data['id'],
				'id_msg_updated' => $board_data['updated'],
			]
		);
	}
}

/**
 * This simple function gets a list of all administrators and sends them an email
 *  to let them know a new member has joined.
 * Called by registerMember() function in Subs-Members.php.
 * Email is sent to all groups that have the moderate_forum permission.
 * The language set by each member is being used (if available).
 *
 * @param string $type The type. Types supported are 'approval', 'activation', and 'standard'.
 * @param int $memberID The ID of the member
 * @param string $member_name The name of the member (if null, it is pulled from the database)
 * @uses the Login language file.
 */
function adminNotify($type, $memberID, $member_name = null)
{
	global $smcFunc;

	if ($member_name == null)
	{
		// Get the new user's name....
		$request = $smcFunc['db']->query('', '
			SELECT real_name
			FROM {db_prefix}members
			WHERE id_member = {int:id_member}
			LIMIT 1',
			[
				'id_member' => $memberID,
			]
		);
		list ($member_name) = $smcFunc['db']->fetch_row($request);
		$smcFunc['db']->free_result($request);
	}

	// This is really just a wrapper for making a new background task to deal with all the fun.
	StoryBB\Task::queue_adhoc('StoryBB\\Task\\Adhoc\\RegisterNotify', [
		'new_member_id' => $memberID,
		'new_member_name' => $member_name,
		'notify_type' => $type,
		'time' => time(),
	]);
}

/**
 * Load a template from EmailTemplates language file.
 *
 * @param string $template The name of the template to load
 * @param array $replacements An array of replacements for the variables in the template
 * @param string $lang The language to use, if different than the user's current language
 * @param bool $loadLang Whether to load the language file first
 * @return array An array containing the subject and body of the email template, with replacements made
 */
function loadEmailTemplate($template, $replacements = [], $lang = '', $loadLang = true)
{
	global $txt, $context, $scripturl, $settings;

	// First things first, load up the email templates language file, if we need to.
	if ($loadLang)
		loadLanguage('EmailTemplates', $lang);

	if (!isset($txt[$template . '_subject']) || !isset($txt[$template . '_body']))
		fatal_lang_error('email_no_template', 'template', [$template]);

	$ret = [
		'subject' => $txt[$template . '_subject'],
		'body' => $txt[$template . '_body'],
		'is_html' => !empty($txt[$template . '_html']),
	];

	// Add in the default replacements.
	$replacements += [
		'FORUMNAME' => $context['forum_name'],
		'SCRIPTURL' => $scripturl,
		'THEMEURL' => $settings['theme_url'],
		'IMAGESURL' => $settings['images_url'],
		'DEFAULT_THEMEURL' => $settings['default_theme_url'],
		'REGARDS' => str_replace('{forum_name}', $context['forum_name'], $txt['regards_team']),
	];

	// Split the replacements up into two arrays, for use with str_replace
	$find = [];
	$replace = [];

	foreach ($replacements as $f => $r)
	{
		$find[] = '{' . $f . '}';
		$replace[] = $r;
	}

	// Do the variable replacements.
	$ret['subject'] = str_replace($find, $replace, $ret['subject']);
	$ret['body'] = str_replace($find, $replace, $ret['body']);

	// Now deal with the {USER.variable} items.
	$ret['subject'] = preg_replace_callback('~{USER.([^}]+)}~', 'user_info_callback', $ret['subject']);
	$ret['body'] = preg_replace_callback('~{USER.([^}]+)}~', 'user_info_callback', $ret['body']);

	// Finally return the email to the caller so they can send it out.
	return $ret;
}

/**
 * Callback function for loademaitemplate on subject and body
 * Uses capture group 1 in array
 *
 * @param array $matches An array of matches
 * @return string The match
 */
function user_info_callback($matches)
{
	global $user_info;
	if (empty($matches[1]))
		return '';

	$use_ref = true;
	$ref = &$user_info;

	foreach (explode('.', $matches[1]) as $index)
	{
		if ($use_ref && isset($ref[$index]))
			$ref = &$ref[$index];
		else
		{
			$use_ref = false;
			break;
		}
	}

	return $use_ref ? $ref : $matches[0];
}
