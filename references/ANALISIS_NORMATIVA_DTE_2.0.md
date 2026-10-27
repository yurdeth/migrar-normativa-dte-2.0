# Análisis: Actualización a Normativa de Facturación 2.0 (MH El Salvador)

**Autor de los cambios:** `luisjvr` — rango analizado: `f3cf8a0..HEAD` (34 commits, 2026-06-22 a 2026-09-25).

**Advertencia:** este documento es solo análisis. **No modifica código.** Sirve como guía para replicar/aplicar la actualización paso a paso en otro sistema u otra rama.

---

## 0. Aclaración importante: ¿qué clase DTE es la activa?

Existen **dos clases `Dte`** en el proyecto y **NO son la misma cosa**:

| Archivo | Estado | Uso |
|---|---|---|
| `clases/Dte.php` | **ACTUALIZADA a Normativa 2.0** (13 commits de luisjvr la tocaron) | Clase activa. Se invoca desde `_helper_dte_class.php` (`require_once 'clases/Dte.php'`). Genera los JSON v2 (FAC/COF/FSE), v4 (CCF/NC/ND/REM) y v3 (ANU). |
| `dte/models/Dte.php` | **LEGACY (Normativa 1.x)**. Últimos commits son antiguos (`5a8e074`, `397c0d3`), NO fue tocada en este rango | Módulo antiguo `dte/`. No participa en el flujo Normativa 2.0. |

Lo mismo ocurre con los JS:

| Archivo | Estado |
|---|---|
| `js/funciones/getAuth.js` / `js/funciones/dte_auto.js` | **Activos** (usados por el flujo POS y Admin de Facturas) |
| `dte/js/getAuth.js` / `dte/js/dte_auto.js` | Versiones del módulo legacy `dte/` (una de ellas actualizada puntualmente, pero el flujo nuevo vive en `js/funciones/`) |

Si vas a aplicar esta actualización, **la clase a trabajar es `clases/Dte.php` y los helpers en `js/funciones/`**. `dte/models/Dte.php` se queda como referencia del esquema anterior (útil para comparar qué cambió).

**Changelogs escritos por el propio autor** (fuente primaria de este análisis):
- `log_DTE_Normativa_2_0.txt` — resumen del quehacer: ALTERs, tablas evento, cambios por sección del JSON.
- `log_cambios_DTE_jun2026.txt` — detalle de distritos, formularios, updates SQL, resúmenes.

---

## 1. Resumen de commits relevantes (orden cronológico)

| Commit | Fecha | Contenido |
|---|---|---|
| `64edadb` | 22-06 | Corrección numDocumento COF/CCF (DUI con/sin guiones) |
| `c85459b` | 23-06 | **Cambios iniciales Normativa 2.0** |
| `d370a9f`, `7c5c5db` | 23-06 | Agregado `id_distrito` a cliente (y corrección) |
| `c4c681c` | 29-06 | Revisión factura DTE-01 (FAC) Normativa 2.0 |
| `9671744` | 30-06 | **Adaptar CCF → versión 2 (COF, Factura Consumidor Final)** |
| `d1fef0f` | 01-07 | **Adaptar CCF → versión 4 (Comprobante Crédito Fiscal)** |
| `50620d5`, `01a341e` | 03-07 | **Adaptar FSE → versión 2 (Sujeto Excluido)** |
| `066d86e` | 03-07 | Actualización dump BD (`db/dte_testing.sql` con `id_distrito`) |
| `d3f5c8f` | 08-07 | **Evento de retorno (ERET) según Normativa 2.0**: `clases/EventoDte.php`, `evento_retorno.php`, `js/funciones/evento_retorno.js`, tablas `evento`/`evento_detalle`; elimina `clases/Dte2.php` |
| `e1cbb3d` | 10-07 | Actualización clases Dte, EventoDte y scripts |
| `9973efd` | 14-07 | Marca de agua de anulación en PDF |
| `696a2e1` | 14-07 | Anulación de documento FSE |
| `462cccb` | 15-07 | `observaciones=null` si no hay datos; PDF FSE |
| `980d41f` | 20-07 | Cambios en clase Dte |
| `6c43d49` | 24-07 | Fix impresión `dte_factura.php` |
| `c6566de` | 29-07 | Refactor `clases/Dte.php` (237 líneas) y limpieza `venta.php` |
| `e259df7` | 30-07 | Ajustes Dte + crea `log_DTE_Normativa_2_0.txt` |
| `7b68472` | 31-07 | Cambios en `venta.js` (flujo de venta) |
| `a8df01e` | 04-08 | **Consulta de DTE en MH**: `proxyConsultaDTE.php` + botón en `getAuth.js` |
| `70bbfa9` | 03-09 | **Catálogos `municipioMH` y `distritoMH` (SQL en `db/`)** |
| `86e71b9` | 08-09 | **Correlativos**: `actualizarCorrelativo()` ampliado a NC y EXP |
| `fee6c9c` | 25-09 | **Catálogo `paisesMH.sql` actualizado** |

