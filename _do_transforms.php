<?php

# ===========================================================================
# Per-resource N-triples transform pipeline for the Omeka -> MongoDB indexer
# (_do_update_nt.php). Each resource's n-triples pass through transform_resource()
# before being stored, replacing the old whole-corpus _do_prepare.sh steps 4.3-4.10.
#
# Two groups, applied in order by transform_resource():
#   cor1..cor3 — corrections to the raw Omeka output (blank-node ids, pnv names, geo)
#   tf1..tf9   — schema.org reshaping (DefinedTerm folding, type conversions, api<->ark)
#
# All transforms are pure string-in/string-out except tf9_swap_uri, which needs the
# Omeka mysqli connection to resolve cross-referenced resources' ARK PIDs. Emitted
# triples are byte-stable so the downstream sort_unique dedups them across resources.
#
# Requires geoPHP (for cor3's area()); it comes from composer (phayes/geophp), so the
# including script only has to require vendor/autoload.php.
# ===========================================================================

const TF_RDF_TYPE     = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>';
const TF_O_LABEL      = '<http://omeka.org/s/vocabs/o#label>';
const TF_O_LANG       = '<http://omeka.org/s/vocabs/o#lang>';
const TF_CLASS_ITEM   = '<http://omeka.org/s/vocabs/o#Item>';
const TF_PERSON_OBS   = '<https://personsincontext.org/model#PersonObservation>';
const TF_PROPERTY_ARK = 1177;   // Omeka property_id of owl:sameAs (the ARK PID, stored in value.uri)
# tf18 — the Dataset every CreativeWork is declared part of (SCHEMA-AP-NDE).
const TF_CLASS_CREATIVEWORK = '<https://schema.org/CreativeWork>';
const TF_ISPARTOF           = '<https://schema.org/isPartOf>';
const TF_DATASET_ARK        = '<https://n2t.net/ark:/60537/bD64Hu>';   // "Gouda Tijdmachine 🕓 Kennisgraaf", Omeka item 13000
# Omeka property_ids used by tf13 to collapse a dateCreated DateRange into an ISO literal:
# follow ric:hasBeginningDate from the DateRange to its SingleDate, read its ric:normalizedDateValue.
const TF_PROPERTY_HASBEGINDATE   = 1646;   // ric:hasBeginningDate (DateRange -> SingleDate)
const TF_PROPERTY_NORMALIZEDDATE = 2024;   // ric:normalizedDateValue (clean ISO string on the SingleDate)
# Resources also typed as one of these get their redundant o:Item type dropped (tf2).
const TF_OTHER_CLASSES = [
    '<http://omeka.org/s/vocabs/o#Media>',
    '<http://omeka.org/s/vocabs/o#ItemSet>',
    '<http://omeka.org/s/vocabs/o#Comment>',
    '<http://omeka.org/s/vocabs/module/mapping#Feature>',

    '<http://modellen.geostandaarden.nl/def/imx-geo#Adres>',
	'<http://www.opengis.net/ont/geosparql#Geometry>',
	
    '<https://www.ica.org/standards/RiC/ontology#DateRange>',
    '<https://www.ica.org/standards/RiC/ontology#SingleDate>',
    '<https://www.ica.org/standards/RiC/ontology#Instantiation>',
    '<https://www.ica.org/standards/RiC/ontology#RecordSet>',

    '<http://www.w3.org/2004/02/skos/core#Concept>',
    '<http://www.w3.org/2004/02/skos/core#ConceptScheme>',
    '<http://www.w3.org/2004/02/skos/core#Collection>',

    '<https://www.goudatijdmachine.nl/def#Perceel>',
    '<https://www.goudatijdmachine.nl/def#OorspronkelijkeAanwijzendeTafelRegel>',

	'<https://schema.org/ArchiveComponent>',
	'<https://schema.org/Article>',
	'<https://schema.org/Book>',
	'<https://schema.org/Collection>',
	'<https://schema.org/ContactPoint>',
	'<https://schema.org/CreativeWork>',
	'<https://schema.org/DataCatalog>',
	'<https://schema.org/DataDownload>',
	'<https://schema.org/Dataset>',
	'<https://schema.org/GeoCoordinates>',
	'<https://schema.org/GeoShape>',
	'<https://schema.org/ImageObject>',
	'<https://schema.org/Organization>',
	'<https://schema.org/Person>',
	'<https://schema.org/Place>',

	# to deprecate/deprecated classes

	'<https://w3id.org/roar#PersonObservation>',  
	'<http://purl.org/dc/dcmitype/Dataset>',
	'<http://purl.org/dc/dcmitype/Image>',
	'<http://purl.org/vocab/bio/0.1/Marriage>',
	'<http://rdf.histograph.io/Borough>',
	'<http://rdf.histograph.io/Street>',

];


# Run the full per-resource pipeline. cor1..cor3 first (cor1 must precede the tf*
# that mint _:genid2-<sha256> blank nodes — its /_:genid([0-9]+)/ regex would
# otherwise corrupt them); tf9 (api<->ark swap) near the end as it rewrites URIs,
# and tf18 after it so the isPartOf subjects are already ARKs.
function transform_resource($id, $ntstring, $mysqli) {
	# Rewrite trail for tf23: the folding transforms below destroy the predicate and the
	# object of the lines they replace, and tf23 can only reify a line it can still find.
	# See tf_trail_note().
	$trail = [];

	$ntstring = cor1_unique_genids($ntstring, $id);
	$ntstring = cor2_pnv_additionalname($ntstring, $id);
	$ntstring = cor3_geosparql_geometry($ntstring);
	$ntstring = tf1_associatedmedia($ntstring);
	$ntstring = tf15_imageobject_media($ntstring, $id, $mysqli);
	$ntstring = tf2_remove_itemtype($ntstring);
	$ntstring = tf3_convert_modified($ntstring);
	$ntstring = tf19_local_media_iiif($ntstring);
	$ntstring = tf20_oat_personobservation($ntstring);
	$ntstring = tf4_convert_persontype($ntstring);
	$ntstring = tf5_convert_itemtype($ntstring);
	$ntstring = tf17_convert_about($ntstring, $trail);
	$ntstring = tf6_convert_additionaltype($ntstring, $trail);
	$ntstring = tf7_convert_hasoccupation($ntstring, $trail);
	$ntstring = tf8_convert_places($ntstring, $trail);
	$ntstring = tf16_materialise_external_places($ntstring);
	$ntstring = tf13_collapse_datecreated($ntstring, $id, $mysqli);
	# tf24 vlak VOOR tf9: het zet de alias-regel <api-media> owl:sameAs <afgeleide-ark>
	# klaar in precies de vorm die Omeka voor items zelf al levert, zodat tf9's bestaande
	# bidirectionele tak hem omdraait naar <ark> owl:sameAs <api> en meteen het subject
	# van het hele mediadocument naar de ARK herschrijft.
	$ntstring = tf24_media_sameas($ntstring, $id, $mysqli);
	$ntstring = tf9_swap_uri($ntstring, $id, $mysqli);
	$ntstring = tf10_string_identifier($ntstring);
	$ntstring = tf11_literal_creator($ntstring, $trail);
	$ntstring = tf12_geocoordinates_latlong($ntstring);
	$ntstring = tf14_normalise_temporalcoverage($ntstring);
	$ntstring = tf18_dataset_ispartof($ntstring);
	$ntstring = tf22_datecreated_datatype($ntstring);
	$ntstring = tf21_normalise_langstring($ntstring);
	# tf23 als ALLERLAATSTE: het reificeert bestaande regels, dus subject/predicaat/object
	# moeten al hun definitieve vorm hebben (tf9's ARK-swap, tf3/tf10/tf21/tf22's literals).
	# $trail vertelt hem waar de vouwende transforms hierboven een waarde heen verplaatst hebben.
	$ntstring = tf23_value_annotations($ntstring, $id, $mysqli, $trail);
	# Guarantee the newline-terminated contract the stored documents rely on:
	# _prepare_collect_nt.py concatenates them verbatim, so a document that does not
	# end in "\n" glues its last triple onto the next document's first one.
	if ($ntstring !== '' && substr($ntstring, -1) !== "\n") $ntstring .= "\n";
	return $ntstring;
}


# ---------------------------------------------------------------------------
# Corrections to the raw Omeka N-triples.
# ---------------------------------------------------------------------------

function geo($input) {
	return '<'.$input[1].'> <http://www.opengis.net/ont/geosparql#hasGeometry> _:geo-'.md5($input[2])." .\n_:geo-".md5($input[2]).'  <http://www.opengis.net/ont/geosparql#asWKT> "'.$input[2].'"^^<http://www.opengis.net/ont/geosparql#wktLiteral> .';
}

