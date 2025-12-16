<?php

require_once (dirname(__FILE__) . '/shared.php');

//----------------------------------------------------------------------------------------

$urls=array(
'https://dl.ndl.go.jp/pid/10366723',
//'http://koreascience.or.kr/article/JAKO198611920826142.page',
//'http://med.wanfangdata.com.cn/Paper/Detail/PeriodicalPaper_zxxb199802011',
'http://publication.nhmus.hu/folent/cikkreszletes.php?idhoz=6134',
'https://dl.ndl.go.jp/pid/10366562',
);

$force = false;

$count = 1;

foreach ($urls as $url)
{
	// domain
	
	$domain = parse_url($url, PHP_URL_HOST);
	
	$cache_dir = dirname(__FILE__). '/url/' . $domain;
	
	if (!file_exists($cache_dir))
	{	
		$oldumask = umask(0); 
		mkdir($cache_dir, 0777);
		umask($oldumask);
	}
	
	$path = parse_url($url, PHP_URL_PATH);
	
	$filename  = $cache_dir . '/' . rawurlencode($path) . '.json';
	
	if (file_exists($filename) && !$force)
	{
		$json = file_get_contents($filename);
	}
	else
	{	
		$get_url = 'http://localhost/microcitation-lite/api/api.php?id=' . urlencode($url);
		
		echo $get_url . "\n";
		$json = get($get_url);
		file_put_contents($filename, $json);
	}
	
	$csl = json_decode($json);
	
	if ($csl)
	{
	
		if (is_array($csl))
		{
			$csl = $csl[0];
		}
		
		if (0)
		{
			print_r($csl);
		}
		
		echo "# $url\n";
		
		$quads = csl_to_quads($csl);
				
		foreach ($quads as $q)
		{
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
		
		echo "\n";
		
	}


}

?>
