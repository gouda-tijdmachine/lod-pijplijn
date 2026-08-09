# lod-pijplijn

The pipeline that builds the Gouda Tijdmachine knowledge graph: it harvests
Omeka S resources, transforms them to RDF, and publishes one n-triples dump
for QLever to index.

## Running it

`./do.sh` runs the whole chain:

| Step | Script | What it does |
|------|--------|--------------|
| 1 | `_do_update_modified_data_resource.sh` | stamps the dataset description as modified |
| 2 | `_do_set_empty_mongo_to_reindex.php` | marks empty MongoDB records for re-harvest |
| 3 | `_do_cleanup_deleted_mongo.py` | drops deleted/unpublished resources from the object store |
| 4 | `_do_update_nt.php` | harvests new/changed resources and applies the transforms in `_do_transforms.php` |
| 5 | `_do_prepare.sh` | collects, swaps api URIs for ARK PIDs, sorts/dedupes, gzips |
| 6 | `_do_publish.sh` | triggers the QLever re-index |
| 7 | `_do_update_datasetdescription.sh` | writes the new distribution size back to Omeka |

The per-resource RDF transforms run at harvest time (step 4), not in step 5,
so changing a transform means re-harvesting the affected resources.

`_do_update_nt.php` and `_do_prepare.sh` both take resource ids as arguments
and then run in test mode, touching neither the production dump nor the
incremental watermark.

## Output

Everything the build produces goes to `out/` and is not in git:

- `out/goudatijdmachine.nt` — the full dump (several GB)
- `out/goudatijdmachine.nt.gz` — what gets published; the Omeka files
  directory symlinks to it, and that URL is what QLever fetches
- `out/goudatijdmachine.test*.nt` — test-mode output

## Configuration

Credentials and internal endpoints are not in this repo. Three flat,
section-less ini files are read from the Omeka config directory
(`../../../omeka-s-config/`, chmod 600):

```ini
; database.ini — the existing Omeka database config
user     = ...
password = ...
dbname   = ...
host     = ...

; omeka-api.ini — Omeka S API keypair, for harvesting over the API
key_identity   = ...
key_credential = ...

; lod-pipeline.ini — infrastructure endpoints
mongo_server   = mongodb://...:27017
qlever_ssh     = user@host
qlever_reindex = /path/to/reindex.sh
```

## Requirements

PHP 8.5, Python 3 (`pymongo`, `pymysql`, `tqdm`), MongoDB, MySQL, and
Composer dependencies:

```sh
git clone --recursive https://github.com/gouda-tijdmachine/lod-pijplijn
composer install
```

`geoPHP` is a submodule; `--recursive` (or `git submodule update --init`)
is required for `_do_update_nt.php` to load it.
