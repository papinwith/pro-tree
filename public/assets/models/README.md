# tree - in-browser plant model

- `tree.onnx` - MobileNetV3-Small classifier for 281 plant species: the 27 species of this system, the plants GBIF has the most open
  photos of in Thailand and its neighbours, and the 8 species growing in the garden (Adenium obesum, Ligustrum ovalifolium,
  Euonymus japonicus, Olea europaea, Monstera deliciosa, Buxus microphylla, Bauhinia blakeana, Aechmea fasciata). Trained with `ml/03_train_tree.py`.
- `tree.labels.json` - species names, photo preparation constants, and `app_threshold` (0.90): the website only
  trusts an answer when the model is at least this sure. On held-out photos, answers at >= 90 % were right 97.9 %
  of the time but covered only 9.3 % of photos. Real phone photos may do worse.
- `credits.csv` - species, licence, photographer and source link of every photo the model learned from.
  The photos are CC0 or CC-BY (GBIF / iNaturalist); CC-BY requires crediting the photographers, hence this file.

The model runs inside the visitor's browser (public/assets/js/tree-model.js, ONNX Runtime Web from jsDelivr);
the photo is not uploaded when `tree` answers. To replace the model: retrain, copy the new `.onnx` and labels here,
then run `python tests/tree_browser_check.py` and `python tests/tree_button_check.py`.
