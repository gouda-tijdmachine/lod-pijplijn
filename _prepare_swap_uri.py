#!/usr/bin/env python3

# Corpus-wide Omeka api -> ARK PID swap. Restores the whole-file pass v1 had
# (v1/_prepare_swap_uri.py, step 4.13 of v1/_do_prepare.sh) and that v2 dropped when
# the swap moved into tf9_swap_uri() in _do_transforms.php.
#
# tf9 swaps a resource once, at harvest time. Anything stored without the transforms,
# or harvested before its ARK existed, keeps its api URIs forever — _do_prepare.sh
# never touches URIs again. This step is the net for that, and for cross-references
# that tf9's per-resource view could not resolve.
#
# Reads n-triples from stdin (or from a filename argument) and writes to stdout. It
# runs between _prepare_collect_nt.py and _prepare_sort_unique.py so that triples
# which become identical after swapping still get deduped, and so the final file
# stays sorted (<https://n2t.net/…> sorts before <https://www.goudatijdmachine.nl/…>).
#
# This is deliberately NOT a port of v1's script. v1 built a BIDIRECTIONAL dict from
# the corpus' own owl:sameAs triples, which was safe only while the whole corpus was
# uniformly unswapped. v2's corpus is mixed — most resources already carry the good
# form <ark> owl:sameAs <api> — so a bidirectional dict would flip those back to api
# URIs. This pass is one-way api -> ark, with a general rule for the owl:sameAs triple
# itself (see swap_sameas), and takes its map from MySQL, the same source tf9 uses.
#
# Media have no ARK of their own; they are named as a QUALIFIER on their item's ARK,
# <item-ark>/<media-id>. load_ark_map() derives those, so media stop being published
# under a non-persistent /omeka/api/media/N URI. See its docstring for the why.
#
# The pass is idempotent: ARKs are never keys of the map, and a good owl:sameAs line
# is re-emitted byte-identically.

import os
import re
import sys

import pymysql
import pymysql.cursors

DB_INI = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                      "..", "..", "..", "omeka-s-config", "database.ini")
DB_PORT = 3316        # read-only replica, as _do_update_nt.php
PROPERTY_ARK = 1177   # Omeka property_id of owl:sameAs; cf. TF_PROPERTY_ARK in _do_transforms.php

# Exactly tf9's four endpoints. items/item_sets/media/resources all carry a global
# resource.id; sites/users/assets/comments/mapping_features and resource_classes/
# resource_templates/vocabularies/properties use SEPARATE id-spaces whose numbers
# collide with real resource ids, so they must never be looked up by resource_id.
API_RE = re.compile(rb'<https://www\.goudatijdmachine\.nl/omeka/api/'
                    rb'(items|item_sets|media|resources)/([0-9]+)>')

API_MARKER = b'/omeka/api/'
SAMEAS = b'<http://www.w3.org/2002/07/owl#sameAs>'
PROGRESS_EVERY = 5000000


def read_db_ini(path):
    """Minimal parser for the flat (section-less) Omeka database.ini."""
    settings = {}
    with open(path, "r") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith((";", "#")) or "=" not in line:
                continue
            key, value = line.split("=", 1)
            settings[key.strip()] = value.strip().strip('"').strip("'")
    return settings


def load_ark_map(settings):
    """resource_id -> b'<ark uri>' for every resource that has an ARK PID.

    Two passes, in this order:

    1. Stored ARKs, from `value`. Same query tf9 uses (_do_transforms.php), narrowed
       to n2t ARKs because property 1177 is owl:sameAs and also holds external URIs
       (RKD, bibliotheken.nl). ORDER BY + first-wins keeps this deterministic and in
       agreement with get_ark()'s LIMIT 1 and the `ark` field stored on each MongoDB
       doc: only resource 97179 carries two ARKs. The reverse is not unique either —
       a few hundred ARKs are shared by several resources — which is harmless for a
       one-way map and would be fatal for a bidirectional one.

    2. DERIVED media ARKs, <item-ark>/<media-id>. No media carries an ARK of its own
       (Omeka setting ark_qualifier_static = "0"), so the Ark module names a media as
       a QUALIFIER on its item's ARK — see Ark\\ArkManager::getArk()'s "Check dynamic
       ark for media" branch, with ark_qualifier = "internal", i.e.
       Ark\\Qualifier\\Plugin\\Internal::create() returning (string) $resource->id().
       Without this pass all ~303k media stay in the published graph under their
       non-persistent /omeka/api/media/N URI. Same first-wins rule, keyed on the media's
       own resource id — media ids share the global resource.id space, so they cannot
       collide with pass 1; a media that somehow did get its own ARK keeps it.
    """
    conn = pymysql.connect(
        host=settings["host"],
        port=DB_PORT,
        user=settings["user"],
        password=settings["password"],
        database=settings["dbname"],
        charset="utf8mb4",
    )
    ark = {}
    try:
        # Unbuffered cursors: stream ~1.5M / ~300k rows without materialising the
        # result set. They must be consumed one after the other, not interleaved.
        with conn.cursor(pymysql.cursors.SSCursor) as cur:
            cur.execute(
                "SELECT resource_id, uri FROM `value`"
                " WHERE property_id = %s AND uri LIKE 'https://n2t.net/ark:%%'"
                " ORDER BY resource_id, id", (PROPERTY_ARK,))
            for rid, uri in cur:
                rid = int(rid)
                if rid not in ark:
                    ark[rid] = b'<' + uri.encode("utf-8") + b'>'

        with conn.cursor(pymysql.cursors.SSCursor) as cur:
            cur.execute(
                "SELECT m.id, v.uri FROM `media` m"
                " JOIN `value` v ON v.resource_id = m.item_id"
                "   AND v.property_id = %s"
                "   AND v.uri LIKE 'https://n2t.net/ark:%%'"
                " ORDER BY m.id, v.id", (PROPERTY_ARK,))
            for mid, uri in cur:
                mid = int(mid)
                if mid not in ark:
                    ark[mid] = (b'<' + uri.encode("utf-8")
                                + b'/' + str(mid).encode("ascii") + b'>')
    finally:
        conn.close()
    return ark


