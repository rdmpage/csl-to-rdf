<?php

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/vendor/autoload.php');


//----------------------------------------------------------------------------------------
function get($url, $content_type = '')
{	
	$data = null;

	$opts = array(
	  CURLOPT_URL 				=> $url,
	  CURLOPT_FOLLOWLOCATION	=> TRUE,
	  CURLOPT_RETURNTRANSFER 	=> TRUE,
	  
	  CURLOPT_HEADER 			=> FALSE,
	  
	  CURLOPT_SSL_VERIFYHOST	=> FALSE,
	  CURLOPT_SSL_VERIFYPEER	=> FALSE,
	  
	  CURLOPT_COOKIEJAR			=> sys_get_temp_dir() . '/cookies.txt',
	  CURLOPT_COOKIEFILE		=> sys_get_temp_dir() . '/cookies.txt',
	  
	);
	
	$header = array(
		"Accept-Language: en-gb",
		"User-agent: Mozilla/5.0 (iPad; U; CPU OS 3_2_1 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Mobile/7B405" 	
	);

	if ($content_type != '')
	{
		$header[] = "Accept: " . $content_type;
	}
	
	$opts[CURLOPT_HTTPHEADER] = $header;
	
	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch); 
	curl_close($ch);
	
	// echo $data;
	
	return $data;
}


//----------------------------------------------------------------------------------------
function format_issn($issn)
{
	if (!preg_match('/[0-9]{4}-[0-9]{3}(\d|X)/i', $issn))
	{
		$issn = substr($issn, 0, 4) . '-' . substr($issn, 4);
	}

	return strtoupper($issn);
}

//----------------------------------------------------------------------------------------
// Make a URI play nice with triple store
function nice_uri($uri)
{
	// known errors 
	
	// <https://doi.org/10.3398/064.079.0101> <http://schema.org/citation> <https://doi.org/10.1899/0887-3593(2005)024\%5B0508:biomeu\%5D2.0.co;2> .
	// 10.1899/0887-3593(2005)024\[0508:BIOMEU\]2.0.CO;2
	$uri = str_replace('\[', '[', $uri);
	$uri = str_replace('\]', ']', $uri);
	
	// <https://doi.org/10.1139/gen-2015-0168> <http://schema.org/citation> <https://doi.org/10.1111/j.1471-8286.2007.01678.x. pmid:> .
	$uri = preg_replace('/\.\s+pmid:/', '', $uri);	
	
	// <https://doi.org/10.1007/s10531-023-02686-9> <http://schema.org/citation> <https://doi.org/10.1139/gen-2019-0226%m32502367> .
	$uri = preg_replace('/%m\d+/', '', $uri);
	
	// <https://doi.org/10.1071/is24059> <http://schema.org/citation> <https://doi.org/10.11646/zoosymposia. 2.1.21> .
	$uri = preg_replace('/\s+/', '', $uri);


	$uri = str_replace('[', urlencode('['), $uri);
	$uri = str_replace(']', urlencode(']'), $uri);
	$uri = str_replace('<', urlencode('<'), $uri);
	$uri = str_replace('>', urlencode('>'), $uri);

	return $uri;
}

//----------------------------------------------------------------------------------------
// Clean up text to play nice with triple stire
function nice_literal($text)
{
	// known errors
	$text = str_replace('\\N', '\\\\N', $text);
	
	// remove HTML/XML tags
	$text = strip_tags($text);
	
	// replace newlines
	$text = preg_replace('/\R/u', ' ', $text);	
	
	// clean up spaces
	$text = preg_replace('/\s\s+/', ' ', $text);	
	
	// escape double quotes
	$text = str_replace('"', '\"', $text);
	
	return $text;
}

//----------------------------------------------------------------------------------------
// Simplify CSL to just those elements we need to create a formatted record
function simplify_csl($csl)
{	
	foreach ($csl as $k => $v)
	{
		switch ($k)
		{
			// keep these
			case 'type':
			case 'container-title': 
			case 'volume': 
			case 'issue': 
			case 'page': 
			case 'issued': 
			case 'DOI': 
			case 'URL':
				break;
				
			case 'title':
				if (is_array($csl->title))
				{
					if (count($csl->title) > 0)
					{
						$csl->title = nice_literal($csl->title[0]);
					}
				}
				else
				{
					$csl->title = nice_literal($csl->title);
				}
				break;			
		
			// simplify authors
			case 'author': 
				$n = count($csl->$k);
				
				$authors = array();
				for ($i = 0; $i < $n; $i++)
				{
					$author = new stdclass;
					
					if (isset($csl->$k[$i]->family))
					{
						$author->family = $csl->$k[$i]->family;
					}
					if (isset($csl->$k[$i]->given))
					{
						$author->given = $csl->$k[$i]->given;
					}
					if (isset($csl->$k[$i]->literal))
					{
						$author->literal = $csl->$k[$i]->literal;
					}
					
					$authors[] = $author;
				}
				
				$csl->author = $authors;
				break;
		
			default:
				unset($csl->$k);
				break;
		}
	}

	return $csl;
}

