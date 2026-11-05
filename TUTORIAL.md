# Guía de Puesta en Marcha: Open FacturARCA

Esta guía explica paso a paso cómo conectar **Open FacturARCA** con los Web Services oficiales de **ARCA / AFIP** en Argentina, tanto para el entorno de **Homologación (Testing)** como para **Producción**.

---

## 📋 Requisitos Previos

1. Tener acceso al portal de **ARCA/AFIP** con **Clave Fiscal (Nivel 3 o superior)**.
2. Servidor web con **PHP 7.4 o superior** y la extensión **OpenSSL** y **SOAP** activas.
3. WordPress 5.8+ y WooCommerce 5.0+ instalados.

---

## 🚀 Paso 1: Generar la Clave Privada y el CSR desde el Plugin

> 💡 **No necesitas usar la consola ni comandos complejos de terminal.**

1. En tu panel de WordPress, ve a **WooCommerce > Ajustes > Open FacturARCA**.
2. Haz clic en la pestaña **"🔑 Generador de Clave y CSR"**.
3. Completa los datos:
   - **Razón Social o Nombre de Fantasía**: Ej. *Mi Tienda Online*.
   - **CUIT del Emisor**: 11 dígitos sin guiones (ej. `20123456789`).
4. Presiona el botón **"⚡ Generar Clave y CSR Ahora"**:
   - El plugin generará tu clave privada de 2048 bits de forma encriptada en la carpeta protegida `certs/`.
   - Se descargará automáticamente a tu computadora un archivo llamado `pedido_CUIT.csr`. **Guarda este archivo.**

---

## 🏛️ Paso 2: Obtener el Certificado Digital en ARCA / AFIP

### Opción A: Para Homologación (Ambiente de Pruebas)
1. Ingresa a [afip.gob.ar](https://auth.afip.gob.ar) con Clave Fiscal.
2. Busca el servicio **"WSASS - Autogestión Certificados Homologación"**.  
   *(Si no lo tienes visible, agrégalo desde el Administrador de Relaciones de Clave Fiscal).*
3. Selecciona **"Crear Certificado"**, ingresa un alias (ej: `test-mitienda`), sube el archivo `pedido_CUIT.csr` y descarga el certificado resultante (`.crt`).

### Opción B: Para Producción (Comprobantes Reales)
1. Ingresa al servicio **"Administración de Certificados Digitales"**.
2. Haz clic en **"Agregar Alias"** (ej: `prod-mitienda`), sube el archivo `pedido_CUIT.csr` y guarda.
3. En la lista de certificados, haz clic en **"Ver"** y luego en **"Descargar Certificado"** (`.crt`).

---

## 🔗 Paso 3: Autorizar los Servicios en ARCA / AFIP (WSFE y Padrón)

Debes asociar tu nuevo certificado con los servicios web oficiales de ARCA:

1. En el portal de ARCA/AFIP, ingresa al **"Administrador de Relaciones de Clave Fiscal"**.
2. Haz clic en **"Nueva Relación"** > **"Buscar Servicio"**.
3. Selecciona **AFIP / ARCA > WebServices**:
   * **Servicio 1 (Obligatorio para Facturar):** `Facturación Electrónica` (`wsfe`).
   * **Servicio 2 (Para autodetección de IVA y CUIT):** `Consulta de Padrón A5` (`ws_sr_constancia_inscripcion`).
4. En el campo **"Representante"**, busca y selecciona el **Alias** que creaste en el Paso 2.
5. Confirma las relaciones.

---

## 🏪 Paso 4: Dar de Alta el Punto de Venta para Web Services

1. En el portal de ARCA/AFIP, ingresa a **"Regímenes de Facturación y Registración (REAR/RECE/RFI)"**.
2. Selecciona tu CUIT y entra en **"A/B/M de Puntos de Venta"**.
3. Haz clic en **"Alta"**:
   - **Número de Punto de Venta**: Ingresa el siguiente número disponible (ej: `2` o `3`).
   - **Nombre de Fantasía**: Nombre de tu tienda online.
   - **Sistema**: Selecciona estrictamente **"Factura Electrónica - Web Services"**.
4. Guarda y anota el número de Punto de Venta creado.

---

## ⚙️ Paso 5: Cargar Certificado y Activar en WordPress

1. Vuelve a tu panel de WordPress: **WooCommerce > Ajustes > Open FacturARCA**.
2. En la pestaña **"🔑 Generador de Clave y CSR"**:
   - En la sección **"Subir Certificado (.crt)"**, selecciona el archivo descargado de ARCA y haz clic en **"Subir y Vincular Certificado"**.
3. Ve a la pestaña **"🏢 Empresas Emisoras"**:
   - Revisa que tu empresa tenga el **Punto de Venta** correcto y la **Condición Fiscal** (Monotributo o Responsable Inscripto).
   - Verifica que el estado de certificados figure en verde: **✔ Configurados**.
4. Ve a la pestaña **"⚙️ Ajustes de Automatización"**:
   - Activa o desactiva la **Facturación Automática** según tu preferencia.
   - Activa las **Notas de Crédito Automáticas** para reembolsos si deseas sincronización contable instantánea.

¡Felicitaciones! Tu tienda WooCommerce ahora emite comprobantes electrónicos con validez legal ante ARCA/AFIP.