function area($wkt) {
	// Parse polygon and compute area
	$geom = geoPHP::load($wkt, 'wkt');
	$degArea = $geom->getArea();

	// Convert degrees² to m² approximately
	$coords = $geom->getPoints();
	$lat0 = $coords[0]->getY();
	$metersPerDegLat = 111320;
	$metersPerDegLon = 111320 * cos(deg2rad($lat0));
	$m2Area = abs($degArea) * $metersPerDegLat * $metersPerDegLon;

	// Round area to integer
	return round($m2Area);
}

# Correction 1 — make Omeka's _:genidN blank nodes unique per resource by appending
# the resource id (e.g. _:genid1 -> _:genid1-<id>). MUST run before tf6/tf7/tf8: its
# /_:genid([0-9]+)/ regex would otherwise rewrite the _:genid2-<sha256> DefinedTerm
# blank nodes those transforms mint, breaking their cross-resource sharing.
function cor1_unique_genids($ntstring, $id) {
	return preg_replace("/_:genid([0-9]+)/", "_:genid\\1-" . $id, $ntstring);
}

# Correction 2 — fold the w3id.org/pnv# (Person Name Vocabulary) properties on the
# resource into one _:gennaam-<id> blank node, linked from the subject via
# schema:additionalName, and copy the resource's schema:name into pnv:literalName.
function cor2_pnv_additionalname($ntstring, $id) {
	preg_match_all("/\<(.*?)\> \<https\:\/\/w3id\.org\/pnv#(.*?)\> (.*?) \./", $ntstring, $matches);
	if (count($matches[0]) > 0) {
		$nrMatches = count($matches[0]);
		$toevoegen2 = "";
		for ($nr = 0; $nr < $nrMatches; $nr++) {
			$ntstring = str_replace($matches[0][$nr], "", $ntstring);
			$toevoegen2 .= "_:gennaam-$id <https://w3id.org/pnv#" . $matches[2][$nr] . "> " . $matches[3][$nr] . " .\n";
		}

		preg_match("/\<https\:\/\/schema\.org\/name\> (.*) [;.]\s+\n/", $ntstring, $name);
		if (isset($name[1])) {
			$toevoegen2 .= "_:gennaam-$id <https://w3id.org/pnv#literalName> " . $name[1] . " .\n";
		}
		$ntstring = preg_replace("/^\s+\n/", "", $ntstring);
		$toevoegen1 = "<" . $matches[1][0] . "> <https://schema.org/additionalName> _:gennaam-$id .\n";
		$ntstring .= $toevoegen1;
		$ntstring .= $toevoegen2;
	}
	return $ntstring;
}

# Correction 3 — wrap geosparql asWKT geometries into a _:geo-<md5> hasGeometry blank
# node (via geo()), and for POLYGON geometries append an osm2rdf #area triple in m².
function cor3_geosparql_geometry($ntstring) {
	$ntstring = preg_replace_callback("/\<(.*?)\> \<http\:\/\/www.opengis.net\/ont\/geosparql\#asWKT\> \"(.*?)\"\^\^\<http:\/\/www.opengis.net\/ont\/geosparql\#wktLiteral\> \./", "geo", $ntstring);

	# _:geo-…  asWKT "POLYGON ((…))"^^wktLiteral .
	# _:geo-…  <https://osm2rdf.cs.uni-freiburg.de/rdf#area> "84"^^xsd:integer .
	$pattern = '/^(\S+)\s+<http:\/\/www\.opengis\.net\/ont\/geosparql#asWKT>\s+"(POLYGON\s*\(\(.*?\)\))"\^\^<http:\/\/www\.opengis\.net\/ont\/geosparql#wktLiteral>\s*\.$/m';
	$ntstring = preg_replace_callback($pattern, function ($matches) {
		$subject = $matches[1];
		$wkt = $matches[2];
		$m2Area = area($wkt);

		// Original line + new area triple
		$areaTriple = $subject . "  <https://osm2rdf.cs.uni-freiburg.de/rdf#area> "
			. "\"$m2Area\"^^<http://www.w3.org/2001/XMLSchema#integer> .";

		return $matches[0] . "\n" . $areaTriple;
	}, $ntstring);

	return $ntstring;
}


# ---------------------------------------------------------------------------
# schema.org reshaping transforms. All schema.org URIs are https:// (the data
# never uses the http:// variant), so the matchers are https-only.
# ---------------------------------------------------------------------------

# Deterministic blank-node label _:genid2-<sha256(bytes)> (matches the .py scripts).
function tf_blank($bytes) {
	return '_:genid2-' . hash('sha256', $bytes);
}

# Record one line rewrite in the trail that transform_resource() carries to tf23.
#
# tf23 is text-driven: it reifies an annotated value by finding the line that carries it.
# A transform that folds a value into a _:genid2 DefinedTerm destroys both the predicate and
# the object, so without this trail tf23 finds nothing and drops the annotation (10 annotated
# schema:hasOccupation literals were lost this way). The entry lets it follow the fold and
# reify the triple that DOES exist afterwards, keeping the invariant that a reification never
# points at a triple that is not in the output.
#
# $obj/$newObj are recorded as bare terms (no trailing " ."); $pred/$newPred keep their angle
# brackets, exactly as they appear in the line. $trail is null for callers that do not want a
# trail (the content-negotiation resolvers run no transforms at all), and then this is a no-op.
function tf_trail_note(&$trail, $subject, $pred, $obj, $newPred, $newObj) {
	if ($trail === null) return;
	$trail[] = [
		'subject' => $subject,
		'pred'    => $pred,
		'obj'     => $obj,
		'newpred' => $newPred,
		'newobj'  => $newObj,
	];
}

# First N-Triples quoted literal at the start of $s, or null. Mirrors the Python
# QUOTED regex rb'"(?:[^"\\]|\\.)*"'.
function tf_quoted($s) {
	if (preg_match('/^"(?:[^"\\\\]|\\\\.)*"/', $s, $m)) {
		return $m[0];
	}
	return null;
}

# Emit the three N-triples for an item's IIIF Presentation API manifest as a
# schema:associatedMedia entry (manifest URI + its CC0 license + IIIF v3 encodingFormat).
# Per SCHEMA-AP-NDE the manifest is identified by its encodingFormat, not by a MediaObject type.
# Shared by tf1 (schema:image trigger) and tf15 (o:Item+ImageObject trigger) so both emit
# byte-identical triples (keeps sort_unique dedup stable across resources).
function iiif_manifest_triples($subject, $rid) {
	$manifest = 'https://www.goudatijdmachine.nl/omeka/iiif/3/' . $rid . '/manifest';
	return '<' . $subject . '> <https://schema.org/associatedMedia> <' . $manifest . "> .\n"
	     . '<' . $manifest . '> <https://schema.org/license> <https://creativecommons.org/publicdomain/zero/1.0/> .' . "\n"
	     . '<' . $manifest . '> <https://schema.org/encodingFormat> "application/ld+json;profile=\'http://iiif.io/api/presentation/3/context.json\'" .' . "\n";
}

# tf1 — add IIIF associatedMedia/manifest triples for each items/ subject with a schema:image.
function tf1_associatedmedia($ntstring) {
	$seen = [];
	$app = '';
	foreach (explode("\n", $ntstring) as $line) {
		if (preg_match('#^<(https://www\.goudatijdmachine\.nl/omeka/api/items/([^/>]+))> <https://schema\.org/image>#', $line, $m)) {
			$subject = $m[1];
			$rid = $m[2];
			if (isset($seen[$rid])) continue;
			$seen[$rid] = true;
			$app .= iiif_manifest_triples($subject, $rid);
		}
	}
	return $ntstring . $app;
}