Commits intermedios de mantenimiento no-DTE (reportes, suscripciones, dashboards, buscador de producto, etc.) quedan fuera del alcance de este documento.

---

## 2. Cambios de base de datos (archivos .sql)

### 2.1 ALTERs estructurales Normativa 2.0 (documentados en `log_DTE_Normativa_2_0.txt`)

```sql
-- 1) Distritos (requisito Normativa 2.0: dirección con departamento/municipio/distrito)
ALTER TABLE `sucursal`  ADD `id_distrito` INT NOT NULL;
ALTER TABLE `cliente`   ADD `id_distrito` INT NOT NULL;
ALTER TABLE `proveedor` ADD `id_distrito` INT NOT NULL;

-- 2) Plazos y períodos de pago (sección "pagos" del resumen, CAT-018)
ALTER TABLE `factura_pago` ADD `periodo` SMALLINT(5) NOT NULL;
ALTER TABLE `factura_pago` ADD `plazo` ENUM('00','01','02','03')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;
```

### 2.2 Correcciones de pertenencia de distritos (ejecutar DESPUÉS de cargar el catálogo)

```sql
UPDATE distritoMH SET id_municipio = 17 WHERE id = 121;  -- MONTE SAN JUAN  → Cuscatlán Sur
UPDATE distritoMH SET id_municipio = 17 WHERE id = 124;  -- SAN CRISTÓBAL   → Cuscatlán Sur
UPDATE distritoMH SET id_municipio = 24 WHERE id = 151;  -- SANTIAGO NONUALCO → La Paz Centro
UPDATE distritoMH SET id_municipio = 14 WHERE id = 19;   -- MASAHUAT        → Santa Ana Norte
UPDATE distritoMH SET id_municipio = 20 WHERE id = 27;   -- ACAJUTLA        → Sonsonate Oeste
UPDATE distritoMH SET id_municipio = 11 WHERE id = 10;   -- ILOBASCO
```

### 2.3 Nuevas tablas para Eventos Normativa 2.0

- **`evento`**: `id_evento` (PK AI), `id_factura`, `tipo_evento` ENUM('EOP','ERET'), `fecha`, `hora`, `id_sucursal`, `id_cliente`, `estado` (0=borrador,1=generado,2=enviado,3=aprobado), `codigoGeneracion` (36), `numeroControl` (50), `json_enviado` (longtext), `selloRecibido` (text), `respuesta_mh` (longtext), `fecha_procesamiento` (datetime), `total` DECIMAL(11,4). Índices sobre `id_factura`, `id_cliente`, `id_sucursal`.
- **`evento_detalle`**: `id_evento_detalle` (PK AI), `id_evento`, `id_factura_detalle`, `id_producto`, `tipo_item` ENUM('PRODUCTO','SERVICIO'), `cantidad`, `precio_unitario`, `descuento`, `subtotal` (DECIMAL 18,4), `exento` (tinyint), `observaciones` (text). Índices sobre `id_evento`, `id_producto`.

### 2.4 Archivos .sql incluidos en los commits

