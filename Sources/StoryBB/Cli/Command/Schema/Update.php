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

class Update extends Command implements StoryBBCommand
{
	use DatabaseDependency;

	public function configure()
	{
		$this->setName('schema:update')
			->setDescription('Update the schema to the current version.')
			->setHelp('This command updates the database.');
	}

	public function execute(InputInterface $input, OutputInterface $output): int
	{
		DatabaseSchema::update_schema($this->db(), false);

		return 0;
	}
}
