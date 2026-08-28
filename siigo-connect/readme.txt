=== Siigo Connect para WooCommerce ===
Contributors: danielserna
Tags: siigo, woocommerce, facturacion electronica, dian, colombia
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Conecta WooCommerce con Siigo Nube: facturación automática (electrónica o interna), sincronización de productos, inventario y clientes.

== Description ==

Integración entre WooCommerce y Siigo Nube (Colombia):

* Facturación automática al pagarse o completarse el pedido (o manual), con factura electrónica DIAN o factura de venta interna según el comprobante elegido.
* Creación/actualización de terceros en Siigo con los datos fiscales del cliente.
* Sincronización de productos, precios e inventario desde Siigo hacia WooCommerce (emparejados por SKU).
* Campos de checkout para Colombia: tipo y número de documento, persona natural/jurídica.
* Registro de llamadas a la API para diagnóstico.

Requiere una cuenta de Siigo Nube con credenciales de API (usuario + access key).

== Installation ==

1. Copia la carpeta `siigo-connect` a `wp-content/plugins/` y activa el plugin.
2. En WooCommerce → Siigo Connect → Conexión, ingresa el usuario y access key de la API (Siigo Nube → Configuración → Credenciales API) y prueba la conexión.
3. En Facturación, elige tipo de comprobante, disparador, vendedor, forma de pago e impuesto IVA. Si cobras envíos, crea en Siigo un producto para el envío (ej. código ENVIO) y configúralo.
4. Asegúrate de que los SKU de tus productos en WooCommerce coincidan con los códigos de producto en Siigo.
5. (Opcional) En Sincronización, activa productos y/o inventario y elige la frecuencia.

== Frequently Asked Questions ==

= ¿Cómo se emparejan los productos? =
Por SKU: el SKU del producto en WooCommerce debe ser igual al código del producto en Siigo.

= ¿Qué pasa si falla la creación de una factura? =
El plugin reintenta automáticamente hasta 5 veces con esperas crecientes (5 min a 24 h). El error queda en las notas del pedido y en el Registro, y en cada pedido hay un botón "Enviar a Siigo" / "Reintentar ahora".

= ¿De dónde salen los códigos DANE de la ciudad del tercero? =
El departamento se deriva del estado de WooCommerce; el municipio usa el código por defecto configurado. Para un mapeo fino usa el filtro `siigoc_customer_city`.

= ¿Funciona con el checkout por bloques? =
Sí: los campos de documento se registran también con la API de campos adicionales de WooCommerce (8.9+), además del checkout clásico.

== Changelog ==

= 1.1.0 =
* Impuestos por producto: cada línea de la factura usa los impuestos configurados en el producto de Siigo (IVA 19%, 5%, exento, excluido, etc.), consultados automáticamente y cacheados. El impuesto de los ajustes pasa a ser solo un respaldo.
* La sincronización guarda los impuestos de cada producto para facturar sin consultas extra.
* El envío también usa los impuestos de su producto en Siigo.

= 1.0.0 =
* Facturación automática: pedido de Woo → factura en Siigo (electrónica o interna, según comprobante), con creación del tercero, envío a DIAN y correo opcionales.
* Campos de checkout para Colombia: tipo y número de documento (checkout clásico y por bloques), editables en el admin del pedido.
* Cola de reintentos con backoff (5 intentos) y caja de estado con botones "Enviar a Siigo" / "Reintentar" en cada pedido.
* Sincronización Siigo → Woo de precios e inventario por cron (15 min a diario), con continuación automática para catálogos grandes, creación opcional de productos como borrador y botón "Sincronizar ahora".
* Filtros para desarrolladores: `siigoc_invoice_payload`, `siigoc_customer_payload`, `siigoc_customer_city`, `siigoc_new_product_status`.

= 0.1.0 =
* Fase 1: conexión con la API de Siigo, página de ajustes (conexión, facturación, sincronización) y registro de llamadas.