| Archivo | Contenido | Cómo aplicarlo |
|---|---|---|
| `db/v2_2026_09_03_distritoMH.sql` | Dump completo tabla `distritoMH` (estructura + 330 líneas de catálogo con `id`, `codigo`, `nombre`, `id_departamento`, `id_municipio`) | Reemplaza/crea la tabla completa |
| `db/v2_2026_09_03_municipioMH.sql` | Dump completo tabla `municipioMH` (`id`, `codigo`, `nombre`, `id_departamento`) | Reemplaza/crea la tabla completa |
| `db/paisesMH.sql` | **NO crea tabla**: hace `TRUNCATE TABLE paisesMH` + INSERT del catálogo nuevo de países (código ISO) | Solo ejecutar en MariaDB (mensaje del commit). Requiere que la tabla exista |
| `db/dte_testing.sql` | Dump completo de BD de pruebas actualizado (incluye `id_distrito` en `cliente`, datos de `distritoMH`, etc.) | Es un dump de referencia/desde cero, no un migrate |
| `db/alter.sql` | Mezcla de mantenimiento: índices (`stock`, `producto`, `proveedor`, `modulo`), `proveedor.direccion` → TEXT, y módulos nuevos: *Libro compras json* (`libro_compras_json.php`) y *Descargar Factura JSON* (`descarga_dte_json.php`) | Ejecutar completo; los índices `IF NOT EXISTS` son re-ejecutables |
| `db/V1__2026_08_07__alter.sql`, `db/v1__2026_08_19__alter_compra2_table.sql` | Mantenimiento no-DTE (suscripciones, compra2) | Fuera del alcance DTE |

> **Nota:** en el directorio sin trackear `update_mh/` (con `README_actualizacion_tablas_mh.md`) ya existe herramienta propia que automatiza renombrar `municipioMH`/`distritoMH` a `_OLD2026`, aplicar los dumps y agregar `id_distrito`. Puede usarse como alternativa a ejecutar los `db/v2_2026_09_03_*.sql` a mano; incluye además `actualizacion_mh.sql` y `actualizacion_mh_solo_datos.sql`.

---

## 3. Cambios en `clases/Dte.php` (clase activa, Normativa 2.0)

Comparando contra el esquema anterior (visible en `dte/models/Dte.php` como referencia del estado viejo):

### 3.1 Versiones de los DTE (`versionDTE` / constante `DTE_VERSIONS`)

```php
private const DTE_VERSIONS = [
    'FAC' => 2, 'COF' => 2, 'FSE' => 2,   // antes 1
    'CCF' => 4, 'NC' => 4, 'ND' => 4, 'REM' => 4,  // antes 3
    'ANU' => 3,                            // antes 2
];
```
`versionDTE2()` conserva el mapeo antiguo (se deja por compatibilidad/referencia).

### 3.2 `getIdentificacionDte()`
- Contingencia real: consulta `jsonDTE.id_contingencia` → tabla `contingencia` para setear `tipoOperacion=2`, `tipoModelo=2`, `tipoContingencia` y `motivoContin`.
- Reutiliza `codigoGeneracion`/`numeroControl` existentes si el DTE ya fue generado; `fecEmi/horEmi` del día en re-emisión.
- Correlativos **exclusivamente** vía clase `CorrelativoDte` (15 dígitos secuencial, `cod_Est_POS` (4) + `cod_pv` "P###").
- Para `NC` agrega `"fusion" => null`.

### 3.3 `getEmisor()`
- Dirección con **`distrito`** (`charfill("0", $row->id_distrito, 2)`).
- **Eliminado** `tipoEstablecimiento`.
- `codEstable`/`codPuntoVenta` en `null` (ya no `codEstableMH`/`codPuntoVentaMH`).

