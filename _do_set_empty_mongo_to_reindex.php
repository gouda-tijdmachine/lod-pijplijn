#!/usr/bin/php
<?php
require_once "vendor/autoload.php";

// Mongo endpoint lives in lod-pipeline.ini, outside the repo (see _do_update_nt.php)
$lod_settings = parse_ini_file(__DIR__ . "/../../../omeka-s-config/lod-pipeline.ini");
if (!$lod_settings || empty($lod_settings['mongo_server'])) {
	fwrite(STDERR, "ERROR: omeka-s-config/lod-pipeline.ini is missing or has no mongo_server.\n");
	exit(1);
}
define('MONGO2_SERVER', $lod_settings['mongo_server']);
date_default_timezone_set('Europe/Amsterdam');

// connect to MongoDB
$mongoClient = new MongoDB\Client(MONGO2_SERVER);
$collection = $mongoClient->gtm->nt;

# get omeka database settings
$database_settings=parse_ini_file("../../../omeka-s-config/database.ini");

# connect to db via mysqli
try {
	$mysqli = new mysqli($database_settings["host"], $database_settings["user"], $database_settings["password"], $database_settings["dbname"]);
} catch (\mysqli_sql_exception $e) {
	throw new \mysqli_sql_exception($e->getMessage(), $e->getCode());
}


// iterate over all records in MongoDB
$cursor = $collection->find(['content' => ''], ['projection' => ['_id' => 1]]);

foreach ($cursor as $doc) {
	$id = $doc['_id'];
	$stmt = $mysqli->prepare("UPDATE omeka.resource SET modified=NOW() WHERE id='$id'");
	$stmt->execute();
	print "$id ";
}
#print "\n";
