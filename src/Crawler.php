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

use EasyRdf\Graph;

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
     * Callback for logging.
     *
     * @var \Callable
     */
    protected $logCallback = null;

    /**
     * Callbacks for progress bar.
     *
     * @var array
     */
    protected $barCallbacks = [];

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
     * Set a log callback.
     *
     * @param \Callable $callback Logging callback
     *
     * @return void
     */
    public function setLogCallback($callback)
    {
        $this->logCallback = $callback;
    }

    /**
     * Set callbacks for the progress bar.
     *
     * @param \Callable $progressCallback Advance progress by one step
     * @param \Callable $maxStepsCallback Advance maximum size by one step
     *
     * @return void
     */
    public function setProgressBarCallbacks($progressCallback, $maxStepsCallback)
    {
        $this->barCallbacks['progress'] = $progressCallback;
        $this->barCallbacks['maxSteps'] = $maxStepsCallback;
    }

    /**
     * Trigger a progress bar action
     *
     * @param string $type Type of action to trigger
     *
     * @return void
     */
    protected function updateProgressBar($type)
    {
        if (isset($this->barCallbacks[$type])) {
            call_user_func($this->barCallbacks[$type]);
        }
    }

    /**
     * Log a message.
     *
     * @param string $msg Message to log
     *
     * @return void
     */
    protected function log($msg)
    {
        if ($this->logCallback) {
            call_user_func($this->logCallback, $msg);
        } else {
            echo $msg . "\n";
        }
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
            $this->log("Queueing $uri");
            $this->updateProgressBar('maxSteps');
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
        $this->log("Accessing $uri...");
        $retries = 3;
        while (true) {
            try {
                $graph = Graph::newAndLoad($uri);
                break;
            } catch (\Exception $e) {
                $retries--;
                if ($retries < 0) {
                    // Throw the bad URL back on the queue for later retry...
                    $this->enqueue($uri);
                    throw $e;
                }
                $this->log("Error! Sleeping for 10 secs, then retrying ($retries tries left)...");
                sleep(10);
            }
        }
        $triples = $graph->serialise('ntriples');
        fwrite($this->handle, $triples);
        $this->visited[] = $uri;
        $this->updateProgressBar('progress');
        preg_match_all('/<(https?:\/\/[^>]*)>/', $triples, $matches);
        foreach (array_unique($matches[1]) as $uri) {
            $this->enqueue($uri);
        }
    }
}