### 3.4 `getReceptor()`
- Nuevo helper **`obtenerDocumentoReceptor($row, $tipodoc)`**: prioridad NIT(36) → DUI(13) → carnet residente(02) → pasaporte(03) → otro(37); devuelve `['numero' => sin guiones, 'tipo' => código]`.
- **COF/FAC/REM**: ya no llevan `nit`; llevan `tipoDocumento` + `numDocumento`, `nrc=null`, actividad null (default `10005` "Otros" si cliente sin giro).
- **CCF**: `nit` + `nrc` + `nombre` + `nombreComercial` + actividad.
- **NC/ND**: `numDocumento`/`tipoDocumento` en lugar de `nit` (en Normativa 2.0 el `nit` desaparece de estos receptores).
- Dirección con **`distrito`**.
- COF mostrador (`id_cliente=-1` bajo `limite_fe`): numDocumento/tipoDocumento/telefono/correo/dirección en null, nombre desde `extra_nombre`.

### 3.5 `getReceptorSE()` (FSE)
- **`sujetoExcluido` pasa a llamarse `receptor`** en el JSON (v2).
- Requeridos: tipoDocumento("13"), numDocumento(DUI), nombre, codActividad (default `10005`), descActividad, dirección (con **distrito**), teléfono, correo.

### 3.6 Cuerpo del documento
- **COF** (`getCuerpoCOF`): igual estructura; `tributos` desde `fact_imp_combust` (FOVIAL/COTRANS/etc.); `ivaItem` calculado precio-IVA.
- **CCF** (`getCuerpoCCF`): `precioUni` y `descuento` **sin IVA** (`/(1+porcent_iva)`); `tributos=["20"]` + impuestos especiales; sin `ivaItem`.
- **NC/ND** (`getCuerpoNC`): `numeroDocumento` = `codigoGeneracion` del doc relacionado; agrega `noGravado`, `totalIva`, `ivaPerci` (antes `ivaPerci1`), `ivaRete` (antes `ivaRete1`); maneja NC sobre doc con/sin precio IVA incluido según `notaCredito.tipo`.

### 3.7 Resúmenes (`getResumenCOF/CCF/NC/FSE`)
Cambios transversales:
- `ivaRete1` → **`ivaRete`**; `ivaPerci1` → **`ivaPerci`**; **`reteRenta` eliminado**.
- Se agrega **`observaciones`** (desde `factura.datos_extra`, máx 3000 chars, `null` si vacío).
- Sección **`pagos`** obligatoria según `condicionOperacion`:
  - Contado (1): `[{codigo:null, montoPago, plazo:null, periodo:null, referencia:null}]`.
  - Crédito (2): lee `factura_pago` vía `FacturaPago::getFacturaPagoTipo($id_factura,'CRE')` → `plazo` (CAT-018) y `periodo` (días).
- **Específicos:**
  - **COF**: `ivaRete`, sin `reteRenta`, `totalIva` recalculado, `pagos`.
  - **CCF**: `ivaPerci`/`ivaRete`, sin `reteRenta`, `totalDescu` sin IVA, `pagos`, tributo "20" con valor del IVA.
  - **NC**: se **eliminan** `descuNoSuj/descuExenta/descuGravada/subTotal`; se **agregan** `totalIva`, `totalNoGravado`, `totalPagar`, `observaciones`, **`codigoRetencionMH`** (null para CCF).
  - **FSE**: se **elimina** `ivaRete1`; `pagos` incluidos.
- **Sección `extension` eliminada** del JSON completo (ya no se envía a MH).

### 3.8 Anulación / Invalidación (`creaInvalidacionDTE` reescrita)
- Identificación v3: **se eliminan `fecAnula/horAnula`**, se agregan **`fecEmi`, `horEmi`, `fusion`** (fecEmi = fecha de procesamiento del original, salvo COF/EXP/FSE que usa hoy).
- `numeroControl` = `"ANU_{tipoDoc}_{id_factura}"`.
- **Idempotencia**: si ya existe ANU con `selloRecibido` de 40 chars + `fhprocesamiento`, no re-genera; si existe pero inválida, la sobrescribe (`_update`).
- `getDocAnulado()`: **sin `montoIva`** (anulado en 2.0), agrega `codigoGeneracionR` (null), y toma `numDocumento` desde `receptor.numDocumento` (antes `receptor.nit`) para CCF/NC/FSE.
- `getEmisorInvalidacion()`: ahora lleva `codEstableMH`/`codPuntoVentaMH` llenos y `codEstable`/`codPuntoVenta` null; sin `tipoEstablecimiento`/`nomEstablecimiento`; usa punto de venta de la caja para COF/CCF.
- `getMotivoAnulado()`: obtiene DUI/NIT del empleado en sesión (`getDataEmpleadoId`).

