#!/bin/bash
set -euo pipefail
cd /home/http/goudatijdmachine.nl/bin/lod/v2/

# Abort the whole run if any step fails, naming the offending step. Each child
# script additionally validates its own output and exits non-zero on trouble.
trap 'echo "ERROR: do.sh aborted at line ${LINENO}: ${BASH_COMMAND}" >&2' ERR

echo "1 - Update the modified data of the LD dataset description, prior to harvesting N-triples"
./_do_update_modified_data_resource.sh

echo "2 - Marking empty MongoDB records to re-index"
./_do_set_empty_mongo_to_reindex.php

echo "3 - Removing deleted/unpublished Omeka resources from the object store"
./_do_cleanup_deleted_mongo.py

echo "4 - Update the object store with new and updates resources"
./_do_update_nt.php

echo "5 - Preparing n-triples"
./_do_prepare.sh

# Guard: never proceed to publish on an empty/missing build (defence in depth;
# _do_prepare.sh already enforces this, but keep do.sh safe if run/edited apart).
[ -s out/goudatijdmachine.nt.gz ] || { echo "ERROR: out/goudatijdmachine.nt.gz missing or empty after prepare — refusing to publish." >&2; exit 1; }

echo "6 - Publishing n-triples"
./_do_publish.sh

echo "7 - Updating dataset description"
./_do_update_datasetdescription.sh
