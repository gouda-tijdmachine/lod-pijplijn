# Gouda Tijdmachine Linked Data Pijplijn

De pijplijn die de kennisgraaf van de Gouda Tijdmachine bouwt: hij oogst de
resources uit Omeka S, transformeert ze naar RDF en publiceert één
[n-triples-dump](https://datasetregister.netwerkdigitaalerfgoed.nl/dataset?uri=https://n2t.net/ark:/60537/bD64Hu) die QLever indexeert en querybaar maakt via https://sparql.goudatijdmachine.nl/.

De volledige beschrijving van de keten (wat elke stap doet, waaróm hij daar
staat, welke volgorde-afhankelijkheden hard zijn en welke poorten een publicatie
kunnen tegenhouden) staat in
[Gouda Tijdmachine Linked Data Pijplijn](https://www.goudatijdmachine.nl/omeka/s/data/page/gouda-tijdmachine-linked-data-pijplijn).
Deze README beperkt zich tot het draaien en inrichten van de scripts.

## Draaien

`./do.sh` doorloopt de hele keten:

| Stap | Script | Wat het doet |
|------|--------|--------------|
| 1 | `_do_update_modified_data_resource.sh` | zet de datasetbeschrijving op gewijzigd, vóór het oogsten |
| 2 | `_do_set_empty_mongo_to_reindex.php` | markeert lege MongoDB-records voor heroogst |
| 3 | `_do_cleanup_deleted_mongo.py` | verwijdert verwijderde en niet-gepubliceerde resources uit de objectstore |
| 4 | `_do_update_nt.php` | oogst nieuwe en gewijzigde resources en past de transformaties uit `_do_transforms.php` toe |
| 5 | `_do_prepare.sh` | verzamelt alles, wisselt api-URI's om naar ARK-PID's, sorteert en ontdubbelt, en gzipt |
| 6 | `_do_publish.sh` | start de herindexering van QLever |
| 7 | `_do_update_datasetdescription.sh` | schrijft de nieuwe distributiegrootte terug naar Omeka |

De RDF-transformaties per resource draaien tijdens het oogsten (stap 4), niet in
stap 5. Een gewijzigde transformatie werkt dus pas door ná een heroogst van de
betrokken resources.

`_do_update_nt.php` en `_do_prepare.sh` nemen allebei resource-id's als argument
en draaien dan in testmodus: ze laten zowel de productiedump als het
incrementele watermerk (`_do_update_nt.dat`) ongemoeid.

## Uitvoer

Alles wat de build oplevert komt in `out/` terecht en staat niet in git:

- `out/goudatijdmachine.nt` » de volledige dump (enkele GB's)
- `out/goudatijdmachine.nt.gz` » wat gepubliceerd wordt; de Omeka-bestandsmap
  verwijst er met een symlink naar, en dát is de URL die QLever ophaalt
- `out/goudatijdmachine.test*.nt` » uitvoer van de testmodus

## Configuratie

Inloggegevens en interne adressen staan niet in deze repository. Drie platte
ini-bestanden (zonder secties) worden gelezen uit de Omeka-configuratiemap
(`../../../omeka-s-config/`, chmod 600):

```ini
; database.ini » de bestaande Omeka-databaseconfiguratie
user     = ...
password = ...
dbname   = ...
host     = ...

; omeka-api.ini » het Omeka S API-sleutelpaar, om over de API te oogsten
key_identity   = ...
key_credential = ...

; lod-pipeline.ini » de interne eindpunten
mongo_server   = mongodb://...:27017
qlever_ssh     = gebruiker@host
qlever_reindex = /pad/naar/reindex.sh
```

Ontbreekt een sleutel, dan stopt het betreffende script met een melding die het
bestand en de sleutel noemt.

## Benodigdheden

PHP 8.5, Python 3 (`pymongo`, `pymysql`, `tqdm`), MongoDB, MySQL en de
Composer-afhankelijkheden:

```sh
git clone https://github.com/gouda-tijdmachine/lod-pijplijn
composer install
```

Ook geoPHP loopt via Composer (`phayes/geophp`): `cor3` gebruikt het om uit een
WKT-polygoon de oppervlakte in m² te berekenen.