### 3.9 Firma
- `generateSignature()` ahora resuelve `id_sucursal` también para FSE vía clase `Compra`, y lanza excepciones descriptivas si no encuentra registro/sucursal. JWT **RS512** con claves RSA por sucursal (`sucursal.pubKey`/`privKey`).

---

## 4. Eventos Normativa 2.0 (nuevo módulo)

| Archivo | Rol |
|---|---|
| `clases/EventoDte.php` (653 líneas, nuevo) | Construye y envía eventos **ERET** (evento de retorno) y **EOP** (operaciones especiales). Constantes `URL_PROD`/`URL_TEST` (`api.dtes.mh.gob.sv` / `apitest...` `/fesv/recepciondte`). Funciones: `getNextEventoNumero`, `getIdentificacionERET`, `getEmisorERET`, `getDocumentoERET`, `getCuerpoERET`, `getResumenERET`, `creaEventoERET`, `creaEventoEOP`, `guardarEvento` (tabla `evento`), `generateSignature` (RS512), `enviarEventoMH` (login + envío desde PHP con `token_diario`), helpers de respuesta MH. |
| `evento_retorno.php` (nuevo) | Backend: `cargar_factura` (trae ítems originales), `guardar_evento` (inserta en `evento`+`evento_detalle`), `generar_eret` (arma el JSON ERET y lo persiste). |
| `js/funciones/evento_retorno.js` (nuevo) | UI: carga factura, edita cantidades/precios/descuentos por ítem, permite agregar servicios, valida que retorno ≤ original, genera el ERET vía AJAX. |
| `admin_factura_rangos_dt.php`, `admin_compras_fecha_dt.php` | Botones/enlaces integrados para lanzar el evento de retorno desde los administradores de facturas/compras. |

---

## 5. Comunicación con Hacienda (JS y proxies)

### 5.1 `js/funciones/getAuth.js` (flujo modal — Admin de Facturas / FSE)
Flujo completo:
1. **Login MH**: `conex(url, user, pwd)` → guarda token con `process=save_token` en `cnx_mh.php`.
2. **Crear DTE**: botón `#btnCreaDte` → `process=creaJsonDTe` contra **`_helper_dte_class.php`** (usa la nueva clase `clases/Dte.php`).
3. **Enviar**: `send()` toma el documento (`process=get_dte` en `generar_dte.php`) y lo manda por **`proxydte.php`** con `Authorization: token` y body `{ambiente, idEnvio, version, tipoDte, documento, codigoGeneracion}` (anulaciones sin tipoDte/codigoGeneracion).
4. **Respuesta MH**: `respuesta_MH()` guarda sello/fhProcesamiento vía `process=save_response_mh` en `generar_dte.php`; si hay observaciones muestra numeradas y **reintenta automáticamente** si el error es correlativo duplicado (`revError` → actualiza correlativo → `crea_dte` de nuevo, flag `reenviado`).
5. **Con sello**: genera PDF (`dte_factura_pdf.php`, CRE→`generar_pdf_dte_cre.php`) y dispara correo; recarga el admin según tipoDoc.
6. **NUEVO 04-08: Consulta de estado DTE** (`#btnConsDte` → `consDteMH()`): consulta por `nitEmisor + tdte + codigoGeneracion` vía **`proxyConsultaDTE.php`** (endpoints `/fesv/recepcion/consultadte/`, detección ambiente 00/01 por URL o body), renderiza respuesta en tabla con `renderJSON()`.
7. **Anulaciones**: `#btnCreaDteNull` → `crea_dteAnula()` valida nombre y DUI del responsable (`isDUI`) antes de `creaJsonDTe` con `anula=1`.
8. **Credenciales**: `updateCred()` sube el certificado (`.key`/cer) por `load_cert.php` (multipart) y guarda frase/usuario vía `enc_user.php`.

