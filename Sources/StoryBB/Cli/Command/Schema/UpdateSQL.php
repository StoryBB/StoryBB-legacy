<?php

/**
 * CLI command for exporting the schema UML into a file for PlantUML.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2019 StoryBB project
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Cli\Command\Schema;

use StoryBB\Cli\Command as StoryBBCommand;
use StoryBB\Dependency\Database as DatabaseDependency;
use StoryBB\Schema\Exporter\PlantUML;
use StoryBB\Schema\Database as DatabaseSchema;
use StoryBB\Schema\Schema as StoryBBSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSQL extends Command implements StoryBBCommand
{
	use DatabaseDependency;

	public function configure()
	{
		$this->setName('schema:updatesql')
			->setDescription('Provides SQL to update the schema to the current version.')
			->setHelp('This command exports the necessary SQL to upgrade whatever is currently in the database, to the current database schema.');
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
		$db = $this->db();
		$results = DatabaseSchema::update_schema($db, true);

		foreach ($results as $resultid => $result)
		{
			if (empty($result))
			{
				unset($results[$resultid]);
				continue;
			}
			$result = rtrim($result);
			if (substr($result, -1) !== ';')
			{
				$results[$resultid] = $result . ";\n";
			}
		}

		if (empty($results))
		{
			$output->writeln('Schema is up to date.');
			return 0;
		}

		$output->writeln("The following queries would be run in non-safe mode:");
		$output->write($results);
		$output->writeln('');

		return 0;
	}
}
