<?php

/**
 * This file does a lot of important stuff. It bootstraps the application.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\App;

use StoryBB\App;
use StoryBB\ClassManager;
use StoryBB\Container;
use StoryBB\Database\AdapterFactory;
use StoryBB\Helper\Cookie;
use StoryBB\Phrase;
use StoryBB\Routing\Exception\InvalidRouteException;
use StoryBB\Search\AdapterFactory as SearchAdapterFactory;
use Performing\TwigComponents\Configuration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Twig\Loader\FilesystemLoader;
use Twig\Environment as TwigEnvironment;
use Twig\TwigFunction;

class AdminWeb
{
	public function build_container()
	{
		global $smcFunc;

		$container = Container::instance();
		$container->inject('cachedir', App::get_root_path() . '/cache');

		$global_config = App::get_global_config();

		$container->inject('database', function() use ($container, $global_config) {
			// Add in the port if needed
			$db_options = [];
			if (!empty($global_config['db_port']))
			{
				$db_options['port'] = $global_config['db_port'];
			}

			$options = array_merge($db_options, ['persist' => $global_config['db_persist']]);

			$db = AdapterFactory::get_adapter($global_config['db_type']);
			$db->set_prefix($global_config['db_prefix']);
			$db->set_server($global_config['db_server'], $global_config['db_name'], $global_config['db_user'], $global_config['db_passwd']);
			$db->connect($options);

			return $db;
		});
		$smcFunc = [
			'db' => $container->get('database'),
		];

		$container->inject('response_headers', function() {
			return new ResponseHeaderBag([]);
		});

		$container->inject('sitesettings', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\SiteSettings');
		});

		$container->inject('filesystem', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\Filesystem');
		});
		$container->inject('search', function() use ($container) {
			$sitesettings = $container->get('sitesettings');
			$backend = $sitesettings->search_backend ?? 'NativeFulltext';

			return $container->instantiate(SearchAdapterFactory::get_adapter_class($backend));
		});
		$container->inject('blockmanager', function() use ($container) {
			$blockmanager = $container->instantiate('StoryBB\\Block\\Manager');
			$blockmanager->load_current_blocks();
			return $blockmanager;
		});
		$container->inject('autocomplete', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\Autocomplete');
		});
		$container->inject('current_theme_id', 1);
		$container->inject('current_theme', function() use ($container) {
			return $container->instantiate('StoryBB\\Model\\Theme', $container->get('current_theme_id'));
		});
		$container->inject('thememanager', function() use ($container) {
			return $container->instantiate('StoryBB\\Model\\ThemeManager');
		});
		$container->inject('formatter', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\Formatter');
		});
		$container->inject('session', function() use ($container) {
			$cookiename = App::get_global_config_item('cookiename') . '_admin';

			$site_settings = $container->get('sitesettings');
			$cookie_url = Cookie::url_parts(!empty($site_settings->localCookies), !empty($site_settings->globalCookies));
			$cookie_settings = [
				'cookie_httponly' => true,
				'cookie_domain' => $cookie_url[0],
				'cookie_path' => $cookie_url[1],
				'cookie_secure' => stripos(parse_url(App::get_global_config_item('boardurl'), PHP_URL_SCHEME), 'https') === 0,
				'cookie_samesite' => 'Lax',
				'name' => 'adminsess',
			];

			if (!empty($site_settings->databaseSession_loose))
			{
				session_cache_limiter('private_no_expires');
			}
			if ($site_settings->databaseSession_enable)
			{
				$session_storage = new NativeSessionStorage($cookie_settings, $container->instantiate('StoryBB\\Session\\DatabaseHandler'));
				$session = new Session($session_storage, new AttributeBag);
			}
			else
			{
				$session = new Session($cookie_settings, new AttributeBag);
			}

			$session->setName($cookiename);
			$session->start();

			$response_headers = $container->get('response_headers');

			if (!$session->has('userid'))
			{
				$session->set('userid', 0);
			}

			// Change it so the cache settings are a little looser than default.
			header('Cache-Control: private');

			if (!$session->has('session_var'))
			{
				$session->set('session_value', md5($session->getId() . mt_rand()));
				$session->set('session_var', substr(preg_replace('~^\d+~', '', sha1(mt_rand() . $session->getId() . mt_rand())), 0, mt_rand(7, 12)));
			}

			return $session;
		});
		$container->inject('currentuser', function() use ($container) {
			$session = $container->get('session');
			$user = $container->instantiate('StoryBB\User\CurrentUser');
			$user->load_user($session->get('userid') ?? 0);
			return $user;
		});
		$container->inject('smileys', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\Smiley');
		});
		$container->inject('router_public', function() use ($container) {
			$routes = new RouteCollection;
			foreach (ClassManager::get_classes_implementing('StoryBB\\Routing\\Behaviours\\Routable') as $controllable)
			{
				$controllable::register_own_routes($routes);
			}

			return $routes;
		});
		$container->inject('router_admin', function() use ($container) {
			$routes = new RouteCollection;
			foreach (ClassManager::get_classes_implementing('StoryBB\\Routing\\Behaviours\\Administrative') as $controllable)
			{
				$controllable::register_own_routes($routes);
			}

			return $routes;
		});
		$matcher = function($type = 'public') use ($container) {
			$compiled_routes = $container->get('cachedir') . '/compiled_' . $type . '_matcher.php';
			if (file_exists($compiled_routes))
			{
				try
				{
					$routes = include($compiled_routes);
					if ($array = unserialize($routes))
					{
						return $array;
					}
				}
				catch (\Throwable $e)
				{
					@unlink($compiled_routes);
				}
			}

			$routes = $container->get('router_' . $type);
			$compilation = (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes();

			file_put_contents($compiled_routes, '<?php return \'' . addcslashes(serialize($compilation), "\0" . '\\\'') . '\';');

			return $compilation;
		};
		$generator = function($type = 'public') use ($container) {
			$compiled_routes = $container->get('cachedir') . '/compiled_' . $type . '_generator.php';
			if (file_exists($compiled_routes))
			{
				try
				{
					$routes = include($compiled_routes);
					if ($array = unserialize($routes))
					{
						return $array;
					}
				}
				catch (\Throwable $e)
				{
					@unlink($compiled_routes);
				}
			}

			$routes = $container->get('router_' . $type);
			$compilation = (new CompiledUrlGeneratorDumper($routes))->getCompiledRoutes();

			file_put_contents($compiled_routes, '<?php return \'' . addcslashes(serialize($compilation), "\0" . '\\\'') . '\';');

			return $compilation;
		};

		$container->inject('compiled_matcher', function() use ($matcher) {
			return $matcher('admin');
		});
		$container->inject('compiled_generator', function() use ($generator) {
			return $generator('public');
		});
		$container->inject('admin_generator', function() use ($generator) {
			return $generator('admin');
		});

		$container->inject('urlgenerator', function() use ($container) {
			$boardurl = App::get_global_config_item('boardurl');
			$sitesettings = $container->get('sitesettings');
			$baseurl = $sitesettings->drop_index_php ? $boardurl : $boardurl . '/index.php';
			$requestcontext = (new RequestContext('/'))->fromRequest($container->get('requestvars'))->setBaseUrl($baseurl);
			return new CompiledUrlGenerator($container->get('compiled_generator'), $requestcontext);
		});
		$container->inject('urlmatcher', function() use ($container) {
			return new CompiledUrlMatcher($container->get('compiled_matcher'), $container->get('requestcontext'));
		});
		$container->inject('adminurlgenerator', function() use ($container) {
			$boardurl = App::get_global_config_item('boardurl');
			$requestcontext = (new RequestContext('/'))->fromRequest($container->get('requestvars'))->setBaseUrl($boardurl . '/admin.php');
			return new CompiledUrlGenerator($container->get('admin_generator'), $requestcontext);
		});
		$container->inject('adminurlmatcher', function() use ($container) {
			return new CompiledUrlMatcher($container->get('admin_matcher'), $container->get('requestcontext'));
		});
		$container->inject('templaterenderer', function() use ($container) {

			$loader = new FilesystemLoader();

			$default_theme_dir = App::get_root_path() . '/Themes/natural';

			$loader->addPath($default_theme_dir . '/templates');
			$loader->addPath($default_theme_dir . '/templates/layouts', 'layouts');
			$loader->addPath($default_theme_dir . '/templates/partials', 'partials');

			$sitesettings = $container->get('sitesettings');
			$options = [];
			if (!$sitesettings->enable_debug_templates)
			{
				$options['cache'] = $container->get('cachedir') . '/template/admin';
			}
			$twig = new TwigEnvironment($loader, $options);

			$twig->addFunction(new TwigFunction('phrase', function ($string, ...$params) {
				return new Phrase($string, $params);
			}));
			$twig->addFunction(new TwigFunction('link', function ($url, array $params = []) use ($container) {
				try
				{
					$urlgenerator = $container->get('urlgenerator');
					return $urlgenerator->generate($url, $params);
				}
				catch (\Exception $e)
				{
					return $e->getMessage();
				}
			}, ['is_variadic' => true]));
			$twig->addFunction(new TwigFunction('slugify', function ($string) {
				$string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
				$string = strtolower($string);
				$string = preg_replace('/[^a-z0-9]+/i', '-', $string);
				return trim($string, '-');
			}));
			$twig->addFunction(new TwigFunction('session', function ($sess) use ($container) {
				return $container->get('session')->get($sess);
			}));

			Configuration::make($twig)
				->setTemplatesPath('components')
				->setTemplatesExtension('twig')
				->useCustomTags()
				->setup();

			return $twig;
		});

		$container->inject('page', function() use ($container) {
			$page = $container->instantiate('StoryBB\\Page');
			$urlgenerator = $container->get('urlgenerator');
			$sitesettings = $container->get('sitesettings');
			$theme = $container->get('current_theme');
			$page->addMetaProperty('og:site_name', $sitesettings->forum_name);

			$page->addLink('help', $urlgenerator->generate('help'));

			$page->addSCSSfile(1, 'admin', $theme->get_compiled_time('admin'));

			$default_theme_url = App::get_global_config_item('boardurl') . '/Themes/natural';
			$page->addLink('stylesheet', $default_theme_url . '/css/fontawesome-free-5.15.1-web/css/all.min.css');

			return $page;
		});

		$container->inject('requestvars', Request::createFromGlobals());
		$container->inject('requestcontext', function() use ($container) {
			$boardurl = App::get_global_config_item('boardurl');
			$sitesettings = $container->get('sitesettings');
			$baseurl = $sitesettings->drop_index_php ? $boardurl : $boardurl . '/admin.php';
			return (new RequestContext('/'))->fromRequest($container->get('requestvars'))->setBaseUrl($baseurl);
		});

		// And the default builder for this container.
		$container->inject('url', $container->get('adminurlgenerator'));

		return $container;
	}
}
