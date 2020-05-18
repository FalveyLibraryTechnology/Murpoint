<?php
/**
 * Murpoint - A simple RDF harvesting tool.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/FalveyLibraryTechnology/Murpoint
 */
namespace Murpoint;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony command to run Murpoint.
 */
class Command extends \Symfony\Component\Console\Command\Command
{
    /**
     * The name of the command
     *
     * @var string
     */
	protected static $defaultName = 'murpoint';

    /**
     * Configure the command.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function configure()
    {
        $this
            ->setDescription('Murpoint crawler')
            ->setHelp('Harvests RDF over HTTP.')
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
				'The starting URL to harvest from'
            )->addArgument(
                'output',
                InputArgument::OPTIONAL,
                'The file used for storing harvested RDF'
            )->addOption(
                'resume',
                null,
                InputOption::VALUE_REQUIRED,
                'Resume an interrupted harvest using a dump file'
			)->addOption(
				'log',
				null,
				InputOption::VALUE_REQUIRED,
				'Filename for logging (omit to use console)'
			)->addOption(
				'timeout',
				null,
				InputOption::VALUE_REQUIRED,
				'HTTP timeout (in seconds)'
			);
		}

	/**
	 * Get the crawler object based on input parameters.
	 *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
	 *
	 * @return Crawler
	 * @throws \Exception
	 */
	protected function getCrawlerFromInput(InputInterface $input, OutputInterface $output): Crawler
	{
		// Special case: resume existing crawl
		$resume = $input->getOption('resume');
		if ($resume !== null) {
			if (empty($resume) || !file_exists($resume)) {
				throw new \Exception("Cannot open resume file.");
			}
			$crawler = Crawler::fromState($resume);
			$crawler->setLogCallback(
				$this->getLogCallback($input->getOption('log'), $output)
			);
			return $crawler;
		}
		// Standard case: starting new crawl
		$url = $input->getArgument('url');
		$urlParts = empty($url) ? [] : parse_url($url);
		$outFile = $input->getArgument('output');
		if (!isset($urlParts['host']) || empty($urlParts['host'])) {
			throw new \Exception(
				"First parameter must be valid starting URL."
			);
		}
		if (!$outFile) {
			throw new \Exception(
				"Second parameter must be writable file for output."
			);
		}
		$crawler = new Crawler($urlParts['host'], $outFile);
		$crawler->setLogCallback(
			$this->getLogCallback($input->getOption('log'), $output)
		);
		$crawler->enqueue($url);
		return $crawler;
	}

	/**
	 * Get the callback for logging messages from Murpoint.
	 *
	 * @param string          $filename Log filename (or false/null to use console)
     * @param OutputInterface $output   Output object
	 *
	 * @return \Callable
	 */
	protected function getLogCallback($filename, $output)
	{
		// No filename? Use console.
		if (!$filename) {
			return function ($msg) use ($output) {
				$output->writeln($msg);
			};
		}
		// Filename set? Use file logging.
		$handle = fopen($filename, 'a');
		register_shutdown_function(
			function () use ($handle) {
				fclose($handle);
			}
		);
		return function ($msg) use ($handle) {
			fputs($handle, $msg . "\n");
		};
	}

    /**
     * Run the command.
     *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output)
	{
		// Set up HTTP timeout, as needed:
		if ($timeout = $input->getOption('timeout')) {
			\EasyRdf\Http::getDefaultHttpClient()->setConfig(compact('timeout'));
		}
		// Special case: resuming from dump file:
		try {
			$crawler = $this->getCrawlerFromInput($input, $output);
			$progressBar = new ProgressBar($output, 0);
			$progressCallback = function () use ($progressBar) {
				$progressBar->advance();
			};
			$maxStepsCallback = function () use ($progressBar) {
				static $steps = 0;
				$steps++;
				$progressBar->setMaxSteps($steps);
			};
			$crawler->setProgressBarCallbacks($progressCallback, $maxStepsCallback);
			$progressBar->start();
		} catch (\Exception $e) {
			$output->writeln($e->getMessage());
			return 1;
		}

		try {
			$crawler->run();
		} catch (\Exception $e) {
			$output->writeln("Fatal error: " . $e->getMessage());
			$output->writeln("Dumping state...");
			$dumpFile = $crawler->getOutputFilename() . '.state';
			$crawler->saveState($dumpFile);
			$output->writeln(
				"You can attempt to resume later with: php Murpoint.php --resume "
				. $dumpFile
			);
			return 1;
		}
		$progressBar->finish();
		return 0;
	}
}
