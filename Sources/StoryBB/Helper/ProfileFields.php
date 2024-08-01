<?php

/**
 * Provides detection to identify the current user's browser.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Helper;

use StoryBB\Hook\Observable;
use StoryBB\StringLibrary;

class ProfileFields
{
	public static function define_fields()
	{
		global $context, $profile_fields, $txt, $scripturl, $modSettings, $user_info, $smcFunc, $cur_profile, $language;
		global $sourcedir, $profile_vars;

		require_once($sourcedir . '/Profile-Modify.php');

		/* This horrific array defines all the profile fields in the whole world!
			In general each "field" has one array - the key of which is the database column name associated with said field. Each item
			can have the following attributes:

					string $type:			The type of field this is - valid types are:
						- callback:		This is a field which has its own callback mechanism for templating.
						- check:		A simple checkbox.
						- hidden:		This doesn't have any visual aspects but may have some validity.
						- password:		A password box.
						- select:		A select box.
						- text:			A string of some description.

					string $label:			The label for this item - default will be $txt[$key] if this isn't set.
					string $subtext:		The subtext (Small label) for this item.
					int $size:			Optional size for a text area.
					array $input_attr:		An array of text strings to be added to the input box for this item.
					string $value:			The value of the item. If not set $cur_profile[$key] is assumed.
					string $permission:		Permission required for this item (Excluded _any/_own subfix which is applied automatically).
					function $input_validate:	A runtime function which validates the element before going to the database. It is passed
									the relevant $_POST element if it exists and should be treated like a reference.

									Return types:
						- true:			Element can be stored.
						- false:		Skip this element.
						- a text string:	An error occured - this is the error message.

					function $preload:		A function that is used to load data required for this element to be displayed. Must return
									true to be displayed at all.

					string $cast_type:		If set casts the element to a certain type. Valid types (bool, int, float).
					string $save_key:		If the index of this element isn't the database column name it can be overriden
									with this string.
					bool $is_dummy:			If set then nothing is acted upon for this element.
					bool $enabled:			A test to determine whether this is even available - if not is unset.
					string $link_with:		Key which links this field to an overall set.

			Note that all elements that have a custom input_validate must ensure they set the value of $cur_profile correct to enable
			the changes to be displayed correctly on submit of the form.

		*/

		$profile_fields = [
			'avatar_choice' => [
				'type' => 'callback',
				'callback_func' => 'avatar_select',
				// This handles the permissions too.
				'preload' => 'profileLoadAvatarData',
				'input_validate' => 'profileSaveAvatarData',
				'save_key' => 'avatar',
			],
			'birthday_date' => [
				'type' => 'callback',
				'callback_func' => 'birthday_date',
				'permission' => 'profile_extra',
				'log_change' => true,
				'save_key' => 'birthdate',
				'preload' => function() use ($cur_profile, &$context, $txt, $modSettings)
				{
					if (empty($cur_profile['birthdate']) || $cur_profile['birthdate'] == '1004-01-01')
					{
						// No existing birthdate, therefore editable.
						$context['member']['birthdate'] = '';
						$context['member']['birthdate_editable'] = true;
						$context['member']['birth_date'] = [
							'year' => '',
							'month' => '',
							'day' => '',
						];
					}
					elseif (!allowedTo('admin_forum'))
					{
						// Regular members aren't allowed to edit their DOB.
						list ($year, $month, $day) = explode('-', $cur_profile['birthdate']);
						$context['member']['formatted_birthdate'] = dateformat($year, $month, $day, $cur_profile['time_format']);

						$context['member']['birthdate_editable'] = false;
					}
					else
					{
						// The profile user has entered a date and the current user is an admin.
						list($year, $month, $day) = explode('-', $cur_profile['birthdate']);
						$context['member']['birth_date'] = [
							'year' => (int) $year,
							'month' => (int) $month,
							'day' => (int) $day,
						];
						$context['member']['birthdate_editable'] = true;
					}

					return true;
				},
				'input_validate' => function(&$value) use (&$cur_profile, &$profile_vars, $modSettings, $sourcedir)
				{
					require_once($sourcedir . '/Register.php');
					if (!empty($cur_profile['birthdate']) && $cur_profile['birthdate'] != '1004-01-01')
					{
						// If it's not already empty, and it's being edited, make sure we're an admin.
						if (!allowedTo('admin_forum'))
						{
							$value = $cur_profile['birthdate'];
							return true;
						}
					}

					// OK so either we're adding a date for the first time or we're updating an existing date.
					if (isset($_POST['bday1'], $_POST['bday2'], $_POST['bday3']))
					{
						// Make sure it's valid and if it is, handle it.
						$value = checkdate((int) $_POST['bday1'], (int) $_POST['bday2'], (int) ($_POST['bday3'] < 1004 ? 1004 : $_POST['bday3'])) ? sprintf('%04d-%02d-%02d', $_POST['bday3'] < 1004 ? 1004 : $_POST['bday3'], $_POST['bday1'], $_POST['bday2']) : '1004-01-01';

						// Also check if it's valid or not.
						if (!empty($modSettings['minimum_age']) && !empty($modSettings['minimum_age_profile']) && $value != '1004-01-01')
						{
							$datearray = getdate(forum_time());
							$age = $datearray['year'] - $_POST['bday3'] - (($datearray['mon'] > $_POST['bday1'] || ($datearray['mon'] == $_POST['bday1'] && $datearray['mday'] >= $_POST['bday2'])) ? 0 : 1);
							if ($age < (int) $modSettings['minimum_age'])
							{
								return false;
							}
						}
					}
					else
					{
						// Not sure what happened here. Put the value back to what it was.
						$value = $cur_profile['birthdate'];
					}

					return true;
				},
				'link_with' => 'birthday',
			],
			'birthday_visibility' => [
				'type' => 'select',
				'options' => [
					0 => $txt['birthday_visibility_adminonly'],
					1 => $txt['birthday_visibility_daymonth'],
					2 => $txt['birthday_visibility_fulldate'],
				],
				'permission' => 'profile_extra',
				'link_with' => 'birthday',
			],
			'date_registered' => [
				'type' => 'date',
				'value' => empty($cur_profile['date_registered']) ? $txt['not_applicable'] : dateformat_ymd($cur_profile['date_registered'] + ($user_info['time_offset'] + $modSettings['time_offset']) * 3600),
				'label' => $txt['date_registered'],
				'log_change' => true,
				'permission' => 'moderate_forum',
				'input_validate' => function(&$value) use ($txt, $user_info, $modSettings, $cur_profile, $context)
				{
					// Bad date!  Go try again - please?
					if (($value = strtotime($value)) === false)
					{
						$value = $cur_profile['date_registered'];
						$format = strpos($user_info['time_format'], '%H') !== false ? 'd M Y h\:i\Ls a' : 'd M Y H\:i\:s';
						return $txt['invalid_registration'] . ' ' . ((new \DateTime('@' . forum_time(false)))->format($format));
					}
					// As long as it doesn't equal "N/A"...
					elseif ($value != $txt['not_applicable'] && $value != strtotime(dateformat_ymd($cur_profile['date_registered'] + ($user_info['time_offset'] + $modSettings['time_offset']) * 3600)))
						$value = $value - ($user_info['time_offset'] + $modSettings['time_offset']) * 3600;
					else
						$value = $cur_profile['date_registered'];

					return true;
				},
			],
			'email_address' => [
				'type' => 'email',
				'label' => $txt['user_email_address'],
				'subtext' => $txt['valid_email'],
				'log_change' => true,
				'permission' => 'profile_password',
				'js_submit' => !empty($modSettings['send_validation_onChange']) ? '
		form_handle.addEventListener(\'submit\', function(event)
		{
			if (this.email_address.value != "'. $cur_profile['email_address'] . '")
			{
				alert('. JavaScriptEscape($txt['email_change_logout']) . ');
				return true;
			}
		}, false);' : '',
				'input_validate' => function(&$value)
				{
					global $context, $old_profile, $profile_vars, $sourcedir, $modSettings;

					if (strtolower($value) == strtolower($old_profile['email_address']))
						return false;

					$isValid = profileValidateEmail($value, $context['id_member']);

					// Do they need to revalidate? If so schedule the function!
					if ($isValid === true && !empty($modSettings['send_validation_onChange']) && !allowedTo('moderate_forum'))
					{
						require_once($sourcedir . '/Subs-Members.php');
						$profile_vars['validation_code'] = generateValidationCode();
						$profile_vars['is_activated'] = 2;
						$context['profile_execute_on_save'][] = 'profileSendActivation';
						unset($context['profile_execute_on_save']['reload_user']);
					}

					return $isValid;
				},
			],
			// Selecting group membership is a complicated one so we treat it separate!
			'id_group' => [
				'type' => 'callback',
				'callback_func' => 'group_manage',
				'permission' => 'manage_membergroups',
				'preload' => 'profileLoadGroups',
				'log_change' => true,
				'input_validate' => 'profileSaveGroups',
			],
			'id_theme' => [
				'type' => 'callback',
				'callback_func' => 'theme_pick',
				'permission' => 'profile_extra',
				'enabled' => $modSettings['theme_allow'] || allowedTo('admin_forum'),
				'preload' => function() use ($smcFunc, &$context, $cur_profile, $txt)
				{
					$request = $smcFunc['db']->query('', '
						SELECT value
						FROM {db_prefix}themes
						WHERE id_theme = {int:id_theme}
							AND variable = {string:variable}
						LIMIT 1', [
							'id_theme' => $cur_profile['id_theme'],
							'variable' => 'name',
						]
					);
					list ($name) = $smcFunc['db']->fetch_row($request);
					$smcFunc['db']->free_result($request);

					$context['member']['theme'] = [
						'id' => $cur_profile['id_theme'],
						'name' => empty($cur_profile['id_theme']) ? $txt['theme_forum_default'] : $name
					];
					return true;
				},
				'input_validate' => function(&$value)
				{
					$value = (int) $value;
					return true;
				},
			],
			'immersive_mode' => [
				'type' => 'check',
				'label' => $txt['immersive_mode'],
				'subtext' => $txt['immersive_mode_desc'],
				'permission' => 'profile_extra',
				'enabled' => in_array($modSettings['enable_immersive_mode'], ['user_on', 'user_off']),
			],
			'lngfile' => [
				'type' => 'select',
				'options' => function() use (&$context)
				{
					return $context['profile_languages'];
				},
				'label' => $txt['preferred_language'],
				'permission' => 'profile_identity',
				'preload' => 'profileLoadLanguages',
				'enabled' => !empty($modSettings['userLanguage']),
				'value' => empty($cur_profile['lngfile']) ? $language : $cur_profile['lngfile'],
				'input_validate' => function(&$value) use (&$context, $cur_profile)
				{
					// Load the languages.
					profileLoadLanguages();

					if (isset($context['profile_languages'][$value]))
					{
						if ($context['user']['is_owner'] && empty($context['password_auth_failed']))
							$_SESSION['language'] = $value;
						return true;
					}
					else
					{
						$value = $cur_profile['lngfile'];
						return false;
					}
				},
			],
			// The username is not always editable - so adjust it as such.
			'member_name' => [
				'type' => allowedTo('admin_forum') && isset($_GET['changeusername']) ? 'text' : 'label',
				'label' => $txt['username'],
				'subtext' => allowedTo('admin_forum') && !isset($_GET['changeusername']) ? '[<a href="' . $scripturl . '?action=profile;u=' . $context['id_member'] . ';area=account_settings;changeusername" style="font-style: italic;">' . $txt['username_change'] . '</a>]' : '',
				'log_change' => true,
				'permission' => 'profile_identity',
				'prehtml' => allowedTo('admin_forum') && isset($_GET['changeusername']) ? '<div class="alert">' . $txt['username_warning'] . '</div>' : '',
				'input_validate' => function(&$value) use ($sourcedir, $context, $user_info, $cur_profile)
				{
					if (allowedTo('admin_forum'))
					{
						// We'll need this...
						require_once($sourcedir . '/Subs-Auth.php');

						// Maybe they are trying to change their password as well?
						$resetPassword = true;
						if (isset($_POST['passwrd1']) && $_POST['passwrd1'] != '' && isset($_POST['passwrd2']) && $_POST['passwrd1'] == $_POST['passwrd2'] && validatePassword($_POST['passwrd1'], $value, [$cur_profile['real_name'], $user_info['username'], $user_info['name'], $user_info['email']]) == null)
							$resetPassword = false;

						// Do the reset... this will send them an email too.
						if ($resetPassword)
							resetPassword($context['id_member'], $value);
						elseif ($value !== null)
						{
							validateUsername($context['id_member'], trim(preg_replace('~[\t\n\r \x0B\0\x{A0}\x{AD}\x{2000}-\x{200F}\x{201F}\x{202F}\x{3000}\x{FEFF}]+~u', ' ', $value)));
							updateMemberData($context['id_member'], ['member_name' => $value]);

							// Call this here so any integrated systems will know about the name change (resetPassword() takes care of this if we're letting StoryBB generate the password)
							(new Observable\Account\PasswordReset($cur_profile['member_name'], $value, $_POST['passwrd1']))->execute();
						}
					}
					return false;
				},
			],
			'passwrd1' => [
				'type' => 'password',
				'label' => ucwords($txt['choose_pass']),
				'subtext' => $txt['password_strength'],
				'size' => 20,
				'value' => '',
				'permission' => 'profile_password',
				'save_key' => 'passwd',
				// Note this will only work if passwrd2 also exists!
				'input_validate' => function(&$value) use ($sourcedir, $user_info, $smcFunc, $cur_profile, $modSettings)
				{
					global $txt;

					// If we didn't try it then ignore it!
					if ($value == '')
						return false;

					// Do the two entries for the password even match?
					if (!isset($_POST['passwrd2']) || $value != $_POST['passwrd2'])
						return 'bad_new_password';

					// Let's get the validation function into play...
					require_once($sourcedir . '/Subs-Auth.php');
					$passwordErrors = validatePassword($value, $cur_profile['member_name'], [$cur_profile['real_name'], $user_info['username'], $user_info['name'], $user_info['email']]);

					// Were there errors?
					if ($passwordErrors != null)
					{
						loadLanguage('Errors');
						$txt['profile_error_password_short'] = numeric_context('profile_error_password_short_contextual', (empty($modSettings['password_strength']) ? 4 : 8));

						return 'password_' . $passwordErrors;
					}

					// Set up the new password variable... ready for storage.
					$value = hash_password($cur_profile['member_name'], un_htmlspecialchars($value));

					return true;
				},
			],
			'passwrd2' => [
				'type' => 'password',
				'label' => ucwords($txt['verify_pass']),
				'size' => 20,
				'value' => '',
				'permission' => 'profile_password',
				'is_dummy' => true,
			],
			// This does ALL the pm settings
			'pm_prefs' => [
				'type' => 'callback',
				'callback_func' => 'pm_settings',
				'permission' => 'pm_read',
				'preload' => function() use (&$context, $cur_profile)
				{
					$context['receive_from'] = !empty($cur_profile['pm_receive_from']) ? $cur_profile['pm_receive_from'] : 0;

					return true;
				},
				'input_validate' => function(&$value) use (&$cur_profile, &$profile_vars)
				{
					// Simple validate and apply the two "sub settings"
					$value = max(min($value, 2), 0);

					$cur_profile['pm_receive_from'] = $profile_vars['pm_receive_from'] = max(min((int) $_POST['pm_receive_from'], 4), 0);

					return true;
				},
			],
			'posts' => [
				'type' => 'int',
				'label' => $txt['profile_posts'],
				'log_change' => true,
				'size' => 7,
				'permission' => 'moderate_forum',
				'input_validate' => function(&$value)
				{
					if (!is_numeric($value))
						return 'digits_only';
					else
						$value = $value != '' ? strtr($value, [',' => '', '.' => '', ' ' => '']) : 0;
					return true;
				},
			],
			'real_name' => [
				'type' => allowedTo('profile_displayed_name_own') || allowedTo('profile_displayed_name_any') || allowedTo('moderate_forum') ? 'text' : 'label',
				'label' => $txt['name'],
				'subtext' => $txt['display_name_desc'],
				'log_change' => true,
				'input_attr' => ['maxlength="60"'],
				'permission' => 'profile_displayed_name',
				'enabled' => allowedTo('profile_displayed_name_own') || allowedTo('profile_displayed_name_any') || allowedTo('moderate_forum'),
				'input_validate' => function(&$value) use ($context, $smcFunc, $sourcedir, $cur_profile)
				{
					$value = trim(preg_replace('~[\t\n\r \x0B\0\x{A0}\x{AD}\x{2000}-\x{200F}\x{201F}\x{202F}\x{3000}\x{FEFF}]+~u', ' ', $value));

					if (trim($value) == '')
						return 'no_name';
					elseif (StringLibrary::strlen($value) > 60)
						return 'name_too_long';
					elseif ($cur_profile['real_name'] != $value)
					{
						require_once($sourcedir . '/Subs-Members.php');
						if (isReservedName($value, $context['id_member']))
							return 'name_taken';
					}
					return true;
				},
			],
			'secret_question' => [
				'type' => 'text',
				'label' => $txt['secret_question'],
				'subtext' => $txt['secret_desc'],
				'size' => 50,
				'permission' => 'profile_password',
			],
			'secret_answer' => [
				'type' => 'text',
				'label' => $txt['secret_answer'],
				'subtext' => $txt['secret_desc2'],
				'size' => 20,
				'postinput' => '<span class="smalltext"><a href="' . $scripturl . '?action=helpadmin;help=secret_why_blank" onclick="return reqOverlayDiv(this.href);" class="main_icons help">' . $txt['secret_why_blank'] . '</a></span>',
				'value' => '',
				'permission' => 'profile_password',
				'input_validate' => function(&$value)
				{
					$value = $value != '' ? md5($value) : '';
					return true;
				},
			],
			'signature' => [
				'type' => 'callback',
				'callback_func' => 'signature_modify',
				'permission' => 'profile_signature',
				'enabled' => substr($modSettings['signature_settings'], 0, 1) == 1,
				'preload' => 'profileLoadSignatureData',
				'input_validate' => 'profileValidateSignature',
			],
			'show_online' => [
				'type' => 'check',
				'label' => $txt['show_online'],
				'permission' => 'profile_identity',
				'enabled' => !empty($modSettings['allow_hideOnline']) || allowedTo('moderate_forum'),
			],
			// Pretty much a dummy entry - it populates all the theme settings.
			'theme_settings' => [
				'type' => 'callback',
				'callback_func' => 'theme_settings',
				'permission' => 'profile_extra',
				'is_dummy' => true,
				'preload' => function() use (&$context, $user_info, $modSettings)
				{
					loadLanguage('Settings');

					$context['allow_no_censored'] = false;
					if ($user_info['is_admin'] || $context['user']['is_owner'])
						$context['allow_no_censored'] = !empty($modSettings['allow_no_censored']);

					return true;
				},
			],
			'time_format' => [
				'type' => 'select',
				'options' => array_merge(['' => $txt['timeformat_default']], \StoryBB\Helper\Datetime::list_dateformats()),
				// 'callback_func' => 'timeformat_modify',
				'permission' => 'profile_extra',
			],
			'timezone' => [
				'type' => 'select',
				'options' => \StoryBB\Helper\Datetime::list_timezones(),
				'permission' => 'profile_extra',
				'label' => $txt['timezone'],
				'input_validate' => function($value)
				{
					$tz = \StoryBB\Helper\Datetime::list_timezones();
					if (!isset($tz[$value]))
						return 'bad_timezone';

					return true;
				},
			],
		];

		call_integration_hook('integrate_load_profile_fields', [&$profile_fields]);

		$disabled_fields = !empty($modSettings['disabled_profile_fields']) ? explode(',', $modSettings['disabled_profile_fields']) : [];
		// For each of the above let's take out the bits which don't apply - to save memory and security!
		foreach ($profile_fields as $key => $field)
		{
			// Do we have permission to do this?
			if (isset($field['permission']) && !allowedTo(($context['user']['is_owner'] ? [$field['permission'] . '_own', $field['permission'] . '_any'] : $field['permission'] . '_any')) && !allowedTo($field['permission']))
				unset($profile_fields[$key]);

			// Is it enabled?
			if (isset($field['enabled']) && !$field['enabled'])
				unset($profile_fields[$key]);

			// Is it specifically disabled?
			if (in_array($key, $disabled_fields) || (isset($field['link_with']) && in_array($field['link_with'], $disabled_fields)))
				unset($profile_fields[$key]);
		}

		return $profile_fields;
	}
}
