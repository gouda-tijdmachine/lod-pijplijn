<?php

# ===========================================================================
# Omeka S value annotations -> klassieke rdf:Statement-reificatie.
#
# Een value annotation hangt een bron/citatie aan één specifieke WAARDE, niet aan de
# resource. Omeka serialiseert dat in zijn JSON-LD als "@annotation" binnen het value-object
# (ValueRepresentation::jsonSerialize()), maar dat is JSON-LD-star: een gewone JSON-LD-parser
# — en dus ook EasyRdf — kent het keyword niet en gooit de hele subtree stilzwijgend weg.
# Zonder deze stap staat er nergens in de RDF een annotatie.
#
# Serialisatie: klassieke rdf:Statement-reificatie, geen RDF-star. Gemeten, niet aangenomen:
# de draaiende QLever weigert << >> en <<( )>> (parse-error; upstream #2169 "Support SPARQL
# 1.2" staat open), Jena 5.6.0's N-Triples-parser weigert beide vormen (in Turtle kan het
# wel), rdflib 7.1.1 weigert ze, en het regel-gebaseerde explode(' ', $line, 4)-idiom in
# bin/lod/v2/_do_transforms.php loopt stuk op "<<" als subject. rdf:Statement is platte
# RDF 1.1 en komt overal doorheen. Een latere overstap naar RDF 1.2 (rdf:reifies + triple
# term) is een mechanische rewrite van dezelfde reifier-knoop.
#
# Deze file is de ENIGE implementatie; hij wordt gebruikt door
#   - bin/lod/v2/_do_transforms.php  (tf23, harvest -> MongoDB -> dump -> QLever)
#   - omeka-s-custom/gtm-rdf-resolver.php en gtm-ark-rdf-resolver.php (content-negotiation)
# ===========================================================================

const VA_RDF_TYPE      = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>';
const VA_RDF_STATEMENT = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#Statement>';
const VA_RDF_SUBJECT   = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#subject>';
const VA_RDF_PREDICATE = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate>';
const VA_RDF_OBJECT    = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#object>';
const VA_O_LABEL       = '<http://omeka.org/s/vocabs/o#label>';
const VA_O_LANG        = '<http://omeka.org/s/vocabs/o#lang>';
const VA_XSD_STRING    = '<http://www.w3.org/2001/XMLSchema#string>';
const VA_PROPERTY_ARK  = 1177;   // Omeka property_id van owl:sameAs (de ARK PID, in value.uri)
const VA_API_BASE      = 'https://www.goudatijdmachine.nl/omeka/api/';
# De reifier-IRI volgt Omeka's eigen resource-URI voor een ValueAnnotation. Bewust NIET
# items/item_sets/media/resources: die vier worden door tf9_swap_uri en _prepare_swap_uri.py
# naar een ARK omgezet, en een ValueAnnotation heeft er geen.
const VA_BASE          = VA_API_BASE . 'value_annotations/';


