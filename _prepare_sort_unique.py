#!/usr/bin/env python3

import sys
from tqdm import tqdm

def sort_unique(filename):
    # Count total lines for progress bar
    with open(filename, "rb") as f:
        total_lines = sum(1 for _ in f)

    lines = set()
    with open(filename, "r", encoding="utf-8", errors="replace") as f:
        for line in tqdm(f, total=total_lines, desc="Reading", unit="lines"):
            lines.add(line)

    # Sorting can also take time → wrap with tqdm if many lines
    sorted_lines = sorted(lines)

    # Single-schema:name fold for DefinedTerm blank nodes. The per-resource folding
    # in _do_update_nt.php emits a schema:name per referencing resource; after the
    # sort, all triples of a given _:genid2-… subject are adjacent, so keep only the
    # first schema:name per such subject (first = lexically smallest = deterministic).
    # Only _:genid2-… subjects are affected; everything else passes through.
    SDO_NAME = '<https://schema.org/name>'
    prev_name_subject = None
    for line in tqdm(sorted_lines, desc="Writing", unit="lines"):
        if line.startswith('_:genid2-'):
            parts = line.split(' ', 2)
            if len(parts) >= 2 and parts[1] == SDO_NAME:
                if parts[0] == prev_name_subject:
                    continue  # already kept one schema:name for this DefinedTerm
                prev_name_subject = parts[0]
        sys.stdout.write(line)

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print(f"Usage: {sys.argv[0]} <filename>")
        sys.exit(1)

    filename = sys.argv[1]
    sort_unique(filename)
