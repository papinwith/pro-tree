#!/bin/sh
# Waits for step 1 (photo download for ml/species_sea.csv) to finish, then trains the
# student "tree_sea" straight from the GBIF names (no teacher). Log: ml/data_sea/train.log
cd "$(dirname "$0")/.."
export PYTHONIOENCODING=utf-8 PYTHONUNBUFFERED=1
until grep -q "photos total" ml/data_sea_fetch.log; do sleep 30; done
python ml/03_train_tree.py --source gbif --species ml/species_sea.csv --data ml/data_sea --name tree_sea > ml/data_sea/train.log 2>&1
echo finished > ml/data_sea/train_done.txt
