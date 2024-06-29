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

namespace StoryBB\Controller;

use StoryBB\Container;
use StoryBB\Database\DatabaseAdapter;
use StoryBB\Helper\Epub\Epub3;
use StoryBB\Helper\Parser;
use StoryBB\Helper\ShipperLib;
use StoryBB\Model\TopicPrefix;
use StoryBB\Routing\Behaviours\Routable;
use StoryBB\StringLibrary;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Shippers implements Routable
{
	public static function register_own_routes(RouteCollection $routes): void
	{
		$routes->add('shippers', (new Route('/shippers', ['_function' => [static::class, 'shipper_list']])));
		$routes->add('shipper_view', (new Route('/shippers/{slug}', ['_function' => [static::class, 'view_shipper']])));
		$routes->add('shipper_edit', (new Route('/shippers/{firstchar<\d+>}/{secondchar<\d+>}/edit', ['_function' => [static::class, 'edit_shipper']])));
		$routes->add('shipper_timeline', (new Route('/shippers/{firstchar<\d+>}/{secondchar<\d+>}/timeline', ['_function' => [static::class, 'edit_timeline']])));
		$routes->add('shipper_visibility', (new Route('/shippers/{firstchar<\d+>}/{secondchar<\d+>}/toggle/{session_var}/{session_id}', ['_function' => [static::class, 'toggle_hidden']])));
		$routes->add('shipper_epub', (new Route('/shippers/{firstchar<\d+>}/{secondchar<\d+>}/epub', ['_function' => [static::class, 'export_epub']])));
	}

	protected static function assert_enabled(): void
	{
		global $modSettings;

		if (empty($modSettings['enable_shipper']))
		{
			fatal_lang_error('shipper_not_enabled', false);
		}
	}

	public static function shipper_list()
	{
		global $smcFunc, $context, $txt, $scripturl;

		static::assert_enabled();

		try {
			[$context['ships'], $context['participating_characters']] = static::get_shippers();
		}
		catch (\Exception $e)
		{
			fatal_lang_error($e->getMessage(), false);
		}

		$context['page_title'] = $txt['shippers'];
		$context['meta_description'] = $txt['shipper_description'];
		$context['sub_template'] = 'shipper';
	}

	protected static function get_shippers(): array
	{
		return ShipperLib::get_shippers();
	}

	public static function toggle_hidden()
	{
		global $context, $smcFunc;

		isAllowedTo('admin_forum');
		checkSession('route');

		$firstchar = (int) $context['routing']['firstchar'];
		$secondchar = (int) $context['routing']['secondchar'];
		if ($firstchar > $secondchar)
		{
			$secondchar = (int) $context['routing']['firstchar'];
			$firstchar = (int) $context['routing']['secondchar'];
		}

		// Does this already exist? If so, we're just flipping the value.
		$result = $smcFunc['db']->query('', '
			SELECT id_ship, hidden
			FROM {db_prefix}shipper
			WHERE first_character = {int:firstchar}
				AND second_character = {int:secondchar}',
			[
				'firstchar' => $firstchar,
				'secondchar' => $secondchar,
			]
		);
		$row = $smcFunc['db']->fetch_assoc($result);
		$smcFunc['db']->free_result($result);

		if ($row)
		{
			// It already exists, so it's a simple update.
			$new_hidden = !empty($row['hidden']) ? 0 : 1;
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}shipper
				SET hidden = {int:new_hidden}
				WHERE id_ship = {int:ship}',
				[
					'new_hidden' => $new_hidden,
					'ship' => $row['id_ship'],
				]
			);
		}
		else
		{
			// It doesn't exist, so we need to make it. This can only mean we're hiding this row.
			$smcFunc['db']->insert(DatabaseAdapter::INSERT_INSERT,
				'{db_prefix}shipper',
				['first_character' => 'int', 'second_character' => 'int', 'ship_name' => 'string', 'ship_slug' => 'string', 'hidden' => 'int', 'shipper' => 'string'],
				[$firstchar, $secondchar, '', '', 1, ''],
				['id_ship']
			);
		}

		$container = Container::instance();
		redirectexit($container->get('urlgenerator')->generate('shippers'));
	}

	public static function view_shipper()
	{
		global $context, $txt, $sourcedir, $smcFunc;

		static::assert_enabled();

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		try {
			[$ships, $characters] = static::get_shippers();
		}
		catch (\Exception $e)
		{
			fatal_lang_error($e->getMessage(), false);
		}

		$current_ship_id = false;
		$context['ship'] = false;
		foreach ($ships as $ship_id => $ship)
		{
			if (!empty($ship['ship_slug']) && $ship['ship_slug'] == $context['routing']['slug'])
			{
				$context['ship'] = $ship;
				$current_ship_id = $ship_id;
				break;
			}
		}
		if (empty($context['ship']) || empty($context['ship']['shipper']))
		{
			fatal_lang_error('shipper_not_found', false);
		}

		$context['shipper'] = Parser::parse_bbc($context['ship']['shipper'], false);

		$context['page_title'] = implode(' / ', $context['ship']['characters']);
		if (!empty($context['ship']['ship_name']))
		{
			$context['page_title'] .= html_entity_decode(' &mdash; &ldquo;') . $context['ship']['ship_name'] . html_entity_decode('&rdquo;');
		}

		$current_participants = explode('_', $current_ship_id);
		$context['other_ships'] = [];
		foreach ($ships as $ship_id => $ship)
		{
			if ($ship_id == $current_ship_id)
			{
				continue;
			}

			$participants = explode('_', $ship_id);
			if (array_intersect($current_participants, $participants) && !empty($ship['show']))
			{
				$context['other_ships'][$ship_id] = $ship;
			}
		}

		$context['sub_template'] = 'shipper_view';
	}

	public static function edit_shipper()
	{
		global $context, $txt, $sourcedir, $smcFunc;

		static::assert_enabled();

		require_once($sourcedir . '/Subs-Post.php');
		require_once($sourcedir . '/Subs-Editor.php');

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		try {
			[$ships, $characters] = static::get_shippers();
		}
		catch (\Exception $e)
		{
			fatal_lang_error($e->getMessage(), false);
		}

		$firstchar = $context['routing']['firstchar'];
		$secondchar = $context['routing']['secondchar'];
		$ship_id_chars = ((int) $firstchar > (int) $secondchar) ? $secondchar . '_' . $firstchar : $firstchar . '_' . $secondchar;

		if (!isset($ships[$ship_id_chars]) || empty($ships[$ship_id_chars]['editable']))
		{
			fatal_lang_error('shipper_not_found', false);
		}

		$context['edit_link'] = $ships[$ship_id_chars]['edit_shipper_link'];
		$context['ship_name'] = $ships[$ship_id_chars]['ship_name'] ?? '';
		$context['ship_slug'] = $ships[$ship_id_chars]['ship_slug'] ?? '';
		$context['shipper'] = $ships[$ship_id_chars]['shipper'] ?? '';

		$context['errors'] = [];

		if (isset($_POST['save']))
		{
			checkSession();
			$context['ship_name'] = StringLibrary::escape($_POST['ship_name'] ?? '', ENT_QUOTES);
			$context['ship_slug'] = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($_POST['ship_slug'] ?? ''));
			$context['shipper'] = StringLibrary::escape($_POST['message'], ENT_QUOTES);
			preparsecode($context['shipper']);

			// Try to synthesise a slug from the name.
			if (empty($context['ship_slug']))
			{
				$context['ship_slug'] = preg_replace('/\-+/', '-', preg_replace('/\s+/', '-', trim($context['ship_name'])));
				$context['ship_slug'] = preg_replace('/[^A-Za-z0-9_-]+/', '', $context['ship_slug']);
			}
			if (empty($context['ship_slug']) && !empty($context['shipper']))
			{
				$context['errors'][] = $txt['shipper_needs_a_url'];
			}

			if (empty($context['errors']))
			{
				if (!empty($ships[$ship_id_chars]['existing_id']))
				{
					$smcFunc['db']->query('', '
						UPDATE {db_prefix}shipper
						SET ship_name = {string:ship_name},
						    ship_slug = {string:ship_slug},
						    shipper = {string:shipper}
						WHERE id_ship = {int:id_ship}',
						[
							'ship_name' => $context['ship_name'],
							'ship_slug' => $context['ship_slug'],
							'shipper' => $context['shipper'],
							'id_ship' => $ships[$ship_id_chars]['existing_id'],
						]
					);
				}
				else
				{
					$chars = explode('_', $ship_id_chars);
					$smcFunc['db']->insert(DatabaseAdapter::INSERT_INSERT,
						'{db_prefix}shipper',
						['ship_name' => 'string', 'ship_slug' => 'string', 'shipper' => 'string', 'hidden' => 'int', 'first_character' => 'int', 'second_character' => 'int'],
						[$context['ship_name'], $context['ship_slug'], $context['shipper'], 0, $chars[0], $chars[1]],
						['id_ship']
					);
				}

				redirectexit($urlgenerator->generate('shippers'));
			}
		}

		$context['shipper_url_base'] = str_replace('SHIPPER', '', $urlgenerator->generate('shipper_view', ['slug' => 'SHIPPER']));

		$context['shipper_raw'] = un_preparsecode($context['shipper']);

		// Now create the editor.
		$editorOptions = [
			'id' => 'message',
			'value' => $context['shipper_raw'],
			'labels' => [
				'post_button' => $txt['save'],
			],
			'height' => '500px',
			'width' => '100%',
			'preview_type' => 0,
			'required' => true,
		];
		create_control_richedit($editorOptions);

		$context['page_title'] = $txt['edit_shipper'];
		$context['sub_template'] = 'shipper_edit';
	}

	public static function edit_timeline()
	{
		global $context, $txt, $sourcedir, $smcFunc;

		static::assert_enabled();

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		try {
			[$ships, $characters] = static::get_shippers();
		}
		catch (\Exception $e)
		{
			fatal_lang_error($e->getMessage(), false);
		}

		$firstchar = $context['routing']['firstchar'];
		$secondchar = $context['routing']['secondchar'];
		$ship_id_chars = ((int) $firstchar > (int) $secondchar) ? $secondchar . '_' . $firstchar : $firstchar . '_' . $secondchar;

		if (!isset($ships[$ship_id_chars]) || empty($ships[$ship_id_chars]['editable']))
		{
			fatal_lang_error('shipper_not_found', false);
		}

		$context['ship'] = $ships[$ship_id_chars];

		if (isset($_POST['save']))
		{
			checkSession();

			// Do we have a ship already for this?
			if (empty($context['ship']['existing_id']))
			{
				// It doesn't exist, so we need to make it. This can only mean we're hiding this row.
				$context['ship']['existing_id'] = $smcFunc['db']->insert(DatabaseAdapter::INSERT_INSERT,
					'{db_prefix}shipper',
					['first_character' => 'int', 'second_character' => 'int', 'ship_name' => 'string', 'ship_slug' => 'string', 'hidden' => 'int', 'shipper' => 'string'],
					[$firstchar, $secondchar, '', '', 0, ''],
					['id_ship'],
					DatabaseAdapter::RETURN_LAST_ID
				);
			}

			// Clean out whatever we do have.
			$smcFunc['db']->query('', '
				DELETE FROM {db_prefix}shipper_timeline
				WHERE id_ship = {int:ship}',
				[
					'ship' => $context['ship']['existing_id'],
				]
			);

			// Build the new list.
			$insert = [];
			if (!empty($_POST['order']) && is_array($_POST['order']))
			{
				$order = array_filter(array_map('intval', $_POST['order']), function($x) {
					return $x > 0;
				});
			}
			else
			{
				$order = [];
			}

			// Now get the list of things and verify they match up with normal accessible topics.
			if (!empty($order))
			{
				$result = $smcFunc['db']->query('', '
					SELECT t.id_topic
					FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
					WHERE t.id_topic IN ({array_int:topics})
						AND b.in_character = 1
						AND t.approved = 1',
					[
						'topics' => $order,
					]
				);
				$valid_topics = [];
				while ($row = $smcFunc['db']->fetch_assoc($result))
				{
					$valid_topics[] = $row['id_topic'];
				}
				$smcFunc['db']->free_result($result);

				$insert = [];
				foreach ($order as $topic_id)
				{
					if (in_array($topic_id, $insert))
					{
						continue; // Don't duplicate topics even if that's what was submitted.
					}
					if (!in_array($topic_id, $valid_topics))
					{
						continue; // Don't allow OOC topics or unapproved topics into this list.
					}

					$insert[] = $topic_id;
				}

				$position = 1;
				$insert_rows = [];
				foreach ($insert as $insert_id)
				{
					$insert_rows[] = [$context['ship']['existing_id'], $insert_id, $position];
					$position++;
				}

				if (!empty($insert_rows))
				{
					$smcFunc['db']->insert(DatabaseAdapter::INSERT_INSERT,
						'{db_prefix}shipper_timeline',
						['id_ship' => 'int', 'id_topic' => 'int', 'position' => 'int'],
						$insert_rows,
						['id_ship', 'id_topic']
					);
				}
			}
			redirectexit($urlgenerator->generate('shippers'));
		}

		loadJavaScriptFile('jquery-ui-1.12.1-sortable.min.js', ['default_theme' => true]);
		addInlineJavascript('
		$(\'.sortable\').sortable({handle: ".draggable-handle", items: "> .windowbg"});', true);

		$context['page_title'] = implode(' / ', $context['ship']['characters']);
		if (!empty($context['ship']['ship_name']))
		{
			$context['page_title'] .= html_entity_decode(' &mdash; &ldquo;') . $context['ship']['ship_name'] . html_entity_decode('&rdquo;');
		}

		$context['sub_template'] = 'shipper_timeline';
		$context['edit_timeline_link'] = $context['ship']['timeline_link'];

		$context['other_ships'] = $ships;
		unset ($context['other_ships'][$ship_id_chars]);
		foreach ($context['other_ships'] as $ship_id => $ship)
		{
			if (empty($ship['show']))
			{
				unset ($context['other_ships'][$ship_id]);
			}

			$extra_chars = $ship['characters'];
			unset ($extra_chars[$firstchar], $extra_chars[$secondchar]);

			foreach (array_keys($ship['topics']) as $topic_id)
			{
				if (isset($context['ship']['topics'][$topic_id]))
				{
					$context['other_ships'][$ship_id]['topics'][$topic_id]['already_in_timeline'] = true;
				}

				$context['other_ships'][$ship_id]['topics'][$topic_id]['extra_characters'] = $extra_chars;
			}
		}
	}

	public static function export_epub()
	{
		global $context, $txt, $sourcedir, $smcFunc, $cachedir, $boardurl;

		is_not_guest();
		static::assert_enabled();

		require_once($sourcedir . '/Subs-Post.php');
		require_once($sourcedir . '/Subs-Editor.php');

		$container = Container::instance();
		$urlgenerator = $container->get('urlgenerator');

		try {
			[$ships, $characters] = static::get_shippers();
		}
		catch (\Exception $e)
		{
			fatal_lang_error($e->getMessage(), false);
		}

		$firstchar = $context['routing']['firstchar'];
		$secondchar = $context['routing']['secondchar'];
		$ship_id_chars = ((int) $firstchar > (int) $secondchar) ? $secondchar . '_' . $firstchar : $firstchar . '_' . $secondchar;

		if (!isset($ships[$ship_id_chars]))
		{
			fatal_lang_error('shipper_not_found', false);
		}

		$ship = $ships[$ship_id_chars];

		$epub = new Epub3($ship);
		$epub->send();
		die;
	}
}
