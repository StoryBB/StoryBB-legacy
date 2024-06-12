<?php

/**
 * CLI command for displaying the route listing.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2019 StoryBB project
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Cli\Command\Route;

use StoryBB\App;
use StoryBB\Cli\Command as StoryBBCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AdminList extends Command implements StoryBBCommand
{
	public function configure()
	{
		$this->setName('route:adminlist')
			->setDescription('List the currently defined admin routes.')
			->setHelp('This lists all of the routes currently defined across the platform within the admin namespace.');
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
		$router = App::container()->get('router_admin');

		$routes = [];

		foreach ($router->all() as $route_name => $route)
		{
			$methods = $route->getMethods() ?: ['GET'];
			$defaults = $route->getDefaults();

			$controller = '<undefined>';
			if (isset($defaults['_function']))
			{
				$controller = implode('::', $defaults['_function']);
			}
			elseif (isset($defaults['_controller']))
			{
				$controller = implode('@', $defaults['_controller']);
			}

			$routes[] = [
				$route_name,
				implode('|', $methods),
				'admin.php' . $route->getPath(),
				$controller,
			];
		}

		uasort($routes, function($a, $b) {
			return $a[2] <=> $b[2];
		});

		$table = new Table($output);
		$table
			->setHeaders(['Route name', 'Methods', 'Path', 'Controller'])
			->setRows($routes);

		$table->render();

		return 0;
	}
}
