<?php

/**
 * The help page handler.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller;

use Exception;
use StoryBB\App;
use StoryBB\ClassManager;
use StoryBB\Container;
use StoryBB\Dependency\Database;
use StoryBB\Dependency\SiteSettings;
use StoryBB\Dependency\UrlGenerator;
use StoryBB\Routing\Behaviours\Routable;
use StoryBB\Routing\UnstyledErrorResponse;
use StoryBB\Routing\NotFoundResponse;
use StoryBB\StringLibrary;
use ScssPhp\ScssPhp\Compiler;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use StoryBB\Enum\CharacterConnectionType;

class Connections implements Routable
{
	use Database;
	use SiteSettings;
	use UrlGenerator;

	public static function register_own_routes(RouteCollection $routes): void
	{
		$routes->add('connections', (new Route('/connections.json', ['_function' => [static::class, 'display_action']])));
	}

	public static function display_action()
	{
		global $context, $txt, $smcFunc, $scripturl, $memberContext;

		$data = [
			'elements' => [],
			'connections' => [],
		];

		$character_ids = [];

		$member_ids = [];
		$request = $smcFunc['db']->query('', '
			SELECT id_member
			FROM {db_prefix}characters
			WHERE is_main = 0');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$member_ids[$row['id_member']] = $row['id_member'];
		}
		$smcFunc['db']->free_result($request);

		loadMemberData($member_ids);

		foreach ($member_ids as $member)
		{
			$loaded = loadMemberContext($member);
			if (!$loaded)
			{
				continue;
			}

			foreach ($memberContext[$member]['characters'] as $character)
			{
				if ($character['is_main'])
				{
					continue;
				}

				$character_ids['e' . $character['id_character']] = false;
				$data['elements'][] = [
					'id' => 'e' . $character['id_character'],
					'type' => 'Person',
					'label' => un_htmlspecialchars($character['character_name']),
					'image' => $character['avatar'],
				];
			}
		}

		$request = $smcFunc['db']->query('', '
			SELECT id_connection, id_character_from, id_character_to, connection_type, connection_label
			FROM {db_prefix}character_connections');
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			if (!isset($character_ids['e' . $row['id_character_from']]))
			{
				continue;
			}
			if (!isset($character_ids['e' . $row['id_character_to']]))
			{
				continue;
			}

			$character_ids['e' . $row['id_character_from']] = true;
			$character_ids['e' . $row['id_character_to']] = true;

			$data['connections'][] = [
				'id' => 'c' . $row['id_connection'],
				'from' => 'e' . $row['id_character_from'],
				'to' => 'e' . $row['id_character_to'],
				'direction' => CharacterConnectionType::from((int) $row['connection_type'])->direction(),
				'label' => $row['connection_label'],
			];
		}
		$smcFunc['db']->free_result($request);

		foreach ($data['elements'] as $index => $element)
		{
			if (empty($character_ids[$element['id']]))
			{
				unset ($data['elements'][$index]);
			}
		}
		$data['elements'] = array_values($data['elements']);

		header('Content-Type: application/json');
		echo json_encode($data, JSON_PRETTY_PRINT);
		obExit(false);
	}
}