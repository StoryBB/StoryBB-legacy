<?php

/**
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\Template;

function Accessibility()
{
	global $context, $db_show_debug, $cur_profile, $scripturl, $txt;

	// We do not want to output debug information here.
	$db_show_debug = false;

	// We only want to output our little layer here.
	Template::set_layout('raw');
	Template::remove_all_layers();
	$context['sub_template'] = 'accessibility';
}