# tf15 — reshape an Omeka image-bank record (typed BOTH o:Item AND schema:ImageObject) into a
# schema:CreativeWork that carries its imagery via schema:associatedMedia, per SCHEMA-AP-NDE.
#
#   1. Retype the item: schema:ImageObject -> schema:CreativeWork. tf2 then drops the redundant
#      o:Item (CreativeWork is in TF_OTHER_CLASSES), so the object ends up `a schema:CreativeWork`.
#   2. Add the item's IIIF Presentation manifest as an associatedMedia entry (iiif_manifest_triples).
#   3. Add a per-image blank node `_:media-<id>` typed schema:MediaObject + schema:ImageObject with
#      contentUrl/thumbnailUrl, so non-IIIF consumers can render the image. The blank node (vs a URI)
#      is deliberate: cached_rdf.php only expands object nodes one level when isblank(?o), so a blank
#      node's properties surface in the per-resource turtle view.
#
# Keys on the o:Item+ImageObject co-occurrence (precise: standalone image media are typed o:Media,
# never o:Item). Runs before tf2 (needs the o:Item type still present) and before tf9 (subject still
# the api-URI, which tf9 then swaps to the ARK). Per-image URLs come from one batched media lookup
# (int-cast ids -> injection-safe, like tf9/tf13); missing media -> manifest+retype only.
function tf15_imageobject_media($ntstring, $id, $mysqli) {
	# Find subjects (api/items/<rid>) carrying both rdf:type o:Item and schema:ImageObject.
	$lines = explode("\n", $ntstring);
	$isItem = [];
	$isImage = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) < 3 || $p[1] !== TF_RDF_TYPE) continue;
		if ($p[2] === TF_CLASS_ITEM) $isItem[$p[0]] = true;
		elseif ($p[2] === '<https://schema.org/ImageObject>') $isImage[$p[0]] = true;
	}
	$subjects = [];   // "<api-uri>" => rid
	foreach ($isImage as $subj => $_) {
		if (!isset($isItem[$subj])) continue;
		if (preg_match('#^<https://www\.goudatijdmachine\.nl/omeka/api/items/([^/>]+)>$#', $subj, $m)) {
			$subjects[$subj] = $m[1];
		}
	}
	if (!$subjects) return $ntstring;

	# 1. Retype schema:ImageObject -> schema:CreativeWork on the matched item subjects.
	$out = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && $p[2] === '<https://schema.org/ImageObject>'
			&& isset($subjects[$p[0]])) {
			$line = $p[0] . ' ' . TF_RDF_TYPE . ' <https://schema.org/CreativeWork> .';
		}
		$out[] = $line;
	}
	$ntstring = implode("\n", $out);

	# 4. One batched lookup of each item's representative media: its primary media if set,
	# else the first media by position (handles items that carry media but no primary). Items
	# with NO media at all return no row and get the type fix only (no empty manifest linked).
	$rids = array_map('intval', array_values($subjects));
	$idlist = implode(',', array_values(array_unique($rids)));
	$media = [];   // item_id => row
	$res = $mysqli->query(
		'SELECT i.id AS item_id, m.source, m.storage_id, m.ingester
		   FROM `item` i
		   JOIN `media` m ON m.id = COALESCE(
		        i.primary_media_id,
		        (SELECT m2.id FROM `media` m2 WHERE m2.item_id = i.id
		          ORDER BY m2.position IS NULL, m2.position, m2.id LIMIT 1))
		  WHERE i.id IN (' . $idlist . ')'
	);
	if ($res) {
		while ($row = $res->fetch_assoc()) {
			if (!isset($media[(int)$row['item_id']])) $media[(int)$row['item_id']] = $row;
		}
	}

	# 2./3. Per subject WITH media: append the IIIF manifest entry, then the representative
	# per-image MediaObject node. Mediumless items get neither (just the CreativeWork retype).
	$app = '';
	foreach ($subjects as $subj => $rid) {
		$urls = tf15_image_urls($media[(int)$rid] ?? null);
		if ($urls === null) continue;   // no media -> type fix only (avoid empty-manifest link)
		$subject = substr($subj, 1, -1);   // strip the < >
		$app .= iiif_manifest_triples($subject, $rid);

		$blank = '_:media-' . $rid;
		$app .= $subj . ' <https://schema.org/associatedMedia> ' . $blank . " .\n";
		$app .= $blank . ' ' . TF_RDF_TYPE . ' <https://schema.org/MediaObject> .' . "\n";
		$app .= $blank . ' ' . TF_RDF_TYPE . ' <https://schema.org/ImageObject> .' . "\n";
		$app .= $blank . ' <https://schema.org/contentUrl> <' . $urls['content'] . '> .' . "\n";
		$app .= $blank . ' <https://schema.org/thumbnailUrl> <' . $urls['thumb'] . '> .' . "\n";
		$app .= $blank . ' <https://schema.org/license> <https://creativecommons.org/publicdomain/zero/1.0/> .' . "\n";
	}
	return $ntstring . $app;
}

# Derive [contentUrl, thumbnailUrl] for a media row, or null when no usable media.
# IIIF media (ingester=iiif, source ends /info.json): use the Memorix IIIF Image API
# (.../full/max/0/default.jpg + .../full/!256,256/0/default.jpg). Otherwise fall back to
# this site's local Omeka derivative files keyed on storage_id (large + square).
function tf15_image_urls($row) {
	if ($row === null) return null;
	$source = $row['source'] ?? '';
	if (($row['ingester'] ?? '') === 'iiif' && substr($source, -10) === '/info.json') {
		$base = substr($source, 0, -10);   // strip '/info.json'
		return [
			'content' => $base . '/full/max/0/default.jpg',
			'thumb'   => $base . '/full/!256,256/0/default.jpg',
		];
	}
	$storage = $row['storage_id'] ?? '';
	if ($storage !== '') {
		return [
			'content' => 'https://www.goudatijdmachine.nl/omeka/files/large/' . $storage . '.jpg',
			'thumb'   => 'https://www.goudatijdmachine.nl/omeka/files/square/' . $storage . '.jpg',
		];
	}
	return null;
}

# tf2 — drop <s> rdf:type <o#Item> when s is also typed as one of TF_OTHER_CLASSES.
function tf2_remove_itemtype($ntstring) {
	static $other = null;
	if ($other === null) $other = array_flip(TF_OTHER_CLASSES);
	$lines = explode("\n", $ntstring);
	$subjects = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && isset($other[$p[2]])) {
			$subjects[$p[0]] = true;
		}
	}
	$out = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && $p[2] === TF_CLASS_ITEM && isset($subjects[$p[0]])) {
			continue; // drop the redundant o:Item type
		}
		$out[] = $line;
	}
	return implode("\n", $out);
}

# tf3 — o:modified -> schema:sdDatePublished (predicate-only, delimited replace).
function tf3_convert_modified($ntstring) {
	return str_replace('<http://omeka.org/s/vocabs/o#modified>', '<https://schema.org/sdDatePublished>', $ntstring);
}

# tf19 — lokale media (ingester upload/sideload/…) hebben per Omeka-semantiek de
# oorspronkelijke bestandsnaam als o:source ("MIN08046B01.jpg"); iiif-ingested
# media hebben daar hun info.json-URL. Consumenten (o.a. de api-viewer) leunen
# op dat laatste. Voor media-subjecten met een niet-http o:source wordt de
# waarde vervangen door de lokale IIIF Image API-URL (IiifServer-module),
# zodat élke media-o:source een raadpleegbare IIIF-resource is. Media met een
# http-source (Memorix/kranten-URL's) blijven ongemoeid.
function tf19_local_media_iiif($ntstring) {
	return preg_replace_callback(
		'~^(<https://www\.goudatijdmachine\.nl/omeka/api/media/(\d+)> <http://omeka\.org/s/vocabs/o#source> ")(?!https?://)[^"]*("[^\n]*\.)$~m',
		function ($m) {
			return $m[1] . 'https://www.goudatijdmachine.nl/omeka/iiif/2/' . $m[2] . '/info.json' . $m[3];
		},
		$ntstring
	);
}

# tf20 — een OAT-regel (Oorspronkelijke Aanwijzende Tafel, kadaster 1832) ís
# inhoudelijk een persoonsvermelding: de eigenaar met naam/voornaam/achternaam,
# gekoppeld aan perceel en plaatselijke aanduiding. Typeer hem additioneel als
# pico:PersonObservation + schema:Person (met registratiejaar 1832 als
# schema:dateCreated), zodat de persoonsreconstructie-pipeline en de
# api-viewer hem meenemen. (schema:Person expliciet: tf2 verwijdert het
# o:Item-type van OAT-regels vóórdat tf4 het zou kunnen omzetten.)
const TF_CLASS_OATREGEL = '<https://www.goudatijdmachine.nl/def#OorspronkelijkeAanwijzendeTafelRegel>';
function tf20_oat_personobservation($ntstring) {
	# Append newline-TERMINATED lines (as tf18 does), not newline-PREFIXED ones: the
	# input already ends in "\n", so prefixing inserted a blank line and left the
	# result without a trailing newline — _prepare_collect_nt.py concatenates stored
	# documents verbatim, so the next document's first triple ended up glued onto this
	# one's last line.
	$app = '';
	foreach (explode("\n", $ntstring) as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && $p[2] === TF_CLASS_OATREGEL) {
			$app .= $p[0] . ' ' . TF_RDF_TYPE . ' ' . TF_PERSON_OBS . " .\n";
			$app .= $p[0] . ' ' . TF_RDF_TYPE . ' <https://schema.org/Person> .' . "\n";
			$app .= $p[0] . ' <https://schema.org/dateCreated> "1832"^^<http://www.w3.org/2001/XMLSchema#string> .' . "\n";
		}
	}
	if ($app === '') return $ntstring;
	if ($ntstring !== '' && substr($ntstring, -1) !== "\n") $ntstring .= "\n";
	return $ntstring . $app;
}

