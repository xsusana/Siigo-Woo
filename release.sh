#!/usr/bin/env bash
# Empaqueta el plugin y (con --publish) publica la release en GitHub para que
# los sitios instalados vean "Actualizar ahora" en Plugins.
#
#   ./release.sh            → solo genera siigo-connect.zip
#   ./release.sh --publish  → además crea el tag vX.Y.Z y la release con el zip
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(sed -n 's/^ \* Version: *//p' siigo-connect/siigo-connect.php | tr -d '[:space:]')
CONSTANT=$(sed -n "s/^define( 'SIIGOC_VERSION', '\(.*\)' );/\1/p" siigo-connect/siigo-connect.php)
STABLE=$(sed -n 's/^Stable tag: *//p' siigo-connect/readme.txt | tr -d '[:space:]')

if [ "$VERSION" != "$CONSTANT" ] || [ "$VERSION" != "$STABLE" ]; then
	echo "Versiones inconsistentes: cabecera=$VERSION, SIIGOC_VERSION=$CONSTANT, Stable tag=$STABLE" >&2
	exit 1
fi

rm -f siigo-connect.zip
zip -rq siigo-connect.zip siigo-connect -x '*.DS_Store'
echo "siigo-connect.zip generado (v$VERSION)"

if [ "${1:-}" != "--publish" ]; then
	exit 0
fi

if [ -n "$(git status --porcelain)" ]; then
	echo "Hay cambios sin commit; haz commit antes de publicar." >&2
	exit 1
fi

# Notas: la sección de esta versión en el changelog de readme.txt.
NOTES=$(awk -v v="= $VERSION =" '$0==v{f=1;next} /^= [0-9]/{f=0} f' siigo-connect/readme.txt)

git tag "v$VERSION"
git push origin HEAD "v$VERSION"
gh release create "v$VERSION" siigo-connect.zip --title "v$VERSION" --notes "$NOTES"
