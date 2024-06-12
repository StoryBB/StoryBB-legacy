<?php

/**
 * CLI command for clearing the route list cache.
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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Clear extends Command implements StoryBBCommand
{
	public function configure()
	{
		$this->setName('route:clear')
			->setDescription('Clear the current routing cache.')
			->setHelp('The routing table (list of URLs and the associated classes) sometimes may become out of date. This allows clearing the list.');
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
		$cachedir = App::container()->get('cachedir');

		@unlink($cachedir . '/compiled_admin_generator.php');
		@unlink($cachedir . '/compiled_admin_matcher.php');
		@unlink($cachedir . '/compiled_public_generator.php');
		@unlink($cachedir . '/compiled_public_matcher.php');

		$output->writeln('Route cache cleared.');

		return 0;
	}
}
