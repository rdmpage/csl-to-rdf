<?php

// Given a list of DOIs for funders JSON

require_once (dirname(__FILE__) . '/shared.php');

$filename = '';
if ($argc < 2)
{
	echo "Usage: " . basename(__FILE__) . " <filename>\n";
	exit(1);
}
else
{
	$filename = $argv[1];
}


$dois = array();

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$doi = trim(fgets($file_handle));
	
	$dois[] = $doi;
}	


$debug = true;
//$debug = false;

$force = false;
//$force = true;

$count = 1;

foreach ($dois as $doi)
{
	// DOI suffix
	$parts = explode('/', $doi);
	$suffix = $parts[1];
	
	$doi_filename = $suffix . '.json';
	
	$cache_dir = dirname(__FILE__). '/funders';
	
	if (!file_exists($cache_dir))
	{	
		$oldumask = umask(0); 
		mkdir($cache_dir, 0777);
		umask($oldumask);
	}
	
	$doi_filename  = $cache_dir . '/' . $doi_filename;
	
	if (file_exists($doi_filename) && !$force)
	{
		$json = file_get_contents($doi_filename);
	}
	else
	{	
		$url = 'https://doi.org/' . $doi;	
		$json = get($url);

		file_put_contents($doi_filename, $json);
		
		// Give server a break 
    	$rand = rand(100000, 3000000);
    	echo "\n# ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
    	usleep($rand);				
	}
	
	$obj = json_decode($json);
	
	if ($obj)
	{
		print_r($obj);
		
		// do something here...
	}
}

?>
