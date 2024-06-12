<?php

/**
 * Displays the summary profile page.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller\Profile;

use StoryBB\Model\Alert;
use StoryBB\Template;

class CharactersPopup extends AbstractProfileController
{
	public function display_action()
	{
		global $context, $db_show_debug, $cur_profile, $scripturl, $txt;

		// We do not want to output debug information here.
		$db_show_debug = false;

		// We only want to output our little layer here.
		Template::set_layout('raw');
		Template::remove_all_layers();
		$context['sub_template'] = 'profile_character_popup';

		// This list will pull from the master list wherever possible. Hopefully it should be clear what does what.
		$profile_items = [
			[
				'item' => 'account_settings',
				'icon' => 'fas fa-users-cog fa-fw',
			],
			[
				'item' => 'avatar_signature',
				'icon' => 'far fa-images fa-fw',
			],
			[
				'item' => 'show_drafts',
				'title' => $txt['popup_drafts'],
				'icon' => 'fas fa-paste fa-fw',
			],
			[
				'item' => 'group_membership',
				'icon' => 'fas fa-user-friends fa-fw',
			],
			[
				'item' => 'paid_subscriptions',
				'icon' => 'far fa-money-bill-alt fa-fw',
			],
		];

		//call_integration_hook('integrate_profile_popup', [&$profile_items]);

		// Now check if these items are available
		$context['profile_items'] = [];
		foreach ($profile_items as $menu_item)
		{
			if (($item = $this->navigation->find_item_by_id($menu_item['item'])) && $item->is_visible())
			{
				$context['profile_items'][$menu_item['item']] = [
					'icon' => $menu_item['icon'] ?? '',
					'url' => $item->get_url(['action' => 'profile']),
					'title' => $menu_item['title'] ?? $item->label,
				];
			}
		}

		$context['current_characters'] = $cur_profile['characters'];
		$context['display_topic_tracker'] = false;

		foreach ($context['current_characters'] as $id_character => $character)
		{
			if (!$character['is_main'])
			{
				$context['display_topic_tracker'] = true;
			}

			if (!$character['is_main'] && !$character['retired'])
			{
				$context['current_characters'][$id_character]['sheet_url'] = $scripturl . '?action=profile;area=character_sheet;u=' . $context['user']['id'] . ';char=' . $id_character;
				$context['current_characters'][$id_character]['avatar_url'] = $scripturl . '?action=profile;area=characters;u=' . $context['user']['id'] . ';char=' . $id_character . ';sa=avatar';
				$context['current_characters'][$id_character]['settings_url'] = $scripturl . '?action=profile;area=characters;u=' . $context['user']['id'] . ';char=' . $id_character . ';sa=edit';
			}
		}
	}
}
