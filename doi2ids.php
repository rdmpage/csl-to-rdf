<?php

// Given a list of DOIs read JSON we have cached and extract identifiers we may want
// to examine.

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
$debug = false;

$orcids = array();
$funders = array();

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
	
	if (file_exists($doi_filename))
	{
		$json = file_get_contents($doi_filename);
		$csl = json_decode($json);
		
		if ($csl)
		{
			if (isset($csl->message))
			{
				$csl = $csl->message;
			}
			
			if ($debug)
			{
				print_r($csl);
			}
			
			// ORCIDs
			if (isset($csl->author))
			{
				foreach ($csl->author as $creator)
				{
					if (isset($creator->ORCID))
					{
						$orcid = $creator->ORCID;
						$orcid = preg_replace('/https?:\/\/orcid.org\//', '', $orcid);
						$orcids[] = $orcid;
					}
				}
			}
			
			// Funders
			if (isset($csl->funder))
			{
				foreach ($csl->funder as $funder)
				{
					if (isset($funder->DOI))
					{
						$doi = $funder->DOI;
						// yes this is weird, but I've seen this...
						$doi = str_replace('10.13039/http://doi.org/', '', $doi);
						$funders[] = $doi;
					}
				}
			}
		}
	}
}

$orcids = array_unique($orcids);
print_r($orcids);

$funders = array_unique($funders);
print_r($funders);


?>