### 5.2 `js/funciones/dte_auto.js` (flujo automático POS / venta)
Versión headless del flujo: `crea_dte()` → `send()` (reutiliza token guardado, si no hay lo pide con `conex()` y lo guarda) → `respuesta_MH()` → en éxito genera PDF y **ticket** (imprime por SO: Windows modal iframe `dte/scripts/ticket_dte.php`, Linux POST a impresor local `printik_pista.php` / `printposwin1.php`). En error muestra observaciones y reintenta por correlativo duplicado.

### 5.3 `generar_dte.php` (backend común)
Procesos: `initial`, `save_token`, `get_dte`, `save_response_mh`, `updateCorrelativoDTE`.
- `save_response_mh()`: persiste sello/observaciones en `jsonDTE` (con `actualizarJsonConSello()`), marca `recibidoMH`, y llama `actualizarCorrelativo()`.
- `actualizarCorrelativo()` (commit `86e71b9`): ampliado a **COF, CCF, NC y EXP**; `cod_punto_venta` desde la caja con fallback `?? 1` y saneo `>0`.

### 5.4 `proxyConsultaDTE.php` (nuevo, 04-08)
Proxy PHP con CORS para la **consulta de DTE**: determina ambiente (body `ambiente` o detecta "apitest" en `url_original`), arma URL (`apitest.`/`api.` `.dtes.mh.gob.sv/fesv/recepcion/consultadte/`), valida `nitEmisor/tdte/codigoGeneracion`, reenvía el JSON y devuelve la respuesta MH.

---

## 6. Distritos en la UI y helpers (`_helpers.php`)

Nuevas funciones AJAX en `_helpers.php`:
- `getDistritos($id_departamento, $id_municipio)` — array para `crear_select` (formularios PHP puros).
- `getDistrito()` — devuelve `<option>`s vía POST (`process=getDistrito`) filtrando `distritoMH` por depto+municipio.
- `getDistritoAutocomplete()` — para selects con búsqueda (commit `c93aa10`).
- Cases agregados al switch `$_POST['process']`: `getDistrito`, `getDistritoAutocomplete`.

Archivos de formulario/UI que incorporan el select Distrito (detalle línea por línea en `log_cambios_DTE_jun2026.txt`):
- **Cliente**: `clientes.php` (`getDefaultClientData` + `'id_distrito'=>''`), `agregar_cliente.php`, `editar_cliente.php`, `js/funciones/cliente.js`.
- **Proveedor**: `agregar_proveedor.php`, `editar_proveedor.php`, `js/funciones/proveedor.js`.
- **Sucursal**: `agregar_sucursal.php`, `editar_sucursal.php`, `js/funciones/sucursal.js`.
- **Venta**: `venta.php`, `js/funciones/venta.js`, `js/funciones/preventa.js`, `preventa.php`, `modal_busca.php`, `autocomp_prod.php` (distrito en modal de cliente + búsqueda de producto).
- Patron JS: `$("#municipio").change(...)` → AJAX `getDistrito` → repuebla `#distrito` (select2).

---

## 7. PDFs y tickets
- `dte_factura_pdf.php`: layout 2.0, distrito, marca de agua de anulación (commit `9973efd`), fix de paginación (`6c43d49`).
- `dte_fse_pdf.php`, `dte_fse_pdf2.php`: FSE versión 2.
- `fpdf185/factForm.php`, `facturas_pdf.php`, `_helper_ticket_dte.php`: soporte distrito/datos 2.0 en facturas PDF y tickets.

---

