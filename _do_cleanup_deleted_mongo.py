#!/usr/bin/env python3

# Removes from the MongoDB object store (gtm.nt) every resource that is no longer
# a published resource in the Omeka MySQL `resource` table, i.e. resources that
# were deleted or unpublished in Omeka.
#
# The decision is based SOLELY on the Omeka `resource` table: a MongoDB document
# is kept only if its _id (which is the Omeka resource id) is still a public
# (is_public=1) row in `resource`. No N-Triples files are read; the MongoDB
# collection is only the store being cleaned (and is consulted just for the set
# of _id's it contains, never their content).
#
# Same intent as cleanup_mongo_omeka.php, but instead of one MySQL query per
# MongoDB document it loads the set of valid (is_public=1) resource ids once and
# does a single set-difference pass, then deletes the stale ids in batches. This
# is orders of magnitude faster on the full collection.
#
# Reads the Omeka DB credentials from ../../omeka-s-config/database.ini (same file
# the PHP scripts use). Pass --dry-run to only report what would be removed.
#
# Usage:
#   python cleanup_deleted_mongo.py [--dry-run]

import argparse
import os
import sys

import pymysql
import pymysql.cursors
from pymongo import MongoClient
from tqdm import tqdm

DB_INI = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                      "..", "..", "..", "omeka-s-config", "database.ini")
# Mongo endpoint lives outside the repo, so the internal address is not published.
LOD_INI = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                       "..", "..", "..", "omeka-s-config", "lod-pipeline.ini")
DELETE_BATCH = 5000


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


def load_valid_ids(settings):
    """Set of resource ids that are still present and public in Omeka MySQL."""
    conn = pymysql.connect(
        host=settings["host"],
        user=settings["user"],
        password=settings["password"],
        database=settings["dbname"],
        charset="utf8mb4",
    )
    valid = set()
    try:
        # Unbuffered cursor: stream the (potentially millions of) ids without
        # materialising the whole result set client-side.
        with conn.cursor(pymysql.cursors.SSCursor) as cur:
            cur.execute("SELECT id FROM resource WHERE is_public = 1")
            for (rid,) in cur:
                valid.add(int(rid))
    finally:
        conn.close()
    return valid


def main():
    parser = argparse.ArgumentParser(
        description="Remove MongoDB object-store docs whose Omeka resource was deleted/unpublished.")
    parser.add_argument("--dry-run", action="store_true",
                        help="only report what would be removed, delete nothing")
    args = parser.parse_args()

    settings = read_db_ini(DB_INI)
    valid_ids = load_valid_ids(settings)
    print(f"Valid (is_public=1) resources in Omeka: {len(valid_ids)}", file=sys.stderr)

    mongo_server = read_db_ini(LOD_INI).get("mongo_server")
    if not mongo_server:
        sys.exit(f"ERROR: no mongo_server in {LOD_INI}")
    client = MongoClient(mongo_server)
    collection = client.gtm.nt

    total_docs = collection.count_documents({})
    cursor = collection.find({}, {"_id": 1})

    to_delete = []
    stale = 0    # docs whose Omeka resource is gone/unpublished
    deleted = 0  # docs actually removed from MongoDB
    skipped = 0  # non-numeric _id, left untouched

    def flush():
        nonlocal deleted
        if to_delete and not args.dry_run:
            result = collection.delete_many({"_id": {"$in": to_delete}})
            deleted += result.deleted_count
        to_delete.clear()

    for doc in tqdm(cursor, total=total_docs, desc="Checking MongoDB", unit="doc"):
        _id = doc["_id"]
        try:
            key = int(_id)
        except (TypeError, ValueError):
            # Non-numeric _id: leave it untouched, just note it.
            skipped += 1
            continue
        if key not in valid_ids:
            stale += 1
            to_delete.append(_id)
            if len(to_delete) >= DELETE_BATCH:
                flush()
    flush()

    print(f"MongoDB docs: {total_docs}", file=sys.stderr)
    if skipped:
        print(f"Skipped (non-numeric _id): {skipped}", file=sys.stderr)
    if args.dry_run:
        print(f"Would remove {stale} stale resource(s) from MongoDB (dry-run).", file=sys.stderr)
    else:
        print(f"Removed {deleted} of {stale} stale resource(s) from MongoDB.", file=sys.stderr)


if __name__ == "__main__":
    main()
