<?php

/**
 * This taks handles notifying someone that a user has requested to join a group they moderate.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2018 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 3.0 Alpha 1
 */

namespace StoryBB\Task\Adhoc;

/**
 * This taks handles notifying someone that a user has requested to join a group they moderate.
 */
class GroupReqNotify extends \StoryBB\Task\Adhoc
{
	/**
	 * This executes the task - loads up the information, puts the email in the queue and inserts any alerts as needed.
	 * @return bool Always returns true.
	 */
	public function execute()
	{
		global $sourcedir, $smcFunc, $language, $modSettings, $scripturl;

		// Do we have any group moderators?
		$request = $smcFunc['db_query']('', '
			SELECT id_member
			FROM {db_prefix}group_moderators
			WHERE id_group = {int:selected_group}',
			array(
				'selected_group' => $this->_details['id_group'],
			)
		);
		$moderators = [];
		while ($row = $smcFunc['db_fetch_assoc']($request))
			$moderators[] = $row['id_member'];
		$smcFunc['db_free_result']($request);

		require_once($sourcedir . '/Subs-Members.php');

		// Make sure anyone who can moderate_membergroups gets notified as well
		$moderators = array_unique(array_merge($moderators, membersAllowedTo('manage_membergroups')));

		if (!empty($moderators))
		{
			// Figure out who wants to be alerted/emailed about this
			$data = array('alert' => [], 'email' => []);

			require_once($sourcedir . '/Subs-Notify.php');
			$prefs = getNotifyPrefs($moderators, 'request_group', true);

			// Bitwise comparisons are fun...
			foreach ($moderators as $mod)
			{
				if (!empty($prefs[$mod]['request_group']))
				{
					if ($prefs[$mod]['request_group'] & 0x01)
						$data['alert'][] = $mod;

					if ($prefs[$mod]['request_group'] & 0x02)
						$data['email'][] = $mod;
				}
			}

			if (!empty($data['alert']))
			{
				$alert_rows = [];

				foreach ($data['alert'] as $group_mod)
				{
					$alert_rows[] = array(
						'alert_time' => $this->_details['time'],
						'id_member' => $group_mod,
						'id_member_started' => $this->_details['id_member'],
						'member_name' => $this->_details['member_name'],
						'content_type' => 'member',
						'content_id' => 0,
						'content_action' => 'group_request',
						'is_read' => 0,
						'extra' => json_encode(array('group_name' => $this->_details['group_name'])),
					);
				}

				$smcFunc['db_insert']('insert', '{db_prefix}user_alerts',
					array('alert_time' => 'int', 'id_member' => 'int', 'id_member_started' => 'int', 'member_name' => 'string',
					'content_type' => 'string', 'content_id' => 'int', 'content_action' => 'string', 'is_read' => 'int', 'extra' => 'string'),
					$alert_rows, []
				);

				updateMemberData($data['alert'], array('alerts' => '+'));
			}

			if (!empty($data['email']))
			{
				require_once($sourcedir . '/ScheduledTasks.php');
				require_once($sourcedir . '/Subs-Post.php');
				loadEssentialThemeData();

				$request = $smcFunc['db_query']('', '
					SELECT id_member, email_address, lngfile, member_name, mod_prefs
					FROM {db_prefix}members
					WHERE id_member IN ({array_int:moderator_list})
					ORDER BY lngfile',
					array(
						'moderator_list' => $moderators,
					)
				);

				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$replacements = array(
						'RECPNAME' => $row['member_name'],
						'APPYNAME' => $this->_details['member_name'],
						'GROUPNAME' => $this->_details['group_name'],
						'REASON' => $this->_details['reason'],
						'MODLINK' => $scripturl . '?action=moderate;area=groups;sa=requests',
					);

					$emaildata = loadEmailTemplate('request_membership', $replacements, empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']);
					StoryBB\Helper\Mail::send($row['email_address'], $emaildata['subject'], $emaildata['body'], null, 'groupreq' . $this->_details['id_group'], $emaildata['is_html'], 2);
				}
			}
		}

		return true;
	}
}