# Voeg aan $ntstring (de n-triples van resource $resource_id) de reificatie toe van al zijn
# geannoteerde waarden. Geeft de string ongewijzigd terug als er niets te annoteren valt.
#
# TEKST-GESTUURD: de database zegt WELKE waarde geannoteerd is, maar subject, predicaat en
# object worden verbatim uit $ntstring overgenomen. Zo is de reificatie per definitie
# byte-identiek aan de triple die werkelijk in de output staat — een reconstructie uit `value`
# zou uiteenlopen zodra een transform de literal heeft aangepast, en de aanroeper hoeft niet te
# weten of de subjecten al naar hun ARK geswapt zijn. Vindt een waarde geen regel (niet publiek
# of weggevallen), dan wordt hij overgeslagen: nooit een reificatie die naar een niet-bestaande
# triple wijst.
#
# $trail is optioneel en komt alleen van bin/lod/v2/_do_transforms.php: de transforms daar
# vouwen een waarde soms tot een _:genid2-DefinedTerm, waarbij zowel het predicaat als het
# object van de oorspronkelijke regel verdwijnen (schema:hasOccupation "…" wordt
# schema:additionalType _:genid2-<sha256>). Het spoor zegt waar die waarde heen ging, zodat de
# reificatie op de gevouwen knoop gericht wordt in plaats van te verdwijnen. De
# content-negotiation-resolvers draaien geen transforms en geven dus geen spoor mee; hun
# uitvoer blijft byte-identiek.
function nt_add_value_annotations($ntstring, $resource_id, $mysqli, array $trail = []) {
	$id = (int) $resource_id;

	$sel = 'SELECT v.resource_id, v.value_annotation_id AS ann, v.type,
	               CONCAT(vo.namespace_uri, p.local_name) AS pred,
	               v.value, v.uri, v.value_resource_id, v.lang
	          FROM `value` v
	          JOIN `property` p    ON p.id  = v.property_id
	          JOIN `vocabulary` vo ON vo.id = p.vocabulary_id ';

	# 1. de geannoteerde waarden van deze resource
	$res = $mysqli->query($sel . 'WHERE v.resource_id = ' . $id
		. ' AND v.value_annotation_id IS NOT NULL AND v.is_public = 1 ORDER BY v.id');
	if (!$res) return $ntstring;
	$annotated = [];
	while ($row = $res->fetch_assoc()) $annotated[] = $row;
	if (!$annotated) return $ntstring;

	# 2. de annotaties zelf — hun eigen values vormen de body (prov:hadPrimarySource, sdo:citation, ...)
	$annIds = [];
	foreach ($annotated as $av) $annIds[(int) $av['ann']] = true;
	$res = $mysqli->query($sel . 'WHERE v.resource_id IN (' . implode(',', array_keys($annIds)) . ')'
		. ' AND v.is_public = 1 ORDER BY v.resource_id, v.id');
	$bodies = [];
	if ($res) {
		while ($row = $res->fetch_assoc()) $bodies[(int) $row['resource_id']][] = $row;
	}
	if (!$bodies) return $ntstring;

	# 3. ARK's voor alle resource-verwijzingen ineens: de resource zelf (om zijn eigen subject
	#    te herkennen), de geannoteerde resource-waarden en de resource-waarden in de bodies.
	$refIds = [$id];
	foreach ($annotated as $av) if (!empty($av['value_resource_id'])) $refIds[] = $av['value_resource_id'];
	foreach ($bodies as $rows) foreach ($rows as $b) if (!empty($b['value_resource_id'])) $refIds[] = $b['value_resource_id'];
	$arkMap = va_ark_map($mysqli, $refIds);

	# 4. de regels indexeren op predicaat + object — alleen die met de resource zelf als
	#    subject: een document bevat ook triples over andere knopen (blank nodes van de
	#    DefinedTerm-vouwingen, gematerialiseerde places, gerelateerde resources, ...).
	$selfSubjects = [];
	if (isset($arkMap[$id])) $selfSubjects['<' . $arkMap[$id] . '>'] = true;
	foreach (['items', 'item_sets', 'media', 'resources'] as $ep) {
		$selfSubjects['<' . VA_API_BASE . $ep . '/' . $id . '>'] = true;
	}

	$parsed = [];   // regelnr => [subject, predicaat-IRI, object-term]
	foreach (explode("\n", $ntstring) as $i => $line) {
		if (preg_match('#^(<[^>]*>) <([^>]*)> (.+) \.$#', $line, $m) && isset($selfSubjects[$m[1]])) {
			$parsed[$i] = [$m[1], $m[2], $m[3]];
		}
	}
	if (!$parsed) return $ntstring;

	# 5. per geannoteerde waarde de bijbehorende regel zoeken en reificeren
	$used = [];
	$out = '';
	$missed = 0;

	foreach ($annotated as $av) {
		$annId = (int) $av['ann'];
		if (empty($bodies[$annId])) continue;

		$hit = null;
		foreach ($parsed as $i => $p) {
			# $used: twee identieke waarden onder hetzelfde predicaat mogen niet dezelfde regel
			# claimen, anders krijgt de tweede annotatie het object van de eerste.
			if (isset($used[$i]) || $p[1] !== $av['pred']) continue;
			if (!va_value_matches($av, $p[2], $arkMap)) continue;
			$hit = $i;
			break;
		}

		# Niets gevonden op de oorspronkelijke vorm: is de waarde door een transform gevouwen,
		# dan zegt het spoor onder welk predicaat en welk object hij nu staat.
		if ($hit === null && $trail) {
			foreach (va_trail_targets($av, $trail, $arkMap) as $t) {
				foreach ($parsed as $i => $p) {
					if (isset($used[$i]) || $p[1] !== $t[0] || $p[2] !== $t[1]) continue;
					$hit = $i;
					break 2;
				}
			}
		}

		if ($hit === null) { $missed++; continue; }
		$used[$hit] = true;

		# Predicaat uit de GEVONDEN regel, niet uit de database: bij een gevouwen waarde staat
		# er schema:additionalType waar `value` nog schema:hasOccupation zegt. Voor een directe
		# match zijn ze per constructie gelijk ($p[1] !== $av['pred'] sloeg de regel over).
		$reifier = '<' . VA_BASE . $annId . '>';
		$out .= $reifier . ' ' . VA_RDF_TYPE      . ' ' . VA_RDF_STATEMENT . " .\n"
		      . $reifier . ' ' . VA_RDF_SUBJECT   . ' ' . $parsed[$hit][0] . " .\n"
		      . $reifier . ' ' . VA_RDF_PREDICATE . ' <' . $parsed[$hit][1] . "> .\n"
		      . $reifier . ' ' . VA_RDF_OBJECT    . ' ' . $parsed[$hit][2] . " .\n";

		foreach ($bodies[$annId] as $b) {
			$term = va_value_term($b, $arkMap);
			if ($term === null) continue;
			$out .= $reifier . ' <' . $b['pred'] . '> ' . $term . " .\n";
			# Een uri-waarde draagt zijn label/taal als o:label + o:lang op de URI zelf, precies
			# zoals Omeka's DataType\Uri::getJsonLd() dat elders in de output doet.
			if (empty($b['value_resource_id']) && $b['uri'] !== null && $b['uri'] !== ''
				&& $b['value'] !== null && $b['value'] !== '') {
				$out .= $term . ' ' . VA_O_LABEL . ' "' . va_escape($b['value']) . '"^^' . VA_XSD_STRING . " .\n";
				if (!empty($b['lang'])) {
					$out .= $term . ' ' . VA_O_LANG . ' "' . va_escape($b['lang']) . '"^^' . VA_XSD_STRING . " .\n";
				}
			}
		}
	}

	if ($missed) {
		error_log("INFO: value annotations resource $id — $missed geannoteerde waarde(n) niet teruggevonden in de n-triples, overgeslagen");
	}
	if ($out === '') return $ntstring;

	if ($ntstring !== '' && substr($ntstring, -1) !== "\n") $ntstring .= "\n";
	return $ntstring . $out;
}