# tf4 — subject typed o:Item AND pico:PersonObservation -> its o:Item type becomes schema:Person.
function tf4_convert_persontype($ntstring) {
	$lines = explode("\n", $ntstring);
	$persons = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && $p[2] === TF_PERSON_OBS) {
			$persons[$p[0]] = true;
		}
	}
	$out = [];
	foreach ($lines as $line) {
		if (strpos($line, TF_CLASS_ITEM) !== false) {
			$p = explode(' ', $line, 4);
			if (count($p) >= 3 && $p[1] === TF_RDF_TYPE && $p[2] === TF_CLASS_ITEM && isset($persons[$p[0]])) {
				$line = str_replace(TF_CLASS_ITEM, '<https://schema.org/Person>', $line);
			}
		}
		$out[] = $line;
	}
	return implode("\n", $out);
}

# tf5 — remaining o:Item type -> schema:CreativeWork (delimited replace, o#ItemSet safe).
function tf5_convert_itemtype($ntstring) {
	return str_replace('<http://omeka.org/s/vocabs/o#Item>', '<https://schema.org/CreativeWork>', $ntstring);
}

# Shared fold: repoint a property's object at a deterministic _:genid2-<sha256>
# schema:DefinedTerm blank node and emit that DefinedTerm once, with its name taken
# from the object literal (@nl) or the term's inline o:label (which is then deleted).
#
#   $needles      substrings for the fast line prefilter (predicate local names)
#   $preds        set (URI => 1) of full predicate IRIs that trigger the fold
#   $outPred      replacement predicate IRI, or null to keep the original predicate
#   $types        rdf:type object IRIs to give the DefinedTerm (besides being one)
#   $literals     also fold literal objects (name = the literal @nl, no sameAs)
#   $foldUris     fold URI objects into a sameAs DefinedTerm (true); when false, URI
#                 objects are left as direct references (the target is already a typed,
#                 named resource, e.g. a Place) and only literals are folded
#   $trail        rewrite trail (by reference): every replaced line is appended as
#                 [subject, old predicate, old object term, new predicate, new object term].
#                 tf23 uses it to reify the FOLDED triple for an annotated value whose
#                 original triple no longer exists — see tf_trail_note() below.
function tf_fold_definedterm($ntstring, array $needles, array $preds, $outPred, array $types, $literals, $foldUris = true, $requireLabel = false, array &$trail = null) {
	$hit = static function ($line) use ($needles) {
		foreach ($needles as $n) {
			if (strpos($line, $n) !== false) return true;
		}
		return false;
	};

	$lines = explode("\n", $ntstring);

	# When $requireLabel, pre-collect the set of subjects that carry an inline o:label, so
	# only trigger-predicate URIs that are actually labelled here get folded; unlabelled
	# ones fall through and stay bare references (they are already typed+named elsewhere, so
	# folding would mint a nameless DefinedTerm). Skipped entirely when $requireLabel is
	# false, so existing callers (tf6/tf7/tf8/tf11) are byte-for-byte unaffected.
	$labelled = [];
	if ($requireLabel) {
		foreach ($lines as $line) {
			if (strpos($line, '#label>') === false) continue;
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && $p[1] === TF_O_LABEL) $labelled[$p[0]] = true;
		}
	}

	# Pass 1: collect the URI objects of the trigger predicates (skipped when $foldUris
	# is false, so their o:labels are left intact and no sameAs term is emitted for them).
	$uris = [];
	if ($foldUris) {
		foreach ($lines as $line) {
			if ($hit($line)) {
				$p = explode(' ', $line, 3);
				if (count($p) >= 3 && isset($preds[$p[1]]) && isset($p[2][0]) && $p[2][0] === '<') {
					$u = explode(' ', $p[2], 2)[0];
					if (!$requireLabel || isset($labelled[$u])) $uris[$u] = true;
				}
			}
		}
	}

	# Pass 2: repoint links to blank nodes; drop+capture the URIs' o:label.
	$out = [];
	$uriLabels = [];   // <uri> => first schema:name literal ("…"@nl)
	$litNames = [];    // blank => schema:name literal for a literal object
	foreach ($lines as $line) {
		if ($hit($line)) {
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && isset($preds[$p[1]])) {
				$pred = ($outPred !== null) ? $outPred : $p[1];
				$obj = $p[2];
				if ($foldUris && $obj[0] === '<') {
					$uri = explode(' ', $obj, 2)[0];
					if (!$requireLabel || isset($labelled[$uri])) {
						$blank = tf_blank(substr($uri, 1, -1));
						$out[] = $p[0] . ' ' . $pred . ' ' . $blank . ' .';
						tf_trail_note($trail, $p[0], $p[1], $uri, $pred, $blank);
						continue;
					}
					# unlabelled URI under $requireLabel: fall through -> kept as a bare reference
				}
				if ($literals && $obj[0] === '"') {
					$q = tf_quoted($obj);
					if ($q !== null) {
						$blank = tf_blank(substr($q, 1, -1));
						$out[] = $p[0] . ' ' . $pred . ' ' . $blank . ' .';
						tf_trail_note($trail, $p[0], $p[1], $q, $pred, $blank);
						if (!isset($litNames[$blank])) $litNames[$blank] = $q . '@nl';
						continue;
					}
				}
			}
		} elseif (strpos($line, '#label>') !== false) {
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && $p[1] === TF_O_LABEL && isset($uris[$p[0]])) {
				if (!isset($uriLabels[$p[0]])) {           // first label wins (one schema:name)
					$q = tf_quoted($p[2]);
					if ($q !== null) $uriLabels[$p[0]] = $q . '@nl';
				}
				continue;  // delete the term's o:label triple
			}
		}
		$out[] = $line;
	}
	$ntstring = implode("\n", $out);

	# Emit each DefinedTerm definition exactly once.
	$app = '';
	foreach ($uris as $uri => $_) {
		$blank = tf_blank(substr($uri, 1, -1));
		foreach ($types as $t) $app .= $blank . ' ' . TF_RDF_TYPE . ' ' . $t . ' .' . "\n";
		if (isset($uriLabels[$uri])) $app .= $blank . ' <https://schema.org/name> ' . $uriLabels[$uri] . ' .' . "\n";
		$app .= $blank . ' <https://schema.org/sameAs> ' . $uri . ' .' . "\n";
	}
	foreach ($litNames as $blank => $name) {
		foreach ($types as $t) $app .= $blank . ' ' . TF_RDF_TYPE . ' ' . $t . ' .' . "\n";
		$app .= $blank . ' <https://schema.org/name> ' . $name . ' .' . "\n";
	}
	return $ntstring . $app;
}

# tf6 — schema:additionalType <TERM> -> _:genid2 DefinedTerm/URL with sameAs + name
# (from the term's inline o:label). additionalType objects are always URIs.
function tf6_convert_additionaltype($ntstring, array &$trail = null) {
	return tf_fold_definedterm(
		$ntstring,
		['additionalType'],
		['<https://schema.org/additionalType>' => 1],
		null,                                          // keep the additionalType predicate
		['<https://schema.org/DefinedTerm>', '<https://schema.org/URL>'],
		false,                                         // no literal objects
		true,                                          // fold URI objects
		false,                                         // no inline-label requirement
		$trail
	);
}

# tf17 — schema:about <TERM> -> _:genid2 DefinedTerm with sameAs + name (from the term's
# inline o:label), but ONLY for about URIs that carry an inline o:label in THIS resource
# (external authority terms: personsincontext, geonames, goudsvrouwennetwerk, omeka data
# pages). about URIs WITHOUT an inline label (internal ARK/api refs to full separately-
# harvested resources, and internal page refs) are LEFT as direct bare references: those
# targets are already typed+named in their own resource (or satisfy schema:about's
# CreativeWork branch), and folding them would mint a nameless DefinedTerm that fails
# SCHEMA-AP-NDE's DefinedTermShape (schema:name sh:minCount 1, rdf:langString) — the same
# reason tf8/tf16 reference URI-valued places directly.
#
# The blank node is byte-identical to tf6's (same tf_blank(sha256)), so a URI used as BOTH
# schema:additionalType (folded by tf6) AND schema:about (e.g.
# .../ThesaurusHistorischePersoonsgegevens/551) collapses onto one shared DefinedTerm after
# _prepare_sort_unique.py. MUST run before tf6 (which consumes that shared URI's o:label)
# and before tf9_swap_uri (internal api refs left direct here are swapped to ARK there).
# about objects are URI-valued in this data, so no literal folding is needed.
function tf17_convert_about($ntstring, array &$trail = null) {
	return tf_fold_definedterm(
		$ntstring,
		['about'],
		['<https://schema.org/about>' => 1],
		null,                                          // keep the about predicate
		['<https://schema.org/DefinedTerm>'],          // DefinedTerm only (no schema:URL)
		false,                                         // no literal objects
		true,                                          // fold URI objects...
		true,                                          // ...but ONLY those with an inline o:label
		$trail
	);
}

