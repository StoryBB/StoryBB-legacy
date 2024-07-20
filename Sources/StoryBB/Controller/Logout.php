<?php

/**
 * The logout handler.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Controller;

use StoryBB\App;
use StoryBB\Container;
use StoryBB\Dependency\RequestVars;
use StoryBB\Dependency\Session;
use StoryBB\Routing\Behaviours\MaintenanceAccessible;
use StoryBB\Routing\Behaviours\Routable;
use StoryBB\Routing\ErrorResponse;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class Logout implements Routable, MaintenanceAccessible
{
	use RequestVars;
	use Session;

	public static function register_own_routes(RouteCollection $routes): void
	{
		$routes->add('logout', (new Route('/logout', ['_controller' => [static::class, 'logout_action']])));
	}

	public function logout_action(): Response
	{
		$request = $this->requestvars();
		if ($token = $request->query->get('t', ''))
		{
			if ($token === $this->session()->get('session_value'))
			{

				// @todo Pass the logout information to integrations.
				// (new Observable\Account\LoggedOut($user_settings['member_name'], $user_info['id']))->execute();

				// @todo update the log_online table

				// Destroy the session, and return it to being a normal short-lived session cookie.
				$this->session()->invalidate();

				$response = new RedirectResponse(App::get_global_config_item('boardurl'));

				$container = Container::instance();
				$persist_cookie = App::get_global_config_item('cookiename') . '_persist';
				if ($request->cookies->has($persist_cookie))
				{
					$persistence = $container->instantiate('StoryBB\\Session\\Persistence');
					$persistence->invalidate_persist_token($request->cookies->get($persist_cookie));

					$response->headers->clearCookie($persist_cookie);
				};

				return $response;
			}
		}

		return new ErrorResponse('session_verify_fail');
	}
}