# EasyRdf-variant voor de content-negotiation-resolvers. Serialiseert de graaf naar n-triples,
# laat nt_add_value_annotations() zijn werk doen en parseert het resultaat terug — zo draait
# er maar één implementatie, en zijn de reifier-IRI's in de dereferencing identiek aan die in
# de dump. Valt er niets te annoteren, dan komt de oorspronkelijke graaf onveranderd terug.
function easyrdf_add_value_annotations($graph, $resource_id, $base_url, $mysqli) {
	$nt = $graph->serialise('ntriples');
	$annotated = nt_add_value_annotations($nt, $resource_id, $mysqli);
	if ($annotated === $nt) return $graph;

	$out = new \EasyRdf\Graph($base_url);
	$out->parse($annotated, 'ntriples', $base_url);
	return $out;
}


# Hoort het object-term $term bij de `value`-rij $av? Resource-waarden matchen op zowel hun
# ARK als hun api-URI (afhankelijk van of de aanroeper al geswapt heeft); literals op de
# ONTESCAPETE tekst, zodat de vergelijking niet afhangt van hoe de producent geescaped heeft.
function va_value_matches(array $av, $term, array $arkMap) {
	if (!empty($av['value_resource_id'])) {
		$rid = (int) $av['value_resource_id'];
		if (isset($arkMap[$rid]) && $term === '<' . $arkMap[$rid] . '>') return true;
		return (bool) preg_match(
			'#^<https://www\.goudatijdmachine\.nl/omeka/api/(?:items|item_sets|media|resources)/' . $rid . '>$#',
			$term
		);
	}
	if ($av['uri'] !== null && $av['uri'] !== '') {
		return $term === '<' . $av['uri'] . '>';
	}
	if ($av['value'] === null) return false;
	$q = va_quoted($term);
	return $q !== null && va_unquote($q) === $av['value'];
}


