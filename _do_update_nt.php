#!/usr/bin/php
<?php

require_once "vendor/autoload.php";
require_once 'geoPHP/geoPHP.inc';
require_once __DIR__ . '/_do_transforms.php';   # cor*/tf* pipeline + transform_resource()


define('BASE_API','https://www.goudatijdmachine.nl/omeka/api/');
define('LAST_UPDATE_FILE','_do_update_nt.dat');
$lod_settings = parse_ini_file(__DIR__ . "/../../../omeka-s-config/lod-pipeline.ini");
if (!$lod_settings || empty($lod_settings['mongo_server'])) {
	fwrite(STDERR, "ERROR: omeka-s-config/lod-pipeline.ini is missing or has no mongo_server.\n");
	exit(1);
}
define('MONGO2_SERVER', $lod_settings['mongo_server']);
date_default_timezone_set('Europe/Amsterdam');

# Omeka API keypair, kept next to database.ini (chmod 600) so this script stays
# committable. __DIR__-relative, unlike the database.ini read below, so the keys
# resolve no matter which directory the script is invoked from.
$omeka_api_settings = parse_ini_file(__DIR__ . "/../../../omeka-s-config/omeka-api.ini");
if (!$omeka_api_settings || empty($omeka_api_settings['key_identity']) || empty($omeka_api_settings['key_credential'])) {
	fwrite(STDERR, "ERROR: omeka-s-config/omeka-api.ini is missing or has no key_identity/key_credential.\n");
	exit(1);
}
define('KEY_IDENTITY', $omeka_api_settings['key_identity']);
define('KEY_CREDENTIAL', $omeka_api_settings['key_credential']);

# Omeka property_id of owl:sameAs (ARK PID, stored in value.uri); cf. old/_do_backfill_ark.php
const PROPERTY_ARK = 1177;

# How many resources to harvest over HTTP in parallel per batch (curl_multi). Kept
# conservative so we don't overload the Omeka PHP-FPM pool; transform+store stay serial.
const BATCH = 8;

# TODO: opnieuw ValueAnnotation testen, zat een fout in identity/credential

$omeka_api=array(
#   "Omeka\Entity\ValueAnnotation"	=> $base_api."value_annotations",
    "Omeka\Entity\Media" 			=> BASE_API."media",
    "Omeka\Entity\Item" 			=> BASE_API."items",
    "Omeka\Entity\ItemSet" 			=> BASE_API."item_sets"
);

$omeka_resource_types=array_keys($omeka_api);

# Test mode: when resource ids are passed on the command line (e.g. ./_do_update_nt.php 171)
# only those resources are (re)harvested and the incremental watermark is left untouched.
$test_ids=array_map('intval',array_slice($argv,1));
$test_mode=count($test_ids)>0;

# get omeka database settings
$database_settings=parse_ini_file("../../../omeka-s-config/database.ini");


# connect to db via mysqli
try {
	# read-only access → connect to the read-only replica port 3316 (writes stay on 3306)
	$mysqli = new mysqli($database_settings["host"], $database_settings["user"], $database_settings["password"], $database_settings["dbname"], 3316);
} catch (\mysqli_sql_exception $e) {
	throw new \mysqli_sql_exception($e->getMessage(), $e->getCode());
}

$context = curl_init();	# here for keep alive
curl_setopt($context, CURLOPT_RETURNTRANSFER, true);
curl_setopt($context, CURLOPT_HEADER, true);

# Open the MongoDB collection once and share it — store_nt() used to build a fresh
# MongoDB\Client (new TCP connect + handshake) per record.
$mongo_collection = (new \MongoDB\Client(MONGO2_SERVER))->gtm->nt;

# Prepare the ARK lookup once and re-execute it per record (get_ark() used to re-prepare
# this every call). Query shape and return contract are unchanged.
$ark_stmt = $mysqli->prepare("SELECT uri FROM omeka.value WHERE resource_id=? AND property_id=? AND uri LIKE \"https://n2t.net/ark:%\" LIMIT 1");


if ($test_mode) {
	# ids are already intval'd above, so they are safe to splice into the query
	$inlist=implode(',',$test_ids);
	error_log("INFO: TEST MODE — only harvesting resource ids: $inlist");
	$stmt = $mysqli->prepare("SELECT id,resource_type,modified FROM resource WHERE id IN ($inlist) ORDER BY id ASC");
} else {
	$last_update=file_get_contents(LAST_UPDATE_FILE);
	error_log("INFO: Looking for modified items since $last_update");
	# find modified items
	$stmt = $mysqli->prepare("SELECT id,resource_type,modified FROM resource WHERE is_public=1 AND modified>='$last_update' ORDER BY modified ASC");
}

$stmt->execute();
$result = $stmt->get_result();

