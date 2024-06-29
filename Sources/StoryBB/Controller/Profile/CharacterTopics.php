<?php

/**
 * Displays the character topics page.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller\Profile;

use StoryBB\App;
use StoryBB\Helper\Parser;
use StoryBB\Model\TopicCollection;
use StoryBB\Model\TopicPrefix;

class CharacterTopics extends AbstractProfileController
{
	use CharacterTrait;

	public function display_action()
	{
		global $txt, $user_info, $scripturl, $modSettings;
		global $context, $smcFunc;

		$url = App::container()->get('urlgenerator');

		$this->init_character();

		$context['sub_template'] = 'profile_character_topics';

		$topic_ids = [];

		// First, get all the topic ids without worrying about ordering.
		$request = $smcFunc['db']->query('', '
			SELECT t.id_topic
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board AND {query_see_board})
				INNER JOIN {db_prefix}messages AS m ON (m.id_topic = t.id_topic)
			WHERE m.id_character = {int:current_member}
				AND t.approved = {int:is_approved}
			GROUP BY id_topic',
			[
				'current_member' => $context['character']['id_character'],
				'is_approved' => 1,
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$topic_ids[] = $row['id_topic'];
		}
		$smcFunc['db']->free_result($request);

		$context['topics'] = [];
		if (!empty($topic_ids))
		{
			$request = $smcFunc['db']->query('', '
				SELECT t.id_topic, m.subject, t.id_board, b.name AS bname, b.slug
				FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
					INNER JOIN {db_prefix}messages AS m ON (t.id_first_msg = m.id_msg)
				WHERE t.id_topic IN ({array_int:topic_ids})
				ORDER BY t.id_topic',
				[
					'topic_ids' => $topic_ids,
				]
			);
			while ($row = $smcFunc['db']->fetch_assoc($request))
			{
				censorText($row['subject']);

				$context['topics'][$row['id_topic']] = [
					'subject' => $row['subject'],
					'link' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
					'prefixes' => [],
					'participants' => [],
					'board' => [
						'name' => $row['bname'],
						'id' => $row['id_board'],
						'link' => $url->generate('board', ['board_slug' => $row['slug']]),
					],
				];
			}
			$smcFunc['db']->free_result($request);

			$prefixes = TopicPrefix::get_prefixes_for_topic_list($topic_ids);
			$participants = TopicCollection::get_participants_for_topic_list($topic_ids);
			foreach ($context['topics'] as $key => $post)
			{
				if (isset($prefixes[$key]))
				{
					$context['topics'][$key]['prefixes'] = $prefixes[$key];
				}
				if (isset($participants[$key]))
				{
					$context['topics'][$key]['participants'] = $participants[$key];
				}
			}
		}
	}
}