# Waar is de waarde $av terechtgekomen nadat de transforms hem gevouwen hebben? Geeft de
# doelparen [predicaat-zonder-punthaken, object-term] terug, in dezelfde vorm als $parsed ze
# heeft: de regex daar strookt de punthaken van het predicaat, terwijl het spoor ze bewaart.
#
# Ketens worden gevolgd — een gevouwen knoop kan door een latere transform opnieuw herschreven
# worden — met een harde bovengrens, zodat een (theoretische) cyclus in het spoor nooit tot een
# oneindige lus leidt.
function va_trail_targets(array $av, array $trail, array $arkMap) {
	$targets = [];
	$pred = '<' . $av['pred'] . '>';

	foreach ($trail as $t) {
		if ($t['pred'] !== $pred) continue;
		if (!va_value_matches($av, $t['obj'], $arkMap)) continue;
		$targets[] = [$t['newpred'], $t['newobj']];
	}
	if (!$targets) return [];

	for ($hop = 0; $hop < 4; $hop++) {
		$grown = false;
		foreach ($targets as [$p, $o]) {
			foreach ($trail as $t) {
				if ($t['pred'] !== $p || $t['obj'] !== $o) continue;
				$next = [$t['newpred'], $t['newobj']];
				if (in_array($next, $targets, true)) continue;
				$targets[] = $next;
				$grown = true;
			}
		}
		if (!$grown) break;
	}

	# Punthaken van het predicaat af, zodat de vergelijking met $parsed[$i][1] direct kan.
	return array_map(static fn($t) => [substr($t[0], 1, -1), $t[1]], $targets);
}


# Bouw het N-Triples object-term voor een `value`-rij: resource -> <ark|api>, uri -> <uri>,
# anders een literal (@lang, of ^^xsd:string zoals Omeka/EasyRdf elke literal serialiseert).
# Null als er niets te emitten valt.
function va_value_term(array $row, array $arkMap) {
	if (!empty($row['value_resource_id'])) {
		$rid = (int) $row['value_resource_id'];
		if (isset($arkMap[$rid])) return '<' . $arkMap[$rid] . '>';
		$ep = ['resource:item' => 'items', 'resource:itemset' => 'item_sets', 'resource:media' => 'media'];
		# Zonder ARK blijft de api-URI staan; _prepare_swap_uri.py is daar corpus-breed het net voor.
		return '<' . VA_API_BASE . ($ep[$row['type']] ?? 'resources') . '/' . $rid . '>';
	}
	if ($row['uri'] !== null && $row['uri'] !== '') {
		return '<' . $row['uri'] . '>';
	}
	if ($row['value'] === null || $row['value'] === '') {
		return null;
	}
	$lit = '"' . va_escape($row['value']) . '"';
	return !empty($row['lang']) ? $lit . '@' . $row['lang'] : $lit . '^^' . VA_XSD_STRING;
}


