<?php

if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

// Version of the normalizer, embedded in every hashed key. Changing normalize_name()
// re-mints every IRI, so bump this and treat it as a migration.
$config['norm_version'] = 'norm/1';

// Global namespace for when we generate URIs for things like people
// when they lack a global identifier
$config['id_namespace'] = 'https://w3id.org/yourkg/id/';

?>