# tf7 — schema:hasOccupation -> schema:additionalType _:genid2 DefinedTerm/Occupation
# (literal occupation name @nl, or URI with inline o:label + sameAs).
function tf7_convert_hasoccupation($ntstring, array &$trail = null) {
	return tf_fold_definedterm(
		$ntstring,
		['hasOccupation'],
		['<https://schema.org/hasOccupation>' => 1],
		'<https://schema.org/additionalType>',         // rewrite predicate to additionalType
		['<https://schema.org/DefinedTerm>', '<https://schema.org/Occupation>'],
		true,                                          // fold literal occupation names
		true,                                          // fold URI objects
		false,                                         // no inline-label requirement
		$trail
	);
}

# tf8 — schema:birthPlace / schema:deathPlace. A literal place name is folded into a
# _:genid2 DefinedTerm/Place carrying that name (@nl). A URI value is left as a direct
# reference: it already points at a typed, named Place resource (its name lives in that
# resource, not inline here), so folding it would mint a nameless DefinedTerm that fails
# the NDE DefinedTerm/Place shapes — hence $foldUris = false.
function tf8_convert_places($ntstring, array &$trail = null) {
	return tf_fold_definedterm(
		$ntstring,
		['birthPlace', 'deathPlace'],
		['<https://schema.org/birthPlace>' => 1, '<https://schema.org/deathPlace>' => 1],
		null,                                          // keep birthPlace/deathPlace
		['<https://schema.org/DefinedTerm>', '<https://schema.org/Place>'],
		true,                                          // fold literal place names
		false,                                         // but reference URI places directly
		false,                                         // no inline-label requirement
		$trail
	);
}

# tf16 — materialise EXTERNAL labelled place URIs into named schema:Place nodes.
#
# SCHEMA-AP-NDE's PlaceShape/DefinedTermShape require each value of schema:birthPlace,
# schema:deathPlace, schema:location, schema:locationCreated and schema:contentLocation
# to be a typed, named Place. Internal ARK place refs already satisfy this — their
# rdf:type + schema:name live in their own separately-harvested resource (pulled in by
# the validation CONSTRUCT) and carry NO inline o:label here — so they must stay direct.
#
# External authority URIs (gemeentegeschiedenis.nl, geonames.org, …) arrive with ONLY an
# inline Omeka o:label (+ optional o:lang) and no type/name, so a bare reference fails the
# shapes. Keyed exactly on "the object URI carries an inline o:label", this keeps the
# direct predicate reference and emits on that same URI:
#     <uri> rdf:type schema:Place .
#     <uri> schema:name "<label>"@<lang> .
# while consuming (deleting) that URI's o:label and o:lang lines. tf8 already folds LITERAL
# place names into _:genid2 DefinedTerms, so this only sees URI objects; it never touches a
# URI lacking an inline label (ARK refs), so currently-passing refs are intact.
#
# Type is schema:Place only: it satisfies PlaceShape for all five predicates AND
# schema:location's extra sh:class schema:Place (a DefinedTerm type would not).
#
# Runs after tf8 (literals already folded) and before tf9_swap_uri (these external URIs are
# neither api nor ARK URIs, so tf9 ignores them — ordering vs tf9 is irrelevant).
function tf16_materialise_external_places($ntstring) {
	static $preds = [
		'<https://schema.org/birthPlace>'      => 1,
		'<https://schema.org/deathPlace>'      => 1,
		'<https://schema.org/location>'        => 1,
		'<https://schema.org/locationCreated>' => 1,
		'<https://schema.org/contentLocation>' => 1,
	];
	static $needles = ['birthPlace', 'deathPlace', 'location', 'locationCreated', 'contentLocation'];

	$hit = static function ($line) use ($needles) {
		foreach ($needles as $n) {
			if (strpos($line, $n) !== false) return true;
		}
		return false;
	};

	$lines = explode("\n", $ntstring);

	# Pass 1: collect URI objects of the five place predicates — the only URIs whose inline
	# o:label/o:lang we may consume (literal objects are tf8's job and start with '"').
	$placeUris = [];
	foreach ($lines as $line) {
		if (!$hit($line)) continue;
		$p = explode(' ', $line, 3);
		if (count($p) >= 3 && isset($preds[$p[1]]) && isset($p[2][0]) && $p[2][0] === '<') {
			$placeUris[explode(' ', $p[2], 2)[0]] = true;   // "<uri>"
		}
	}
	if (!$placeUris) return $ntstring;

	# Pass 2: capture+delete each place URI's inline o:label (name) and o:lang (tag); the
	# direct predicate line and all other lines (incl. non-place URIs' labels) pass through.
	$out = [];
	$labels = [];   // "<uri>" => "…" quoted literal (first label wins -> byte-stable)
	$langs  = [];   // "<uri>" => "nl" (first lang wins)
	foreach ($lines as $line) {
		if (strpos($line, '#label>') !== false || strpos($line, '#lang>') !== false) {
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && isset($placeUris[$p[0]])) {
				if ($p[1] === TF_O_LABEL) {
					if (!isset($labels[$p[0]])) {
						$q = tf_quoted($p[2]);
						if ($q !== null) $labels[$p[0]] = $q;
					}
					continue;   // consume the o:label line
				}
				if ($p[1] === TF_O_LANG) {
					if (!isset($langs[$p[0]])) {
						$q = tf_quoted($p[2]);
						if ($q !== null) {
							$tag = substr($q, 1, -1);   // strip the surrounding quotes
							if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $tag)) $langs[$p[0]] = $tag;
						}
					}
					continue;   // consume the o:lang line
				}
			}
		}
		$out[] = $line;
	}
	$ntstring = implode("\n", $out);

	# Emit rdf:type schema:Place + schema:name@lang once per LABELLED place URI. A place URI
	# with no captured label (ARK refs, or one that only had an o:lang) gets nothing — it
	# stays a bare direct reference, unchanged.
	$app = '';
	foreach ($labels as $uri => $name) {
		$tag = $langs[$uri] ?? 'nl';
		$app .= $uri . ' ' . TF_RDF_TYPE . ' <https://schema.org/Place> .' . "\n";
		$app .= $uri . ' <https://schema.org/name> ' . $name . '@' . $tag . ' .' . "\n";
	}
	return $ntstring . $app;
}

# tf10 — normalise schema:identifier objects that Omeka emits as a language-tagged
# literal ("…"@nl) to a plain xsd:string. The SCHEMA-AP-NDE profile requires the value
# to be an xsd:string or a schema:PropertyValue; an rdf:langString satisfies neither and
# is meaningless on an identifier URI. Most records already carry the explicit
# "…"^^xsd:string form, so emitting that exact byte-form keeps sort_unique dedup stable.
function tf10_string_identifier($ntstring) {
	return preg_replace(
		'#(<https://schema\.org/identifier> "(?:[^"\\\\]|\\\\.)*")@[A-Za-z][A-Za-z0-9-]* \.#',
		'$1^^<http://www.w3.org/2001/XMLSchema#string> .',
		$ntstring
	);
}

