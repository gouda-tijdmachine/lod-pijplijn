#!/usr/bin/env python3

import os
import sys
from pymongo import MongoClient
from tqdm import tqdm

# Mongo endpoint lives outside the repo, so the internal address is not published.
LOD_INI = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                       "..", "..", "..", "omeka-s-config", "lod-pipeline.ini")


def read_ini(path):
    """Minimal parser for the flat (section-less) ini files in omeka-s-config."""
    settings = {}
    with open(path, "r") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith((";", "#")) or "=" not in line:
                continue
            key, value = line.split("=", 1)
            settings[key.strip()] = value.strip().strip('"').strip("'")
    return settings


def main():
    mongo_server = read_ini(LOD_INI).get("mongo_server")
    if not mongo_server:
        sys.exit("ERROR: no mongo_server in %s" % LOD_INI)
    client = MongoClient(mongo_server)
    collection = client.gtm.nt

    # Test mode: when resource ids are passed as args, collect only those docs.
    # Match both string and int _id forms since the stored type may be either.
    raw = sys.argv[1:]
    query = {}
    if raw:
        ids = []
        for x in raw:
            ids.append(x)
            try:
                ids.append(int(x))
            except ValueError:
                pass
        query = {"_id": {"$in": ids}}

    total_docs = collection.count_documents(query)
    cursor = collection.find(query)

    for nt in tqdm(cursor, total=total_docs, desc="Collecting", unit="doc"):
        content = nt.get("content", "")
        content = content.replace(" (op Gemeentegeschiedenis)", "")
        sys.stdout.write(content)
        sys.stdout.flush()

if __name__ == "__main__":
    main()
