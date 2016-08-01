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

$urlParts = isset($argv[1]) ? parse_url($argv[1]) : [];
$outFile = isset($argv[2]) ? fopen($argv[2], 'w') : false;
if (!isset($urlParts['host']) || empty($urlParts['host'])) {
    die("First parameter must be valid starting URL.\n");
}
if (!$outFile) {
    die("Second parameter must be writable file for output.\n");
}

$crawler = new Crawler($urlParts['host'], $outFile);
$crawler->enqueue($argv[1]);
$crawler->run();

class Crawler
{
    protected $handle;
    protected $host;
    protected $queue = [];
    protected $visited = [];

    public function __construct($host, $handle)
    {
        $this->host = $host;
        $this->handle = $handle;
    }

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

    public function run()
    {
        while (!empty($this->queue)) {
            $this->processUri(array_shift($this->queue));
        }
    }

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
