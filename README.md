<div align="center">

<img src="assets/logo.svg" alt="Open FacturARCA Logo" width="480">

<br><br>

### Facturación Electrónica ARCA (AFIP) para WooCommerce
**100% Código Abierto • Sin Costos por Comprobante • Multi-empresa • Auditable y Seguro**

[![Licencia](https://img.shields.io/badge/licencia-GPLv3-blue.svg)](LICENSE)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0+-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4.svg)](https://www.php.net/)
[![ARCA](https://img.shields.io/badge/ARCA%20%2F%20AFIP-WSFEV1%20%7C%20WSAA-green.svg)](https://www.afip.gob.ar/)

[📖 Ver Guía Paso a Paso](TUTORIAL.md) • [⚡ Reportar un Problema](https://github.com/sarthouse/open-facturarca/issues) • [🤝 Contribuir](https://github.com/tu-usuario/open-facturarca/pulls)

</div>

---

## 💡 ¿Por qué Open FacturARCA?

La mayoría de las integraciones de facturación para WooCommerce en Argentina exigen suscripciones mensuales o cobran una tarifa fija por cada factura emitida a través de intermediarios cerrados.

**Open FacturARCA** conecta tu tienda de forma directa, nativa y segura con los Web Services oficiales de ARCA (WSAA y WSFEV1), eliminando intermediarios, comisiones y cajas negras.

---

## ✨ Características Principales

* 🧠 **Resolución Fiscal 100% Automática (Zero-Friction Checkout):**
  * **Sin campos confusos para el cliente:** Se elimina el selector manual de IVA en el checkout. El comprador solo ingresa su DNI o CUIT en `billing_dni`.
  * **Autoconsulta a Padrón ARCA:** Si ingresa un CUIT (11 dígitos), el plugin consulta en tiempo real el servicio oficial `ws_sr_constancia_inscripcion` de ARCA y detecta si es Responsable Inscripto, Monotributista o Exento, recuperando además la Razón Social.
  * Si ingresa un DNI (7 u 8 dígitos), se clasifica automáticamente como Consumidor Final.
  * Emisión automática de **Factura A, B o C** según la condición fiscal del emisor y receptor.
* 🏢 **Arquitectura Multi-empresa:** Emite facturas desde diferentes CUITs o razones sociales dentro de una misma instalación de WooCommerce.
* ⚡ **Doble Modo de Emisión (Individual y en Lote):**
  * **Automático:** Emite comprobantes en segundo plano al cambiar el estado del pedido (ej. *Completado* o *Procesando*).
  * **Manual / Mixto:** Emite con 1 solo clic desde el panel de cada orden en WooCommerce.
  * **Acciones en Lote (Bulk):** Selecciona múltiples pedidos desde la tabla de pedidos y emite todas las facturas en un solo paso.
* 📄 **Comprobante y Remito en PDF Nativo:**
  * Generación de comprobantes y remitos en archivos `.pdf` binarios reales (sin dependencias pesadas).
  * Descarga disponible para el cliente directamente en la pantalla de agradecimiento y en *Mi Cuenta > Pedidos*.
  * Las notas del pedido se mantienen privadas en el panel para auditoría interna del administrador.
* 📊 **Panel de Estadísticas y Analítica Fiscal:**
  * Tarjetas de rendimiento en tiempo real: Total Facturado, Neto Gravado, IVA Débito Fiscal y conteo por tipo de comprobante (A, B, C, NC).
  * Filtro por rango de fechas personalizable.
* 📑 **Libro IVA Ventas (Exportación CSV):**
  * Descarga en un clic del Libro IVA Ventas oficial formateado con codificación UTF-8 BOM para apertura perfecta en Excel.
  * Columnas estandarizadas: Fecha, Tipo, Pto Vta, Nro, CUIT/DNI, Denominación, Total, Neto, Alícuotas de IVA y CAE.
* 🗺️ **Facturación por Provincia y Coeficientes IIBB (Convenio Multilateral):**
  * Desglose automático de ventas por cada una de las 24 provincias argentinas basándose en la dirección del comprador.
  * Cálculo instantáneo de **% de Coeficiente de Ingresos** para la liquidación mensual y anual de Ingresos Brutos (CM05).
* 🔄 **Gestión Integral de Notas de Crédito:**
  * Emisión automática por el monto devuelto al generar reembolsos nativos en WooCommerce.
  * Formulario para emitir Notas de Crédito parciales o totales de forma manual.
  * Vinculación reglamentaria de comprobantes asociados (`CbtesAsoc`).
* 🔑 **Generador de Certificados Integrado:** Genera tu clave privada `.key` y pedido `.csr` con 1 clic desde el panel de WordPress, sin necesidad de usar la consola.
* 📋 **Nota de Pedido / Venta Interna (Letra X):**
  * Comprobante comercial con precios, subtotales, método de pago y estado de la orden (`NV-00000042.pdf`).
  * Ideal para órdenes pendientes de pago, transferencias bancarias, presupuestos o clientes mayoristas antes de emitir la factura fiscal.
* 📦 **Remito de Entrega Integrado (Letra X):**
  * Genera el remito de expedición/depósito con la leyenda reglamentaria *"DOCUMENTO NO VÁLIDO COMO FACTURA"* y letra **X**.
  * Incluye ítems, SKUs, cantidades, datos de envío/destinatario y casillas de firma y recepción.
* 🖨️ **Gestión de Ejemplares (Original, Duplicado, Triplicado):**
  * Configurable independientemente para Facturas/Notas de Crédito y para Remitos.
  * Diseñado con salto de página automático (`page-break`) y etiqueta identificatoria de copia (`ORIGINAL`, `DUPLICADO`, `TRIPLICADO`) para impresión en imprenta o PDF.
* 🔒 **Seguridad y Cero Fugas:**
  * Candados anti-duplicación (idempotencia) para evitar que webhooks repetidos emitan dos facturas.
  * Claves privadas y tokens protegidos con directivas de servidor (`.htaccess` / Apache 2.4 / Nginx).
  * Descargas securizadas con Nonces criptográficos y validación de permisos de usuario.

---

## 🚀 Instalación Rápida

1. Descarga el repositorio o clónalo dentro de tu directorio de plugins:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/sarhouse/open-facturarca.git
   ```
2. Ve a **Plugins > Plugins Instalados** en tu panel de WordPress y activa **Open FacturARCA**.
3. Dirígete a **WooCommerce > Ajustes > Open FacturARCA** para comenzar la configuración.

> 📘 **¿Primera vez configurando ARCA/AFIP?**  
> Sigue nuestra **[Guía Paso a Paso de Puesta en Marcha](TUTORIAL.md)** para generar tus certificados, delegar el servicio web y dar de alta tu punto de venta en menos de 10 minutos.

---

## 🛡️ Auditoría y Seguridad

Este proyecto fue desarrollado bajo una premisa estricta de seguridad:
* **Sin almacenamiento de datos bancarios o fiscales innecesarios.**
* **Sin trackers ni telemetría.**
* **Cálculo de impuestos 100% del lado del servidor**, inmune a manipulaciones de frontend.
* **Control de acceso estricto** en endpoints públicos para prevenir vulnerabilidades IDOR.

---

## 🤝 Cómo Colaborar

Las contribuciones son bienvenidas. Si deseas colaborar:
1. Haz un Fork del repositorio.
2. Crea una rama con tu mejora (`git checkout -b feature/nueva-mejora`).
3. Envía un Pull Request describiendo los cambios.

---

## 📄 Licencia

Distribuido bajo licencia **GPLv3**. Eres libre de usarlo, auditarlo, modificarlo y adaptarlo a tus necesidades.
