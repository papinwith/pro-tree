# ml/ - training the "tree" plant model

The website's local model `tree` (MobileNetV3-Small, 273 species) is trained here, on this machine, and published to
`public/assets/models/`, where it runs inside the visitor's browser. **Teachers** (Qwen on Ollama, Pl@ntNet, Gemini) are
only asked when `tree` is not sure; their agreed answers come back as new training photos. Nothing changes the model
by itself - you retrain and publish by hand, and publishing is refused unless the new model is good enough.

## The loop

1. **Visitors / admins** use the identify button. If `tree` is >= 90 % sure the answer is shown at once.
   Otherwise the server asks Qwen and a second teacher (Pl@ntNet, else Gemini):
   - they name the same species -> the photo is saved as an **approved** example;
   - they disagree -> saved as **pending**; a person approves (optionally correcting the name) or rejects it on
     `admin/training_samples.php`;
   - an admin pressing "use this result" also saves the photo as approved.
2. **Download the approved photos** (set the same `TRAINING_EXPORT_TOKEN` on the website and here):

       set TRAINING_EXPORT_TOKEN=<24+ random characters>
       python ml/pull_feedback.py --url https://YOUR-SITE/admin/training_samples_export.php

   A species new to the model joins the class list once it has 8 photos.
3. **Retrain** (about 15 minutes on an RTX 2050):

       python ml/03_train_tree.py --source gbif --species ml/species_sea_fetched.csv --data ml/data_sea --name tree_sea

   Watch it live with `python ml/train_monitor.py` (local only).
4. **Publish, if it passes the check:**

       python ml/publish_model.py --name tree_sea

   It measures the model on photos it never learned from and refuses unless, at the 90 % confidence gate, at least
   90 % of its answers are right over at least 100 test photos. Then run `python tests/tree_browser_check.py` and
   `python tests/tree_button_check.py`, commit `public/assets/models/` and push to deploy.

## First-time data (already done once)

| Step | Script |
|---|---|
| species list from GBIF (Thailand first, catalogue species forced in) | `00b_species_from_gbif.py` |
| open-licence photos (CC0 / CC-BY) with photographer credits | `01_fetch_images.py` |
| optional: a teacher labels photos (Qwen) | `02_teacher_label.py` |
| train | `03_train_tree.py` |
| how reliable is the model at each confidence level | `gate_check.py` |

## Caveats

- Wrong labels teach wrong things. Two teachers agreeing is a good sign, not a guarantee (they can be wrong the same
  way); an admin pressing "use this result" without knowing the plant can approve a mistake. Review the pending queue
  and reject what you are unsure of.
- Retraining changes the test photos too (the split is random per run of the same data), so accuracy figures are not
  exactly comparable between versions.
- Photos stay in the website's database as base64 text until you download them; they are not deleted afterwards.
- `ml/data_sea/`, `ml/models/` and the live viewers are not in git.
