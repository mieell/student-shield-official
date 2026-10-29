"""
merge_real_into_training.py
----------------------------
Merges real decrypted student vents (real_vents.csv) with the existing
synthetic training data (train_vents.csv + val_vents.csv), then produces
new merged_train.csv and merged_val.csv ready for fine-tuning.

Run from project root:
    D:\\Scripts\\python.exe ml_service/merge_real_into_training.py
"""

import csv
import random
import os
from collections import Counter

REAL_VENTS_PATH  = "ml_service/data/real_vents.csv"
TRAIN_PATH       = "ml_service/data/train_vents.csv"
VAL_PATH         = "ml_service/data/val_vents.csv"
MERGED_TRAIN_OUT = "ml_service/data/merged_train.csv"
MERGED_VAL_OUT   = "ml_service/data/merged_val.csv"

FIELDS = ["text", "label"]

random.seed(42)


def load_csv(path: str, label_col="label", text_col="text") -> list:
    rows = []
    with open(path, encoding="utf-8") as f:
        for row in csv.DictReader(f):
            try:
                rows.append({"text": row[text_col].strip(), "label": int(row[label_col])})
            except (KeyError, ValueError):
                continue
    return rows


def save_csv(rows: list, path: str):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        w.writeheader()
        w.writerows(rows)


def main():
    print("Loading datasets...")
    real   = load_csv(REAL_VENTS_PATH)
    train  = load_csv(TRAIN_PATH)
    val    = load_csv(VAL_PATH)

    print(f"  Real vents    : {len(real)}")
    print(f"  Synthetic train: {len(train)}")
    print(f"  Synthetic val  : {len(val)}")

    # Split real 80/20 into train/val (stratified by label)
    real_0 = [r for r in real if r["label"] == 0]
    real_1 = [r for r in real if r["label"] == 1]
    random.shuffle(real_0)
    random.shuffle(real_1)

    def split_80_20(items):
        split = max(1, int(len(items) * 0.8))
        return items[:split], items[split:]

    real_train_0, real_val_0 = split_80_20(real_0)
    real_train_1, real_val_1 = split_80_20(real_1)
    real_train = real_train_0 + real_train_1
    real_val   = real_val_0   + real_val_1

    print(f"\nReal split -> train: {len(real_train)}  val: {len(real_val)}")

    # Merge
    merged_train = train + real_train
    merged_val   = val   + real_val
    random.shuffle(merged_train)
    random.shuffle(merged_val)

    print(f"\nMerged train: {len(merged_train)}")
    print(f"Merged val  : {len(merged_val)}")

    # Distribution
    for name, rows in [("Train", merged_train), ("Val", merged_val)]:
        dist = Counter(r["label"] for r in rows)
        total = len(rows)
        print(f"\n{name} distribution:")
        for label, count in sorted(dist.items()):
            pct = count / total * 100
            lvl = "at-risk" if label == 1 else "not-at-risk"
            print(f"  label={label} ({lvl}): {count} ({pct:.1f}%)")

    # Save
    save_csv(merged_train, MERGED_TRAIN_OUT)
    save_csv(merged_val,   MERGED_VAL_OUT)
    print(f"\nSaved: {MERGED_TRAIN_OUT}")
    print(f"Saved: {MERGED_VAL_OUT}")
    print("\nNext steps:")
    print("  1. Upload ml_service/ to Google Colab")
    print("  2. In StudentShield_Colab_Trainer.ipynb, change filenames to:")
    print("     train: 'data/merged_train.csv'")
    print("     val:   'data/merged_val.csv'")
    print("  3. Run training!")


if __name__ == "__main__":
    main()
