#!/bin/bash
set -euo pipefail

NTRIPLES="out/goudatijdmachine.nt.gz"
TODAY=$(date +"%Y%m%d.%H")

# Real published gzip is ~400 MB; floor only trips on a clearly broken (empty/
# truncated) artifact so we never reindex Qlever onto an empty dataset.
MIN_GZ_BYTES=50000000   # ~50 MB

if [ ! -s "$NTRIPLES" ]; then
    echo "ERROR [publish]: $NTRIPLES is missing or empty — refusing to reindex." >&2
    exit 1
fi
gzsize=$(stat -c%s "$NTRIPLES" 2>/dev/null || echo 0)
if [ "$gzsize" -lt "$MIN_GZ_BYTES" ]; then
    echo "ERROR [publish]: $NTRIPLES is only ${gzsize} bytes (< $MIN_GZ_BYTES) — looks truncated, refusing to reindex." >&2
    exit 1
fi

# The QLever host and its reindex command live in lod-pipeline.ini, next to
# database.ini and outside this repo, so internal hostnames are not published.
LOD_INI="$(dirname "$(readlink -f "$0")")/../../../omeka-s-config/lod-pipeline.ini"
ini_value() {   # <key> — flat, section-less ini, same shape as database.ini
    grep "^$1" "$LOD_INI" | grep -v ';' | awk -F'=' '{print $2}' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/"//g'
}
QLEVER_SSH=$(ini_value qlever_ssh)
QLEVER_REINDEX=$(ini_value qlever_reindex)
if [ -z "$QLEVER_SSH" ] || [ -z "$QLEVER_REINDEX" ]; then
    echo "ERROR [publish]: qlever_ssh / qlever_reindex missing from $LOD_INI" >&2
    exit 1
fi

echo '3.1 - Qlever will fetch https://www.goudatijdmachine.nl/omeka/files'
echo '3.2 - Re-index and restart Qlever / GTM'
ssh "$QLEVER_SSH" "$QLEVER_REINDEX"
