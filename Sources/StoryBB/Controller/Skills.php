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
use StoryBB\Helper\Parser;
use StoryBB\Model\TopicPrefix;
use StoryBB\Routing\Behaviours\Routable;
use StoryBB\StringLibrary;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Skills implements Routable
{
	public static function register_own_routes(RouteCollection $routes): void
	{
		$routes->add('skills', (new Route('/skills', ['_function' => [static::class, 'skill_list']])));
		//$routes->add('skills_characters', (new Route('/skills/characters', ['_function' => [static::class, 'skill_character_list']])));
	}

	public static function skill_list()
	{
		global $context, $txt, $smcFunc, $memberContext;

		loadLanguage('Profile');

		$context['skills'] = [];
		$context['characters'] = [];
		$character_map = [];

		$request = $smcFunc['db']->query('', '
			SELECT ss.id_skillset, ss.skillset_name, sb.id_branch, sb.skill_branch_name, s.id_skill, s.skill_name
			FROM {db_prefix}skillsets AS ss
				INNER JOIN {db_prefix}skill_branches AS sb ON (sb.id_skillset = ss.id_skillset)
				INNER JOIN {db_prefix}skills AS s ON (s.id_branch = sb.id_branch)
			WHERE ss.active = 1
				AND sb.active = 1
			ORDER BY ss.id_skillset, sb.branch_order, s.skill_order'
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$context['skills'][$row['id_skillset']]['title'] = $row['skillset_name'];
			$context['skills'][$row['id_skillset']]['skills'][$row['id_branch']]['title'] = $row['skill_branch_name'];
			$context['skills'][$row['id_skillset']]['skills'][$row['id_branch']]['skills'][$row['id_skill']] = ['name' => $row['skill_name'], 'characters' => []];
		}
		$smcFunc['db']->free_result($request);

		$request = $smcFunc['db']->query('', '
			SELECT ss.id_skillset, sb.id_branch, s.id_skill, cs.id_character, c.id_member
			FROM {db_prefix}skillsets AS ss
				INNER JOIN {db_prefix}skill_branches AS sb ON (sb.id_skillset = ss.id_skillset)
				INNER JOIN {db_prefix}skills AS s ON (s.id_branch = sb.id_branch)
				INNER JOIN {db_prefix}character_skills AS cs ON (cs.id_skill = s.id_skill)
				INNER JOIN {db_prefix}characters AS c ON (cs.id_character = c.id_character)
			WHERE ss.active = 1
				AND sb.active = 1
			ORDER BY c.character_name'
		);
		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$character_map[$row['id_character']] = $row['id_member'];
			if (isset($context['skills'][$row['id_skillset']]['skills'][$row['id_branch']]['skills'][$row['id_skill']]))
			{
				$context['skills'][$row['id_skillset']]['skills'][$row['id_branch']]['skills'][$row['id_skill']]['characters'][$row['id_character']] = $row['id_character'];
			}
		}
		$smcFunc['db']->free_result($request);

		$members = array_unique($character_map);
		loadMemberData($character_map);

		foreach ($character_map as $character => $member)
		{
			loadMemberContext($member);
			$context['characters'][$character] = $memberContext[$member]['characters'][$character];
		}

		foreach ($context['skills'] as $id_skillset => $skills) {
			foreach ($skills['skills'] as $id_branch => $branch) {
				foreach ($branch['skills'] as $id_skill => $skill) {
					foreach ($skill['characters'] as $id_character) {
						$context['skills'][$id_skillset]['skills'][$id_branch]['skills'][$id_skill]['characters'][$id_character] = $context['characters'][$id_character];
					}
				}
			}
		}

		$context['page_title'] = $txt['character_skills'];
		$context['sub_template'] = 'skills_list';
	}

	public static function skill_character_list()
	{
		global $context, $txt, $smcFunc;

		loadLanguage('Profile');

		$context['page_title'] = $txt['character_skills'];
	}
}