# Buffer all rows so we can harvest them in parallel batches while still processing
# (transform + store) strictly in modified-ASC order — preserving the incremental
# watermark and $test_urls ordering.
$rows = array();
while ($row = $result->fetch_assoc()) {
	$rows[] = $row;
}

$total = count($rows);
$n = 0;
$test_urls = array();

foreach (array_chunk($rows, BATCH) as $chunk) {
	# 1. Build the parallel-fetch job list for rows we actually harvest.
	$jobs = array();   // chunk index => resource n-triples URL
	foreach ($chunk as $i => $row) {
		if (in_array($row["resource_type"],$omeka_resource_types)) {
			# Geen noreverse=1 meer: dat was een lokale corepatch op Omeka 4.0/4.1 die bij de
			# upgrade naar 4.2.x verdween, waarna hij stilletjes een dode parameter was. Het
			# @reverse-blok gaat nu uit via de core-instelling `disable_jsonld_reverse`
			# (admin -> Instellingen -> Algemeen). Dat is niet cosmetisch: Omeka zet in
			# @reverse alle INKOMENDE verwijzingen, dus het document van bijv. een
			# ric:DateRange droeg voor elk item dat hem gebruikt een regel
			# <item> schema:dateCreated <DateRange>. Die kopie is overbodig (het item
			# exporteert hem zelf) en wordt nooit ongeldig gemaakt: wees een item later een
			# ANDERE DateRange aan, dan bleef het oude document de oude waarde beweren en
			# kreeg dat item in de samengevoegde dump twee schema:dateCreated-waarden —
			# 293 subjects, 287 daarvan een dag/maand-omgekeerd paar.
			$jobs[$i]=$omeka_api[$row["resource_type"]]."/".$row["id"]."?format=ntriples&raw=1&key_identity=".KEY_IDENTITY."&key_credential=".KEY_CREDENTIAL;
		}
	}

	# 2. Fetch this batch concurrently (curl_multi).
	$fetched = fetch_many($jobs);   // chunk index => [headers, body] | null (transport error)

	# 3. Process the chunk in original order — identical per-row work as before.
	foreach ($chunk as $i => $row) {
		$n++;
		error_log(sprintf("INFO: Record %d/%d (%.1f%%)", $n, $total, $total ? $n / $total * 100 : 0));
		$row_date=substr($row["modified"],0,10);
		if (!$test_mode && $last_update!=$row_date) {
			file_put_contents(LAST_UPDATE_FILE,$last_update);
			$last_update=$row_date;
		}
		if (isset($jobs[$i])) {
			$id=$row["id"];
			$status_date=$test_mode?"test":$last_update;
			if ($fetched[$i]===null) {
				# Transport error in the parallel fetch — fall back to the sequential
				# retry path (which sleeps + retries), then process its result.
				list($headers,$body)=get_headers_body($jobs[$i]);
			} else {
				list($headers,$body)=$fetched[$i];
			}
			process_body($id,$jobs[$i],$headers,$body,2,$status_date);
			if ($test_mode) {
				$test_urls[]=$omeka_api[$row["resource_type"]]."/".$row["id"]."?format=turtle";
			}
		}
	}
}
curl_close($context);

if ($test_mode) {
	# Drop the turtle URLs where they can be inspected by hand; leave the watermark untouched.
	file_put_contents("_test_urls.txt",implode("\n",$test_urls)."\n");
	error_log("INFO: Wrote ".count($test_urls)." turtle URL(s) to _test_urls.txt");
} else {
	date_default_timezone_set('Europe/Amsterdam');
	$date = date('Y-m-d', time());
	# Advance the incremental watermark — write the SAME file that is read at startup.
	file_put_contents(LAST_UPDATE_FILE,$date);
	error_log("INFO: Stored $date to ".LAST_UPDATE_FILE);
}


# Harvest a batch of URLs concurrently with curl_multi. $jobs maps an arbitrary key
# (the chunk index) to a URL; returns the same keys mapping to [headers, body], or
# null for a handle that hit a transport error (the caller retries those sequentially
# via get_headers_body, which sleeps + retries). HTTP/2 multiplexing keeps the batch on
# as few connections as possible, like the single keep-alive handle used to.
function fetch_many($jobs) {
	if (!$jobs) return array();

	$mh = curl_multi_init();
	curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
	$handles = array();
	foreach ($jobs as $key => $url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
		curl_multi_add_handle($mh, $ch);
		$handles[$key] = $ch;
	}

	# Drive all transfers to completion.
	do {
		$status = curl_multi_exec($mh, $running);
		if ($running) curl_multi_select($mh);
	} while ($running && $status == CURLM_OK);

	# Collect responses; split headers/body via the per-handle header size.
	$out = array();
	foreach ($handles as $key => $ch) {
		if (curl_errno($ch)) {
			error_log("ERROR: ".curl_error($ch)." on ".$jobs[$key]);
			$out[$key] = null;
		} else {
			$response = curl_multi_getcontent($ch);
			$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$out[$key] = array(substr($response, 0, $header_size), substr($response, $header_size));
		}
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
	}
	curl_multi_close($mh);
	return $out;
}

