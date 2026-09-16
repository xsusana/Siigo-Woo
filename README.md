# Siigo-Woo

Plugin **Siigo Connect para WooCommerce** (carpeta `siigo-connect/`). Detalle funcional en [siigo-connect/readme.txt](siigo-connect/readme.txt).

## Publicar una versión nueva

Los sitios con el plugin instalado consultan las releases de este repositorio y muestran **Actualizar ahora** en Plugins cuando hay una versión mayor.

1. Sube la versión en los tres lugares: cabecera `Version:` y `SIIGOC_VERSION` en `siigo-connect/siigo-connect.php`, y `Stable tag:` en `siigo-connect/readme.txt`. Añade la entrada al changelog del readme.
2. Haz commit.
3. Ejecuta `./release.sh --publish`: genera `siigo-connect.zip`, crea el tag `vX.Y.Z` y la release de GitHub con el zip adjunto.

La release debe llevar adjunto `siigo-connect.zip` (con la carpeta `siigo-connect/` dentro); sin ese archivo WordPress no ofrece la actualización.

### Repositorio privado

Mientras el repositorio sea privado, cada sitio necesita un token de GitHub *fine-grained* con acceso de solo lectura (**Contents: Read-only**) a este repositorio, configurado en WooCommerce → Siigo Connect → Conexión → Token de GitHub, o en `wp-config.php`:

```php
define( 'SIIGOC_GITHUB_TOKEN', 'github_pat_...' );
```
