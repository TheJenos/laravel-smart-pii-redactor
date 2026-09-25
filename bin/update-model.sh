#!/usr/bin/env bash
#
# Update the bundled Stanford NER jar and English 3-class classifier in resources/models.
#
# Usage: bin/update-model.sh [version]    (default: 4.2.0)
#
# Releases are listed at https://nlp.stanford.edu/software/CRF-NER.html#Download

set -euo pipefail

VERSION="${1:-4.2.0}"
URL="https://nlp.stanford.edu/software/stanford-ner-${VERSION}.zip"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODELS_DIR="${ROOT_DIR}/resources/models"

JAR="stanford-ner.jar"
CLASSIFIER="english.all.3class.distsim.crf.ser.gz"

for cmd in curl unzip; do
    command -v "$cmd" >/dev/null || { echo "✘ $cmd is required" >&2; exit 1; }
done

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Downloading ${URL}..."
curl -fL --progress-bar -o "${TMP_DIR}/stanford-ner.zip" "$URL"

# The archive nests everything under a dated folder (e.g. stanford-ner-2020-11-17/)...
unzip -q -j "${TMP_DIR}/stanford-ner.zip" "*/${JAR}" "*/classifiers/${CLASSIFIER}" -d "${TMP_DIR}/extracted"

for file in "$JAR" "$CLASSIFIER"; do
    [ -f "${TMP_DIR}/extracted/${file}" ] || { echo "✘ ${file} not found in the archive" >&2; exit 1; }
done

mkdir -p "$MODELS_DIR"
mv "${TMP_DIR}/extracted/${JAR}" "${TMP_DIR}/extracted/${CLASSIFIER}" "$MODELS_DIR/"

echo "✔ Stanford NER ${VERSION} saved to resources/models:"
ls -lh "${MODELS_DIR}/${JAR}" "${MODELS_DIR}/${CLASSIFIER}"
