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
use EasyRdf\Graph;

require 'vendor/autoload.php';

// Special case: resuming from dump file:
try {
    if ($argc > 0 && $argv[1] == '--resume') {
        if (!isset($argv[2]) || !file_exists($argv[2])) {
            die("Cannot open resume file.\n");
        }
        $crawler = Crawler::fromState($argv[2]);
    } else {
        // Standard case: starting new crawl
        $urlParts = isset($argv[1]) ? parse_url($argv[1]) : [];
        $outFile = isset($argv[2]) ? $argv[2] : false;
        if (!isset($urlParts['host']) || empty($urlParts['host'])) {
            die("First parameter must be valid starting URL.\n");
        }
        if (!$outFile) {
            die("Second parameter must be writable file for output.\n");
        }
        $crawler = new Crawler($urlParts['host'], $outFile);
        $crawler->enqueue($argv[1]);
    }
} catch (\Exception $e) {
    die($e->getMessage() . "\n");
}

try {
    $crawler->run();
} catch (\Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    echo "Dumping state...\n";
    $dumpFile = $crawler->getOutputFilename() . '.state';
    $crawler->saveState($dumpFile);
    echo "You can attempt to resume later with {$argv[0]} --resume $dumpFile\n";
}

/**
 * Main crawler class to harvest RDF.
 */
class Crawler
{
    /**
     * File handle for output.
     *
     * @var resource
     */
    protected $handle;

    /**
     * Host to harvest.
     *
     * @var string
     */
    protected $host;

    /**
     * Name of output file.
     *
     * @var string
     */
    protected $outFile;

    /**
     * Queue of pending URLs to fetch.
     *
     * @var string[]
     */
    protected $queue;

    /**
     * List of previously-visited URLs.
     *
     * @var string[]
     */
    protected $visited;

    /**
     * Constructor
     *
     * Note: The last three parameters are only intended to be used when resuming
     * from a saved state created during a failed harvest.
     *
     * @param string $host    Hostname within which to restrict fetching
     * @param string $outFile Output file for triples
     * @param array  $queue   Queue (default = empty)
     * @param array  $visited Visited URLs (default = empty)
     * @param string $mode    File write mode (default = 'w')
     */
    public function __construct($host, $outFile, $queue = [], $visited = [], $mode = 'w')
    {
        $this->host = $host;
        $this->handle = fopen($outFile, $mode);
        $this->outFile = $outFile;
        if (!$this->handle) {
            throw new \Exception('Cannot open ' . $outFile . ' for writing.');
        }
        $this->queue = $queue;
        $this->visited = $visited;
    }

    /**
     * Add a URL to the queue.
     *
     * @param string $url URL
     *
     * @return void
     */
    public function enqueue($uri)
    {
        // strip off fragment identifiers:
        $uri = current(explode('#', $uri));

        // make sure the hostname matches:
        $urlParts = parse_url($uri);

        if (!in_array($uri, $this->visited) && !in_array($uri, $this->queue) && $urlParts['host'] == $this->host) {
            echo "Queueing $uri\n";
            $this->queue[] = $uri;
        }
    }

    /**
     * Run the harvest.
     *
     * @return void
     */
    public function run()
    {
        while (!empty($this->queue)) {
            $this->processUri(array_shift($this->queue));
        }
    }

    /**
	 * Get the output filename.
	 *
	 * @return string
	 */
	public function getOutputFilename()
	{
	    return $this->outFile;
	}

    /**
     * Save the current state of the crawler to disk.
     *
     * @param string $filename Output file name.
     *
     * @return void
     */
    public function saveState($filename)
    {
        $state = [
            'host' => $this->host,
            'outFile' => $this->outFile,
            'queue' => $this->queue,
            'visited' => $this->visited,
        ];
        file_put_contents($filename, serialize($state));
    }

    /**
     * Create a new Crawler from a saved state file.
     *
     * @param string $filename State to load
     *
     * @return Crawler
     */
    public static function fromState($filename)
    {
        $state = unserialize(file_get_contents($filename));
        return new Crawler(
            $state['host'], $state['outFile'],
            $state['queue'], $state['visited'], 'a'
        );
    }

    /**
     * Internal support method: process the contents of a URI.
     *
     * @param string $uri URI to process.
     *
     * @return void
     */
    protected function processUri($uri)
    {
        echo "Accessing $uri...\n";
        $retries = 3;
        while (true) {
            try {
                $graph = Graph::newAndLoad($uri);
                break;
            } catch (\Exception $e) {
                $retries--;
                if ($retries < 0) {
                    throw $e;
                }
                echo "Error! Sleeping for 10 secs, then retrying ($retries tries left)...\n";
                sleep(10);
            }
        }
        $triples = $graph->serialise('ntriples');
        fwrite($this->handle, $triples);
        $this->visited[] = $uri;
        preg_match_all('/<(https?:\/\/[^>]*)>/', $triples, $matches);
        foreach (array_unique($matches[1]) as $uri) {
            $this->enqueue($uri);
        }
    }
}