# tf11 — fold a LITERAL schema:creator ("Daems, H.J."^^xsd:string or @nl) into a
# deterministic _:genid2-<sha256> node typed schema:DefinedTerm + schema:Person, with
# the literal carried as schema:name (@nl). The SCHEMA-AP-NDE profile requires creator
# to be a Person/Organization/CreatorShape *node*, never a bare literal; PersonShape's
# only required property is that langString name, which this node satisfies.
#
# URI creators (e.g. <…/ark…> already typed schema:Person) are left untouched — only
# literal objects are folded. Like tf7/tf8 the blank is keyed on the name bytes, so
# identical creators across resources collapse to one DefinedTerm after sort_unique.
function tf11_literal_creator($ntstring, array &$trail = null) {
	$lines = explode("\n", $ntstring);
	$out = [];
	$names = [];   // blank => schema:name literal ("…"@nl)
	foreach ($lines as $line) {
		if (strpos($line, 'creator') !== false) {
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && $p[1] === '<https://schema.org/creator>'
				&& isset($p[2][0]) && $p[2][0] === '"') {
				$q = tf_quoted($p[2]);
				if ($q !== null) {
					$blank = tf_blank(substr($q, 1, -1));
					$out[] = $p[0] . ' <https://schema.org/creator> ' . $blank . ' .';
					tf_trail_note($trail, $p[0], $p[1], $q, $p[1], $blank);
					if (!isset($names[$blank])) $names[$blank] = $q . '@nl';
					continue;
				}
			}
		}
		$out[] = $line;
	}
	$ntstring = implode("\n", $out);

	$app = '';
	foreach ($names as $blank => $name) {
		$app .= $blank . ' ' . TF_RDF_TYPE . ' <https://schema.org/DefinedTerm> .' . "\n";
		$app .= $blank . ' ' . TF_RDF_TYPE . ' <https://schema.org/Person> .' . "\n";
		$app .= $blank . ' <https://schema.org/name> ' . $name . ' .' . "\n";
	}
	return $ntstring . $app;
}

# tf12 — add schema:latitude/longitude (xsd:double) to each schema:GeoCoordinates node
# from its geosparql POINT geometry. The NDE profile requires a GeoCoordinates value to
# carry both as doubles; this data only models position as a WKT POINT (lon lat) on a
# cor3-built hasGeometry node. Purely additive: the geosparql geometry is kept, and
# GeoShape nodes / non-POINT geometries are left untouched.
function tf12_geocoordinates_latlong($ntstring) {
	$lines = explode("\n", $ntstring);

	# Pass 1: POINT geometry blank node -> [lon, lat] (WKT axis order is lon, lat).
	$points = [];
	foreach ($lines as $line) {
		if (strpos($line, '#asWKT>') === false || strpos($line, 'POINT') === false) continue;
		if (preg_match('/^(_:\S+)\s+<http:\/\/www\.opengis\.net\/ont\/geosparql#asWKT>\s+'
			. '"POINT\s*\(\s*([0-9.eE+-]+)\s+([0-9.eE+-]+)\s*\)"/', $line, $m)) {
			$points[$m[1]] = [$m[2], $m[3]];
		}
	}
	if (!$points) return $ntstring;

	# Pass 2: map each subject to its geometry node and whether it's a GeoCoordinates.
	$geomOf = [];
	$isGeoCoord = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) < 4) continue;
		if ($p[1] === '<http://www.opengis.net/ont/geosparql#hasGeometry>') {
			$geomOf[$p[0]] = $p[2];
		} elseif ($p[1] === TF_RDF_TYPE && $p[2] === '<https://schema.org/GeoCoordinates>') {
			$isGeoCoord[$p[0]] = true;
		}
	}

	# Emit schema:latitude/longitude on each GeoCoordinates POINT.
	$app = '';
	foreach ($geomOf as $subj => $geom) {
		if (!isset($isGeoCoord[$subj]) || !isset($points[$geom])) continue;
		list($lon, $lat) = $points[$geom];
		$app .= $subj . ' <https://schema.org/latitude> "'  . $lat . '"^^<http://www.w3.org/2001/XMLSchema#double> .' . "\n";
		$app .= $subj . ' <https://schema.org/longitude> "' . $lon . '"^^<http://www.w3.org/2001/XMLSchema#double> .' . "\n";
	}
	return $ntstring . $app;
}

# tf14 — normalise a free-text schema:temporalCoverage *literal* into valid ISO-8601. The
# NDE profile requires each value to be an HTTP(S) URI or an ISO date/interval; this only
# touches the date-like literals (years, ranges, centuries). A mixed "year / century" value
# is split into multiple temporalCoverage triples (the property has no maxCount). Named ABR
# period literals ("Nieuwe Tijd", …), "21a", "tot de Reformatie" and any unparseable value
# are left untouched (a separate name->ABR-URI mapping task), as are URI values.
#
# Returns the list of ISO atoms for one raw value (empty -> caller leaves the value as-is).
function tc_atoms($raw) {
	$raw = trim($raw);
	# A single date atom (no interval): YYYY[-MM[-DD[Thh:mm[:ss]]]], optional leading '-', or '..'.
	$atom = '/^(-?\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2})?)?)?)?|\.\.)$/';
	# The full profile form: an atom, optionally '/' then an end (atom | 2-digit | '..').
	$full = '#^(-?\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2})?)?)?)?|\.\.)(/(-?\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2})?)?)?)?|\d{2}(-\d{2})?|\.\.))?$#';
	if (preg_match($full, $raw)) return [$raw];   // already valid (bare year, unspaced range)

	$atoms = [];
	foreach (preg_split('#\s*/\s*#', $raw) as $tok) {
		$tok = trim($tok);
		if ($tok === '') continue;
		if (preg_match($atom, $tok)) { $atoms[] = $tok; continue; }              // valid atom
		if (preg_match('/^(\d{4})-(\d{4})$/', $tok, $m)) { $atoms[] = $m[1] . '/' . $m[2]; continue; }  // dash range
		if (preg_match('/^(\d{1,2})e$/', $tok, $m)) {                            // century (strict)
			$n = (int)$m[1];
			$atoms[] = sprintf('%04d/%04d', ($n - 1) * 100 + 1, $n * 100);
			continue;
		}
		if (preg_match('/^(\d{4})-$/', $tok, $m)) { $atoms[] = $m[1] . '/..'; continue; }  // open-ended
		# else: unparseable token (named period, "21a", free text) -> skip
	}
	return array_values(array_unique($atoms));
}

function tf14_normalise_temporalcoverage($ntstring) {
	if (strpos($ntstring, 'temporalCoverage') === false) return $ntstring;
	$lines = explode("\n", $ntstring);
	$out = [];
	foreach ($lines as $line) {
		if (strpos($line, 'temporalCoverage') !== false) {
			$p = explode(' ', $line, 3);
			if (count($p) >= 3 && $p[1] === '<https://schema.org/temporalCoverage>'
				&& isset($p[2][0]) && $p[2][0] === '"') {
				$q = tf_quoted($p[2]);
				if ($q !== null) {
					$raw = substr($q, 1, -1);
					$atoms = tc_atoms($raw);
					if ($atoms && !(count($atoms) === 1 && $atoms[0] === $raw)) {
						foreach ($atoms as $a) {
							$out[] = $p[0] . ' <https://schema.org/temporalCoverage> "' . $a
								. '"^^<http://www.w3.org/2001/XMLSchema#string> .';
						}
						continue;
					}
				}
			}
		}
		$out[] = $line;
	}
	return implode("\n", $out);
}

# tf13 — collapse a schema:dateCreated whose object is a ric:DateRange *resource* into a
# single ISO-8601 literal, as the NDE profile requires (xsd:date / xsd:gYearMonth /
# xsd:gYear, maxCount 1). Runs BEFORE tf9, so the object is still an api-URI
# <…/api/items/N> from which N (the DateRange's resource_id) is parsed. The ISO value is
# the *beginning* SingleDate's ric:normalizedDateValue, reached in one self-join on the
# Omeka `value` table (DateRange --hasBeginningDate--> SingleDate --normalizedDateValue-->).
# Clean ISO values are kept verbatim; a non-clean value is reduced to its 4-digit year, and
# anything that can't yield one is left unchanged (the DateRange ref stays — nothing invented).
function tf13_collapse_datecreated($ntstring, $id, $mysqli) {
	# Collect the DateRange resource_ids referenced by schema:dateCreated (api-URI form).
	if (!preg_match_all(
		'#<https://schema\.org/dateCreated> <https://www\.goudatijdmachine\.nl/omeka/api/(?:items|resources)/([0-9]+)>#',
		$ntstring, $m)) {
		return $ntstring;
	}
	$ids = array_values(array_unique(array_map('intval', $m[1])));
	$idlist = implode(',', $ids);   // int-cast above -> injection-safe

	# One self-join: DateRange id -> its beginning SingleDate's normalizedDateValue.
	$norm = [];
	$res = $mysqli->query(
		'SELECT bd.resource_id AS dr, nv.value AS norm
		   FROM `value` bd
		   JOIN `value` nv ON nv.resource_id = bd.value_resource_id
		                  AND nv.property_id = ' . TF_PROPERTY_NORMALIZEDDATE . '
		  WHERE bd.property_id = ' . TF_PROPERTY_HASBEGINDATE . '
		    AND bd.resource_id IN (' . $idlist . ')'
	);
	if ($res) {
		while ($row = $res->fetch_assoc()) {
			if (!isset($norm[$row['dr']])) $norm[(int)$row['dr']] = $row['norm'];
		}
	}
	if (!$norm) return $ntstring;

	# Derive a typed ISO literal from the beginning value, or null to leave the ref alone.
	$literalOf = static function ($v) {
		$xsd = 'http://www.w3.org/2001/XMLSchema#';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '"' . $v . '"^^<' . $xsd . 'date> .';
		if (preg_match('/^\d{4}-\d{2}$/', $v))       return '"' . $v . '"^^<' . $xsd . 'gYearMonth> .';
		if (preg_match('/^\d{4}$/', $v))             return '"' . $v . '"^^<' . $xsd . 'gYear> .';
		$digits = preg_replace('/\D+/', '', $v);     // fallback: strip to a bare 4-digit year
		if (strlen($digits) === 4)                   return '"' . $digits . '"^^<' . $xsd . 'gYear> .';
		return null;
	};

	# Replace each dateCreated -> DateRange object with the literal (subject/predicate kept).
	foreach ($norm as $dr => $v) {
		$lit = $literalOf($v);
		if ($lit === null) continue;
		$ntstring = preg_replace(
			'#(<https://schema\.org/dateCreated>) <https://www\.goudatijdmachine\.nl/omeka/api/(?:items|resources)/' . $dr . '> \.#',
			'$1 ' . str_replace('\\', '\\\\', $lit),
			$ntstring
		);
	}
	return $ntstring;
}