# resource_id => ARK-URI voor een lijst resource-ids. Zelfde bron, filter en first-wins-regel
# als tf9_swap_uri() en get_ark() in bin/lod/v2, zodat alle drie dezelfde ARK kiezen voor de
# enkele resource die er twee heeft.
function va_ark_map($mysqli, array $ids) {
	$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
	if (!$ids) return [];
	$res = $mysqli->query('SELECT resource_id, uri FROM `value` WHERE property_id=' . VA_PROPERTY_ARK
		. ' AND uri LIKE \'https://n2t.net/ark:%\' AND resource_id IN (' . implode(',', $ids) . ')'
		. ' ORDER BY resource_id, id');
	$map = [];
	if ($res) {
		while ($row = $res->fetch_assoc()) {
			$rid = (int) $row['resource_id'];
			if (!isset($map[$rid])) $map[$rid] = $row['uri'];
		}
	}

	# Media dragen geen eigen ARK: ze heten als qualifier op de ARK van hun item,
	# <item-ark>/<media-id> (Ark-module, ark_qualifier_static="0"). Zie tf_media_arks() in
	# bin/lod/v2/_do_transforms.php — die vorm staat sinds de mediaswap ook echt in de
	# n-triples, dus zonder deze aanvulling herkent nt_add_value_annotations() het subject
	# van een mediadocument niet meer en zou een annotatie op een mediawaarde stil wegvallen.
	# Vandaag heeft geen enkele media een geannoteerde waarde, dit is puur de invariant.
	$missing = array_values(array_diff($ids, array_keys($map)));
	if ($missing) {
		$res = $mysqli->query('SELECT m.id, v.uri FROM `media` m'
			. ' JOIN `value` v ON v.resource_id=m.item_id AND v.property_id=' . VA_PROPERTY_ARK
			. ' AND v.uri LIKE \'https://n2t.net/ark:%\''
			. ' WHERE m.id IN (' . implode(',', $missing) . ') ORDER BY m.id, v.id');
		if ($res) {
			while ($row = $res->fetch_assoc()) {
				$mid = (int) $row['id'];
				if (!isset($map[$mid])) $map[$mid] = $row['uri'] . '/' . $mid;
			}
		}
	}
	return $map;
}


# Het eerste N-Triples literal-token aan het begin van $s (inclusief quotes), of null.
function va_quoted($s) {
	if (preg_match('/^"(?:[^"\\\\]|\\\\.)*"/', $s, $m)) return $m[0];
	return null;
}

# Tegenhanger van va_quoted(): literal-token -> kale tekst, zodat die vergeleken kan worden
# met de `value`-kolom. Dekt de escapes die N-Triples toestaat.
function va_unquote($token) {
	return preg_replace_callback(
		'/\\\\(u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s',
		function ($m) {
			switch ($m[1]) {
				case 't':  return "\t";
				case 'b':  return "\x08";
				case 'n':  return "\n";
				case 'r':  return "\r";
				case 'f':  return "\f";
				case '"':  return '"';
				case "'":  return "'";
				case '\\': return '\\';
			}
			if ($m[1][0] === 'u' || $m[1][0] === 'U') return mb_chr(hexdec(substr($m[1], 1)), 'UTF-8');
			return $m[1];
		},
		substr($token, 1, -1)
	);
}

# Kale tekst -> de body van een N-Triples literal (zonder de omringende quotes).
function va_escape($s) {
	return str_replace(
		["\\",   '"',   "\n",  "\r",  "\t"],
		["\\\\", '\\"', '\\n', '\\r', '\\t'],
		$s
	);
}
