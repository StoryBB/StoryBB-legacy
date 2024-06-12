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
use StoryBB\Hook\Mutatable;
use StoryBB\Phrase;
use StoryBB\Routing\Exception\InvalidRouteException;
use StoryBB\Search\AdapterFactory as SearchAdapterFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

class Installer
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

		$container->inject('filesystem', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\Filesystem');
		});
	}
}