# tf9 — swap Omeka api URIs <-> ARK PIDs. Self ark comes from the inline owl:sameAs;
# cross-referenced resources' ARKs are looked up in MySQL (value.uri, property_id=1177).
# Derived media ARKs: <item-ark>/<media-id>, for the media ids passed in.
#
# No media carries an ARK of its own. The Omeka Ark module is configured with
# ark_qualifier_static = "0" and ark_qualifier = "internal", so a media is not given a
# name — it is addressed as a QUALIFIER on its item's ARK, the qualifier being the media's
# own resource id. Cf. Ark\ArkManager::getArk()'s "Check dynamic ark for media" branch and
# Ark\Qualifier\Plugin\Internal::create(), which returns (string) $resource->id().
# Without this, all ~303k media stay in the published graph under their non-persistent
# https://www.goudatijdmachine.nl/omeka/api/media/N URI.
#
# Mirrors pass 2 of load_ark_map() in _prepare_swap_uri.py — keep the two in sync, and
# note the same first-ARK-wins rule (only resource 97179 carries two).
function tf_media_arks(array $ids, $mysqli) {
	$ids = array_values(array_unique(array_map('intval', $ids)));
	if (!$ids) return [];
	$idlist = implode(',', $ids);   // int-cast -> injection-safe
	$res = $mysqli->query(
		'SELECT m.id, v.uri FROM `media` m'
		. ' JOIN `value` v ON v.resource_id=m.item_id AND v.property_id=' . TF_PROPERTY_ARK
		. ' AND v.uri LIKE \'https://n2t.net/ark:%\''
		. ' WHERE m.id IN (' . $idlist . ') ORDER BY m.id, v.id');
	if (!$res) return [];
	$out = [];
	while ($row = $res->fetch_assoc()) {
		$mid = (int)$row['id'];
		if (!isset($out[$mid])) $out[$mid] = $row['uri'] . '/' . $mid;   // first ARK wins
	}
	return $out;
}

# tf24 — give a media document the alias triple that an item gets from Omeka natively:
#   <api-media-uri> owl:sameAs <derived-ark>
# It deliberately emits the api->ark direction, because tf9 (which runs immediately after)
# already knows that shape: its inline-sameAs branch builds a bidirectional entry from it,
# flips the pair to the good <ark> owl:sameAs <api> form, and rewrites every occurrence of
# the media's api URI in the document to the ARK. So this transform is three lines of
# intent and no rewriting of its own.
#
# Only fires on a media's OWN document — matched on the api URI in SUBJECT position, so an
# item document listing its media via o:media is untouched (those are cross-references and
# are handled by tf9's batched lookup). No-op when the media's item has no ARK.
function tf24_media_sameas($ntstring, $id, $mysqli) {
	$id = (int)$id;
	$subj = '<https://www.goudatijdmachine.nl/omeka/api/media/' . $id . '>';
	if (!preg_match('/^' . preg_quote($subj, '/') . ' /m', $ntstring)) return $ntstring;

	$mediaArk = tf_media_arks([$id], $mysqli);
	if (!isset($mediaArk[$id])) return $ntstring;

	$line = $subj . ' <http://www.w3.org/2002/07/owl#sameAs> <' . $mediaArk[$id] . "> .\n";
	if (strpos($ntstring, $line) !== false) return $ntstring;   // idempotent
	if ($ntstring !== '' && substr($ntstring, -1) !== "\n") $ntstring .= "\n";
	return $ntstring . $line;
}

function tf9_swap_uri($ntstring, $id, $mysqli) {
	$swap = [];

	# Self ARK from the inline owl:sameAs, bidirectional — flips <api> sameAs <ark>
	# to <ark> sameAs <api> in the single pass below.
	# ONLY n2t ARK objects: a resource may carry external owl:sameAs values too (RKD,
	# bibliotheken.nl, ...). Accepting those made the LAST pair win, so the resource's
	# whole subject was rewritten to the external URI and its ARK vanished from the graph
	# (~638 subjects, e.g. 1074097 -> <https://data.rkd.nl/artists/21505>).
	# First ARK wins, matching get_ark()'s LIMIT 1 and the `ark` field stored in MongoDB
	# (only resource 97179 carries two ARKs).
	if (preg_match_all('#<(https://www\.goudatijdmachine\.nl/omeka/api/[^>]*)> <http://www\.w3\.org/2002/07/owl\#sameAs> <(https://n2t\.net/ark:/[^>]*)>#', $ntstring, $sm)) {
		$n = count($sm[0]);
		for ($i = 0; $i < $n; $i++) {
			if (isset($swap[$sm[1][$i]])) continue;   // first ARK wins
			$swap[$sm[1][$i]] = $sm[2][$i];
			$swap[$sm[2][$i]] = $sm[1][$i];
		}
	}

	# Other-resource api object references -> their ARK via one batched DB lookup.
	# Restrict to true resource endpoints: items/item_sets/media/resources all carry
	# a global resource.id, whereas resource_templates/resource_classes/vocabularies/
	# properties/... use SEPARATE id-spaces and must NOT be looked up by resource_id.
	if (preg_match_all('#<(https://www\.goudatijdmachine\.nl/omeka/api/(?:items|item_sets|media|resources)/([0-9]+))>#', $ntstring, $am)) {
		$fulls = [];                       // full api URI => resource_id
		$n = count($am[0]);
		for ($i = 0; $i < $n; $i++) {
			$rid = (int)$am[2][$i];
			if ($rid !== (int)$id) $fulls[$am[1][$i]] = $rid;
		}
		if ($fulls) {
			$ids = array_values(array_unique($fulls));
			$idlist = implode(',', $ids);  // ids are int-cast -> injection-safe
			# property_id 1177 is owl:sameAs, which also holds external URIs (RKD, ...) —
			# filter to n2t ARKs, else a cross-reference could be rewritten to an external
			# URI. ORDER BY + first-wins keeps this deterministic and agrees with get_ark().
			$res = $mysqli->query('SELECT resource_id, uri FROM `value` WHERE property_id=' . TF_PROPERTY_ARK . ' AND uri LIKE \'https://n2t.net/ark:%\' AND resource_id IN (' . $idlist . ') ORDER BY resource_id, id');
			if ($res) {
				$arkById = [];
				while ($row = $res->fetch_assoc()) {
					$rid = (int)$row['resource_id'];
					if (!isset($arkById[$rid])) $arkById[$rid] = $row['uri'];
				}
				foreach ($fulls as $full => $rid) {
					if (isset($arkById[$rid])) $swap[$full] = $arkById[$rid];
				}
			}
			# Whatever is still unmapped has no ARK stored on itself. Media never do —
			# resolve those to their derived <item-ark>/<media-id> form (tf_media_arks).
			# What survives after this is genuinely unmappable: a resource without an ARK,
			# a media whose item has none, or a reference to a deleted resource. Those keep
			# their api URI, as everywhere else in this pipeline.
			$rest = [];
			foreach ($fulls as $full => $rid) {
				if (!isset($swap[$full])) $rest[$full] = $rid;
			}
			if ($rest) {
				$mediaArk = tf_media_arks(array_values($rest), $mysqli);
				foreach ($rest as $full => $rid) {
					if (isset($mediaArk[$rid])) $swap[$full] = $mediaArk[$rid];
				}
			}
		}
	}

	if (!$swap) return $ntstring;

	# Single left-to-right pass: each ark|api URI swapped at most once (no double-swap).
	return preg_replace_callback(
		'#<(https://n2t\.net/ark:/60537/[^>]*)>|<(https://www\.goudatijdmachine\.nl/omeka/api[^>]*)>#',
		function ($m) use ($swap) {
			$u = ($m[1] !== '') ? $m[1] : $m[2];
			return isset($swap[$u]) ? '<' . $swap[$u] . '>' : $m[0];
		},
		$ntstring
	);
}

