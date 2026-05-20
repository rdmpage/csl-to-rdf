<?php


// Given a list of DOIs get a list of DOI that cite each DOI


error_reporting(E_ALL);

if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}
require_once(dirname(__FILE__) . '/shared.php');

//----------------------------------------------------------------------------------------
function opencitations_get($url)
{
	$opts = array(
	  CURLOPT_URL =>$url,
	  CURLOPT_FOLLOWLOCATION => TRUE,
	  CURLOPT_RETURNTRANSFER => TRUE,
	  
	  CURLOPT_HTTPHEADER => array (
	  	"Authorization: " .  getenv('OPENCITATIONS_API_KEY')
	  )
	);
	
	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch); 
	curl_close($ch);

	return $data;
}

//----------------------------------------------------------------------------------------

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

$force = false;
$count = 1;

foreach ($dois as $doi)
{
	// We cache results in the opencitations folder

	// DOI prefix
	$parts = explode('/', $doi);
	$prefix = $parts[0];
	
	$citations_filename = preg_replace('/[\/|:]/', '-', $doi);
	$citations_filename .= '.json';
	
	$cache_dir = dirname(__FILE__). '/opencitations';
	
	if (!file_exists($cache_dir))
	{	
		$oldumask = umask(0); 
		mkdir($cache_dir, 0777);
		umask($oldumask);
	}	
	
	$cache_dir .= '/' . $prefix;
	
	if (!file_exists($cache_dir))
	{	
		$oldumask = umask(0); 
		mkdir($cache_dir, 0777);
		umask($oldumask);
	}
	
	$citations_filename  = $cache_dir . '/' . $citations_filename;
	
	if (file_exists($citations_filename) && !$force)
	{
		$json = file_get_contents($citations_filename);
	}
	else
	{	
		$url = 'https://opencitations.net/index/api/v2/citations/doi:' . $doi;
		$json = opencitations_get($url);
		
		file_put_contents($citations_filename, $json);
		
		// Give server a break 
		if ($count % 5 == 0)
		{
			$rand = rand(100000, 3000000);
			echo "\n# ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
			usleep($rand);				
		}
	}
	
	$obj = json_decode($json);
	if ($obj)
	{
		//print_r($obj);
		
		echo "\n# $doi\n";
			
		foreach ($obj as $item)
		{
			$cited_doi  = '';
			$citing_doi = '';
			
			$ids = explode(' ', $item->cited);
			
			foreach ($ids as $id)
			{
				if (preg_match('/^doi:(.*)/', $id, $m))
				{
					$cited_doi = $m[1];
				}
			}
			
			$ids = explode(' ', $item->citing);
			
			foreach ($ids as $id)
			{
				if (preg_match('/^doi:(.*)/', $id, $m))
				{
					$citing_doi = $m[1];				
				}
			}
			
			if ($citing_doi != '' && $cited_doi != '')
			{			
				echo "# " . $item->oci . "\n";
				echo '<https://doi.org/' . nice_uri($citing_doi) . '>';
				echo ' <http://schema.org/citation>';
				echo ' <https://doi.org/' . nice_uri($cited_doi) . '>';
				echo " .\n";
			}
		
		}
	
	}
	
	$count++;

}

?>
