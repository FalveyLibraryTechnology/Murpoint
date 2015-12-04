Murpoint
========

Introduction
------------
This tool was created to harvest all RDF from a specific domain. You can provide
it with a starting URI, and it will follow all links that match the hostname of
the starting URI, harvesting everything to a text file containing n-triples. No
URIs outside of the starting host will be accessed.

Installation
------------
This project uses Composer. Run "composer install" to set up dependencies.

Basic Usage
-----------
php Murpoint.php [starting URI] [output file]

Background
----------
This project was designed to feed the triple store used by dimenovels.org, and
is named for Captain Howard Murpoint, the villain of Charles Garvice's dime
novel, "The Spider and the Fly."