//----------------------------------------------------------------------------------------
function csl_to_quads($csl, $options = [])
{
	$quads = array();
	
	$cuid = new EndyJasmi\Cuid;
	
	$s = null;
	
	// By default use DOI as @id, if no DOI then use URL. If no URL,
	// use CSL id if it is a URI, otherwise we bail.
	if (!$s)
	{
		if (isset($csl->DOI))
		{	
			$s = 'https://doi.org/' . nice_uri(strtolower($csl->DOI));
		}
	}
	
	if (!$s)
	{
		if (isset($csl->URL))
		{	
			$s = nice_uri(strtolower($csl->URL));
		}
	}
	
	if (!$s)
	{
		if (isset($csl->id) && preg_match('/^http/', $csl->id))
		{	
			$s = nice_uri(strtolower($csl->id));
		}
	}	
	
	// if we don't have something we can use as an identifier then we bail(?)
	if (!$s)
	{
		return $quads;
	}
	
	
	// type  -----------------------------------------------------------------------------
	$type = 'CreativeWork';
	
	if (isset($csl->type))
	{
		switch ($csl->type)
		{
			case 'book':
				$type = 'Book';
				break;
			
			case 'chapter':
				$type = 'Chapter';
				break;
			
			case 'article-journal':
		    case 'journal-article':
				$type = 'ScholarlyArticle';
				break;

			case 'posted-content': // CrossRef for preprint
				$type = 'CreativeWork';
				break;
				
			case 'thesis':
				$type = 'Thesis';
				break;
				
			default:
				$type = 'CreativeWork';
				break;	
		}
	}

	$quads[] = array(
		$s, 
		'http://www.w3.org/1999/02/22-rdf-syntax-ns#type',
		'http://schema.org/' . $type
		);	
		
		
	// preprint  --------------------------------------------------------------------------
	// relationship between this work and others, e.g. preprint
	if (isset($csl->relation))
	{
		if (isset($csl->relation->{'has-preprint'}))
		{
			foreach ($csl->relation->{'has-preprint'} as $relation)
			{
				if ($relation->{'id-type'} == 'doi')
				{
					$p = 'http://schema.org/seeAlso'; // could do with something more specific
					$o = 'https://doi.org/' . nice_uri($relation->id);
					$quads[] = array($s, $p, $o);					
				}
			}
		}
		
		if (isset($csl->relation->{'is-preprint-of'}))
		{
			foreach ($csl->relation->{'is-preprint-of'} as $relation)
			{
				if ($relation->{'id-type'} == 'doi')
				{
					$p = 'http://schema.org/seeAlso'; // could do with something more specific
					$o = 'https://doi.org/' . nice_uri($relation->id);
					$quads[] = array($s, $p, $o);					
				}
			}
		}
	}			
	
	// title -----------------------------------------------------------------------------
	$name_done = false; // flag for later if we add support for multiple languages
	
	if (isset($csl->multi))
	{
		if (isset($csl->multi->_key->title))
		{
			foreach ($csl->multi->_key->title as $language => $value)
			{
				$p = 'http://schema.org/name';
				$o = '"' . nice_literal($value) . '"@' . $language;
								
				$quads[] = array($s, $p, $o);
			}		
			$name_done = true;			
		}
	}
	
	if (!$name_done)
	{
		$name = '';
		
		if (isset($csl->title))
		{
			if (is_array($csl->title))
			{
				if (count($csl->title) > 0)
				{
					$name = $csl->title[0];
				}
			}
			else
			{
				$name = $csl->title;
			}
		}
		
		if ($name != '')
		{
			$p = 'http://schema.org/name';
			$o = '"' . nice_literal($name)  . '"';			
			
			$quads[] = array($s, $p, $o);
		}
	}
	
	// abstract --------------------------------------------------------------------------
	$abstract_done = false; // flag for later if we add support for multiple languages
	
	/*
	if (isset($csl->multi))
	{
		if (isset($csl->multi->_key->abstract))
		{
			foreach ($csl->multi->_key->abstract as $language => $value)
			{
				$value = preg_replace('/\R/u', ' ', $value);
				$value = preg_replace('/\s\s+/u', ' ', $value);
				$value = strip_tags($value);
				$work->addLiteral('schema:description', $value, $language);
			}		
			$abstract_done = true;			
		}
	}
	*/
	
	if (!$abstract_done)
	{
		if (isset($csl->abstract))
		{
			$abstract = $csl->abstract;
			$abstract = '"' . nice_literal($abstract) . '"';
			
			$quads[] = array(
				$s, 
				'http://schema.org/abstract', 
				$abstract
				);
		}
	}	
	
	// date ------------------------------------------------------------------------------
	if (isset($csl->issued))
	{
		$date = '';
		$d = $csl->issued->{'date-parts'}[0];

		// sanity check
		if (is_numeric($d[0]))
		{
			$datetype = 'date';
		
			if ( count($d) > 0 ) $year = $d[0] ;
			if ( count($d) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[1] ) ;
			if ( count($d) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[2] ) ;
			if ( isset($month) and isset($day) )
			{
				$date     = "$year-$month-$day";
				$datetype = 'date';
			}
			else if ( isset($month) )
			{
				$date     = "$year-$month";
				$datetype = 'gYearMonth';				
			}
			else if ( isset($year) ) 
			{
				$date     = "$year";
				$datetype = 'gYear';	
			}
			
			if (0)
			{
				// treat as simple literal
				$quads[] = array(
					$s, 
					'http://schema.org/datePublished', 
					'"' . $date . '"'
					);
			}
			else
			{
				// treat as date, triples stores can do date queries much faster
				// if we use date type
				$quads[] = array(
					$s, 
					'http://schema.org/datePublished', 
					'"' . $date . '"^^<http://www.w3.org/2001/XMLSchema#' . $datetype . '>' 
					);			
			}
		}				
	}		
	
	// funder  ---------------------------------------------------------------------------
	if (isset($csl->funder))
	{
		foreach ($csl->funder as $funder)
		{
			$funder_id = '';
						
			if (isset($funder->DOI))
			{
				$funder_id = 'https://doi.org/' . nice_uri($funder->DOI);
			}
			
			if ($funder_id == '')
			{
				// blank node
				$funder_id = '_:' . $cuid->cuid();
			}
		
			// funder is an organisation
			$quads[] = array(
				$funder_id, 
				'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 
				'http://schema.org/Organization'
				);
	
			if (isset($funder->name))
			{
				$o = '"' . nice_literal($funder->name) . '"';
				
				$quads[] = array(
					$funder_id, 
					'http://schema.org/name', 
					$o
					);
			}
			
			// OK, now we have a funder node, need to discover how we connect it 
			// to the work following examples in https://schema.org/Grant
			
			if (isset($funder->award) && count($funder->award) > 0)
			{
				// clean
				$funder->award = array_unique($funder->award);
				
				// awards are text strings, e.g. grant numbers
				foreach ($funder->award as $award)
				{
					// blank node
					$grant_id = '_:' . $cuid->cuid();
										
					// is a schema:Grant
					$quads[] = array(
						$grant_id, 
						'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 
						'http://schema.org/Grant'
						);
						
					// award name is schema:identifier
					$o = '"' . nice_literal($award) . '"';
	
					$quads[] = array(
						$grant_id, 
						'http://schema.org/identifier', 
						$o
						);
	
					// link award to funder								
					$quads[] = array(
						$grant_id, 
						'http://schema.org/funder', 
						$funder_id
					);
					
					// link award to work
					$quads[] = array(
						$s, 
						'http://schema.org/funding', 
						$grant_id
					);
				}
			
			}
			else
			{
				// connect work directly to funder as we don't have award info
				$quads[] = array(
					$s, 
					'http://schema.org/funder', 
					$funder_id,
					);
			}
		}
	}

	// creators  -------------------------------------------------------------------------
	if (isset($csl->author) && 1)
	{
		foreach ($csl->author as $creator)
		{
			$creator_id = '';
	
			if ($creator_id == '')
			{
				if (isset($creator->ORCID))
				{
					$creator_id = $creator->ORCID;
					$creator_id = str_replace('http://', 'https://', $creator_id);
				}
			}
			
			// no guid then blank node
			if ($creator_id == '')
			{
				$creator_id = '_:' . $cuid->cuid();
			}	
			
			// link work to creator
			$quads[] = array(
				$s, 
				'http://schema.org/creator', 
				$creator_id
				);
				
			// assume that creator is a person
			$quads[] = array(
				$creator_id, 
				'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 
				'http://schema.org/Person'
				);			
				
			// simple details of creator
			if (isset($creator->literal))
			{
				$o = '"' . nice_literal($creator->literal) . '"';
				
				$quads[] = array(
					$creator_id, 
					'http://schema.org/name', 
					$o
					);
			}
			if (isset($creator->given))
			{
				$o = '"' .  nice_literal($creator->given)  . '"';
				
				$quads[] = array(
					$creator_id, 
					'http://schema.org/givenName', 
					$o
					);
			}
			if (isset($creator->family))
			{
				$o = '"' .  nice_literal($creator->family)  . '"';
				
				$quads[] = array(
					$creator_id, 
					'http://schema.org/familyName', 
					$o
					);
			}
				
		}
	}
	
	// citations -------------------------------------------------------------------------	
	//
	// note that some DOIs e.g. 10.1002/(SICI)1097-0061(199708)13:10<945::AID-YEA150>3.0.CO;2-5 
	// create reference keys that include the artciloe DOI!
	if (isset($csl->reference))
	{
		foreach ($csl->reference as $reference)
		{
			$cited_id = '';
			
			if (isset($reference->DOI))
			{
				$cited_id = 'https://doi.org/' . nice_uri(strtolower($reference->DOI));
			}	
			
			// Decide how to handle citations that lack DOIs, what level of detail to include, etc.
			// Also decide how to refer to them, e.g. using "key" as part of a fragment identifier
			
			if ($cited_id == '' && in_array('allcitations', $options))
			{
				// blank node
				$cited_id = '_:' . $cuid->cuid();	
			}
			
			// local identifier (do we use this?)
			$key = '';
			if (isset($reference->key))
			{
				$key = $reference->key;
				$key = preg_replace('/[\t\n\r]/u', '', $key);
			}			
						
			if ($cited_id != '')
			{
				$add = isset($reference->DOI); 
				
				if (!$add)
				{
					$add = isset($reference->unstructured); 
				}
				
				if ($add)
				{
					$quads[] = array(
						$s, 
						'http://schema.org/citation', 
						$cited_id
						);
						
						
					if (isset($reference->{'article-title'}))
					{
						$text = $reference->{'article-title'};
						$text = '"' .  nice_literal($text)  . '"';
						
						$quads[] = array(
							$cited_id, 
							'http://schema.org/name', 
							$text
							);
					
					}

					if (isset($reference->unstructured))
					{
						$text = $reference->unstructured;
						$text = '"' .  nice_literal($text)  . '"';
						
						$quads[] = array(
							$cited_id, 
							'http://schema.org/description', 
							$text
							);
					
					}
					
				}
			}
		}
	}		
	
	// license ---------------------------------------------------------------------------
	if (isset($csl->license))
	{
		$license_urls = [];
		if (is_array($csl->license))
		{
			foreach ($csl->license as $license)

			if (isset($license->URL))
			{
				$license_urls[] = $license->URL;
			}
		}
		
		$license_urls = array_unique($license_urls);
		if (count($license_urls) > 0)
		{
			foreach ($license_urls as $license_url)
			{
				$quads[] = array(
					$s, 
					'http://schema.org/license', 
					$license_url
					);			
			}
		}
	}

	// publisher -------------------------------------------------------------------------
	if (isset($csl->publisher))
	{
		if (is_string($csl->publisher))
		{
			// blank node
			$publisher_id = '_:' . $cuid->cuid();
			
			// link to work
			$quads[] = array(
				$s, 
				'http://schema.org/publisher', 
				$publisher_id
				);		
				
			// type 
			$quads[] = array(
				$publisher_id, 
				'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 
				'http://schema.org/Organization'				
				);				
				
			// name
			$o = '"' . nice_literal($csl->publisher) . '"';	
			
			$quads[] = array(
				$publisher_id, 
				'http://schema.org/name', 
				$o
				);	
		}
	}
	
	
	// CSL for display -------------------------------------------------------------------	
	$cleaned_csl = simplify_csl($csl);
	
	$text = json_encode($cleaned_csl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);			
	$text = str_replace(['\\', '"'], ['\\\\', '\\"'], $text);		
	$o = '"' .  $text  . '"';
	
	if (0)
	{		
		// CSL-JSON as a description
		
		$quads[] = array(
			$s, 
			'http://schema.org/description', 
			$o
			);
	}
	else
	{
		// CSL-JSON as an encoding
		
		// blank node
		$encoding_id = '_:' . $cuid->cuid();
		
		// type 
		$quads[] = array(
			$encoding_id, 
			'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 
			'http://schema.org/MediaObject'				
			);		
							
		// encoding MIME type
		$quads[] = array(
			$encoding_id, 
			'http://schema.org/encodingFormat',
			'"application/vnd.citationstyles.csl+json"'
			);
			
		// JSON string
		$quads[] = array(
			$encoding_id, 
			'http://schema.org/text',
			$o			
			);

		$quads[] = array(
			$s, 
			'http://schema.org/encoding', 
			$encoding_id
			);
	}
	

	return $quads;

}

?>
