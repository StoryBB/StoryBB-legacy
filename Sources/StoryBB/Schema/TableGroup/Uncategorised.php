<?php

/**
 * Uncategorised tables in the StoryBB schema.
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
 * Uncategories tables in the StoryBB schema.
 */
class Uncategorised
{
	/**
	 * Returns the description of this group of tables.
	 *
	 * @return string The group description, untranslated.
	 */
	public static function group_description(): string
	{
		return 'Uncategorised';
	}

	/**
	 * Returns the tables in this group of tables.
	 *
	 * @return array An array of Table objects that make up this group of tables.
	 */
	public static function return_tables(): array
	{
		return [
			Table::make('admin_info_files',
				[
					'id_file' => Column::tinyint()->auto_increment(),
					'filename' => Column::varchar(255),
					'path' => Column::varchar(255),
					'parameters' => Column::varchar(255),
					'data' => Column::text(),
					'filetype' => Column::varchar(255),
				],
				[
					Index::primary(['id_file']),
					Index::key(['filename' => 30])
				]
			),
			Table::make('approval_queue',
				[
					'id_msg' => Column::int(),
					'id_attach' => Column::int(),
				]
			),
			Table::make('attachments',
				[
					'id_attach' => Column::int()->auto_increment(),
					'id_thumb' => Column::int(),
					'id_msg' => Column::int(),
					'id_character' => Column::int(),
					'id_folder' => Column::tinyint()->default(1),
					'attachment_type' => Column::tinyint(),
					'filename' => Column::varchar(255),
					'file_hash' => Column::varchar(40),
					'fileext' => Column::varchar(8),
					'label' => Column::varchar(255),
					'size' => Column::int(),
					'downloads' => Column::mediumint(),
					'width' => Column::mediumint(),
					'height' => Column::mediumint(),
					'mime_type' => Column::varchar(128),
					'approved' => Column::tinyint()->default(1),
				],
				[
					Index::primary(['id_attach']),
					Index::unique(['id_character', 'id_attach']),
					Index::key(['id_msg']),
					Index::key(['attachment_type']),
				]
			),
			Table::make('ban_groups',
				[
					'id_ban_group' => Column::mediumint()->auto_increment(),
					'name' => Column::varchar(20),
					'ban_time' => Column::int(),
					'expire_time' => Column::int()->nullable(),
					'cannot_access' => Column::tinyint(),
					'cannot_register' => Column::tinyint(),
					'cannot_post' => Column::tinyint(),
					'cannot_login' => Column::tinyint(),
					'reason' => Column::varchar(255),
					'notes' => Column::text(),
				],
				[
					Index::primary(['id_ban_group']),
				]
			),
			Table::make('ban_items',
				[
					'id_ban' => Column::mediumint()->auto_increment(),
					'id_ban_group' => Column::smallint(),
					'ip_low' => Column::varbinary(16)->nullable(),
					'ip_high' => Column::varbinary(16)->nullable(),
					'email_address' => Column::varchar(255),
					'id_member' => Column::mediumint(),
					'hits' => Column::mediumint(),
				],
				[
					Index::primary(['id_ban']),
					Index::key(['id_ban_group']),
					Index::key(['ip_low', 'ip_high']),
				],
				[
					Constraint::from('ban_items.id_ban_group')->to('ban_groups.id_ban_group'),
				]
			),
			Table::make('block_instances',
				[
					'id_instance' => Column::mediumint()->auto_increment(),
					'class' => Column::varchar(255),
					'visibility' => Column::text(),
					'configuration' => Column::text(),
					'region' => Column::varchar(255),
					'position' => Column::smallint(),
					'active' => Column::tinyint(),
				],
				[
					Index::primary(['id_instance']),
				]
			),
			Table::make('bookmark',
				[
					'id_bookmark' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint(),
					'id_topic' => Column::mediumint(),
				],
				[
					Index::primary(['id_bookmark']),
					Index::unique(['id_member', 'id_topic']),
					Index::key(['id_topic', 'id_member']),
				],
				[
					Constraint::from('bookmark.id_member')->to('members.id_member'),
					Constraint::from('bookmark.id_topic')->to('topics.id_topic'),
				]
			),
			Table::make('characters',
				[
					'id_character' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint(),
					'character_name' => Column::varchar(255),
					'avatar' => Column::varchar(255),
					'signature' => Column::text(),
					'id_theme' => Column::tinyint(),
					'posts' => Column::mediumint(),
					'date_created' => Column::int(),
					'last_active' => Column::int(),
					'is_main' => Column::tinyint(),
					'rotate_avatar' => Column::tinyint(),
					'main_char_group' => Column::smallint(),
					'char_groups' => Column::varchar(255),
					'char_sheet' => Column::int(),
					'retired' => Column::tinyint(),
				],
				[
					Index::primary(['id_character']),
					Index::key(['id_member']),
				],
				[
					Constraint::from('characters.id_member')->to('members.id_member'),
				]
			),
			Table::make('character_sheet_comments',
				[
					'id_comment' => Column::int()->auto_increment(),
					'id_character' => Column::int(),
					'id_author' => Column::mediumint(),
					'time_posted' => Column::int(),
					'sheet_comment' => Column::text(),
				],
				[
					Index::primary(['id_comment']),
					Index::key(['id_character', 'time_posted']),
				],
				[
					Constraint::from('character_sheet_comments.id_character')->to('characters.id_character'),
					Constraint::from('character_sheet_comments.id_author')->to('members.id_member'),
				]
			),
			Table::make('character_sheet_templates',
				[
					'id_template' => Column::smallint()->auto_increment(),
					'template_name' => Column::varchar(100),
					'template' => Column::text(),
					'position' => Column::smallint(),
				],
				[
					Index::primary(['id_template']),
				]
			),
			Table::make('character_sheet_versions',
				[
					'id_version' => Column::int()->auto_increment(),
					'sheet_text' => Column::mediumtext(),
					'id_character' => Column::int(),
					'id_member' => Column::mediumint(),
					'created_time' => Column::int(),
					'id_approver' => Column::mediumint(),
					'approved_time' => Column::int(),
					'approval_state' => Column::tinyint(),
				],
				[
					Index::primary(['id_version']),
					Index::key(['id_character', 'id_approver']),
				]
			),
			Table::make('contact_form',
				[
					'id_message' => Column::mediumint()->auto_increment(),
					'id_member' => Column::mediumint(),
					'contact_name' => Column::varchar(255),
					'contact_email' => Column::varchar(255),
					'subject' => Column::varchar(255),
					'message' => Column::text(),
					'time_received' => Column::int(),
					'status' => Column::tinyint(),
				],
				[
					Index::primary(['id_message']),
				]
			),
			Table::make('contact_form_response',
				[
					'id_response' => Column::mediumint()->auto_increment(),
					'id_message' => Column::mediumint(),
					'id_member' => Column::mediumint(),
					'response' => Column::text(),
					'time_sent' => Column::int(),
				],
				[
					Index::primary(['id_response']),
					Index::key(['id_message']),
				]
			),
			Table::make('custom_fields',
				[
					'id_field' => Column::smallint()->auto_increment(),
					'col_name' => Column::varchar(12),
					'field_name' => Column::varchar(40),
					'field_desc' => Column::varchar(255),
					'field_type' => Column::varchar(8)->default('text'),
					'field_length' => Column::smallint()->default(255),
					'field_options' => Column::text(),
					'field_order' => Column::smallint(),
					'mask' => Column::varchar(255),
					'show_reg' => Column::tinyint(),
					'show_display' => Column::tinyint(),
					'show_profile' => Column::varchar(20)->default('forumprofile'),
					'private' => Column::tinyint(),
					'active' => Column::tinyint()->default(1),
					'bbc' => Column::tinyint(),
					'can_search' => Column::tinyint(),
					'default_value' => Column::varchar(255),
					'enclose' => Column::text(),
					'placement' => Column::tinyint(),
					'in_character' => Column::tinyint(),
				],
				[
					Index::primary(['id_field']),
					Index::unique(['col_name']),
				]
			),
			table::make('custom_field_values',
				[
					'id_value' => Column::int()->auto_increment(),
					'id_field' => Column::smallint(),
					'id_character' => Column::int(),
					'value' => Column::text(),
				],
				[
					Index::primary(['id_value']),
					Index::unique(['id_field', 'id_character']),
				],
				[
					Constraint::from('custom_field_values.id_field')->to('custom_fields.id_field'),
					Constraint::from('custom_field_values.id_character')->to('characters.id_character'),
				]
			),
			Table::make('files',
				[
					'id' => Column::int()->auto_increment(),
					'handler' => Column::varchar(32),
					'content_id' => Column::int(),
					'filename' => Column::varchar(255),
					'filehash' => Column::varchar(64),
					'mimetype' => Column::varchar(100),
					'size' => Column::bigint(),
					'id_owner' => Column::int(),
					'timemodified' => Column::int(),
				],
				[
					Index::primary(['id']),
					Index::key(['handler', 'content_id']),
				]
			),
			Table::make('group_moderators',
				[
					'id_group' => Column::smallint(),
					'id_member' => Column::mediumint(),
				],
				[
					Index::primary(['id_group', 'id_member']),
				],
				[
					Constraint::from('group_moderators.id_group')->to('membergroups.id_group'),
					Constraint::from('group_moderators.id_member')->to('members.id_member'),
				]
			),
			Table::make('integrations',
				[
					'id_integration' => Column::smallint()->auto_increment(),
					'integratable' => Column::varchar(255),
					'integration' => Column::varchar(255),
					'active' => Column::tinyint(),
					'options' => Column::text(),
				],
				[
					Index::primary(['id_integration']),
				]
			),
			Table::make('language_delta',
				[
					'id_delta' => Column::int()->auto_increment(),
					'id_lang' => Column::varchar(5),
					'lang_file' => Column::varchar(64),
					'lang_var' => Column::varchar(20),
					'lang_key' => Column::varchar(100),
					'lang_string' => Column::text(),
					'is_multi' => Column::tinyint(),
				],
				[
					Index::primary(['id_delta']),
					Index::unique(['id_lang', 'lang_file', 'lang_var', 'lang_key']),
				]
			),
			Table::make('log_actions',
				[
					'id_action' => Column::int()->auto_increment(),
					'id_log' => Column::tinyint()->default(1),
					'log_time' => Column::int(),
					'id_member' => Column::mediumint(),
					'ip' => Column::varbinary(16)->nullable(),
					'action' => Column::varchar(30),
					'id_board' => Column::smallint(),
					'id_topic' => Column::mediumint(),
					'id_msg' => Column::int(),
					'extra' => Column::text(),
				],
				[
					Index::primary(['id_action']),
					Index::key(['id_log']),
					Index::key(['log_time']),
					Index::key(['id_member']),
					Index::key(['id_board']),
					Index::key(['id_msg']),
					Index::key(['id_topic', 'id_log']),
				]
			),
			Table::make('log_activity',
				[
					'date' => Column::date()->default('1004-01-01'),
					'hits' => Column::mediumint(),
					'topics' => Column::smallint(),
					'posts' => Column::smallint(),
					'chars' => Column::smallint(),
					'registers' => Column::smallint(),
					'most_on' => Column::smallint(),
				],
				[
					Index::primary(['date']),
				]
			),
			Table::make('log_banned',
				[
					'id_ban_log' => Column::mediumint()->auto_increment(),
					'id_member' => Column::mediumint(),
					'ip' => Column::varbinary(16)->nullable(),
					'email' => Column::varchar(255),
					'log_time' => Column::int(),
				],
				[
					Index::primary(['id_ban_log']),
					Index::key(['log_time']),
				]
			),
			Table::make('log_boards',
				[
					'id_member' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'id_msg' => Column::int(),
				],
				[
					Index::primary(['id_member', 'id_board']),
				]
			),
			Table::make('log_comments',
				[
					'id_comment' => Column::mediumint()->auto_increment(),
					'id_member' => Column::mediumint(),
					'member_name' => Column::varchar(80),
					'comment_type' => Column::varchar(8)->default('warning'),
					'id_recipient' => Column::mediumint(),
					'recipient_name' => Column::varchar(255),
					'log_time' => Column::int(),
					'id_notice' => Column::mediumint(),
					'counter' => Column::tinyint(),
					'body' => Column::text(),
				],
				[
					Index::primary(['id_comment']),
					Index::key(['id_recipient']),
					Index::key(['log_time']),
					Index::key(['comment_type']),
				]
			),
			Table::make('log_digest',
				[
					'id_topic' => Column::mediumint(),
					'id_msg' => Column::int(),
					'note_type' => Column::varchar(10)->default('post'),
					'daily' => Column::tinyint(),
					'exclude' => Column::mediumint(),
				]
			),
			Table::make('log_errors',
				[
					'id_error' => Column::mediumint()->auto_increment(),
					'log_time' => Column::int(),
					'id_member' => Column::mediumint(),
					'ip' => Column::varbinary(16)->nullable(),
					'url' => Column::text(),
					'message' => Column::text(),
					'session' => Column::varchar(128),
					'error_type' => Column::varchar(15)->default('general'),
					'file' => Column::varchar(255),
					'line' => Column::mediumint(),
				],
				[
					Index::primary(['id_error']),
					Index::key(['log_time']),
					Index::key(['id_member']),
					Index::key(['ip']),
				]
			),
			Table::make('log_floodcontrol',
				[
					'ip' => Column::varbinary(16),
					'log_time' => Column::int(),
					'log_type' => Column::varchar(8)->default('post'),
				],
				[
					Index::primary(['ip', 'log_type'])
				],
				[],
				[
					'prefer_engine' => ['MEMORY', 'InnoDB'],
				]
			),
			Table::make('log_group_requests',
				[
					'id_request' => Column::mediumint()->auto_increment(),
					'id_member' => Column::mediumint(),
					'id_group' => Column::smallint(),
					'time_applied' => Column::int(),
					'reason' => Column::text(),
					'status' => Column::tinyint(),
					'id_member_acted' => Column::mediumint(),
					'member_name_acted' => Column::varchar(255),
					'time_acted' => Column::int(),
					'act_reason' => Column::text(),
				],
				[
					Index::primary(['id_request']),
					Index::key(['id_member', 'id_group'])
				]
			),
			Table::make('log_mark_read',
				[
					'id_member' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'id_msg' => Column::int(),
				],
				[
					Index::primary(['id_member', 'id_board']),
				]
			),
			Table::make('log_member_notices',
				[
					'id_notice' => Column::mediumint()->auto_increment(),
					'subject' => Column::varchar(255),
					'body' => Column::text(),
				],
				[
					Index::primary(['id_notice']),
				]
			),
			Table::make('log_notify',
				[
					'id_member' => Column::mediumint(),
					'id_topic' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'sent' => Column::tinyint(),
				],
				[
					Index::primary(['id_member', 'id_topic', 'id_board']),
					Index::key(['id_topic', 'id_member']),
				]
			),
			Table::make('log_online',
				[
					'session' => Column::varchar(128),
					'log_time' => Column::int(),
					'id_member' => Column::mediumint(),
					'id_character' => Column::int(),
					'robot_name' => Column::varchar(20),
					'ip' => Column::varbinary(16)->nullable(),
					'url' => Column::varchar(2048),
					'route' => Column::varchar(255),
					'routeparams' => Column::varchar(2048),
				],
				[
					Index::primary(['session']),
					Index::key(['log_time']),
					Index::key(['id_member']),
					Index::key(['route' => 16]),
				],
				[],
				[
					'prefer_engine' => ['MEMORY', 'InnoDB'],
				]
			),
			Table::make('log_reported',
				[
					'id_report' => Column::mediumint()->auto_increment(),
					'id_msg' => Column::int(),
					'id_topic' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'id_member' => Column::mediumint(),
					'membername' => Column::varchar(255),
					'subject' => Column::varchar(255),
					'body' => Column::mediumtext(),
					'time_started' => Column::int(),
					'time_updated' => Column::int(),
					'num_reports' => Column::mediumint(),
					'closed' => Column::tinyint(),
					'ignore_all' => Column::tinyint(),
				],
				[
					Index::primary(['id_report']),
					Index::key(['id_member']),
					Index::key(['id_topic']),
					Index::key(['closed']),
					Index::key(['time_started']),
					Index::key(['id_msg']),
				]
			),
			Table::make('log_reported_comments',
				[
					'id_comment' => Column::mediumint()->auto_increment(),
					'id_report' => Column::mediumint(),
					'id_member' => Column::mediumint(),
					'membername' => Column::varchar(255),
					'member_ip' => Column::varbinary(16)->nullable(),
					'comment' => Column::varchar(255),
					'time_sent' => Column::int(),
				],
				[
					Index::primary(['id_comment']),
					Index::key(['id_report']),
					Index::key(['time_sent']),
					Index::key(['id_member']),
				]
			),
			Table::make('log_search_messages',
				[
					'id_search' => Column::tinyint(),
					'id_msg' => Column::int(),
				],
				[
					Index::primary(['id_search', 'id_msg']),
				]
			),
			Table::make('log_search_results',
				[
					'id_search' => Column::tinyint(),
					'id_topic' => Column::mediumint(),
					'id_msg' => Column::int(),
					'relevance' => Column::smallint(),
					'num_matches' => Column::smallint(),
				],
				[
					Index::primary(['id_search', 'id_topic']),
				]
			),
			Table::make('log_search_subjects',
				[
					'word' => Column::varchar(20),
					'id_topic' => Column::mediumint(),
				],
				[
					Index::primary(['word', 'id_topic']),
					Index::key(['id_topic']),
				]
			),
			Table::make('log_search_topics',
				[
					'id_search' => Column::tinyint(),
					'id_topic' => Column::mediumint(),
				],
				[
					Index::primary(['id_search', 'id_topic']),
				]
			),
			Table::make('log_subscribed',
				[
					'id_sublog' => Column::int()->auto_increment(),
					'id_subscribe' => Column::mediumint(),
					'id_member' => Column::int(),
					'old_id_group' => Column::smallint(),
					'start_time' => Column::int(),
					'end_time' => Column::int(),
					'status' => Column::tinyint(),
					'payments_pending' => Column::tinyint(),
					'pending_details' => Column::text(),
					'reminder_sent' => Column::tinyint(),
					'vendor_ref' => Column::varchar(255),
				],
				[
					Index::primary(['id_sublog']),
					Index::unique(['id_subscribe', 'id_member']),
					Index::key(['end_time']),
					Index::key(['reminder_sent']),
					Index::key(['payments_pending']),
					Index::key(['status']),
					Index::key(['id_member']),
				]
			),
			Table::make('log_topics',
				[
					'id_member' => Column::mediumint(),
					'id_topic' => Column::mediumint(),
					'id_msg' => Column::int(),
					'unwatched' => Column::tinyint(),
				],
				[
					Index::primary(['id_member', 'id_topic']),
					Index::key(['id_topic']),
				]
			),
			Table::make('mail_queue',
				[
					'id_mail' => Column::int()->auto_increment(),
					'time_sent' => Column::int(),
					'recipient' => Column::varchar(255),
					'body' => Column::mediumtext(),
					'subject' => Column::varchar(255),
					'headers' => Column::text(),
					'send_html' => Column::tinyint(),
					'priority' => Column::tinyint()->default(1),
					'private' => Column::tinyint(),
				],
				[
					Index::primary(['id_mail']),
					Index::key(['time_sent']),
					Index::key(['priority', 'id_mail']),
				]
			),
			Table::make('membergroups',
				[
					'id_group' => Column::smallint()->auto_increment(),
					'group_name' => Column::varchar(80),
					'description' => Column::text(),
					'online_color' => Column::varchar(20),
					'max_messages' => Column::smallint(),
					'icons' => Column::varchar(255),
					'group_type' => Column::tinyint(),
					'hidden' => Column::tinyint(),
					'id_parent' => Column::smallint()->signed()->default(-2),
					'is_character' => Column::tinyint(),
					'badge_order' => Column::smallint(),
				],
				[
					Index::primary(['id_group']),
				]
			),
			Table::make('members',
				[
					'id_member' => Column::mediumint()->auto_increment(),
					'member_name' => Column::varchar(80),
					'date_registered' => Column::int(),
					'posts' => Column::mediumint(),
					'id_group' => Column::smallint(),
					'current_character' => Column::int(),
					'immersive_mode' => Column::tinyint(),
					'lngfile' => Column::varchar(255),
					'last_login' => Column::int(),
					'real_name' => Column::varchar(255),
					'instant_messages' => Column::smallint(),
					'unread_messages' => Column::smallint(),
					'new_pm' => Column::tinyint(),
					'alerts' => Column::int(),
					'buddy_list' => Column::text(),
					'pm_ignore_list' => Column::varchar(255),
					'pm_prefs' => Column::mediumint(),
					'passwd' => Column::varchar(255),
					'auth' => Column::varchar(255),
					'email_address' => Column::varchar(255),
					'birthdate' => Column::date()->default('1004-01-01'),
					'birthday_visibility' => Column::tinyint(),
					'show_online' => Column::tinyint()->default(1),
					'time_format' => Column::varchar(80),
					'signature' => Column::text(),
					'time_offset' => Column::float(),
					'avatar' => Column::varchar(255),
					'member_ip' => Column::varbinary(16)->nullable(),
					'member_ip2' => Column::varbinary(16)->nullable(),
					'secret_question' => Column::varchar(255),
					'secret_answer' => Column::varchar(64),
					'id_theme' => Column::tinyint(),
					'is_activated' => Column::tinyint()->default(1),
					'validation_code' => Column::varchar(10),
					'id_msg_last_visit' => Column::int(),
					'additional_groups' => Column::varchar(255),
					'total_time_logged_in' => Column::int(),
					'password_salt' => Column::varchar(255),
					'ignore_boards' => Column::text(),
					'warning' => Column::tinyint(),
					'passwd_flood' => Column::varchar(12),
					'pm_receive_from' => Column::tinyint()->default(1),
					'timezone' => Column::varchar(80)->default('UTC'),
					'policy_acceptance' => Column::tinyint(),
					'currency' => Column::bigint()->signed(),
				],
				[
					Index::primary(['id_member']),
					Index::key(['member_name']),
					Index::key(['real_name' => 80]),
					Index::key(['email_address' => 30]),
					Index::key(['date_registered']),
					Index::key(['id_group']),
					Index::key(['birthdate']),
					Index::key(['posts']),
					Index::key(['last_login']),
					Index::key(['lngfile' => 30]),
					Index::key(['warning']),
					Index::key(['total_time_logged_in']),
					Index::key(['id_theme']),
				]
			),
			Table::make('member_logins',
				[
					'id_login' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint(),
					'time' => Column::int(),
					'ip' => Column::varbinary(16)->nullable(),
					'ip2' => Column::varbinary(16)->nullable(),
				],
				[
					Index::primary(['id_login']),
					Index::key(['id_member']),
					Index::key(['time']),
				]
			),
			Table::make('mentions',
				[
					'content_id' => Column::int(),
					'content_type' => Column::varchar(10),
					'id_mentioned' => Column::int(),
					'id_character' => Column::int(),
					'mentioned_chr' => Column::int(),
					'id_member' => Column::mediumint(),
					'time' => Column::int(),
				],
				[
					Index::primary(['content_id', 'content_type', 'id_mentioned']),
					Index::key(['content_id', 'content_type']),
					Index::key(['id_member']),
				]
			),
			Table::make('moderators',
				[
					'id_board' => Column::smallint(),
					'id_member' => Column::mediumint(),
				],
				[
					Index::primary(['id_board', 'id_member']),
				],
				[
					Constraint::from('moderators.id_board')->to('boards.id_board'),
					Constraint::from('moderators.id_member')->to('members.id_member'),
				]
			),
			Table::make('moderator_groups',
				[
					'id_board' => Column::smallint(),
					'id_group' => Column::smallint(),
				],
				[
					Index::primary(['id_board', 'id_group']),
				],
				[
					Constraint::from('moderator_groups.id_board')->to('boards.id_board'),
					Constraint::from('moderator_groups.id_group')->to('membergroups.id_group'),
				]
			),
			Table::make('page',
				[
					'id_page' => Column::mediumint()->auto_increment(),
					'page_name' => Column::varchar(64),
					'page_title' => Column::varchar(255),
					'page_content' => Column::mediumtext(),
					'show_help' => Column::tinyint(),
					'show_custom_field' => Column::smallint(),
					'custom_field_filter' => Column::tinyint(),
					'show_sidebar' => Column::tinyint(),
					'sidebar_icon' => Column::varchar(100),
				],
				[
					Index::primary(['id_page']),
					Index::key(['page_name']),
				]
			),
			Table::make('page_access',
				[
					'id_page' => Column::mediumint(),
					'id_group' => Column::smallint()->signed(),
					'allow_deny' => Column::tinyint(),
				],
				[
					Index::primary(['id_page', 'id_group']),
				],
				[
					Constraint::from('page_access.id_page')->to('page.id_page'),
				]
			),
			Table::make('qanda',
				[
					'id_question' => Column::smallint()->auto_increment(),
					'lngfile' => Column::varchar(255),
					'question' => Column::varchar(255),
					'answers' => Column::text(),
				],
				[
					Index::primary(['id_question']),
					Index::key(['lngfile' => 30]),
				]
			),
			Table::make('search_index',
				[
					'id_document' => Column::int()->auto_increment(),
					'content_type' => Column::varchar(32),
					'content_id' => Column::int(),
					'title' => Column::varchar(255),
					'content' => Column::mediumtext(),
					'meta' => Column::mediumtext(),
					'id_member' => Column::mediumint(),
					'id_character' => Column::int(),
					'content_time' => Column::int(),
				],
				[
					Index::primary(['id_document']),
					Index::key(['id_member', 'id_character']),
					Index::unique(['content_type', 'content_id']),
					Index::fulltext(['title', 'content', 'meta']),
					Index::fulltext(['title', 'meta']),
				]
			),
			Table::make('settings',
				[
					'variable' => Column::varchar(64),
					'value' => Column::text(),
				],
				[
					Index::primary(['variable']),
				]
			),
			Table::make('sessions',
				[
					'session_id' => Column::varbinary(128),
					'data' => Column::mediumblob(),
					'session_time' => Column::int(),
					'lifetime' => Column::int(),
				],
				[
					Index::primary(['session_id']),
				]
			),
			Table::make('sessions_persist',
				[
					'id_persist' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint(),
					'persist_key' => Column::varbinary(32),
					'timecreated' => Column::int(),
					'timeexpires' => Column::int(),
				],
				[
					Index::primary(['id_persist']),
					Index::unique(['id_member', 'persist_key']),
					Index::key(['timeexpires']),
				]
			),
			Table::make('smileys',
				[
					'id_smiley' => Column::smallint()->auto_increment(),
					'code' => Column::text(),
					'filename' => Column::varchar(48),
					'description' => Column::varchar(80),
					'smiley_row' => Column::tinyint(),
					'smiley_order' => Column::smallint(),
					'hidden' => Column::tinyint(),
				],
				[
					Index::primary(['id_smiley']),
				]
			),
			Table::make('subscriptions',
				[
					'id_subscribe' => Column::mediumint()->auto_increment(),
					'name' => Column::varchar(60),
					'description' => Column::varchar(255),
					'cost' => Column::text(),
					'length' => Column::varchar(6),
					'id_group' => Column::smallint(),
					'add_groups' => Column::varchar(40),
					'active' => Column::tinyint()->default(1),
					'repeatable' => Column::tinyint(),
					'allow_partial' => Column::tinyint(),
					'reminder' => Column::tinyint(),
					'email_complete' => Column::text(),
				],
				[
					Index::primary(['id_subscribe']),
					Index::key(['active']),
				]
			),
			Table::make('themes',
				[
					'id_member' => Column::mediumint()->signed(),
					'id_theme' => Column::tinyint()->default(1),
					'variable' => Column::varchar(64),
					'value' => Column::text(),
				],
				[
					Index::primary(['id_theme', 'id_member', 'variable']),
					Index::key(['id_member']),
				]
			),
			Table::make('todo',
				[
					'id_todo' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint()->signed(),
					'item' => Column::varchar(255),
					'position' => Column::int(),
					'created_at' => Column::int(),
					'completed_at' => Column::int(),
				],
				[
					Index::primary(['id_todo']),
					Index::key(['id_member']),
				]
			),
			Table::make('user_alerts',
				[
					'id_alert' => Column::int()->auto_increment(),
					'alert_time' => Column::int(),
					'id_member' => Column::mediumint(),
					'id_member_started' => Column::mediumint(),
					'member_name' => Column::varchar(255),
					'chars_src' => Column::int(),
					'chars_dest' => Column::int(),
					'content_type' => Column::varchar(255),
					'content_id' => Column::int(),
					'content_action' => Column::varchar(255),
					'is_read' => Column::int(),
					'extra' => Column::text(),
				],
				[
					Index::primary(['id_alert']),
					Index::key(['id_member']),
					Index::key(['alert_time']),
				]
			),
			Table::make('user_alerts_prefs',
				[
					'id_member' => Column::mediumint(),
					'alert_pref' => Column::varchar(32),
					'alert_value' => Column::tinyint(),
				],
				[
					Index::primary(['id_member', 'alert_pref']),
				]
			),
			Table::make('user_drafts',
				[
					'id_draft' => Column::int()->auto_increment(),
					'id_topic' => Column::mediumint(),
					'id_board' => Column::smallint(),
					'id_reply' => Column::int(),
					'type' => Column::tinyint(),
					'poster_time' => Column::int(),
					'id_member' => Column::mediumint(),
					'subject' => Column::varchar(255),
					'smileys_enabled' => Column::tinyint()->default(1),
					'body' => Column::mediumtext(),
					'locked' => Column::tinyint(),
					'is_sticky' => Column::tinyint(),
					'to_list' => Column::varchar(255),
					'meta' => Column::text(),
				],
				[
					Index::primary(['id_draft']),
					Index::unique(['id_member', 'id_draft', 'type']),
				]
			),
			Table::make('user_exports',
				[
					'id_export' => Column::int()->auto_increment(),
					'id_attach' => Column::int(),
					'id_member' => Column::mediumint(),
					'id_requester' => Column::mediumint(),
					'requested_on' => Column::int(),
				],
				[
					Index::primary(['id_export']),
					Index::key(['id_member']),
				]
			),
			Table::make('user_likes',
				[
					'id_member' => Column::mediumint(),
					'content_type' => Column::char(6),
					'content_id' => Column::int(),
					'like_time' => Column::int(),
				],
				[
					Index::primary(['content_id', 'content_type', 'id_member']),
					Index::key(['content_id', 'content_type']),
					Index::key(['id_member']),
				]
			),
			Table::make('user_preferences',
				[
					'id_preference' => Column::int()->auto_increment(),
					'id_member' => Column::mediumint(),
					'preference' => Column::varchar(255),
					'value' => Column::text(),
				],
				[
					Index::primary(['id_preference']),
					Index::key(['id_member', 'preference']),
				],
				[
					Constraint::from('user_preferences.id_member')->to('members.id_member'),
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
			'background' => 'LightGray',
			'border' => 'Gray',
		];
	}
}
