#!/bin/sh
# Waits for step 2 (teacher labelling) to finish, frees the GPU from Ollama,
# then trains the student twice: on all teacher labels (tree) and only on the
# photos the teacher got right (tree_agree). Logs: ml/data/train_*.log
cd "$(dirname "$0")/.."
export PYTHONIOENCODING=utf-8 PYTHONUNBUFFERED=1
until grep -q "Teacher report" ml/data/teacher_run.log; do sleep 20; done
ollama stop qwen2.5vl:7b >/dev/null 2>&1
python ml/03_train_tree.py --labels teacher --name tree > ml/data/train_tree.log 2>&1
python ml/03_train_tree.py --labels agree --name tree_agree > ml/data/train_tree_agree.log 2>&1
echo finished > ml/data/train_done.txt
