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
use StoryBB\Schema\Exporter\PlantUML;
use StoryBB\Schema\Schema as StoryBBSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportPlantUML extends Command implements StoryBBCommand
{
	
	public function configure()
	{
		$this->setName('schema:exportuml')
			->setDescription('Exports StoryBB schema as UML for documentation.')
			->setHelp('This command exports the StoryBB schema to a specified destination path in PlantUML format.')
			->addArgument('path', InputArgument::REQUIRED, 'The destination path to write to');
	}
	public function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = $input->getArgument('path');
		if (substr($path, 0, 2) == './')
		{
			$path = getcwd() . substr($path, 1);
		}

		$exporter = new PlantUML(new StoryBBSchema);
		$uml = $exporter->output();
		if (@file_put_contents($path, $uml))
		{
			$output->writeln('Successfully written PlantUML to ' . $path);
			return 0;
		}

		$output->writeln('Could not write to ' . $path);
		return 1;
	}
}
