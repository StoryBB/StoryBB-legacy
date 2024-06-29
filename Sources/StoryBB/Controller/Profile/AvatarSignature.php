<?php

/**
 * Displays the avatar and signature page.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller\Profile;

class AvatarSignature extends AbstractProfileController
{
	use ProfileSettingsTrait;

	protected function get_token_name()
	{
		return str_replace('%u', $this->params['u'], 'profile-fp%u');
	}

	public function display_action()
	{
		global $context, $txt, $sourcedir, $scripturl, $modSettings;

		$memID = $this->params['u'];

		createToken($this->get_token_name());
		$context['token_check'] = $this->get_token_name();

		foreach ($context['member']['characters'] as $id_character => $character)
		{
			if ($character['is_main'])
			{
				$context['character'] = $character;
			}
		}

		require_once($sourcedir . '/Profile-Modify.php');

		loadThemeOptions($memID);
		if (allowedTo(['profile_forum_own', 'profile_forum_any']))
			loadCustomFields($memID, 'forumprofile');

		$context['sub_template'] = 'profile_options';
		$context['page_desc'] = str_replace('{forum_name}', $context['forum_name_html_safe'], $txt['forumProfile_info']);
		$context['show_preview_button'] = true;

		loadJavascriptFile('chars.js', ['default_theme' => true], 'chars');
		loadJavaScriptFile('exif-2.3.0.js', ['minimize' => false, 'default_theme' => true], 'exif');
		loadJavaScriptFile('croppie-2.6.4.min.js', ['minimize' => false, 'default_theme' => true], 'croppie');
		loadCSSFile('croppie.css', [], 'croppie');

		setupProfileContext(
			[
				'avatar_choice', 'hr',
				'signature',
			]
		);

		$context['profile_submit_url'] = $scripturl . '?action=profile;area=avatar_signature;u=' . $memID;
		$context['profile_submit_url'] = !empty($modSettings['force_ssl']) && $modSettings['force_ssl'] < 2 ? strtr($context['profile_submit_url'], ['http://' => 'https://']) : $context['profile_submit_url'];
	}

	public function post_action()
	{
		global $context, $txt, $sourcedir, $modSettings, $post_errors, $profile_vars, $user_info, $cur_profile;

		$memID = $this->params['u'];

		validateToken($this->get_token_name());

		require_once($sourcedir . '/Profile-Modify.php');

		$post_errors = [];
		$profile_vars = [];

		// Clean up the POST variables.
		$this->sanitize_post();

		// Change the IP address in the database.
		if ($context['user']['is_owner'])
			$profile_vars['member_ip'] = $user_info['ip'];

		saveProfileFields('forumprofile');

		call_integration_hook('integrate_profile_save', [&$profile_vars, &$post_errors, $memID, $cur_profile, 'forumprofile']);

		// There was a problem, let them try to re-enter.
		if (!empty($post_errors))
		{
			// Load the language file so we can give a nice explanation of the errors.
			loadLanguage('Errors');
			$context['post_errors'] = $post_errors;
		}
		elseif (!empty($profile_vars))
		{
			// Changing the avatar is not necessarily obvious.
			$this->handle_changed_avatar();

			// Change the rest of the variables.
			updateMemberData($memID, $profile_vars);

			// Anything worth logging?
			$this->log_changes();

			// Have we got any post save functions to execute?
			if (!empty($context['profile_execute_on_save']))
				foreach ($context['profile_execute_on_save'] as $saveFunc)
					$saveFunc();

			// Let them know it worked!
			session_flash('success', $context['user']['is_owner'] ? $txt['profile_updated_own'] : sprintf($txt['profile_updated_else'], $cur_profile['member_name']));

			// Invalidate any cached data.
			cache_put_data('member_data-profile-' . $memID, null, 0);
		}

		// Have some errors for some reason?
		if (!empty($post_errors))
		{
			// Set all the errors so the template knows what went wrong.
			foreach ($post_errors as $error_type)
				$context['modify_error'][$error_type] = true;
		}
		// If it's you then we should redirect upon save.
		elseif (!empty($profile_vars) && $context['user']['is_owner'] && empty($context['do_preview']))
		{
			session_flash('success', $txt['profile_updated_own']);
			redirectexit('action=profile;area=avatar_signature');
		}

		return $this->display_action();
	}
}