# tf18 — declare every subject typed schema:CreativeWork part of the Kennisgraaf Dataset
# (SCHEMA-AP-NDE: "Points to the dataset(s) that the CreativeWork is part of"). The Dataset node
# itself (TF_DATASET_ARK = Omeka item 13000) already carries its own rdf:type schema:Dataset in the
# merged graph, so only the isPartOf edge is minted here.
#
# Runs LAST, after tf5/tf15 (which mint the CreativeWork type) and after tf9 (so the subject is
# already the resource's ARK; subjects without one keep their api URI, as everywhere else).
# Blank-node subjects are skipped — the profile's CreativeWorkShape is sh:nodeKind sh:IRI.
# Re-runnable: an isPartOf already present is never duplicated. The emitted line is byte-stable,
# so the downstream sort_unique dedups it across resources.
function tf18_dataset_ispartof($ntstring) {
	$lines = explode("\n", $ntstring);
	$subjects = [];
	$existing = [];
	foreach ($lines as $line) {
		$p = explode(' ', $line, 4);
		if (count($p) < 3) continue;
		if ($p[1] === TF_RDF_TYPE && $p[2] === TF_CLASS_CREATIVEWORK && $p[0][0] === '<') {
			$subjects[$p[0]] = true;
		} elseif ($p[1] === TF_ISPARTOF && $p[2] === TF_DATASET_ARK) {
			$existing[$p[0]] = true;
		}
	}
	$app = '';
	foreach ($subjects as $subj => $_) {
		if (isset($existing[$subj]) || $subj === TF_DATASET_ARK) continue;
		$app .= $subj . ' ' . TF_ISPARTOF . ' ' . TF_DATASET_ARK . " .\n";
	}
	if ($app === '') return $ntstring;
	if ($ntstring !== '' && substr($ntstring, -1) !== "\n") $ntstring .= "\n";
	return $ntstring . $app;
}

# tf21 — give UNTAGGED literals the @nl language tag on the properties SCHEMA-AP-NDE
# requires to be rdf:langString (schema:name/description/abstract/text/copyrightNotice):
#
#   <RES> <https://schema.org/name> "Wageningen University"^^<xsd:string> .
# becomes
#   <RES> <https://schema.org/name> "Wageningen University"@nl .
#
# Restores the step v1 lost with the move to per-resource transforms
# (v1/_prepare_normalise_langstring.py, step 4.12 of v1/_do_prepare.sh) — but WIDER than
# that script, which only touched *plain* literals and so was a no-op in practice: the
# Omeka API always serialises an explicit datatype, so the corpus holds 0 plain literals
# and ~14.6k carrying ^^xsd:string. Both forms are handled here.
#
# @nl is the dataset default; this is a safety net for values entered in Omeka without a
# language, not a substitute for setting one. Only xsd:string (and the plain form) is
# retyped — a name typed xsd:date, xsd:integer, ... is left alone rather than silently
# relabelled, and a literal that already carries any @lang does not match, so the
# transform is idempotent.
#
# Order-independent: it only rewrites literal objects, while the other transforms rewrite
# subjects/URIs (tf9) or fold references into blank nodes — and the DefinedTerm folds
# (tf6/tf7/tf8/tf11/tf16) already emit their names @nl. Note schema:identifier is
# deliberately absent: tf10 moves it in the opposite direction, @lang -> ^^xsd:string.
# schema:disambiguatingDescription is absent too — the profile does not require a
# language tag on it.
function tf21_normalise_langstring($ntstring) {
	return preg_replace(
		'#(<https?://schema\.org/(?:name|description|abstract|text|copyrightNotice)> "(?:[^"\\\\\n]|\\\\.)*")(?:\^\^<http://www\.w3\.org/2001/XMLSchema\#string>)? \.#',
		'$1@nl .',
		$ntstring
	);
}

# tf22 — geef een schema:dateCreated literal het XSD-type dat bij zijn precisie hoort.
# SCHEMA-AP-NDE's DateShape accepteert alleen schema:Date, xsd:date (volledige datum),
# xsd:gYearMonth of xsd:gYear — en zegt expliciet dat een partiële datum NIET als
# xsd:date getypeerd mag worden. Omeka serialiseert elke literal als xsd:string, dus
# "1832"^^xsd:string valt om terwijl de waarde zelf prima is (4.758 resources).
#
#   YYYY-MM-DD -> xsd:date        YYYY-MM -> xsd:gYearMonth        YYYY -> xsd:gYear
#
# Alleen exact-ISO waarden worden omgetypeerd — het patroon is dat van DateShape zelf,
# inclusief jaren van meer dan vier cijfers en negatieve jaren ("-0044"). Alles wat daar
# niet aan voldoet ("1811-1832", "ca. 1910", "1744 - na 1780") blijft ongemoeid: er wordt
# niets verzonnen, die 21 waarden vragen om redactie in Omeka.
#
# Draait NA tf13, dat via de ric:DateRange-omweg al correct getypeerde literals maakt;
# die dragen geen xsd:string en matchen hier dus niet. Idempotent.
function tf22_datecreated_datatype($ntstring) {
	if (strpos($ntstring, 'dateCreated') === false) return $ntstring;
	return preg_replace_callback(
		'#(<https://schema\.org/dateCreated> )"((?:[^"\\\\\n]|\\\\.)*)"\^\^<http://www\.w3\.org/2001/XMLSchema\#string> \.#',
		function ($m) {
			$v = $m[2];
			if (!preg_match('/^-?([1-9][0-9]{3,}|0[0-9]{3})(-[0-9]{2}(-[0-9]{2})?)?$/', $v)) return $m[0];
			# tel de scheidingsstreepjes, het jaar-minteken niet meegerekend
			$parts = substr_count($v, '-') - (str_starts_with($v, '-') ? 1 : 0);
			$dt = $parts === 2 ? 'date' : ($parts === 1 ? 'gYearMonth' : 'gYear');
			return $m[1] . '"' . $v . '"^^<http://www.w3.org/2001/XMLSchema#' . $dt . '> .';
		},
		$ntstring
	);
}

# ---------------------------------------------------------------------------
# tf23 — Omeka value annotations
# ---------------------------------------------------------------------------
#
# Een value annotation (de bron/citatie die aan één specifieke WAARDE hangt) verdwijnt uit de
# RDF omdat Omeka hem als JSON-LD-star "@annotation" serialiseert en EasyRdf dat keyword niet
# kent. tf23 zet hem terug als klassieke rdf:Statement-reificatie.
#
# De implementatie staat in omeka-s-custom/value_annotations.php, want de
# content-negotiation-resolvers (gtm-rdf-resolver.php, gtm-ark-rdf-resolver.php) draaien
# dezelfde stap op hun eigen n-triples. Eén implementatie, zodat de reifier-IRI en de
# emitte triples in dump en dereferencing identiek zijn.
#
# Draait als ALLERLAATSTE in transform_resource(): tf23 reificeert bestaande regels, dus
# subject/predicaat/object moeten al hun definitieve vorm hebben (tf9's ARK-swap, de
# literal-normalisaties van tf3/tf10/tf21/tf22).
#
# $trail is het rewrite-spoor van de vouwende transforms (tf6/tf7/tf8/tf11/tf17), zie
# tf_trail_note(). Zonder dat spoor vindt tf23 een gevouwen waarde niet meer terug en laat hij
# de annotatie vallen; de resolvers draaien geen transforms en geven daarom geen spoor mee.
# De gedeelde implementatie in omeka-s-custom/ is leidend: de content-negotiation-resolvers
# (gtm-rdf-resolver.php, gtm-ark-rdf-resolver.php) includen hetzelfde bestand, en tf23 moet
# byte-identieke reificaties opleveren als zij. Staat die er niet — een clone van deze repo,
# zonder de Omeka-installatie ernaast — dan valt hij terug op de meegeleverde kopie.
$va_shared = __DIR__ . '/../../../omeka-s-custom/value_annotations.php';
require_once is_file($va_shared) ? $va_shared : __DIR__ . '/omeka-s-custom/value_annotations.php';

function tf23_value_annotations($ntstring, $id, $mysqli, array $trail = []) {
	return nt_add_value_annotations($ntstring, $id, $mysqli, $trail);
}
