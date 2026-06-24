#!/usr/bin/env bash
#
# Construit un ZIP de module PrestaShop installable (Modules → Importer un module).
# Le ZIP contient le dossier racine `virevopay/` (le nom DOIT être celui du module).
#
# Usage : bash bin/build-zip.sh
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(grep -m1 "this->version" virevopay/virevopay.php \
	| sed -E "s/.*'([0-9.]+)'.*/\1/" | tr -d '\r')

mkdir -p dist
OUT="dist/virevopay-${VERSION}.zip"
rm -f "$OUT"

# Archive uniquement le sous-arbre du module, préfixé par son dossier.
git archive --format=zip --prefix=virevopay/ -o "$OUT" HEAD:virevopay

echo "✅ Construit : $OUT (version $VERSION)"