## 8. Otros cambios de soporte
- `clases/FacturaPago.php`: nuevo `getFacturaPagoTipo($id_factura, $tipo)` para leer `plazo`/`periodo` de la cuota de crédito (usado en los resúmenes).
- `clases/CorrelativoDte.php`: `getCorrelativoDte()`, `insertarCorrelativo()`, `updateCorrelativo()` — correlativos DTE por sucursal/punto de venta.
- `js/funciones/update_cor_dte.js` + `update_cor_dte.php`: admin para sincronizar correlativeos DTE.
- `clases/Dte2.php` **eliminado** (su contenido fue absorbido por `clases/Dte.php` en `d3f5c8f`).
- Mantenimiento no-DTE (fuera de la normativa): `clear_img.php`, mensajes de `MovimientoProducto`, `libro_compras_json.php`, dashboards, reportes, suscripciones.

---

## 9. ORDEN LÓGICO DE IMPLEMENTACIÓN (paso a paso)

> Regla de oro: **primero base de datos, luego backend PHP, luego frontend JS, luego formularios/PDF, y pruebas al final en ambiente `00`.**

### Fase 1 — Base de datos
1. **Backup completo** de la BD.
2. Ejecutar ALTERs estructurales (sección 2.1): `id_distrito` en `sucursal`/`cliente`/`proveedor`; `periodo`/`plazo` en `factura_pago`.
3. Crear tablas `evento` y `evento_detalle` (sección 2.3).
4. Cargar catálogos **en este orden**:
   a. `municipioMH` (`db/v2_2026_09_03_municipioMH.sql`) — o vía `update_mh/` (renombra la vieja a `_OLD2026`).
   b. `distritoMH` (`db/v2_2026_09_03_distritoMH.sql`).
   c. **Updates de corrección de pertenencia** (sección 2.2) — *después* de cargar el catálogo, nunca antes.
   d. `paisesMH` (`db/paisesMH.sql`) — solo TRUNCATE+INSERT, requiere la tabla existente.
5. (Opcional) `db/alter.sql` para índices y módulos nuevos (`libro_compras_json.php`, `descarga_dte_json.php`).
6. Verificar que `db/dte_testing.sql` sirva de referencia para validar estructura final.

### Fase 2 — Configuración
7. `.env`: confirmar `DTE_ENVIRONMENT` (`00` pruebas / `01` producción) — la clase lo lee directo.
8. Por cada **sucursal**: cargar `pubKey`/`privKey` RSA (firma RS512 del DTE) y credenciales NIT-DSC MH (`load_cert.php` / `enc_user.php` si se hace por UI).

### Fase 3 — Backend PHP (núcleo)
9. Actualizar clases: `clases/Dte.php` (reemplazo completo), **eliminar** `clases/Dte2.php` si existe, agregar `clases/EventoDte.php`, `clases/CorrelativoDte.php`, `clases/FacturaPago.php` (método `getFacturaPagoTipo`), y los models que usa (`DteModel`, `FacturaModel`, `BaseModel`, `PresentacionProductoModel`).
10. Actualizar `_helpers.php` (funciones de distrito + cases AJAX).
11. Actualizar endpoints: `_helper_dte_class.php`, `generar_dte.php` (con `actualizarCorrelativo` ampliado y `actualizarJsonConSello`), `evento_retorno.php`, `proxyConsultaDTE.php` (nuevo), verificar `proxydte.php` y `cnx_mh.php` vigentes.
12. **Cargar `id_distrito` en los registros existentes** de `sucursal`/`cliente`/`proveedor` (los DTE fallarán sin distrito: es requerido en dirección de emisor y receptor). Los formularios nuevos lo capturan, pero los datos históricos hay que completarlos (al menos los de sucursal/emisor y clientes frecuentes).

### Fase 4 — Frontend
13. JS de comunicación: `js/funciones/getAuth.js`, `js/funciones/dte_auto.js` (los de `dte/js/` son legacy).
14. JS de eventos: `js/funciones/evento_retorno.js`.
15. JS de distritos en formularios: `cliente.js`, `proveedor.js`, `sucursal.js` (handler `#municipio change → getDistrito`), `venta.js`, `preventa.js`.
16. `js/funciones/update_cor_dte.js` + `update_cor_dte.php`.

