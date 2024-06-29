<?php

/**
 * Shippers
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Helper;

use StoryBB\Container;
use StoryBB\Database\DatabaseAdapter;
use StoryBB\Helper\Epub\Epub3;
use StoryBB\Helper\Parser;
use StoryBB\Model\TopicPrefix;
use StoryBB\StringLibrary;

class ShipperLib
{
	public static function get_shippers(): array
	{
		global $smcFunc, $context, $txt, $scripturl;

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		$final_ships = [];
		$participating_characters = [];

		$topics = [];
		$characters = [];
		$topic_starters = [];

		$custom_ships = [];
		$custom_ships_by_character = [];
		$customised_topics = [];

		$request = $smcFunc['db']->query('', '
			SELECT id_ship, first_character, second_character, ship_name, ship_slug, hidden, shipper
			FROM {db_prefix}shipper');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$custom_ships[$row['id_ship']] = $row;
			$custom_ships_by_character[$row['first_character'] . '_' . $row['second_character']] = $row['id_ship'];
			$characters[$row['first_character']] = false;
			$characters[$row['second_character']] = false;
		}
		$smcFunc['db']->free_result($request);

		$request = $smcFunc['db']->query('', '
			SELECT st.id_ship, st.id_topic, st.position, t.id_first_msg
			FROM {db_prefix}shipper_timeline AS st
				INNER JOIN {db_prefix}topics AS t ON (st.id_topic = t.id_topic)
				INNER JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
			WHERE {query_see_board}
				AND b.in_character = 1');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (!isset($custom_ships[$row['id_ship']]))
			{
				continue;
			}

			$custom_ships[$row['id_ship']]['topics'][$row['id_topic']] = (int) $row['position'];
			$customised_topics[$row['id_topic']] = true;
			$topic_starters[$row['id_first_msg']] = $row['id_topic'];
		}
		$smcFunc['db']->free_result($request);

		$request = $smcFunc['db']->query('', '
			SELECT t.id_topic, m.id_character, t.id_first_msg, MAX(chars.is_main) AS is_ooc
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_topic = t.id_topic)
				INNER JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
				INNER JOIN {db_prefix}characters AS chars ON (m.id_character = chars.id_character)
			WHERE {query_see_board}
				AND b.in_character = 1
				AND t.approved = 1
			GROUP BY t.id_topic, m.id_character, t.id_first_msg');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			// If any of the topic participants are participating in an OOC capacity, ignore it.
			if ($row['is_ooc'])
			{
				continue;
			}

			// Collate the topics 
			$topics[$row['id_topic']]['characters'][$row['id_character']] = true;
			$topics[$row['id_topic']]['first_msg'] = $row['id_first_msg'];
			$topic_starters[$row['id_first_msg']] = $row['id_topic'];
			$characters[$row['id_character']] = false;
		}
		$smcFunc['db']->free_result($request);

		// Filter out topics that only have one character in them (and aren't explicitly tagged in a timeline)
		foreach ($topics as $id_topic => $topic)
		{
			if (count($topic['characters']) < 2 && !isset($customised_topics[$id_topic]))
			{
				unset ($topics[$id_topic]);
			}
		}

		if (empty($topics))
		{
			throw new \RuntimeException('no_shipper_topics');
		}

		// Fill in the topic IDs.
		$request = $smcFunc['db']->query('', '
			SELECT id_topic, subject
			FROM {db_prefix}messages
			WHERE id_msg IN ({array_int:msgs})',
			[
				'msgs' => array_keys($topic_starters),
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (!isset($topics[$row['id_topic']]))
			{
				continue;
			}
			censorText($row['subject']);
			$topics[$row['id_topic']]['subject'] = $row['subject'];
		}
		$smcFunc['db']->free_result($request);

		$prefixes = TopicPrefix::get_prefixes_for_topic_list(array_keys($topics));

		// Fill in the characters.
		$request = $smcFunc['db']->query('', '
			SELECT id_member, id_character, character_name
			FROM {db_prefix}characters
			WHERE id_character IN ({array_int:characters})',
			[
				'characters' => array_keys($characters),
			]
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$characters[$row['id_character']] = $row;
		}
		$smcFunc['db']->free_result($request);

		// Now go figure out the pairings.
		$pairings = [];
		foreach ($topics as $id_topic => $topic)
		{
			foreach (static::permute_characters(array_keys($topic['characters'])) as $participants)
			{
				asort($participants);
				$pairings[implode('_', $participants)][] = $id_topic;
			}
		}

		foreach ($pairings as $participants => $topics_participated)
		{
			$participants = explode('_', $participants);

			$ship = [
				'characters' => [],
				'topics' => [],
				'show' => true,
				'editable' => allowedTo('admin_forum'),
				'epub' => $urlgenerator->generate('shipper_epub', ['firstchar' => $participants[0], 'secondchar' => $participants[1]]),
			];

			foreach ($participants as $id_character)
			{
				$ship['characters'][$id_character] = $characters[$id_character]['character_name'];
				if ($context['user']['id'] && $characters[$id_character]['id_member'] == $context['user']['id'])
				{
					$ship['editable'] = true;
				}
			}

			uasort($ship['characters'], function ($a, $b) {
				return strcasecmp($a, $b);
			});

			$ship['label'] = implode(' x ', $ship['characters']);

			$ship_id_chars = ((int) $participants[0] > (int) $participants[1]) ? $participants[1] . '_' . $participants[0] : $participants[0] . '_' . $participants[1];
			$existing_ship = $custom_ships_by_character[$ship_id_chars] ?? 0;

			foreach ($topics_participated as $topic)
			{
				$ship['topics'][$topic] = [
					'subject' => $topics[$topic]['subject'],
					'position' => !empty($custom_ships[$existing_ship]['topics'][$topic]) ? (int) $custom_ships[$existing_ship]['topics'][$topic] : 10000 + $topics[$topic]['first_msg'],
					'topic_href' => $scripturl . '?topic=' . $topic . '.0',
					'prefixes' => $prefixes[$topic] ?? [],
				];
			}

			if ($ship['editable'])
			{
				$ship['edit_shipper_link'] = $urlgenerator->generate('shipper_edit', ['firstchar' => $participants[0], 'secondchar' => $participants[1]]);
				$ship['timeline_link'] = $urlgenerator->generate('shipper_timeline', ['firstchar' => $participants[0], 'secondchar' => $participants[1]]);
			}
			if (allowedTo('admin_forum'))
			{
				$ship['toggle_hidden_link'] = $urlgenerator->generate('shipper_visibility', ['firstchar' => $participants[0], 'secondchar' => $participants[1], 'session_var' => $context['session_var'], 'session_id' => $context['session_id']]);
			}
			if (isset($custom_ships[$existing_ship]))
			{
				$ship['existing_id'] = $custom_ships[$existing_ship]['id_ship'];
				$ship['hidden'] = !empty($custom_ships[$existing_ship]['hidden']);
				if ($ship['hidden'])
				{
					$ship['show'] = allowedTo('admin_forum');
				}

				if (!empty($custom_ships[$existing_ship]['ship_name']))
				{
					$ship['ship_name'] = $custom_ships[$existing_ship]['ship_name'];
				}
				if (!empty($custom_ships[$existing_ship]['ship_slug']))
				{
					$ship['ship_slug'] = $custom_ships[$existing_ship]['ship_slug'];
					if (!empty(!empty($custom_ships[$existing_ship]['shipper'])))
					{
						$ship['shipper'] = $custom_ships[$existing_ship]['shipper'];
						$ship['shipper_link'] = $urlgenerator->generate('shipper_view', ['slug' => $ship['ship_slug']]);
					}
				}

				if (isset($custom_ships[$existing_ship]['topics']))
				{
					foreach ($custom_ships[$existing_ship]['topics'] as $topic => $position)
					{
						if (!isset($ship['topics'][$topic]) && isset($topics[$topic]))
						{
							$ship['topics'][$topic] = [
								'subject' => $topics[$topic]['subject'],
								'position' => !empty($custom_ships[$existing_ship]['topics'][$topic]) ? (int) $custom_ships[$existing_ship]['topics'][$topic] : 10000 + $topics[$topic]['first_msg'],
								'topic_href' => $scripturl . '?topic=' . $topic . '.0',
								'prefixes' => $prefixes[$topic] ?? [],
								'extra_characters' => [],
							];
							$extras = array_diff(array_keys($topics[$topic]['characters']), $participants);
							foreach ($extras as $extra)
							{
								if (!empty($characters[$extra]))
								{
									$ship['topics'][$topic]['extra_characters'][$extra] = $characters[$extra]['character_name'];
								}
							}
						}
					}
				}
			}

			if (count($ship['topics']) < 2)
			{
				continue;
			}

			if (!empty($ship['show']))
			{
				foreach ($ship['characters'] as $id_character => $character_name)
				{
					$participating_characters[$id_character] = $character_name;
				}
			}

			uasort($ship['topics'], function ($a, $b) {
				return $a['position'] <=> $b['position'];
			});

			$final_ships[$ship_id_chars] = $ship;
		}

		uasort($final_ships, function($a, $b) {
			return strcasecmp($a['label'], $b['label']);
		});

		uasort($participating_characters, function($a, $b) {
			return strcasecmp($a, $b);
		});

		return [$final_ships, $participating_characters];
	}

	protected static function permute_characters(array $characters): array
	{
		for ($i = 0, $n = count($characters); $i < $n; $i++)
		{
			for ($j = $i + 1; $j < $n; $j++)
			{
				$pairings[] = [$characters[$i], $characters[$j]];
			}
		}

		return $pairings;
	}	
}