=== Siigo Connect para WooCommerce ===
Contributors: danielserna
Tags: siigo, woocommerce, facturacion electronica, dian, colombia
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
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

== Changelog ==

= 0.1.0 =
* Fase 1: conexión con la API de Siigo, página de ajustes (conexión, facturación, sincronización) y registro de llamadas.
