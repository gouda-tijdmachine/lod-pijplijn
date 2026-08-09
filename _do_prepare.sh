#!/bin/bash
set -euo pipefail

# Build the published n-triples. The per-resource RDF transforms (formerly steps
# 4.3-4.10) run inside _do_update_nt.php as each resource is stored in MongoDB, so
# this collects the (already-transformed) content, re-applies the corpus-wide
# api->ARK swap, uniquely sorts it while folding duplicate DefinedTerm schema:names
# to one, and compresses. Every stage must yield a non-empty file; the final
# out/goudatijdmachine.nt must also clear a sanity size floor so an empty/broken build
# never gets published.
#
# The swap (v1's step 4.13, dropped when tf9_swap_uri moved into the harvester) is
# back deliberately: tf9 swaps a resource once, at harvest time, so any doc stored
# without the transforms keeps its api URIs forever. See _prepare_swap_uri.py. It
# must run BEFORE sort_unique, so triples that become identical after swapping are
# deduped and the output stays sorted.

# Every build product (the n-triples, their gzip, the test variants) lives in ./out.
# Nothing outside this directory is written to. The published gzip is exposed through
# the symlink omeka-s-files/goudatijdmachine.nt.gz -> out/goudatijdmachine.nt.gz.
OUT="./out"
mkdir -p "$OUT"

MIN_FINAL_BYTES=1000000000   # ~1 GB (real file is several GB; tune if the dataset shrinks)

# SCHEMA-AP-NDE staat maximaal één schema:dateCreated per CreativeWork toe. Meer dan één
# betekende altijd dat een document een triple over een ANDER subject droeg: Omeka zet in
# @reverse alle inkomende verwijzingen, dus het document van een ric:DateRange beweerde
# <item> schema:dateCreated <DateRange> voor elk item dat hem gebruikte. Die kopie werd
# nooit ongeldig gemaakt, dus na een correctie in Omeka bleef de oude waarde in de dump
# staan (293 subjects, 287 daarvan een dag/maand-omgekeerd paar). Dat blok staat nu uit via
# de Omeka-instelling `disable_jsonld_reverse` — maar dat is een databasewaarde, en de
# equivalente bescherming (de lokale corepatch achter noreverse=1) is bij de upgrade naar
# 4.2.x al eens stil omgevallen. Deze poort merkt dat vóór publicatie.
#
# Drempel staat op het aantal bekende, in Omeka te redigeren duplicaten. Dat was er één —
# item 2959075 (ark b0044gf) had daar echt twee waarden staan, 1865 én 1932 — maar dat is
# op 2026-08-07 met de hand in Omeka rechtgezet (alleen 1932 nog; schema:datePublished
# stond al op 1932). Sindsdien hoort geen enkel subject meer dan één waarde te hebben, dus
# elke bevinding hier is een regressie, geen bekend geval.
MAX_DUP_DATECREATED=0

die() { echo "ERROR [prepare]: $*" >&2; exit 1; }

require_nonempty() {   # <file> <step-label>
    [ -s "$1" ] || die "$2: expected output '$1' is missing or empty. Aborting before it spreads downstream."
}

require_minsize() {    # <file> <min-bytes> <step-label>
    local size
    size=$(stat -c%s "$1" 2>/dev/null || echo 0)
    [ "$size" -ge "$2" ] || die "$3: '$1' is only ${size} bytes (< $2) — looks truncated. Aborting."
}

require_single_datecreated() {   # <file> <max-allowed> <step-label>
    # The file is sorted, so `uniq -d` finds repeated subjects without a second sort.
    # grep/uniq legitimately match nothing, which would trip `set -o pipefail`; the
    # `|| true` keeps an empty result an empty result rather than a build abort.
    local dups count
    dups=$(LC_ALL=C grep -F '> <https://schema.org/dateCreated> ' "$1" | awk '{print $1}' | uniq -d || true)
    count=$(printf '%s' "$dups" | grep -c . || true)
    [ "$count" -le "$2" ] || die "$3: $count subjects carry more than one schema:dateCreated (max $2). SCHEMA-AP-NDE allows one. Check that Omeka's \`disable_jsonld_reverse\` is still on, then re-harvest the ric:DateRange resources. Offenders:
$(printf '%s' "$dups" | head -20)"
    echo "       subjects with >1 schema:dateCreated: $count (max $2)"
}

if [ "$#" -gt 0 ]; then
    # Test mode: collect/sort only the given resource ids into separate test files,
    # never touching the production $OUT/goudatijdmachine.nt[.gz]. The ~1GB size floor
    # and the gzip step are skipped — they make no sense for a handful of resources.
    echo "4.1 - [TEST] Collecting resources $* as n-triples + api->ARK swap, resulting in $OUT/goudatijdmachine.test.nt"
    python _prepare_collect_nt.py "$@" | python _prepare_swap_uri.py > "$OUT/goudatijdmachine.test.nt"
    require_nonempty "$OUT/goudatijdmachine.test.nt" "4.1 collect_nt | swap_uri (test)"

    echo "4.2 - [TEST] Uniquely sort + single-schema:name fold, resulting in $OUT/goudatijdmachine.test.sorted.nt"
    python _prepare_sort_unique.py "$OUT/goudatijdmachine.test.nt" > "$OUT/goudatijdmachine.test.sorted.nt"
    require_nonempty "$OUT/goudatijdmachine.test.sorted.nt" "4.2 sort_unique (test)"
    require_single_datecreated "$OUT/goudatijdmachine.test.sorted.nt" "$MAX_DUP_DATECREATED" "4.2 sort_unique (test)"

    echo "[TEST] Done. Inspect $OUT/goudatijdmachine.test.nt and $OUT/goudatijdmachine.test.sorted.nt"
    exit 0
fi

echo "4.1 - Collecting all (already-transformed) resources as n-triples from the object store + api->ARK swap, resulting in $OUT/goudatijdmachine1.nt"
python _prepare_collect_nt.py | python _prepare_swap_uri.py > "$OUT/goudatijdmachine1.nt"
require_nonempty "$OUT/goudatijdmachine1.nt" "4.1 collect_nt | swap_uri"

echo "4.2 - Uniquely sort + single-schema:name fold, resulting in $OUT/goudatijdmachine.nt"
python _prepare_sort_unique.py "$OUT/goudatijdmachine1.nt" > "$OUT/goudatijdmachine.nt"
require_nonempty "$OUT/goudatijdmachine.nt" "4.2 sort_unique"
require_minsize "$OUT/goudatijdmachine.nt" "$MIN_FINAL_BYTES" "4.2 sort_unique"
require_single_datecreated "$OUT/goudatijdmachine.nt" "$MAX_DUP_DATECREATED" "4.2 sort_unique"

rm "$OUT/goudatijdmachine1.nt"

#echo "4.3 - Validating all n-triples"
#python old/_prepare_validate.py "$OUT/goudatijdmachine.nt"

echo "4.4 - Compressing n-triples file, resulting in $OUT/goudatijdmachine.nt.gz"
gzip -kf "$OUT/goudatijdmachine.nt"
require_nonempty "$OUT/goudatijdmachine.nt.gz" "4.4 gzip"
