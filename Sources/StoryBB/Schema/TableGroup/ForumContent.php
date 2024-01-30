<?php

/**
 * Tables relating to core forum content in the StoryBB schema.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Schema\TableGroup;

use StoryBB\Schema\Table;
use StoryBB\Schema\Column;
use StoryBB\Schema\Index;
use StoryBB\Schema\Constraint;

/**
 * Tables relating to core forum content in the StoryBB schema.
 */
class ForumContent
{
	/**
	 * Returns the description of this group of tables.
	 *
	 * @return string The group description, untranslated.
	 */
	public static function group_description(): string
	{
		return 'Forum Content';
	}

	/**
	 * Returns the tables in this group of tables.
	 *
	 * @return array An array of Table objects that make up this group of tables.
	 */
	public static function return_tables(): array
	{
		return [
			Table::make('boards',
				[
					'id_board' => Column::smallint()->auto_increment(),
					'id_cat' => Column::tinyint(),
					'child_level' => Column::tinyint(),
					'id_parent' => Column::smallint(),
					'board_order' => Column::smallint()->signed(),
					'id_last_msg' => Column::int(),
					'id_msg_updated' => Column::int(),
					'member_groups' => Column::varchar(255)->default('-1,0'),
					'id_profile' => Column::smallint()->default(1),
					'name' => Column::varchar(255),
					'slug' => Column::varchar(255),
					'description' => Column::text(),
					'num_topics' => Column::mediumint(),
					'num_posts' => Column::mediumint(),
					'count_posts' => Column::tinyint(),
					'id_theme' => Column::tinyint(),
					'override_theme' => Column::tinyint(),
					'unapproved_posts' => Column::smallint()->signed(),
					'unapproved_topics' => Column::smallint()->signed(),
					'redirect' => Column::varchar(255),
					'deny_member_groups' => Column::varchar(255),
					'in_character' => Column::tinyint(),
					'board_sort' => Column::varchar(25),
				],
				[
					Index::primary(['id_board']),
					Index::unique(['id_cat', 'id_board']),
					Index::key(['id_parent']),
					Index::key(['id_msg_updated']),
					Index::key(['member_groups' => 48]),
				],
				[
					Constraint::from('boards.id_cat')->to('categories.id_cat'),
				]
			),
			Table::make('categories',
				[
					'id_cat' => Column::tinyint()->auto_increment(),
					'cat_order' => Column::tinyint(),
					'name' => Column::varchar(255),
					'description' => Column::text(),
					'can_collapse' => Column::tinyint()->default(1),
				],
				[
					Index::primary(['id_cat']),
				]
			),
			Table::make('messages',
				[
					'id_msg' => Column::int()->auto_increment(),
					'id_topic' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'poster_time' => Column::int(),
					'id_creator' => Column::mediumint(),
					'id_member' => Column::mediumint(),
					'id_character' => Column::int(),
					'id_avatar_attach' => Column::int(),
					'id_msg_modified' => Column::int(),
					'subject' => Column::varchar(255),
					'poster_name' => Column::varchar(255),
					'poster_email' => Column::varchar(255),
					'poster_ip' => Column::varbinary(16)->nullable(),
					'smileys_enabled' => Column::tinyint()->default(1),
					'modified_time' => Column::int(),
					'modified_name' => Column::varchar(255),
					'modified_reason' => Column::varchar(255),
					'body' => Column::mediumtext(),
					'approved' => Column::tinyint()->default(1),
					'likes' => Column::smallint(),
				],
				[
					Index::primary(['id_msg']),
					Index::unique(['id_board', 'id_msg']),
					Index::unique(['id_member', 'id_msg']),
					Index::key(['approved']),
					Index::key(['poster_ip', 'id_topic']),
					Index::key(['id_member', 'id_topic']),
					Index::key(['id_member', 'id_board']),
					Index::key(['id_member', 'approved', 'id_msg']),
					Index::key(['id_topic', 'id_msg', 'id_member', 'approved']),
					Index::key(['id_member', 'poster_ip', 'id_msg']),
					Index::key(['likes']),
				]
			),
			Table::make('topic_invites', 
				[
					'id_invite' => Column::int()->auto_increment(),
					'id_topic' => Column::mediumint(),
					'id_character' => Column::int(),
					'invite_status' => Column::tinyint(),
					'invite_time' => Column::int(),
				],
				[
					Index::primary(['id_invite']),
					Index::key(['id_topic', 'id_character']),
					Index::key(['id_character', 'id_topic']),
				],
				[
					Constraint::from('topic_invites.id_topic')->to('topics.id_topic'),
					Constraint::from('topic_invites.id_character')->to('characters.id_character'),
				]
			),
			Table::make('topic_prefixes',
				[
					'id_prefix' => Column::smallint()->auto_increment(),
					'name' => Column::varchar(50),
					'css_class' => Column::varchar(100),
					'sort_order' => Column::smallint(),
					'selectable' => Column::tinyint(),
				],
				[
					Index::primary(['id_prefix']),
				]
			),
			Table::make('topic_prefix_boards',
				[
					'id_prefixboard' => Column::int()->auto_increment(),
					'id_prefix' => Column::smallint(),
					'id_board' => Column::mediumint(),
				],
				[
					Index::primary(['id_prefixboard']),
					Index::key(['id_prefix', 'id_board']),
					Index::key(['id_board', 'id_prefix']),
				],
				[
					Constraint::from('topic_prefix_boards.id_prefix')->to('topic_prefixes.id_prefix'),
					Constraint::from('topic_prefix_boards.id_topic')->to('boards.id_board'),
				]
			),
			Table::make('topic_prefix_groups',
				[
					'id_prefix' => Column::smallint(),
					'id_group' => Column::smallint()->signed(),
					'allow_deny' => Column::tinyint(),
				],
				[
					Index::primary(['id_prefix', 'id_group']),
				],
				[
					Constraint::from('topic_prefix_groups.id_prefix')->to('topic_prefixes.id_prefix'),
					Constraint::from('topic_prefix_groups.id_group')->to('membergroups.id_group'),
				]
			),
			Table::make('topic_prefix_topics',
				[
					'id_prefixtopic' => Column::int()->auto_increment(),
					'id_prefix' => Column::smallint(),
					'id_topic' => Column::mediumint(),
				],
				[
					Index::primary(['id_prefixtopic']),
					Index::key(['id_prefix', 'id_topic']),
					Index::key(['id_topic', 'id_prefix']),
				],
				[
					Constraint::from('topic_prefix_topics.id_prefix')->to('topic_prefixes.id_prefix'),
					Constraint::from('topic_prefix_topics.id_topic')->to('topics.id_topic'),
				]
			),
			Table::make('topics',
				[
					'id_topic' => Column::mediumint()->auto_increment(),
					'is_sticky' => Column::tinyint(),
					'id_board' => Column::smallint(),
					'id_first_msg' => Column::int(),
					'id_last_msg' => Column::int(),
					'id_member_started' => Column::mediumint(),
					'id_member_updated' => Column::mediumint(),
					'id_poll' => Column::mediumint(),
					'id_previous_board' => Column::smallint(),
					'id_previous_topic' => Column::mediumint(),
					'slug' => Column::varchar(255),
					'num_replies' => Column::int(),
					'num_views' => Column::int(),
					'locked' => Column::tinyint(),
					'finished' => Column::tinyint(),
					'redirect_expires' => Column::int(),
					'id_redirect_topic' => Column::mediumint(),
					'unapproved_posts' => Column::smallint(),
					'approved' => Column::tinyint()->default(1),
					'is_moved' => Column::tinyint(),
				],
				[
					Index::primary(['id_topic']),
					Index::unique(['id_last_msg', 'id_board']),
					Index::unique(['id_first_msg', 'id_board']),
					Index::unique(['id_poll', 'id_topic']),
					Index::key(['is_sticky']),
					Index::key(['approved']),
					Index::key(['id_member_started', 'id_board']),
					Index::key(['id_board', 'is_sticky', 'id_last_msg']),
					Index::key(['id_board', 'id_first_msg']),
				],
				[
					Constraint::from('topics.id_board')->to('boards.id_board'),
					Constraint::from('topics.id_first_msg')->to('messages.id_msg'),
					Constraint::from('topics.id_last_msg')->to('messages.id_msg'),
				]
			),
		];
	}

	/**
	 * Return the colour scheme that the UML builder should use.
	 *
	 * @return array Array of named or hex colours for PlantUML.
	 */
	public static function plantuml_colour_scheme(): array
	{
		return [
			'background' => 'LightSkyBlue',
			'border' => 'RoyalBlue',
		];
	}
}
