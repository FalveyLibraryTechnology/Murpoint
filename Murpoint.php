<?php
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
        $graph = Graph::newAndLoad($uri);
        $triples = $graph->serialise('ntriples');
        fwrite($this->handle, $triples);
        $this->visited[] = $uri;
        preg_match_all('/<(https?:\/\/[^>]*)>/', $triples, $matches);
        foreach (array_unique($matches[1]) as $uri) {
            $this->enqueue($uri);
        }
    }
}
