<?php

// Given a list of URLs fetch HTML, extract metadata from <meta> tags, convert to
// CSL-JSON, then convert to RDF

require_once (dirname(__FILE__) . '/shared.php');
require_once (dirname(__FILE__) . '/HtmlDomParser.php');
use Sunra\PhpSimple\HtmlDomParser;

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

$urls = array();

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$url = trim(fgets($file_handle));
	
	$urls[] = $url;
}	

$force = false;

$debug = false;
//$debug = true;

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
		
		if (preg_match('/handle.net\/(.*)$/', $url, $m))
		{
			$csl->URL = 'https://hdl.handle.net/' . $m[1];
		}
			
		// meta
		foreach ($dom->find('meta') as $meta)
		{
			if ($debug)
			{
				fwrite(STDERR, $meta->name . ' ' . $meta->content . "\n");
			}
			
			switch ($meta->name)
			{				
				case 'citation_doi':
					$csl->DOI = $meta->content;
					break;
					
				case 'citation_abstract_html_url':
				case 'eprints.official_url':
					if (!isset($csl->URL))
					{
						$csl->URL = $meta->content;
					}
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
					
					if ($debug)
					{
						echo "|" . $meta->content . "|\n";
					}
										
				   if (preg_match("/(?<year>[0-9]{4})[\/|-](?<month>[0-9]{1,2})[\/|-](?<day>[0-9]{1,2})$/", $meta->content, $matches))
				   {   
						$csl->issued->{'date-parts'}[0] = array(
							(Integer)$matches['year'],
							(Integer)$matches['month'],
							(Integer)$matches['day']
							);             
				   }
				   if (preg_match("/^(?<year>[0-9]{4})-(?<month>[0-9]{1,2})$/", $meta->content, $matches))
				   {   
						$csl->issued->{'date-parts'}[0] = array(
							(Integer)$matches['year'],
							(Integer)$matches['month'],
							);             
				   }
				   if (preg_match("/^(?<year>[0-9]{4})$/", $meta->content, $matches))
				   {   
						$csl->issued->{'date-parts'}[0] = array(
							(Integer)$matches['year']
							);             
				   }
				   
				   if (0)
				   {
				   	fwrite(STDERR, print_r($csl->issued->{'date-parts'}, true));
				   	exit();
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

				case 'DC.Publisher':
				case 'DC.publisher':
					$csl->publisher = $meta->content;
					break;
					
				case 'citation_dissertation_institution';
					$csl->publisher = $meta->content;
					$csl->type = 'thesis';
					break;
					
				case 'citation_dissertation_name';
					$csl->type = 'thesis';
					break;

				case 'DC.type':
					switch ($meta->content)
					{
						case 'Doctoral Thesis':
						case 'info:eu-repo/semantics/doctoralThesis';
							$csl->type = 'thesis';
							break;
							
						default:
							break;
					}
					break;

				default:
					break;
			}
		}
		
		if ($debug)
		{
			fwrite(STDERR, print_r($csl, true));
		}
	}

	if ($csl)
	{
		if (is_array($csl))
		{
			$csl = $csl[0];
		}

		if ($debug)
		{
			fwrite(STDERR, print_r($csl, true));
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