### Fase 5 — Vistas / formularios / PDFs
17. Formularios con select Distrito: cliente (agregar/editar/admin), proveedor, sucursal, modales de venta.
18. Página `evento_retorno.php` (UI del ERET) y botones en `admin_factura_rangos_dt.php` / `admin_compras_fecha_dt.php`.
19. PDFs/tickets: `dte_factura_pdf.php`, `dte_fse_pdf.php`/`dte_fse_pdf2.php`, `fpdf185/factForm.php`, `facturas_pdf.php`, `_helper_ticket_dte.php`.
20. Menú: inserts de módulos del `alter.sql` (Libro compras json, Descargar Factura JSON) si se requieren visibles.

### Fase 6 — Pruebas
21. Con `DTE_ENVIRONMENT=00` (apitest): emitir un DTE de cada tipo — **FAC(01) v2, COF(02)?? — según tabla `tipodoc`, CCF(03) v4, FSE(14) v2, NC(04) v4, REM(05) v4** — con cliente con y sin distrito, contado y crédito (validar sección `pagos`), item exento y gravado, con impuestos especiales (FOVIAL) y sin ellos.
22. Probar **anulación** de cada tipo (validar fecEmi del original, sello 40 chars, idempotencia al reintentar) y **evento ERET**.
23. Probar **consulta DTE** (`proxyConsultaDTE.php`) con un código de generación recibido.
24. Probar correlativo duplicado (debe reintentar solo una vez) y contingencia (`tipoOperacion=2`).
25. Recién en verde completo: cambiar `DTE_ENVIRONMENT=01`.

---

## 10. Mapa rápido de archivos involucrados

**Clases (backend):** `clases/Dte.php` ⭐, `clases/EventoDte.php` ⭐, `clases/CorrelativoDte.php`, `clases/FacturaPago.php`, ~~`clases/Dte2.php` (eliminado)~~, `dte/models/Dte.php` (legacy, referencia).

**Endpoints PHP:** `_helper_dte_class.php`, `generar_dte.php`, `evento_retorno.php`, `proxyConsultaDTE.php` ⭐, `proxydte.php`, `cnx_mh.php`, `load_cert.php`, `enc_user.php`, `_helpers.php`, `update_cor_dte.php`, `admin_factura_rangos(_dt).php`, `admin_compras_fecha(_dt).php`.

**JS:** `js/funciones/getAuth.js` ⭐, `js/funciones/dte_auto.js` ⭐, `js/funciones/evento_retorno.js` ⭐, `js/funciones/update_cor_dte.js`, `js/funciones/cliente.js`, `js/funciones/venta.js`, `js/funciones/preventa.js`, `js/funciones/sucursal.js`, `js/funciones/proveedor.js` (⭐ = nuevos o fuertemente modificados).

**SQL:** `db/v2_2026_09_03_municipioMH.sql`, `db/v2_2026_09_03_distritoMH.sql`, `db/paisesMH.sql`, `db/dte_testing.sql`, `db/alter.sql` + ALTERs/CREATE TABLEs documentados en `log_DTE_Normativa_2_0.txt`.

**PDF/Tickets:** `dte_factura_pdf.php`, `dte_fse_pdf.php`, `dte_fse_pdf2.php`, `fpdf185/factForm.php`, `facturas_pdf.php`, `_helper_ticket_dte.php`.

**Documentación del autor:** `log_DTE_Normativa_2_0.txt`, `log_cambios_DTE_jun2026.txt`.

---

**Nota de vigencia:** este análisis cubre la fase de implementacion original de
la Normativa 2.0 en el origen (hasta `fee6c9c`, 25-09-2026). Los commits
posteriores con alcance de port (robustez de correo/API key, fix de pagos a
credito y el modulo Evento de Retorno completo) estan documentados en las
secciones 1.9 y 1.10 de `GUIA_PORT_NORMATIVA_2_0_CLIENTES.md`, que es el
documento operativo vigente y el que manda en el alcance del port.
