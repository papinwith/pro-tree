#!/bin/sh
# Long run: top up to 120 photos per species, then train tree_th3 for 40 epochs (garden species x3), then measure.
cd "$(dirname "$0")/.."
export PYTHONIOENCODING=utf-8 PYTHONUNBUFFERED=1 MSYS_NO_PATHCONV=1
python -W ignore -u ml/01_fetch_images.py --species ml/species_th.csv --data ml/data_th --per-species 120 --threads 16 --species-parallel 6 > ml/data_th/fetch3.log 2>&1
python -W ignore -u ml/03_train_tree.py --source gbif --species ml/species_th.csv --data ml/data_th --name tree_th3 --epochs 40 --boost ml/species_exhibit.csv --boost-factor 3 > ml/data_th/train3.log 2>&1
python -W ignore -u ml/gate_check.py --name tree_th3 --species ml/species_th.csv --data ml/data_th --target 0.96 > ml/data_th/gate3.log 2>&1
echo finished > ml/data_th/pipeline3_done.txt