# Process one already-harvested response: guard on the HTTP status, retry 5xx
# sequentially (sleep + refetch), then hand a good body to save_nt().
function process_body($id,$url,$headers,$body,$status,$row_date) {
	$showurl=preg_replace("/\?.*$/","",$url);
	error_log("INFO: Getting $showurl ($row_date)");

	if (preg_match("#HTTP/\S+ 404#",$headers)) {
		error_log("ERROR: 404 on $url");
	} elseif (preg_match("#HTTP/\S+ 50#",$headers)) {
		error_log("ERROR: 500 on $url");
		sleep(5);
		list($headers,$body)=get_headers_body($url);
		process_body($id,$url,$headers,$body,$status,$row_date);
	} else {
		save_nt($id,$body,$status);
	}
}

# Guard out obviously-bad responses, run the transform pipeline, store in MongoDB.
function save_nt($id,$ntstring,$status) {
	#error_log("DEBUG: Storing $id");

	if (strpos($ntstring,'"errors"')>0) {
		print "errors in $id:\n\n".$ntstring."\n\n";
	} elseif (strpos($ntstring,'|http:')>0) {
		print "invalid iri in $id:\n\n".$ntstring."\n\n";
	} elseif (strpos($ntstring,'<head>')>0 || strpos($ntstring,'</head>')>0 || strpos($ntstring,'<h1>')>0) {
		print "html in $id:\n\n".$ntstring."\n\n";
	} else {
		global $mysqli;
		$ntstring = transform_resource($id, $ntstring, $mysqli);
		store_nt($id, $ntstring, date('Y-m-d'), $status);
	}
}

function get_headers_body($url) {
	global $context;

	curl_setopt($context, CURLOPT_URL, $url);

	$response = curl_exec($context);
	if (curl_errno($context)) {
		echo 'ERROR:' . curl_error($context).", retyring in 5s";
		sleep(5);
		return get_headers_body($url);
	}

	$header_size = curl_getinfo($context, CURLINFO_HEADER_SIZE);
	$headers = substr($response, 0, $header_size);
	$body = substr($response, $header_size);

	return array($headers,$body);
}

function store_nt($id,$content,$last_update,$status) {
	global $mysqli, $mongo_collection;
	if ($status > 1) {
		$mongo_collection->deleteOne(['_id' => $id ]);
	}
	$last_update=date('Y-m-d');
	$doc = [ '_id'=>$id, 'content'=>$content, 'last_update'=>$last_update ];
	$ark = get_ark($id, $mysqli);
	if ($ark !== null) {
		$doc['ark'] = $ark;
	}
	$mongo_collection->insertOne($doc);
}

# Look up the ARK PID for a resource (see old/_do_backfill_ark.php). Returns the part
# after 'ark:/' (e.g. "60537/b0024ch"), or null when the resource has no ARK.
# For media, which never carry an ARK of their own, this falls back to the derived
# qualifier form "60537/<item-name>/<media-id>" — see tf_media_arks() in _do_transforms.php.
function get_ark($id, $mysqli) {
	global $ark_stmt;   // prepared once at startup; re-executed per record
	$propertyArk = PROPERTY_ARK;
	$ark_stmt->bind_param('ii', $id, $propertyArk);
	$ark_stmt->execute();
	$row = $ark_stmt->get_result()->fetch_assoc();
	if (!$row || empty($row['uri'])) {
		# No ARK stored on the resource itself. Media never have one — they are named as a
		# qualifier on their item's ARK, <item-ark>/<media-id>. tf_media_arks()
		# (_do_transforms.php) derives that and returns an empty array for anything that is
		# not a media, so keeping the derivation in one place stops the Mongo `ark` key,
		# tf9's swap and _prepare_swap_uri.py from drifting apart.
		# This key is what cached_rdf.php looks a resource up by, so without it a media ARK
		# cannot be dereferenced to RDF.
		$derived = tf_media_arks([(int)$id], $mysqli);
		if (!isset($derived[(int)$id])) return null;
		$uri = $derived[(int)$id];
	} else {
		$uri = $row['uri'];
	}
	$pos = strpos($uri, 'ark:/');
	if ($pos === false) return null;
	return substr($uri, $pos + strlen('ark:/'));   // e.g. "60537/b0024ch", "60537/bUAQRv/10"
}