class Swapper:
    def __init__(self, ark):
        self.ark = ark
        self.lines = 0
        self.uris_swapped = 0
        self.sameas_fixed = 0
        self.unmapped = {}          # endpoint -> set of resource ids without an ARK

    def _repl(self, m):
        hit = self.ark.get(int(m.group(2)))
        if hit is None:
            self.unmapped.setdefault(m.group(1), set()).add(int(m.group(2)))
            return m.group(0)
        self.uris_swapped += 1
        return hit

    def map_term(self, term):
        """b'<uri>' -> b'<ark>' when the term is a mapped api URI, else unchanged."""
        m = API_RE.fullmatch(term)
        if m is None:
            return term
        hit = self.ark.get(int(m.group(2)))
        if hit is None:
            self.unmapped.setdefault(m.group(1), set()).add(int(m.group(2)))
            return term
        return hit

    def swap_sameas(self, subject, obj):
        """Canonicalise one owl:sameAs triple to <ark> owl:sameAs <other>.

        A naive one-way rewrite degenerates BOTH forms of the alias triple into
        <ark> sameAs <ark>, losing the api alias for the whole corpus:
            stale  <api> sameAs <ark>  -> subject mapped -> <ark> sameAs <ark>
            good   <ark> sameAs <api>  -> object  mapped -> <ark> sameAs <ark>
        So map both terms first; when they collapse to the same URI, one side is
        the api alias of the other and we re-emit the pair with the ARK in front and
        the *other* side preserved. Alias triples that are already in the good form
        come out byte-identical; genuinely different subjects (e.g. an external
        owl:sameAs to RKD, or the one api->api link in the corpus) keep both sides.
        """
        ms, mo = self.map_term(subject), self.map_term(obj)
        if ms != subject:
            self.sameas_fixed += 1
        if ms == mo:
            other = obj if ms == subject else subject
            return ms + b' ' + SAMEAS + b' ' + other + b' .\n'
        return ms + b' ' + SAMEAS + b' ' + mo + b' .\n'

    def run(self, src, out):
        for line in src:
            self.lines += 1
            if self.lines % PROGRESS_EVERY == 0:
                sys.stderr.write("swap_uri: %d M lines, %d URIs swapped\n"
                                 % (self.lines // 1000000, self.uris_swapped))
                sys.stderr.flush()

            if API_MARKER not in line:
                out.write(line)
                continue

            if SAMEAS in line:
                parts = line.split(b' ', 2)
                if len(parts) == 3 and parts[1] == SAMEAS:
                    obj = parts[2].rstrip(b'\r\n')
                    if obj.endswith(b' .'):
                        out.write(self.swap_sameas(parts[0], obj[:-2]))
                        continue
                # Anything that does not parse as <s> <p> <o> . falls through to the
                # generic substitution below rather than being silently mangled.

            out.write(API_RE.sub(self._repl, line))

    def report(self):
        sys.stderr.write("swap_uri: %d lines, %d api URIs -> ARK, %d owl:sameAs re-fronted onto their ARK\n"
                         % (self.lines, self.uris_swapped, self.sameas_fixed))
        # Since media got derived <item-ark>/<media-id> ARKs (load_ark_map pass 2) these
        # buckets should be empty or near it. What survives is genuinely unmappable:
        # a resource with no ARK (only item 2956513 as of 2026-08-07), a media whose item
        # has none, or a dangling reference to a resource that no longer exists in MySQL.
        # Still not fatal — unmapped URIs are passed through unchanged, never dropped.
        for endpoint in sorted(self.unmapped):
            sys.stderr.write("swap_uri: no ARK for %d distinct %s\n"
                             % (len(self.unmapped[endpoint]), endpoint.decode()))
        sys.stderr.flush()


def main():
    if len(sys.argv) > 2:
        sys.stderr.write("Usage: %s [filename]   (reads stdin when no filename)\n" % sys.argv[0])
        sys.exit(1)

    ark = load_ark_map(read_db_ini(DB_INI))
    sys.stderr.write("swap_uri: %d resources have an ARK PID\n" % len(ark))
    sys.stderr.flush()
    if not ark:
        sys.stderr.write("ERROR [swap_uri]: empty ARK map — refusing to pass the corpus through unswapped.\n")
        sys.exit(1)

    swapper = Swapper(ark)
    out = sys.stdout.buffer
    if len(sys.argv) == 2:
        with open(sys.argv[1], "rb") as f:
            swapper.run(f, out)
    else:
        swapper.run(sys.stdin.buffer, out)
    out.flush()
    swapper.report()


if __name__ == "__main__":
    main()
