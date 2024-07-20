<?php

/**
 * This file has the important job of taking care of help messages and the help center.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\App;
use StoryBB\Model\Policy;

/**
 * Identifies if the requested URL/action is allowed to be visited if the user
 * has to agree to the terms (e.g. the help pages so they can see the terms!)
 *
 * @return bool True if allowed
 */
function on_allowed_reagreement_actions(): bool
{
	global $context;

	$allowed_actions = [
		'accessibility' => true,
		'contact' => true,
		'help' => true,
		'profile' => [
			['area' => 'export_data'],
			['area' => 'delete_account'],
		],
		'reagreement' => true,
	];
	$allowed_routes = [
		'help',
		'help_policy',
	];
	call_integration_hook('integration_reagreement_actions', [&$allowed_actions, &$allowed_routes]);

	if (!empty($_REQUEST['action']) && isset($allowed_actions[$_REQUEST['action']]))
	{
		// So we've requested an action that might be allowed in reagreement situations... does it have any rules?
		if (!is_array($allowed_actions[$_REQUEST['action']]))
		{
			// Items that aren't arrays just blanket allow that action, e.g. the contact page.
			return true;
		}
		else
		{
			// If it was an array (like the profile page), it lists combinations that are allowed to occur.
			foreach ($allowed_actions[$_REQUEST['action']] as $allowed_route)
			{
				// So, step through one by one to see if this is a page we're allowed to get to.
				$matched_all_in_this_route = true;
				foreach ($allowed_route as $key => $value)
				{
					if (!isset($_GET[$key]) || $_GET[$key] != $value)
					{
						$matched_all_in_this_route = false;
					}
				}
				if ($matched_all_in_this_route)
				{
					return true;
				}
			}
		}
	}

	if (!empty($context['routing']['_route']) && in_array($context['routing']['_route'], $allowed_routes))
	{
		return true;
	}

	return false;
}

/**
 * Puts up the page requiring users to complete an agreement.
 */
function Reagreement()
{
	global $context, $txt, $user_info, $language, $scripturl;

	if (empty($_GET['action']) || $_GET['action'] != 'reagreement')
	{
		$_SESSION['reagreement_return'] = $_GET;
	}

	App::container()->get('blockmanager')->set_overall_block_visibility(false);

	loadLanguage('Login');

	$policies = Policy::get_unagreed_policies();
	if (isset($_POST['save']))
	{
		checkSession();
		validateToken('reagree');

		$agreed = [];
		foreach (array_keys($policies) as $policy_type)
		{
			if (isset($_POST['policy_' . $policy_type]))
			{
				$agreed[] = $policy_type;
				unset ($policies[$policy_type]);
			}
		}
		if (!empty($agreed))
		{
			Policy::agree_to_policy($agreed, !empty($user_info['language']) ? $user_info['language'] : $language, (int) $user_info['id']);
		}
	}

	if (empty($policies))
	{
		// Something went wrong? (Or, everything went right?) Fix up their status and redirect them onwards.
		updateMemberData($context['user']['id'], ['policy_acceptance' => Policy::POLICY_CURRENTLYACCEPTED]);
		if (!empty($_SESSION['reagreement_return']))
		{
			$url = [];
			foreach ($_SESSION['reagreement_return'] as $k => $v)
			{
				if ($v !== '')
				{
					$url[] = $k . '=' . $v;
				}
				else
				{
					$url[] = $k;
				}
			}
			unset ($_SESSION['reagreement_return']);
			redirectexit(implode(';', $url));
		}
		redirectexit();
	}

	$url = App::container()->get('urlgenerator');
	foreach ($policies as $policy_type => $policy)
	{
		$policy['title'] = '<a href="' . $url->generate('help_policy', ['policy' => $policy_type]) . '" target="_blank" rel="noopener">' . $policy['title'] . '</a>';
		$context['policies'][$policy_type] = $policy;
	}

	$txt['updated_agreement_desc'] = sprintf($txt['updated_agreement_desc'], $context['forum_name_html_safe']);
	$txt['updated_agreement_contact_admin'] = sprintf($txt['updated_agreement_contact_admin'], $url->generate('contact'));
	$context['page_title'] = $txt['updated_agreement'];
	$context['sub_template'] = 'reagreement';

	createToken('reagree');
}
