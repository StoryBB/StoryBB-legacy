<?php

/**
 * This class handles the database processing for a post.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Model;

use StoryBB\Helper\Mentions;
use StoryBB\Hook\Integratable;
use StoryBB\Hook\Observable;
use StoryBB\Task;

/**
 * This class handles the database processing for a post.
 */
class Post
{

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
	public static function create(&$msgOptions, &$topicOptions, &$posterOptions)
	{
		global $user_info, $txt, $modSettings, $smcFunc, $sourcedir;

		// Set optional parameters to the default value.
		$msgOptions['smileys_enabled'] = !empty($msgOptions['smileys_enabled']);
		$msgOptions['attachments'] = empty($msgOptions['attachments']) ? [] : $msgOptions['attachments'];
		$msgOptions['approved'] = isset($msgOptions['approved']) ? (int) $msgOptions['approved'] : 1;
		$msgOptions['id_avatar_attach'] = isset($msgOptions['id_avatar_attach']) ? (int) $msgOptions['id_avatar_attach'] : 0;
		$topicOptions['id'] = empty($topicOptions['id']) ? 0 : (int) $topicOptions['id'];
		$topicOptions['poll'] = isset($topicOptions['poll']) ? (int) $topicOptions['poll'] : null;
		$topicOptions['lock_mode'] = isset($topicOptions['lock_mode']) ? $topicOptions['lock_mode'] : null;
		$topicOptions['sticky_mode'] = isset($topicOptions['sticky_mode']) ? $topicOptions['sticky_mode'] : null;
		$topicOptions['redirect_expires'] = isset($topicOptions['redirect_expires']) ? $topicOptions['redirect_expires'] : null;
		$topicOptions['redirect_topic'] = isset($topicOptions['redirect_topic']) ? $topicOptions['redirect_topic'] : null;
		$topicOptions['is_moved'] = !empty($topicOptions['is_moved']) ? $topicOptions['is_moved'] : null;
		$posterOptions['id'] = empty($posterOptions['id']) ? 0 : (int) $posterOptions['id'];
		$posterOptions['ip'] = empty($posterOptions['ip']) ? $user_info['ip'] : $posterOptions['ip'];
		$posterOptions['char_id'] = empty($posterOptions['char_id']) ? 0 : (int) $posterOptions['char_id'];

		// Not exactly a post option but it allows hooks and/or other sources to skip sending notifications if they don't want to
		$msgOptions['send_notifications'] = isset($msgOptions['send_notifications']) ? (bool) $msgOptions['send_notifications'] : true;

		// We need to know if the topic is approved. If we're told that's great - if not find out.
		if (!empty($topicOptions['id']) && !isset($topicOptions['is_approved']))
		{
			$request = $smcFunc['db']->query('', '
				SELECT approved
				FROM {db_prefix}topics
				WHERE id_topic = {int:id_topic}
				LIMIT 1',
				[
					'id_topic' => $topicOptions['id'],
				]
			);
			list ($topicOptions['is_approved']) = $smcFunc['db']->fetch_row($request);
			$smcFunc['db']->free_result($request);
		}

		// If nothing was filled in as name/e-mail address, try the member table.
		if (!isset($posterOptions['name']) || $posterOptions['name'] == '' || (empty($posterOptions['email']) && !empty($posterOptions['id'])))
		{
			if (empty($posterOptions['id']))
			{
				$posterOptions['id'] = 0;
				$posterOptions['name'] = $txt['guest_title'];
				$posterOptions['email'] = '';
			}
			elseif ($posterOptions['id'] != $user_info['id'])
			{
				$request = $smcFunc['db']->query('', '
					SELECT member_name, email_address
					FROM {db_prefix}members
					WHERE id_member = {int:id_member}
					LIMIT 1',
					[
						'id_member' => $posterOptions['id'],
					]
				);
				// Couldn't find the current poster?
				if ($smcFunc['db']->num_rows($request) == 0)
				{
					trigger_error('StoryBB\\Model\\Post::create(): Invalid member id ' . $posterOptions['id'], E_USER_NOTICE);
					$posterOptions['id'] = 0;
					$posterOptions['name'] = $txt['guest_title'];
					$posterOptions['email'] = '';
				}
				else
					list ($posterOptions['name'], $posterOptions['email']) = $smcFunc['db']->fetch_row($request);
				$smcFunc['db']->free_result($request);
			}
			else
			{
				$posterOptions['name'] = $user_info['name'];
				$posterOptions['email'] = $user_info['email'];
			}
		}

		if (!empty($modSettings['enable_mentions']))
		{
			$msgOptions['mentioned_members'] = Mentions::getMentionedMembers($msgOptions['body']);
			if (!empty($msgOptions['mentioned_members']))
				$msgOptions['body'] = Mentions::getBody($msgOptions['body'], $msgOptions['mentioned_members']);
		}

		// It's do or die time: forget any user aborts!
		$previous_ignore_user_abort = ignore_user_abort(true);

		$new_topic = empty($topicOptions['id']);

		$message_columns = [
			'id_board' => 'int', 'id_topic' => 'int', 'id_creator' => 'int', 'id_member' => 'int', 'id_character' => 'int', 'subject' => 'string-255', 'body' => (!empty($modSettings['max_messageLength']) && $modSettings['max_messageLength'] > 65534 ? 'string-' . $modSettings['max_messageLength'] : (empty($modSettings['max_messageLength']) ? 'string' : 'string-65534')),
			'poster_name' => 'string-255', 'poster_email' => 'string-255', 'poster_time' => 'int', 'poster_ip' => 'inet',
			'smileys_enabled' => 'int', 'modified_name' => 'string', 'approved' => 'int', 'id_avatar_attach' => 'int',
		];

		$message_parameters = [
			$topicOptions['board'], $topicOptions['id'], $posterOptions['id'], $posterOptions['id'], $posterOptions['char_id'], $msgOptions['subject'], $msgOptions['body'],
			$posterOptions['name'], $posterOptions['email'], time(), $posterOptions['ip'],
			$msgOptions['smileys_enabled'] ? 1 : 0, '', $msgOptions['approved'], $msgOptions['id_avatar_attach'],
		];

		// What if we want to do anything with posts?
		call_integration_hook('integrate_create_post', [&$msgOptions, &$topicOptions, &$posterOptions, &$message_columns, &$message_parameters]);

		// Insert the post.
		$msgOptions['id'] = $smcFunc['db']->insert('',
			'{db_prefix}messages',
			$message_columns,
			$message_parameters,
			['id_msg'],
			1
		);

		// Something went wrong creating the message...
		if (empty($msgOptions['id']))
			return false;

		// Fix the attachments.
		if (!empty($msgOptions['attachments']))
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}attachments
				SET id_msg = {int:id_msg}
				WHERE id_attach IN ({array_int:attachment_list})',
				[
					'attachment_list' => $msgOptions['attachments'],
					'id_msg' => $msgOptions['id'],
				]
			);

		// What if we want to export new posts out to a CMS?
		(new Observable\Post\Created($msgOptions, $topicOptions, $posterOptions, $message_columns, $message_parameters))->execute();

		// Insert a new topic (if the topicID was left empty.)
		if ($new_topic)
		{
			$topic_columns = [
				'id_board' => 'int', 'id_member_started' => 'int', 'id_member_updated' => 'int', 'id_first_msg' => 'int',
				'id_last_msg' => 'int', 'locked' => 'int', 'is_sticky' => 'int', 'num_views' => 'int',
				'id_poll' => 'int', 'unapproved_posts' => 'int', 'approved' => 'int',
				'redirect_expires' => 'int', 'id_redirect_topic' => 'int', 'is_moved' => 'int',
			];
			$topic_parameters = [
				$topicOptions['board'], $posterOptions['id'], $posterOptions['id'], $msgOptions['id'],
				$msgOptions['id'], $topicOptions['lock_mode'] === null ? 0 : $topicOptions['lock_mode'], $topicOptions['sticky_mode'] === null ? 0 : $topicOptions['sticky_mode'], 0,
				$topicOptions['poll'] === null ? 0 : $topicOptions['poll'], $msgOptions['approved'] ? 0 : 1, $msgOptions['approved'],
				$topicOptions['redirect_expires'] === null ? 0 : $topicOptions['redirect_expires'], $topicOptions['redirect_topic'] === null ? 0 : $topicOptions['redirect_topic'], $topicOptions['is_moved'] === null ? 0 : $topicOptions['is_moved'],
			];

			call_integration_hook('integrate_before_create_topic', [&$msgOptions, &$topicOptions, &$posterOptions, &$topic_columns, &$topic_parameters]);

			$topicOptions['id'] = $smcFunc['db']->insert('',
				'{db_prefix}topics',
				$topic_columns,
				$topic_parameters,
				['id_topic'],
				1
			);

			// The topic couldn't be created for some reason.
			if (empty($topicOptions['id']))
			{
				// We should delete the post that did work, though...
				$smcFunc['db']->query('', '
					DELETE FROM {db_prefix}messages
					WHERE id_msg = {int:id_msg}',
					[
						'id_msg' => $msgOptions['id'],
					]
				);

				return false;
			}

			// Fix the message with the topic.
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}messages
				SET id_topic = {int:id_topic}
				WHERE id_msg = {int:id_msg}',
				[
					'id_topic' => $topicOptions['id'],
					'id_msg' => $msgOptions['id'],
				]
			);

			// There's been a new topic AND a new post today.
			trackStats(['topics' => '+', 'posts' => '+']);

			updateStats('topic', true);
			updateStats('subject', $topicOptions['id'], $msgOptions['subject']);

			if (!empty($topicOptions['prefixes']))
			{
				TopicPrefix::set_prefix_topic($topicOptions['id'], $topicOptions['prefixes']);
			}
			if (!empty($topicOptions['invite']))
			{
				TopicCollection::invite_to_topic($topicOptions['id'], $topicOptions['invite']);
			}

			if ($msgOptions['approved'])
			{
				Task::queue_adhoc('StoryBB\\Task\\Adhoc\\Achievement\\TopicStarter', [
					'account' => $posterOptions['id'],
					'character' => $posterOptions['char_id'],
				]);

				(new Integratable\Topic\Created($msgOptions, $topicOptions, $posterOptions))->execute();
			}

			// What if we want to export new topics out to a CMS?
			(new Observable\Topic\Created($msgOptions, $topicOptions, $posterOptions))->execute();
		}
		// The topic already exists, it only needs a little updating.
		else
		{
			$update_parameters = [
				'poster_id' => $posterOptions['id'],
				'id_msg' => $msgOptions['id'],
				'locked' => $topicOptions['lock_mode'],
				'is_sticky' => $topicOptions['sticky_mode'],
				'id_topic' => $topicOptions['id'],
				'counter_increment' => 1,
			];
			if ($msgOptions['approved'])
				$topics_columns = [
					'id_member_updated = {int:poster_id}',
					'id_last_msg = {int:id_msg}',
					'num_replies = num_replies + {int:counter_increment}',
				];
			else
				$topics_columns = [
					'unapproved_posts = unapproved_posts + {int:counter_increment}',
				];
			if ($topicOptions['lock_mode'] !== null)
				$topics_columns[] = 'locked = {int:locked}';
			if ($topicOptions['sticky_mode'] !== null)
				$topics_columns[] = 'is_sticky = {int:is_sticky}';

			call_integration_hook('integrate_modify_topic', [&$topics_columns, &$update_parameters, &$msgOptions, &$topicOptions, &$posterOptions]);

			// Update the number of replies and the lock/sticky status.
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}topics
				SET
					' . implode(', ', $topics_columns) . '
				WHERE id_topic = {int:id_topic}',
				$update_parameters
			);

			// One new post has been added today.
			trackStats(['posts' => '+']);

			if ($msgOptions['approved'])
			{
				(new Integratable\Reply\Created($msgOptions, $topicOptions, $posterOptions))->execute();
			}
		}

		// Creating is modifying...in a way.
		// @todo Why not set id_msg_modified on the insert?
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}messages
			SET id_msg_modified = {int:id_msg}
			WHERE id_msg = {int:id_msg}',
			[
				'id_msg' => $msgOptions['id'],
			]
		);

		// Increase the number of posts and topics on the board.
		if ($msgOptions['approved'])
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}boards
				SET num_posts = num_posts + 1' . ($new_topic ? ', num_topics = num_topics + 1' : '') . '
				WHERE id_board = {int:id_board}',
				[
					'id_board' => $topicOptions['board'],
				]
			);
		else
		{
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}boards
				SET unapproved_posts = unapproved_posts + 1' . ($new_topic ? ', unapproved_topics = unapproved_topics + 1' : '') . '
				WHERE id_board = {int:id_board}',
				[
					'id_board' => $topicOptions['board'],
				]
			);

			// Add to the approval queue too.
			$smcFunc['db']->insert('',
				'{db_prefix}approval_queue',
				[
					'id_msg' => 'int',
				],
				[
					$msgOptions['id'],
				],
				[]
			);

			Task::queue_adhoc('StoryBB\\Task\\Adhoc\\ApprovePostNotify', [
				'msgOptions' => $msgOptions,
				'topicOptions' => $topicOptions,
				'posterOptions' => $posterOptions,
				'type' => $new_topic ? 'topic' : 'post',
			]);
		}

		// Mark inserted topic as read (only for the user calling this function).
		if (!empty($topicOptions['mark_as_read']) && !$user_info['is_guest'])
		{
			// Since it's likely they *read* it before replying, let's try an UPDATE first.
			if (!$new_topic)
			{
				$smcFunc['db']->query('', '
					UPDATE {db_prefix}log_topics
					SET id_msg = {int:id_msg}
					WHERE id_member = {int:current_member}
						AND id_topic = {int:id_topic}',
					[
						'current_member' => $posterOptions['id'],
						'id_msg' => $msgOptions['id'],
						'id_topic' => $topicOptions['id'],
					]
				);

				$flag = $smcFunc['db']->affected_rows() != 0;
			}

			if (empty($flag))
			{
				$smcFunc['db']->insert('ignore',
					'{db_prefix}log_topics',
					['id_topic' => 'int', 'id_member' => 'int', 'id_msg' => 'int'],
					[$topicOptions['id'], $posterOptions['id'], $msgOptions['id']],
					['id_topic', 'id_member']
				);
			}
		}

		if ($msgOptions['approved'] && empty($topicOptions['is_approved']))
		{
			Task::queue_adhoc('StoryBB\\Task\\Adhoc\\ApproveReplyNotify', [
				'msgOptions' => $msgOptions,
				'topicOptions' => $topicOptions,
				'posterOptions' => $posterOptions,
			]);
		}

		// If there's a custom search index, it may need updating...
		require_once($sourcedir . '/Search.php');
		$searchAPI = findSearchAPI();
		if (is_callable([$searchAPI, 'postCreated']))
			$searchAPI->postCreated($msgOptions, $topicOptions, $posterOptions);

		// Increase the post counter for the user that created the post.
		if (!empty($posterOptions['update_post_count']) && !empty($posterOptions['id']) && $msgOptions['approved'])
		{
			// Are you the one that happened to create this post?
			if ($user_info['id'] == $posterOptions['id'])
				$user_info['posts']++;
			updateMemberData($posterOptions['id'], ['posts' => '+']);
		}
		if ($msgOptions['approved'] && !empty($posterOptions['char_id']) && !empty($posterOptions['update_post_count'])) {
			updateCharacterData($posterOptions['char_id'], ['posts' => '+']);
		}

		// They've posted, so they can make the view count go up one if they really want. (this is to keep views >= replies...)
		$_SESSION['last_read_topic'] = 0;

		// Better safe than sorry.
		if (isset($_SESSION['topicseen_cache'][$topicOptions['board']]))
			$_SESSION['topicseen_cache'][$topicOptions['board']]--;

		// Update all the stats so everyone knows about this new topic and message.
		updateStats('message', true, $msgOptions['id']);

		// Update the last message on the board assuming it's approved AND the topic is.
		if ($msgOptions['approved'])
			updateLastMessages($topicOptions['board'], $new_topic || !empty($topicOptions['is_approved']) ? $msgOptions['id'] : 0);

		// Queue createPost background notification
		if ($msgOptions['send_notifications'] && $msgOptions['approved'])
		{
			Task::queue_adhoc('StoryBB\\Task\\Adhoc\\CreatePostNotify', [
				'msgOptions' => $msgOptions,
				'topicOptions' => $topicOptions,
				'posterOptions' => $posterOptions,
				'type' => $new_topic ? 'topic' : 'reply',
			]);
		}

		// Alright, done now... we can abort now, I guess... at least this much is done.
		ignore_user_abort($previous_ignore_user_abort);

		// Success.
		return true;
	}

	/**
	 * Modifying a post...
	 *
	 * @param array &$msgOptions An array of information/options for the post
	 * @param array &$topicOptions An array of information/options for the topic
	 * @param array &$posterOptions An array of information/options for the poster
	 * @return bool Whether the post was modified successfully
	 */
	public static function modify(&$msgOptions, &$topicOptions, &$posterOptions)
	{
		global $user_info, $modSettings, $smcFunc, $sourcedir;

		$topicOptions['poll'] = isset($topicOptions['poll']) ? (int) $topicOptions['poll'] : null;
		$topicOptions['lock_mode'] = isset($topicOptions['lock_mode']) ? $topicOptions['lock_mode'] : null;
		$topicOptions['sticky_mode'] = isset($topicOptions['sticky_mode']) ? $topicOptions['sticky_mode'] : null;

		// This is longer than it has to be, but makes it so we only set/change what we have to.
		$messages_columns = [];
		if (isset($posterOptions['name']))
			$messages_columns['poster_name'] = $posterOptions['name'];
		if (isset($posterOptions['email']))
			$messages_columns['poster_email'] = $posterOptions['email'];
		if (isset($msgOptions['subject']))
			$messages_columns['subject'] = $msgOptions['subject'];
		if (isset($msgOptions['body']))
		{
			$messages_columns['body'] = $msgOptions['body'];

			// Fetch the old body in case something wants to do something with it.
			$request = $smcFunc['db']->query('', '
				SELECT body
				FROM {db_prefix}messages
				WHERE id_msg = {int:id_msg}',
				[
					'id_msg' => $msgOptions['id'],
				]
			);
			list ($msgOptions['old_body']) = $smcFunc['db']->fetch_row($request);
			$smcFunc['db']->free_result($request);
		}
		if (!empty($msgOptions['modify_time']))
		{
			$messages_columns['modified_time'] = $msgOptions['modify_time'];
			$messages_columns['modified_name'] = $msgOptions['modify_name'];
			$messages_columns['modified_reason'] = $msgOptions['modify_reason'];
			$messages_columns['id_msg_modified'] = $modSettings['maxMsgID'];
		}
		if (isset($msgOptions['smileys_enabled']))
			$messages_columns['smileys_enabled'] = empty($msgOptions['smileys_enabled']) ? 0 : 1;
		if (isset($msgOptions['id_avatar_attach']))
		{
			$messages_columns['id_avatar_attach'] = (int) $msgOptions['id_avatar_attach'];
		}

		// Which columns need to be ints?
		$messageInts = ['modified_time', 'id_msg_modified', 'smileys_enabled'];
		$update_parameters = [
			'id_msg' => $msgOptions['id'],
		];

		if (!empty($modSettings['enable_mentions']) && isset($msgOptions['body']))
		{
			$oldmentions = [];

			if (!empty($msgOptions['old_body']))
			{
				preg_match_all('/\[member\=([0-9]+)\]([^\[]*)\[\/member\]/U', $msgOptions['old_body'], $match);

				if (isset($match[1]) && isset($match[2]) && is_array($match[1]) && is_array($match[2]))
					foreach ($match[1] as $i => $oldID)
						$oldmentions[$oldID] = ['id' => $oldID, 'real_name' => $match[2][$i]];
			}

			$mentions = Mentions::getMentionedMembers($msgOptions['body']);
			$messages_columns['body'] = $msgOptions['body'] = Mentions::getBody($msgOptions['body'], $mentions);

			// Remove the poster.
			if (isset($mentions[$user_info['id']]))
				unset($mentions[$user_info['id']]);

			if (isset($oldmentions[$user_info['id']]))
				unset($oldmentions[$user_info['id']]);

			if (is_array($mentions) && is_array($oldmentions) && count(array_diff_key($mentions, $oldmentions)) > 0 && count($mentions) > count($oldmentions))
			{
				// Queue this for notification.
				$msgOptions['mentioned_members'] = array_diff_key($mentions, $oldmentions);

				Task::queue_adhoc('StoryBB\\Task\\Adhoc\\CreatePostNotify', [
					'msgOptions' => $msgOptions,
					'topicOptions' => $topicOptions,
					'posterOptions' => $posterOptions,
					'type' => 'edit',
				]);
			}
		}

		call_integration_hook('integrate_modify_post', [&$messages_columns, &$update_parameters, &$msgOptions, &$topicOptions, &$posterOptions, &$messageInts]);

		foreach ($messages_columns as $var => $val)
		{
			$messages_columns[$var] = $var . ' = {' . (in_array($var, $messageInts) ? 'int' : 'string') . ':var_' . $var . '}';
			$update_parameters['var_' . $var] = $val;
		}

		// Nothing to do?
		if (empty($messages_columns))
			return true;

		// Change the post.
		$smcFunc['db']->query('', '
			UPDATE {db_prefix}messages
			SET ' . implode(', ', $messages_columns) . '
			WHERE id_msg = {int:id_msg}',
			$update_parameters
		);

		// Update the topic prefixes if that's set. It _should_ only be set on editing first post.
		if (isset($topicOptions['prefixes']))
		{
			TopicPrefix::set_prefix_topic($topicOptions['id'], $topicOptions['prefixes']);
		}

		if (!empty($topicOptions['invite']))
		{
			TopicCollection::invite_to_topic($topicOptions['id'], $topicOptions['invite']);
		}

		// Lock and or sticky the post.
		if ($topicOptions['sticky_mode'] !== null || $topicOptions['lock_mode'] !== null || $topicOptions['poll'] !== null)
		{
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}topics
				SET
					is_sticky = {raw:is_sticky},
					locked = {raw:locked},
					id_poll = {raw:id_poll}
				WHERE id_topic = {int:id_topic}',
				[
					'is_sticky' => $topicOptions['sticky_mode'] === null ? 'is_sticky' : (int) $topicOptions['sticky_mode'],
					'locked' => $topicOptions['lock_mode'] === null ? 'locked' : (int) $topicOptions['lock_mode'],
					'id_poll' => $topicOptions['poll'] === null ? 'id_poll' : (int) $topicOptions['poll'],
					'id_topic' => $topicOptions['id'],
				]
			);
		}

		// Mark the edited post as read.
		if (!empty($topicOptions['mark_as_read']) && !$user_info['is_guest'])
		{
			// Since it's likely they *read* it before editing, let's try an UPDATE first.
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}log_topics
				SET id_msg = {int:id_msg}
				WHERE id_member = {int:current_member}
					AND id_topic = {int:id_topic}',
				[
					'current_member' => $user_info['id'],
					'id_msg' => $modSettings['maxMsgID'],
					'id_topic' => $topicOptions['id'],
				]
			);

			$flag = $smcFunc['db']->affected_rows() != 0;

			if (empty($flag))
			{
				$smcFunc['db']->insert('ignore',
					'{db_prefix}log_topics',
					['id_topic' => 'int', 'id_member' => 'int', 'id_msg' => 'int'],
					[$topicOptions['id'], $user_info['id'], $modSettings['maxMsgID']],
					['id_topic', 'id_member']
				);
			}
		}

		// If there's a custom search index, it needs to be modified...
		require_once($sourcedir . '/Search.php');
		$searchAPI = findSearchAPI();
		if (is_callable([$searchAPI, 'postModified']))
			$searchAPI->postModified($msgOptions, $topicOptions, $posterOptions);

		if (isset($msgOptions['subject']))
		{
			// Only update the subject if this was the first message in the topic.
			$request = $smcFunc['db']->query('', '
				SELECT id_topic
				FROM {db_prefix}topics
				WHERE id_first_msg = {int:id_first_msg}
				LIMIT 1',
				[
					'id_first_msg' => $msgOptions['id'],
				]
			);
			if ($smcFunc['db']->num_rows($request) == 1)
				updateStats('subject', $topicOptions['id'], $msgOptions['subject']);
			$smcFunc['db']->free_result($request);
		}

		// Finally, if we are setting the approved state we need to do much more work :(
		if (isset($msgOptions['approved']))
			approvePosts($msgOptions['id'], $msgOptions['approved']);

		return true;
	}
}
