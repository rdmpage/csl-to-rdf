<?php

// Given a list of DOIs use content negotiation to fetch CSL-JSON then convert to RDF

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

$options = [
	'allcitations', // include references that lack DOIs (i.e., we have blank nodes) 
	'nquads' 		// named graph with HTTPS DOI as URI, otherwise just output triples
	];
	
$options = []; // ignore citations without DOIs, output triples

$count = 1;

foreach ($dois as $doi)
{
	// DOI prefix
	$parts = explode('/', $doi);
	$prefix = $parts[0];
	
	$doi_filename = preg_replace('/[\/|:]/', '-', $doi);
	$doi_filename .= '.json';
	
	$cache_dir = dirname(__FILE__). '/doi/' . $prefix;
	
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
		$json = get($url, 'application/vnd.citationstyles.csl+json');

		file_put_contents($doi_filename, $json);
		
		// Give server a break 
    	$rand = rand(100000, 3000000);
    	echo "\n# ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
    	usleep($rand);				
	}
	
	$csl = json_decode($json);
	
	if ($csl)
	{
		if (isset($csl->message))
		{
			$csl = $csl->message;
		}
		
		if ($debug)
		{
			fwrite(STDERR, print_r($csl, true));
		}
		
		$quads = csl_to_quads($csl, $options);
		
		foreach ($quads as $q)
		{
			if (in_array('nquads', $options))
			{
				$graph_uri = 'https://doi.org/' . $doi;
				$q[] = $graph_uri;
			}
		
			$row = array();
			foreach ($q as $c)
			{
				$item = $c;
				
				if (preg_match('/^(https?|urn):/', $item))
				{
					$item = '<' . $item . '>';
				}
			
				$row[] = $item;
			}
		
			echo join(" ", $row) . " .\n";
		}		
		
	}
}

?>
