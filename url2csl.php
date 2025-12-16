<?php

require_once (dirname(__FILE__) . '/shared.php');

require_once (dirname(__FILE__) . '/HtmlDomParser.php');
use Sunra\PhpSimple\HtmlDomParser;


//----------------------------------------------------------------------------------------

$urls=array(
'https://elib.sfu-kras.ru/handle/2311/110837',
'https://journal.lib.uoguelph.ca/index.php/eso/article/view/6846',
'https://www.biotaxa.org/AEMNP/article/view/26282',
'https://journals.flvc.org/troplep/article/view/104884',
'http://et-online.nu/index.php/contents/article/view/45',
);

$urls = array(
'https://hdl.handle.net/10400.5/7829',
'http://et-online.nu/index.php/contents/article/view/45',
'https://journal.lib.uoguelph.ca/index.php/eso/article/view/6846', // actually has a DOI
'https://www.biotaxa.org/AEMNP/article/view/26282',
);

$urls = array(
'https://ttfnc.org/livingworld/index.php/lwj/article/view/rogy_et_al_2024',
//'https://hdl.handle.net/10037/28098', // blocked
);

$urls = array(
'http://hdl.handle.net/1854/LU-01J28WHJV9QA7T48ZEV1494R9V'
);


$force = false;

$debug = false;
$debug = true;

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
	
	$filename  = $cache_dir . '/' . rawurlencode($path) . '.html';
	
	if (file_exists($filename) && !$force)
	{
		$html = file_get_contents($filename);
	}
	else
	{
		$html = get($url);

		file_put_contents($filename, $html);
		
		// Give server a break 
    	$rand = rand(100000, 3000000);
    	echo "\n# ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
    	usleep($rand);		
			
	}
	
	$html = file_get_contents($filename);

	// meta tags, need to convert to linked data for a subset of tags that
	// will add value
	$dom = HtmlDomParser::str_get_html($html);
	
	if ($dom)
	{	
		$csl = new stdclass;
			
		// meta
		foreach ($dom->find('meta') as $meta)
		{
			if ($debug)
			{
				echo $meta->name . ' ' . $meta->content . "\n";
			}
			
			switch ($meta->name)
			{				
				case 'citation_doi':
					$csl->DOI = $meta->content;
					break;
					
				case 'citation_abstract_html_url':
				case 'eprints.official_url':
					$csl->URL = $meta->content;
					break;

				case 'citation_pdf_url':
					$link = new stdclass;
					$link->{'content-type'} = 'application/pdf';			
					$link->URL = $meta->content;
					$csl->link[] = $link;
					break;

				case 'citation_title':
					$csl->title = strip_tags(html_entity_decode($meta->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					break;

				case 'citation_journal_title':
					$csl->{'container-title'} = strip_tags(html_entity_decode($meta->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					break;

				case 'citation_issn':
					$csl->ISSN[] = format_issn($meta->content);
					break;

				case 'citation_volume':
					$csl->volume = $meta->content;
					break;

				case 'citation_issue':
					$csl->issue = $meta->content;
					break;
					
				case 'citation_firstpage':
					if (isset($csl->page))
					{
						$csl->page  = $meta->content . '-' . $csl->page;
					}
					else
					{
						$csl->page 	= $meta->content;
					}
					$csl->{'page-first'} = $meta->content;
					break;

				case 'citation_lastpage':
					if (isset($csl->page))
					{
						if ($meta->content !== $csl->page)
						{
							$csl->page .= '-' . $meta->content;
						}
					}
					else
					{
						$csl->page 	= $meta->content;
					}
					break;					

				case 'citation_date':
				case 'citation_publication_date':
					$csl->issued = new stdclass;
					$csl->issued->{'date-parts'} = [];
					
				   if (preg_match("/(?<year>[0-9]{4})\/(?<month>[0-9]{1,2})\/(?<day>[0-9]{1,2})$/", $meta->content, $matches))
				   {   
						$csl->issued->{'date-parts'}[0] = array(
							(Integer)$matches['year'],
							(Integer)$matches['month'],
							(Integer)$matches['day']
							);             
				   }
				   if (preg_match("/(?<year>[0-9]{4})$/", $meta->content, $matches))
				   {   
						$csl->issued->{'date-parts'}[0] = array(
							(Integer)$matches['year']
							);             
				   }
				   break;	   
						
				case 'citation_author':
					$author = new stdclass;
					$author->literal = strip_tags(html_entity_decode($meta->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					$author->literal = preg_replace('/\s\s+/', ' ', $author->literal);
					$csl->author[] = $author;
					break;

				case 'citation_author_institution':
					break;

				case 'DC.Description':
				case 'DCTERMS.abstract':
				case 'citation_abstract':
					$csl->abstract = strip_tags(html_entity_decode($meta->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					break;
			
				default:
					break;
			}
		}
		
		if ($debug)
		{
			print_r($csl);
		}
	}
	
	if ($csl)
	{
	
		if (is_array($csl))
		{
			$csl = $csl[0];
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
