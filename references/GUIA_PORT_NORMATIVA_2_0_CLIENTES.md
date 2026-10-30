# GUÍA DE PORT DE NORMATIVA DTE 2.0 — de dte_testing hacia un sistema cliente

**Propósito:** guía de trabajo para llevar los cambios de la Normativa de Facturación 2.0 de Hacienda (El Salvador) desde el sistema base (`dte_testing`) hacia un sistema cliente de producción, **portando solo lo de la normativa, no igualando los sistemas**.

**Documento hermano:** `ANALISIS_NORMATIVA_DTE_2.0.md` (análisis original de los 34 commits). Esta guía es la versión operativa: qué tomar de cada commit, qué NO tomar, y las lecciones aprendidas en los ports a sistemas reales ya ejecutados.

---

## 0. PRINCIPIO FUNDAMENTAL (leer antes de tocar nada)

**NO se busca simetría `origen <-> destino`.** Ambos parten de la misma base pero se han ramificado: el origen acumula features de gasolineras/otros clientes (descuentos nuevos, insolvencia, lotes en tickets, WhatsApp) y el destino tiene features propias del cliente (CxP/CxC, validaciones propias, modales distintos). El objetivo es UNO:

> Que el sistema destino emita DTE bajo la Normativa 2.0: JSON v2/v4/v3, distritos en dirección, eventos (ERET), consulta de DTE, catálogos MH vigentes.

Por lo tanto:
- Cada bloque de código que se porte debe **rastrearse a su commit de origen** (`git log -S '<línea>'`) y decidirse con esa evidencia: ¿está en la lista de commits de normativa? → se porta. ¿No está? → se registra como decisión del cliente, no se porta por defecto.
- Los archivos compartidos suelen tener **código propio del destino mezclado** (ej. en un destino real `clases/Factura.php` tenía su propia lógica de caja). **Nunca copiar un archivo completo sin hacer diff antes**; aplicar parches por método/fragmento.
- Los errores que NO se deben repetir están en la sección 5. Léanla.

---

## 1. COMMITS DE NORMATIVA A PORTAR (por tema, con alcance exacto)

### 1.1 Base de datos (ANTES de cualquier código)

| Commit | Qué trae | Archivo SQL | Alcance |
|---|---|---|---|
| `70bbfa9` | Catálogos municipio/distrito | `db/v2_2026_09_03_municipioMH.sql`, `db/v2_2026_09_03_distritoMH.sql` | Tablas completas + datos. **Los dumps ya incluyen las correcciones de pertenencia de distritos y el intercambio Cabañas 10/11 del catálogo v1.1 (07/2026)** |
| `fee6c9c` | Catálogo de países | `db/paisesMH.sql` | TRUNCATE + INSERT (no crea tabla) |
| `d3f5c8f` | Tablas de eventos ERET/EOP | `log_DTE_Normativa_2_0.txt` líneas 38–116 | `CREATE TABLE evento` y `evento_detalle` + índices + AUTO_INCREMENT. **¡No están en ningún dump!** Aplicarlas a mano en la base y regenerar el dump de referencia si se usa sync_schema |
| `d1fef0f` | Plazos/pagos | `log_DTE_Normativa_2_0.txt` | `factura_pago.periodo SMALLINT` + `factura_pago.plazo ENUM('00','01','02','03')` |
| (varios) | Distrito en dirección | `log_DTE_Normativa_2_0.txt` | `id_distrito INT NOT NULL` en `cliente`, `proveedor`, `sucursal` |

**Herramienta:** `db/sync_schema.php` genera el SQL de estructura comparando dumps: `php db/sync_schema.php <dump_origen> <dump_destino> > out.sql`. Solo agrega tablas/columnas faltantes (nunca corrige definiciones existentes ni datos). **La salida del sync_schema NO se poda** (decisión del usuario, 2026-10-28): el .sql final conserva TODO lo que el script genere — tablas y columnas de features no normativas del origen incluidas — y solo se le añaden los bloques aditivos (corrección de catálogos MH, INSERTs de `modulo`, datos correctores). Quitar esquema es decisión manual del usuario sobre el archivo recibido; el error de podar una columna que el código portado lee es asimétrico (producción rota en el primer flujo que la toque — lección real 2026-10-28: PDF fatal por `Unknown column 'lotes'` y notices de giros 2/3 tras una poda "por evidencia" con grep incompleto). Además existen `db/actualizacion_mh.sql` (estructura + catálogos MH, para sistemas sin el paquete) y `db/actualizacion_mh_solo_datos.sql` (solo datos, idempotente por `ON DUPLICATE KEY UPDATE`) — usar el de solo_datos cuando el sync_schema ya llevó la estructura. **Verificar el catálogo del destino antes de asumir**: si `municipioMH` ya tiene Cabañas Este/Oeste con códigos 10/11 y `distritoMH` mapea el este→10/oeste→11, no necesita actualizacion_mh.

**Nota clave sobre `distritoMH.id_municipio`:** guarda el **CÓDIGO del municipio (CAT-013)**, no el `id` de la tabla `municipioMH` (ej. Cuscatlán Norte = codigo 17 = id 26). Verificar siempre bajo esa semántica.

**Verificación completa del catálogo (obligatoria, Cabañas es solo la muestra).** El chequeo de Cabañas Este/Oeste detectó UN caso de ids cruzados; el mismo tipo de error (códigos intercambiados entre municipios hermanos, `id_municipio` apuntando al municipio equivocado) puede existir en cualquier otra fila y la muestra no lo ve. Cuando el destino YA tiene `distritoMH`/`municipioMH`/`paisesMH`, comparar fila a fila contra el dump de origen (fuente de verdad), keyed por `id`: `distritoMH` ~263 filas (`codigo`, `nombre`, `id_departamento`, `id_municipio`), `municipioMH` (`codigo`, `nombre`, departamento) y `paisesMH` (`codigo`, `nombre`). Método: extraer el bloque `INSERT INTO \`<tabla>\`` de ambos dumps y hacer diff normalizado. Divergencia encontrada = el origen manda como contenido y el `id` manda como clave: UPDATE puntual AGREGADO AL FINAL del dump de salida (el out.sql debe quedar autocontenido: estructura + datos correctores en un solo archivo), o el bloque equivalente de `actualizacion_mh_solo_datos.sql` (idempotente) concatenado al mismo out.sql, y anotar cada corrección en el registro. Si el destino no tenía las tablas, no aplica: nacen del catálogo del origen.

### 1.2 Clases núcleo (copiar/mergear)

| Commit | Archivo | Qué tomar |
|---|---|---|
| (rango completo) | `clases/Dte.php` | **Copiar completo** salvo que el destino tenga código propio dentro (hacer diff). Trae: `DTE_VERSIONS` (FAC/COF/FSE=2, CCF/NC/ND/REM=4, ANU=3), `obtenerDocumentoReceptor()` (NIT→DUI→carnet→pasaporte→otro), receptores con `tipoDocumento`/`numDocumento`, dirección con `distrito`, resúmenes con `ivaRete`/`ivaPerci` (antes `ivaRete1`/`ivaPerci1`), sin `reteRenta`, sección `pagos` (plazo/periodo vía `FacturaPago::getFacturaPagoTipo`), `observaciones` desde `factura.datos_extra`, sin sección `extension`, `creaInvalidacionDTE` v3 con `fecEmi/horEmi/fusion` e idempotencia |
| `d3f5c8f` | `clases/EventoDte.php` | Nuevo, copiar completo (eventos ERET/EOP, firma RS512, envío a `/fesv/recepciondte`) |
| (nuevo) | `clases/FacturaPago.php` | Copiar completo: `getFacturaPago()` / `getFacturaPagoTipo($id,'CRE')` para la sección pagos |
| `e1cbb3d` | `dte/scripts/_utiles.php` | Merge selectivo: `estado($id, $tipoDte='')` (soporta '14' FSE), `anuladoDTEData($id,$tipoDoc='')` + `anuladoDTEData2`. **NO tomar** `factDetalle` reescrita (viene de `a0419e2`, fix de tickets, no normativa — la versión con UNION ALL del destino también funciona) |
| (nuevo) | `clases/CorrelativoDte.php` | Suele ya estar; correlativos DTE por sucursal/punto de venta |

**Verificación posterior:** `clases/Contingencia.php` llama `getReceptorSE`/`getResumenDoc`/`getExtension` — comparar con el origen (en la práctica ha resultado idéntico y compatible; `getExtension` sigue existiendo).

### 1.3 Endpoints PHP

| Archivo | Alcance del port |
|---|---|
| `generar_dte.php` | Merge: `save_response_mh()` nueva (separa anula=0/1, sello en json_value, confirma correlativo solo si MH aceptó) + `actualizarCorrelativo()` ampliado a COF/CCF/NC/EXP. **Corregir bug heredado:** `|` bit a bit en `$tipodoc == 'NC' | $tipodoc == 'EXP'` → `||`. **Ojo:** `anular_dte.php` y `consultar_dte.php` tienen copias viejas de `save_response_mh` — idénticas también en el origen; se dejan igual (deuda preexistente) |
| `proxyConsultaDTE.php` | Nuevo, copiar (consulta de DTE ante MH) |
| `evento_retorno.php` | Nuevo, copiar |
| `update_cor_dte.php` | Nuevo, copiar (admin de correlativos) |
| `descarga_dte_json.php` | Nuevo, copiar (botón JSON DTE del admin) |
| `_helpers.php` | **MERGE selectivo, riesgo alto.** Portar: `datos_fact_det()` NO (ver §5), `cliente_factura()` (agrega `c.telefono1`, `c.id_distrito` — telefono requerido en receptor 2.0), `getNombreDistrito()`, `getDistritoAutocomplete()` + su `case` en el dispatcher POST, `obtenerTextoVersionDTE()`, `getDTERecidosFSEMH()`. **NO portar:** `salidaPdf()` con `Output(...,"S")` cambia el contrato para todos los PDFs (a menos que se porte el flujo de correo también), funciones de insolvencia, filtros de movimientos ASIGNACION/TRANSFERENCIA, y **preservar** lo propio del cliente (`getAllCuentasPorPagar/Cobrar`, etc.) |

### 1.4 Frontend JS

| Archivo | Qué tomar |
|---|---|
| `js/funciones/getAuth.js` | Nueva `consDteMH()` vía proxy (+ `renderJSON` si el destino no la tiene). El botón `#btnConsDte`/`consultar_dte.php` suele existir ya |
| `js/funciones/dte_auto.js` | Verificar existencia; incluye `openTicketModal` (requiere modal `#ticketModal` en la página) |
| `js/funciones/evento_retorno.js` | Nuevo, copiar. **Fácil de olvidar** — sin él `evento_retorno.php` abre muda |
| `venta.php` + `venta.js` | Merge selectivo (ver §3) |

### 1.5 Distritos en UI + búsqueda por distrito (commits `70bbfa9`, `c93aa10`, `a873196`)

El objetivo funcional: **poder buscar un distrito por nombre en cualquier formulario y que se rellenen automáticamente municipio y departamento**, sin el orden obligatorio departamento → municipio → distrito.

Componentes (los 4 frentes: cliente, proveedor, sucursal, venta):

1. **Backend** (`_helpers.php`): `getDistritoAutocomplete()` — select2 AJAX que busca por nombre en todo el país y devuelve `{id: codigo, text: "Distrito — Municipio, Departamento", id_departamento, id_municipio}`; y el `case 'getDistritoAutocomplete'` en el dispatcher.
2. **Select normal de distrito** ya existente (dependiente de municipio): conservar — la cascada directa municipio→distrito sigue funcionando.
3. **Select2 AJAX + cascada inversa** en cada JS del formulario:
   - Excluir `#distrito` del init genérico: `$('.select').not('#distrito').select2({...})`.
   - **Excluirlo de TODOS los inits genéricos, no solo del primero** (error 21, port real 2026-10-28): grep de cada `.select2(` genérico del archivo (`$('.select')`, `$('.selectt')`, etc.) y agregar `.not('#distrito')` a todos — la vista de edición suele armar el select con un helper local (`crear_select()`) que asigna clases extra, y el init que corre DESPUÉS del bloque AJAX pisa la búsqueda (síntoma: funciona en agregar_* y no en editar_*). En widgets con estado manda el ÚLTIMO init: verificar orden, no presencia.
   - Inicializar `#distrito` como select2 AJAX (`process: 'getDistritoAutocomplete'`).
   - Al elegir un resultado: `$("#departamento").val(data.id_departamento)` + recargar municipios + recargar distritos y seleccionar el elegido (`trigger('change.select2')`).
   - **Adaptación por destino:** el JS de cada formulario puede diferir (ej. `cliente.js` del origen vs `funciones_cliente.js` del destino — un destino real usó nombres distintos y el cambio se omitió en silencio por no existir el archivo del origen). Buscar el ancla equivalente: el handler `$("#municipio").change` con `process: "getDistrito"` que el destino ya suele tener. **Regla general (ver skill, "Equivalencias funcionales"):** si el archivo del origen no existe en el destino, grep del ancla en TODO el destino, portar al equivalente y anotar el mapeo; nunca omitir por "no existe el archivo". Un frente cuenta como portado solo si la cascada inversa quedó materializada en su archivo real (verificación por comportamiento, no por presencia).
4. **Lectura y guardado:** el backend del formulario debe recibir `distrito` del POST y guardar `'id_distrito' => $distrito` (en `agregar_cliente()` de venta y en los ABM de cliente/proveedor/sucursal que el destino ya suele tener).
5. **Cache-busting:** incluir los JS con `include_js_once()` (versión por `filemtime`) en las páginas de admin de cliente/proveedor — si el destino aún usa `<script src>` fijo, cambiarlo.

### 1.6 PDFs y tickets

| Archivo | Qué tomar |
|---|---|
| `fpdf185/factForm.php` | Copiar completo: agrega `Rotate()`/`_endpage()` — prerrequisito de la marca de agua. Solo adiciones, no rompe nada |
| `dte_factura_pdf.php` | Merge selectivo: `marcaAguaAnulado()` + `$anulado` (desde `jsonDTE.anulado`), línea de distrito (`getNombreDistrito`), línea `obtenerTextoVersionDTE()`, **guards `$json_value['extension'] ?? ''`** (el JSON v2 ya no lleva `extension` — los notices/fatales salen de aquí), include de `dte/scripts/_utiles.php` (por `anuladoDTEData`), prefijo `pdfdtes/` para `output=file` |
| `dte_fse_pdf.php` | Copiar completo (layout FSE 2.0). Requiere helpers arriba. Cambia `facTable.php`→`factForm.php`. El ruteo del correo depende de `getAuth.js` (ver §5 error 17): `genera_pdf_save` con `tipodoc=='FSE'` debe llamar a `dte_fse_pdf.php`, y toda URL a este archivo debe llevar `&tipodoc=FSE` explícito |
| `dte_fse_pdf2.php` | Huérfano en el origen (sin referencias). NO portar |
| `_helper_ticket_dte.php` | Los descuentos/lotes del origen NO son normativa (commits `2dfbf26`, `996bcc9`) → no portar salvo decisión del cliente |
| Reportes FSE | `reporte_dte_emitidos_fse_{pdf,xls}.php`, `descarga_dte_emitidos_fse_{json,pdf}.php` + bloque JS `generaReport` en `funciones_compras_rangos.js` (botones "DTE Emitidos") |

### 1.7 Administradores

| Archivo | Qué tomar |
|---|---|
| `admin_factura_rangos_dt.php` | Bloques "JSON DTE" (`descarga_dte_json.php`) y "Evento Retorno" (COF/EXP con `$recibido` y `!$anuladoDTE`). **Mantener** el `ver_ventas.php` del destino si `ver_fact.php` no existe allí |
| `admin_compras_fecha.php` | Div de botones "DTE Emitidos" (btnPdf/btnXls/**btnJson**/btnAllPdf) |
| `admin_compras_fecha_dt.php` | Lógica FSE: `firmada/recibida/anuladoDTE` con `'14'`, sello de anulación vía `anuladoDTEData($id,'FSE')`, bloque "Evento Retorno" FSE, "PDF Compra" (`pdf_compra.php`). Los renombres `estado→estado2` etc. viajan juntos. Suele poder copiarse completo si no hay código propio del destino |
| Cache-busting | `admin_cliente.php` / `admin_proveedor.php`: incluir los JS con `include_js_once()` en vez de `<script src>` fijo |

### 1.8 Commits recientes del rango (post-primer-port, incluir en el port)

| Commit | Qué trae | Alcance del port |
|---|---|---|
| `dbbfcfa` | `anular()` de `clases/Factura.php` distingue ENTRADA/SALIDA | Portar completo el método: si el movimiento de la factura es ENTRADA (devolución/NC), la reversa se crea como SALIDA y **resta** stock global y de `stock_ubicacion.id_destino`; si es SALIDA conserva el comportamiento original (suma al `id_origen`). Sin esto, el eraser revienta con "No se encontro el registro de stock ubicacion para el ID 0" al anular una NC (la devolución inserta su SU con `id_origen=0` por diseño en `devolucion.php`), y el flujo de dos pasos (eraser → `anulada=1` → aparece "DTE Anulación") nunca llega a MH |
| `3ccb8ef` | Cache-busting de JS en admins | `admin_cliente.php` y `admin_proveedor.php`: reemplazar los `echo <script src>` fijos por `include_js_once('js/funciones/...')` (versión por `filemtime`; la función ya existe en `_helpers.php` del destino) |
| `bc1a86a` | Sello del evento de retorno visible en el admin | `dte/scripts/_utiles.php`: función `eventosERET($id_factura)` (consulta tabla `evento`, tipo ERET, con sello). `admin_factura_rangos_dt.php`: en la columna de estado, para COF/EXP mostrar "SELLO EVENTO RETORNO" por cada ERET aceptado. **Requisito real: la tabla `evento` debe existir y tener filas** — indagar contra datos reales, no dar por bueno el port solo porque compile |
| `050e6607` | Coherencia en admin de compras: "Anular" de `admin_compras_fecha_dt.php` solo se renderiza con `anulada==0` (sobre una compra anulada, el único desenlace era el throw de `anular_compra.php`) | Portar SIEMPRE: es la materialización del principio de la FASE 2.5 (acción visible = acción ejecutable). Verificar que el destino lea `anulada` antes del bloque del menú; el guard del backend queda como segunda barrera |
| `f996452` | Descarga individual del JSON: `descarga_dte_json.php` (nuevo; sirve `{codigoGeneracion}.json` con sello inyectado, solo DTE con codigoMsg 001/002) + ítem "JSON DTE" en el menú de `admin_factura_rangos_dt.php` + **INSERT en `modulo`** (en el origen va en `db/alter.sql`, menú facturación `id_menu=5`, `mostrarmenu=0`) | Portar el commit COMPLETO (3 archivos): endpoint, ítem de menú y la línea del INSERT en el `alter.sql` del destino (adaptada a su convención local — el commit la trae así y así queda registrada para futuras instalaciones). Además, integrarlo al flujo de migración: al generar el `out.sql` con `sync_schema.php`, consultar contra la BD del destino `SELECT ... FROM modulo WHERE filename='descarga_dte_json.php'`; si la fila no existe, **agregar el INSERT al final del out.sql** que se pasará a producción, resolviendo el `id_menu` contra la BD del DESTINO (`SELECT id_menu FROM modulo WHERE filename='admin_factura_rangos.php'`, no asumir el 5 del origen) y `mostrarmenu=0`. Sin la fila en `modulo`, `permission_usr` devuelve NOT para operadores y solo funciona con admin (bypass): "funciona con admin" NO valida el port. Ojo: el endpoint consulta `factura JOIN jsonDTE` — solo sirve DTE de venta, no FSE de compra |

### 1.9 Commits post-normativa de robustez (portar junto con el rango)

| Commit | Qué trae | Alcance del port |
|---|---|---|
| `3ae94bc` | Correo al anular DTE, revisado: en `sendMailAnula` de `generar_dte.php` — API key por constante `API_MAIL` (viene de `obtener_api_key.php`), guard `EMAIL_CC=false`, adjuntos = JSON de anulación + **PDF del DTE invalidado regenerado con marca de agua** (petición HTTP interna a `dte_factura_pdf.php?output=screen` vía cURL, con `session_write_close()` antes para no bloquearse con el lock de sesión), try/catch alrededor de `sendEmail`, texto del correo actualizado; fix `|`→`||` en `actualizarCorrelativo`; en `venta.php`, guard de correo vacío en `updateClientMail()` | El guard de correo y el try/catch ya se portan por §5; el adjunto PDF vía cURL es opcional — decisión del cliente (requiere que `dte_factura_pdf.php` con `output=screen` no mande correo y que cURL esté disponible). El guard de `updateClientMail` portarlo siempre |
| `8b3bec3` | Fix "Cannot use object of type stdClass as array": `getFacturaPagoTipo()` devuelve objeto pero el bloque de crédito de `Dte.php` accedía `['periodo']` — facturar a crédito y crear DTE reventaba | Portar siempre: unificar TODOS los bloques de sección pagos con `(object)` cast + `periodo>0 ? periodo : 30` + `plazo` vacío → `'01'` (detalle en §5.16) |
| `72579ed` | Carga condicional del API key (soluciona lo que `3ae94bc` dejó roto): `require_once('../obtener_api_key.php')` solo si `file_exists`, y `$apikey = defined('API_MAIL') ? API_MAIL : ($_ENV['API_MAIL'] ?? '')` en `dte_factura_pdf.php` y `generar_dte.php`; fix de ruta vendor en `clases/EventoDte.php` (`../vendor` en vez de `../../vendor`) | Portar siempre la carga condicional + fallback (muchos destinos no tienen `obtener_api_key.php`). En destinos con layout plano sin `dte/scripts/`, adaptar rutas. Verificar el `.env` del destino: si define `API_MAIL`, nada más hace falta |
| `eaf6d25` | Correo automático FSE y blindaje general del envío: (1) **rama FSE en `sendMailAnula`** (`generar_dte.php` y `consultar_dte.php`): 4º parámetro `$tipodoc`; con `FSE` el DTE se lee de `jsonDTE` con `tipoDte='14'` (el `id_factura` es el `id_compra` y `datos_factura()` leería el JSON equivocado de la tabla `factura`) → sin esto, anular un FSE daba "Zero recipients"; los 4 call sites (`save_response_mh` y `save_response_mhOld`) pasan `$tipodoc`; (2) **ruteo FSE en `genera_pdf_save`** de `getAuth.js` (~385) y `dte_auto.js` (~265): `tipodoc=='FSE'` → `dte_fse_pdf.php` (antes todo iba a `dte_factura_pdf.php` y el correo FSE muerto en silencio); (3) include protegido de `obtener_api_key.php` + fallback + guard `EMAIL_CC` + try/catch en `consultar_dte.php`, `generar_pdf_dte.php` y `dte_fse_pdf.php` (completando lo que `72579ed` cubrió en 2 archivos); URL del PDF regenerado en `sendMailAnula` → `dte_fse_pdf.php?tipodoc=FSE&output=screen` cuando es FSE | Portar siempre (1) y (2) — son causa directa de correos FSE que nunca llegan, tanto al validar como al anular. (3) portarlo en cada archivo que envíe correo que el destino tenga. El destino puede tener estructura distinta en `sendMailAnula` (sin adjunto PDF por cURL): portar la rama FSE y el parámetro aunque el resto del cuerpo difiera |
| `fa74ff5` | **Sesión aislada por proyecto**: `session_name(preg_replace('/[^A-Za-z0-9_-]/', '', basename(__DIR__)) ?: 'DTE_SESS')` antes de CADA `session_start()` del sistema. Los proyectos hermanos alojados bajo el mismo dominio en subcarpetas (`url/carpeta1`, `url/carpeta2`) comparten la cookie `PHPSESSID` por defecto → se pisan `$_SESSION` y el logout de uno desloguea del otro. Aplicado en `_session.php`, `login.php`, `logout.php`, `login_admin.php`, `sessionRenew.php`, `activa_insolvencia.php` | Portar siempre (una línea, sin riesgo). **No copiar a ciegas**: localizar TODOS los `session_start()` del destino y anteponer la línea en cada uno; si algún archivo que inicia sesión está en un subdirectorio, `basename(__DIR__)` daría la subcarpeta y no el proyecto — usar la ruta raíz del destino. Avisar al cliente que las sesiones activas se invalidan (un solo re-login tras desplegar). No aplica como problema en producción con un dominio por cliente, pero blinda entornos de desarrollo/prueba multi-proyecto |
| `f675e29` | **Retención de IVA en Notas de Crédito (NCE v4)**: Hacienda rechaza con `[resumen.ivaRete] CALCULO INCORRECTO` o recibe con observación `[cuerpoDocumentoN.ivaRete]` si la retención no calza en ambos bloques. Regla validada en ambiente 00: el `ivaRete` de cada ítem debe ser su proporción de la retención (tasa derivada `rete_iva / gravado total` del cuerpo, aplicada a cada `ventaGravada`), el `resumen.ivaRete` debe ser la SUMA de los ítems (nunca repetir el total en cada ítem: N ítems × total no suma), y `montoTotalOperacion` va BRUTO (`totalGravada + IVA`, sin descontar retención) con `totalPagar = montoTotalOperacion - ivaRete` | Portar siempre en el `getCuerpoNC`/`getResumenNC` del Dte.php VIVO del destino (puede ser `clases/Dte.php` o `dte/models/Dte.php` según el layout — seguir el require del endpoint real de emisión). Con 0 de retención todo queda 0 y también valida. Ojo: en CCFE-v4 los ítems NO llevan `ivaRete` (solo el resumen); la regla del prorrateo aplica a NCE/ND. Sin este fix, la primera NC multi-ítem con retención del cliente se rechaza. **Validación post-port en el destino (obligatoria):** verificar de dónde saca el destino la retención de la NC (`factura.rete_iva`/`rete_renta` en el origen — comparar origen↔destino: si el destino usa otras columnas o el flujo de devolución no las arrastra, adaptar antes de dar por bueno el port). Cierre con un caso real: NC multi-ítem con retención emitida a ambiente 00 — el `ivaRete` de los ítems debe sumar exactamente el del `resumen` y `totalPagar` debe cuadrar; y una NC sin retención (todo en 0) |
| `4f5e25c6` | **Fix COF con retención** (`getResumenCOF` de `clases/Dte.php`): `montoTotalOperacion` se calculaba como `$total_final` (= `factura.total`, que ya viene NETO de la retención 1%) → MH rechazaba con `[resumen.montoTotalOperacion] CALCULO INCORRECTO`. Su acierto: el monto de la operación va ANTES de retenciones/percepciones; `ivaRete`/`ivaPerci` solo reducen `totalPagar`. Su defecto: restó `$totalDescu` de `$subTotal`, pero `subTotal` ya viene neto del descuento — **CORREGIDO por `ac76119` (2026-10-30, validado en ambiente 00 con COF/CCF × con/sin descuento y retención)** | **Ya NO portar este tal cual**: su fórmula `subTotal - totalDescu` es incorrecta y `ac76119` la revierte. Portar en su lugar el estado final de `ac76119` (fila siguiente). La regla que sí sobrevive de este commit: monto bruto antes de retenciones, ivaRete solo baja totalPagar |
| `dcb9f34` | **Redondeo del total en el modal de pagos**: `#monto_pago` se muestra con 4 decimales (ej. `246.7965`); los 3 puntos de `venta.js` que lo leían con `parseFloat` crudo arrastraban el tercer dígito y el cálculo de pagos/saldo pendiente no cuadraba (saldo fantasma por centavos) | Portar siempre: envolver las lecturas de `$('#monto_pago').text()` en `round(..., 2)` en `venta.js` (bloques `calcularTotalPagado`, `input .montoPago` y `keyup #credito`). En destinos donde `monto_pago` ya se muestre a 2 decimales el fix es inocuo, pero lo blind contra cambios de formato |
| `47942220` + `f67299bc` | **Aviso de suscripción pendiente de pago**: `aviso_suscripcion.php` NUEVO (función `render_aviso_suscripcion()` con config interna: `activo`, intervalo, countdown del botón "Entendido", link WhatsApp de cobro, imágenes `assets/img/invoice.png` y `assets/img/warning.png`) + include y llamada en TODOS los footers que renderizan HTML (`footer.php`, `footer2.php`, `footera.php`, `footerb.php` — ojo: `footera.php` tenía líneas en blanco que el commit reemplaza). `f67299bc` corrige el error del primero: dejó `'activo' => true` en el origen y lo apagó a `false` | **Portar SIEMPRE, sin preguntar, y DESACTIVADO por defecto** (estado FINAL: 47942220 con el flag de f67299bc ya en `'activo' => false` — ambos commits van en conjunto, no se porta uno sin el otro y no se pregunta al cliente). Copiar `aviso_suscripcion.php` + **la carpeta `assets/img/` con sus imágenes** (`invoice.png`, `warning.png` — si el destino ya tiene `assets/img/`, solo copiar los PNG faltantes) + las 2 líneas en cada footer del destino (localizar los footers REALES del destino, no copiar la lista del origen). **Verificación obligatoria post-copia: `ls` de `assets/img/invoice.png` y `assets/img/warning.png` en el DESTINO** — los assets son lo que más se olvida al portar este commit (queda el .php con imágenes rotas); el port NO se da por bueno sin ese `ls`. No incluir en endpoints JSON/PDF (la función ya lo documenta). Activarlo después es decisión del cliente poniendo `'activo' => true` (su WhatsApp/texto son de cobranza de OSS — verificar/adaptar el contacto antes de activarlo), pero el código SIEMPRE va portado |
| `fc521e34` | **Redirigir al dashboard si hay sesión activa en login**: al inicio de `login.php` (tras `session_start()`), `if (!empty($_SESSION['usuario'])) { header('Location: dashboard.php'); exit(); }`. De paso simplifica la redirección post-login (el if de admin/no-admin que llevaba al mismo lugar). Complementa `fa74ff5` (sesión aislada por proyecto): con cookie de sesión propia por proyecto, si el usuario ya está logueado y cae en `url/proyecto/login.php`, ya no puede reloguearse encima | Portar siempre (junto con `fa74ff5`). Verificar que el destino tenga `dashboard.php` como página post-login (si su home es otra, redirigir a esa). Ubicar el guard DESPUÉS del `session_name()`/`session_start()` que trae el port de `fa74ff5` |
| `ac76119` | **DTE con descuento, resumen correcto** (`clases/Dte.php`, validado en ambiente 00 con 4 casos COF/CCF × con/sin retención, mismos ítems+descuento): (1) `getResumenCOF`: `montoTotalOperacion = $subTotal` — corrige `4f5e25c6`, que restaba `totalDescu` de nuevo (subTotal ya viene neto del descuento: `ventaGravada = cantidad*precioUni - montoDescu` por ítem) → MH rechazaba COF con descuento con `[resumen.montoTotalOperacion] CALCULO INCORRECTO`; (2) `getResumenCCF`: `totalDescu = factura.descuento` SIN dividir por `(1+iva)` — el escritor ya guarda el descuento de CCF sin IVA (= suma de los `montoDescu` del cuerpo) y la división era doble conversión → observación `[resumen.totalDescu] Calculo incorrecto` (sello otorgado igualmente). Regla de fondo: el resumen NO re-convierte lo que el escritor ya normalizó por tipo de doc (COF guarda descuento CON IVA, CCF SIN IVA) | Portar siempre en el Dte.php VIVO del destino, verificando PRIMERO la semántica de `factura.descuento` del destino contra datos reales (factura COF con descuento + CCF con el mismo descuento: si COF la guarda con IVA y CCF sin IVA, es el mismo layout del origen y el port aplica directo; si el destino unifica, adaptar). Validación post-port en ambiente 00: una COF con descuento (monto = subTotal, totalDescu = suma montoDescu ítems), un CCF con el mismo descuento (totalDescu sin observación), y si aplica retención 1%: monto bruto, ivaRete solo baja totalPagar. Nota: `getResumenNC`/`getResumenFSE` del origen conservan la división `(1+iva)` — no portarla a COF/CCF, pero tampoco "alinear" NC/FSE con este fix sin evidencia MH (NC sin descuento ya valida; NC con descuento sin probar) |

**Nota de orden:** `72579ed` corrige un defecto introducido por `3ae94bc` (el require incondicional de `obtener_api_key.php` revienta en sistemas sin ese archivo). Si se porta `3ae94bc`, portar también `72579ed`; nunca quedarse solo con el primero.

### 1.10 Módulo Evento de Retorno completo (rango `251779b`..`8c3ce17`)

El ERET nació con la normativa y este rango lo completa: stock, correo al
receptor, representación gráfica y blindaje de validaciones. Varios commits
corrigen a los anteriores: **portar el estado final del rango completo**, no
commits sueltos.

| Commit | Qué trae | Alcance del port |
|---|---|---|
| `251779b` | ENTRADA de stock al crear un ERET (retorno fisico de productos a inventario), con MovimientoProducto, ubicacion bodega-preferente, unidades base y lote generico. Referencia `ERET-<id>` sin consumir correlativo | Portar como parte del estado final (ojo: `8c3ce17` mueve la entrada a post-sello; lo que porta es la version final en `insertarStockERET`) |
| `512101b` | Correo al receptor al aceptarse el ERET: `sendMailERET` (JSON del evento + factura regenerada con marca de agua) y `marcaAguaRetorno()` en `dte_factura_pdf.php` (leyenda RETORNO + sello del evento cuando la factura tiene ERET validado) | Portar. La leyenda de la marca quedó corregida a "SELLO EVENTO DE RETORNO" dentro de `eb95134`; verificar que el destino no herede el texto "SELLO ANULACIÓN" copiado |
| `b2e05f6` | Validacion de saldo por item: "Ya Retornado" por linea (vista y backend) contra lo vendido; el input de cantidad arranca en 0 con max al saldo | Portar (ver ojo de `8c3ce17`: la sumatoria cambia de criterio) |
| `9e4d707` | Merge de integracion (`512101b` + `b2e05f6`); no aporta contenido propio | No porta nada por si solo |
| `eb95134` | Representacion grafica del ERET: `evento_retorno_pdf.php` NUEVO con el mismo formato visual del `dte_factura_pdf` (3 QR, columnas del DTE, pie de totales), el codigo de generacion del DTE afectado en cada linea del detalle, sin numero de control; se adjunta al correo; corrige la leyenda de la marca | Portar completo. Dependencias del PDF nuevo: `clases/Dte.php`, `num2letras.php`, `AlignMarginText.php`, `factForm.php` con `Rotate()` (del port base) |
| `e4ca90d` | El adjunto JSON del evento era el JWT firmado (base64 ilegible): `creaEventoERET` devuelve tambien `json` decodificado y el correo usa ese; fix de `totalLetras` en `EventoDte` | Portar |
| `c002011` | Incrustar `selloRecibido` en el JSON adjunto al correo (mismo patron de `save_response_mh` para DTEs) | Portar |
| `8c3ce17` | Blindaje del ERET: las validaciones de saldo/tope monetario cuentan SOLO eventos aceptados por Hacienda (paridad con `jsonDTE.recibidoMH=1` y sello); el stock ENTRA al recibir el sello (`insertarStockERET`), no al guardar; un evento aceptado no se reenvia; revalidacion al transmitir; el precio de retorno no puede superar el original | Portar SIEMPRE: sin esto, eventos rechazados consumen saldo y meten stock fantasma. Incluye helpers `eretRetornadoAceptado`/`eretTotalAceptado`/`validarSaldosERET` |

**Commit posterior al rango (2026-10-20):** `6007e66` — **manejo de lotes en el
ERET** (solo `evento_retorno.php`; la mayor parte del diff es re-format, la
sustancia está en `insertarStockERET`). Antes, un producto con
`seguimiento_lote == 1` BLOQUEABA el evento ("el evento de retorno aun no
soporta seleccion de lote"). Ahora el retorno reingresa a LOS MISMOS lotes de
la venta original: (1) lee de `movimiento_producto` (SALIDA de la venta, por
`mp.id_factura`) + `movimiento_producto_detalle` cuánto salió de cada lote —
una venta pudo salir de dos o más lotes; (2) descuenta lo ya reingresado por
ERETs previos de la MISMA factura (ENTRADAs con `referencia LIKE 'ERET-%'` y
`concepto LIKE '%FACTURA ID <id>'`; la anulación usa otra referencia y queda
excluida); (3) reparte la cantidad a retornar FIFO en el orden de la venta,
respetando el saldo pendiente por lote (vendido − ya retornado); si no alcanza,
excepción (solo `error_log`: la transmisión a MH ya ocurrió). Los productos SIN
lote quedan igual que antes (lote genérico `gestionarLote`).

**ADAPTAR, NO COPIAR — el soporte de lotes varía por destino.** Este commit
asume la estructura de inventario del ORIGEN: columna `producto.seguimiento_lote`,
tablas `movimiento_producto`/`movimiento_producto_detalle` con `id_factura` e
`id_lote`, y la clase `Lote` (`getInstance`/`gestionarLote`). **Antes de
portarlo, verificar en el DESTINO si se manejan lotes**: ¿existe
`seguimiento_lote` en su tabla `producto`? ¿sus movimientos guardan lote y
quedan ligados a la factura? Si el destino NO maneja lotes, portar SOLO la rama
de lote genérico (o conservar la lógica de stock que quedó del port base) y NO
arrastrar las queries de lotes. Si maneja lotes con otra estructura (columnas o
tablas distintas), adaptar la detección de lotes de venta a SU esquema. `php -l`
no detecta nada de esto: hay que mirar el esquema/dump del destino.

**Archivos del modulo** (estado final = rango + `6007e66`): `evento_retorno.php`,
`evento_retorno_pdf.php` (nuevo), `js/funciones/evento_retorno.js`,
`clases/EventoDte.php`, `dte_factura_pdf.php` (marca de retorno). En destinos
con anulacion procedural (sin `MovimientoProducto`), `insertarStockERET`
adapta al flujo propio del destino, como se hizo con `anular_factura`.

**Revisados y excluidos del rango:** `5eb8dd6` (regeneracion del dump de
referencia del origen; los dumps son por cliente, no porta) y `d006843`
(resolucion de conflicto interna del merge `9e4d707`, viaja con el).

**Principio transversal de estos commits (y de todo el port): indagar que los datos son reales.** No basta con que el código compile y los archivos estén: verificar contra los datos verdaderos del sistema — que la tabla `evento` exista y tenga registros con `selloRecibido`, que la factura de prueba realmente tenga su movimiento ENTRADA con `id_origen=0`, que el sello mostrado corresponda a una transmisión aceptada por MH. Los diffs dicen qué cambió; la BD dice si el cambio tiene efecto.

### 1.11 Módulo de descarga pública del DTE (carpeta `downloads/`, commit `39fa0ec`)

**Qué es el módulo** (existía desde antes del commit): el QR impreso en el PDF
del DTE (lo arma `_helper_ticket_dte.php`, que se porta en el rango normativo)
apunta a `downloads/descargadte.php?codigoGeneracion=...&e=<empresa>&l=<logo>`:
una landing pública ("Centro de Descargas DTE") SIN sesión, con dos botones que
sirven el DTE por Código de Generación: `downloads/dteqr_pdf.php` (PDF) y
`downloads/dteqr_json.php` (JSON). Son los endpoints por los que el RECEPTOR
descarga su DTE; por ser públicos no usan `_core.php`, sesión ni correo.

**`39fa0ec`** ("Fix pdf de descarga (import desde Granos Moreno)", 2026-10-21)
reescribe ambos endpoints y toca una línea de `clases/Dte.php`:

| Archivo | Qué trae |
|---|---|
| `downloads/dteqr_json.php` | Sirve el JSON EXACTO firmado y sellado (`json_value`) sin re-encoding NI escritura a disco (antes lo escribía en `jsondte/` y lo descargaba con headers de Excel `application/vnd.ms-excel`). Headers correctos (`application/json` + Content-Disposition/Length), guard 400 sin `codigoGeneracion`, 404 DTE inexistente, `display_errors 0` (un notice contaminaba la descarga) |
| `downloads/dteqr_pdf.php` | Reescritura completa con el MISMO diseño del `dte_factura_pdf.php` Normativa 2.0 (funciones `headerEmisorReceptor`/`generarCuerpo`/`generarPie`, helpers de sucursal/giro/departamento/municipio/distrito vía `BaseModel`, `textoVersionDTE`). NUEVO: marca de agua "ANULADO" en diagonal + sello de anulación (lee el `selloRecibido` del `jsonDTE` tipoDTE='ANU') — la versión anterior no la tenía. Autocontenido: solo FPDF/phpqrcode/vendor/modelos, sin sesión |
| `clases/Dte.php` | 1 línea en `getCuerpoNC`: inicializar `$sumaGravadaBody = 0;` antes del foreach que calcula la base del prorrateo de `ivaRete` (complemento de `f675e29`, §1.9) — sin ella, una NC con TODOS los ítems exentos reventaba por variable indefinida |

**Alcance del port:** verificar que el destino TENGA el módulo
(`downloads/descargadte.php` + `dteqr_pdf.php` + `dteqr_json.php`): es la
contraparte del QR que viaja con `_helper_ticket_dte.php`; si falta, el QR del
PDF lleva a un 404 y el receptor no puede descargar su DTE. Portar el estado
final de los 3 archivos. Adaptaciones típicas: (a) las rutas
`__DIR__.'/../fpdf185|vendor|clases'` asumen el layout del origen — verificar
que resuelvan en el destino; (b) los helpers de distrito/giro consultan con
`BaseModel` las tablas que el destino ya tiene — confirmar que existan y se
llamen igual; (c) si el destino tiene su landing `descargadte.php` con branding
propio, portar solo los endpoints. Ojo: `dteqr_pdf.php` lee la venta con
`FacturaModel::find` — sirve DTEs de VENTA (no FSE de compra), igual que
`descarga_dte_json.php` de §1.8. La línea de `Dte.php` va junto con `f675e29`:
si se porta ese, llevar también esta inicialización.

---

### 1.12 Modal de reenvío de DTE (`d0ad9de`, 2026-10-23 — HEAD del origen)

**Qué es:** reenvío MANUAL del DTE por correo mediante un MODAL en el admin de
facturas: el operador elige destinatario (prefill con el email del cliente) y
CC, puede guardar el correo en la ficha del cliente, y el envío lo hace
`dte_factura_pdf.php` devolviendo resultado en JSON. NO reemplaza al envío
automático ni al viejo "Enviar PDF DTE" (`output=text`): conviven los tres.

**Archivos y alcance del port:**

| Archivo | Qué tomar |
|---|---|
| `enviar_correo_dte.php` | NUEVO, copiar a raíz. Es el modal (formulario destinatario/CC + botón Guardar correo + Enviar DTE) con dispatcher `initial`/`get_dte`/`update_client_email`. Depende SOLO de piezas que el destino ya tiene: `_helpers.php` (`tipoDTE`, `getDte`, `datos_factura`, `cliente_factura`, `getHeadDocDTE`), `_helper_dte_class.php` (`get_DteJson`), `_helper_html_mail.php`, vendor. Verificar que esas funciones existan en el destino antes de copiar (todas suelen venir con el rango base) |
| `_helpers.php` | Agregar `tipoDTE($tipoDoc)` (mapa alias→código MH: FAC/COF→01, CCF→03, REM→04, NC→05, ND→06, FSE→14, ANU→''). **Verificar colisión de nombre** con funciones propias del destino antes de insertar (grep `function tipoDTE`); duplica el mapa de `getTipoDTEFromDoc` pero es a la que llama el modal — portarla igual, no "optimizar" unificando |
| `admin_factura_rangos_dt.php` | SOLO el ítem de menú "Reenviar DTE" (`enviar_correo_dte.php?id_factura=X&tipodoc=Y` en `#viewModal`, con `permission_usr`), dentro de la rama de DTE recibido, junto a JSON DTE/Ticket DTE. **NO portar** el refactor cosmético de índices `dt` (`$col+=1`) ni el borrado de código comentado: el destino conserva sus índices duros |
| `dte_factura_pdf.php` | (1) `ob_start()` al inicio + al final, para `output=file`, vaciar buffers (`while ob_end_clean`) y responder **JSON** `{success, message}` con `exit` — es la respuesta que el modal consume; los `display_errors` activos hacen este ob_start imprescindible (un warning contaminaría el JSON). (2) Nuevos params `destinatario`/`cc` (trim de `$_REQUEST`). (3) Rework del bloque de correo `text|file`: destinatario del modal tiene PRIORIDAD sobre el del cliente; validación de formato de destinatario y de lista CC (separadores `,`/`;`); errores acumulados en `$errores` y `$respuesta`; **si el modal no manda CC se usa `false`, NO `EMAIL_CC` del .env en esta ruta** (cambio de comportamiento documentable: el CC global queda fuera del envío por esta vía). (4) Los handlers de `getAuth.js` (abajo) |
| `js/funciones/getAuth.js` | Al final: helpers `esEmailValido`/`validarListaEmails`, handler `#btnSendMail` (valida correos en cliente, POST a `dte_factura_pdf.php` con `output=file&destinatario&cc` — encodear con `encodeURIComponent` — y muestra el `success/message` del JSON; rama `CRE` → `generar_pdf_dte_cre.php`), handlers `#destinatario` (color del botón Guardar si cambió vs `data-original`) y `#btnSaveEmail` (confirm + POST `process=update_client_email` a `enviar_correo_dte.php` + resincroniza `data-original`) |

**Notas de port (todas verificadas contra el origen):**

- **Rama CRE muerta:** `generar_pdf_dte_cre.php` NO existe en el propio origen;
  la rama `if (tipodoc === 'CRE')` del modal es herencia del `genera_pdf_save`
  preexistente. Portarla tal cual (inerte: los tipos del admin de facturas
  jamás valen 'CRE') y anotarla, o recortarla — pero NO "arreglarla" creando el
  archivo.
- **Compatibilidad del JSON en `output=file`:** el flujo automático
  (`genera_pdf_save` de `getAuth.js`/`dte_auto.js`) manda `output=file` y
  IGNORA el cuerpo de la respuesta → el nuevo JSON no lo rompe. Verificar en
  el destino que ningún OTRO llamador dependa de que `output=file` devuelva
  binario/HTML antes de portar el cambio.
- **El correo automático queda en silencio:** con este commit el envío
  automático (`output=file` sin modal) no usa `EMAIL_CC` del .env (ver arriba);
  si el cliente depende de ese CC global para el envío automático, es decisión
  que debe quedar en el registro.
- **Convivencia de 3 vías de envío:** automática (sello → `genera_pdf_save`),
  "Enviar PDF DTE" (`output=text`, al email del registro del cliente) y el
  modal "Reenviar DTE" (destinatario/CC elegibles). La traza de la FASE 2 y la
  coherencia de la FASE 2.5 deben cubrir las TRES: cada control hace lo que su
  etiqueta dice y el JSON del modal llega íntegro al navegador.
- **Backend del "Guardar" correo:** `update_client_email` valida email,
  compara contra el actual (`strcasecmp`) y hace `UPDATE cliente SET email`
  escapado con `addslashes` — portar tal cual.

---

## 2. COMMITS NO NORMATIVOS DETECTADOS (no portar por defecto)

Trazados con `git log -S`. Están en el rango temporal o cerca, pero NO son normativa:

| Commit | Qué es | Decisión |
|---|---|---|
| `a0419e2` | Rewrite de `datos_fact_det()`/`factDetalle()` con CASE/LEFT JOIN (fix de tickets de un cliente) | No portar. La versión con UNION ALL (que el destino suele tener) también cubre servicios |
| `2dfbf26` | Descuentos por ítem en ticket | No portar salvo decisión |
| `996bcc9` | Lotes en ticket (2025) | No portar salvo decisión |
| `7c8d0e5` | **Correo en modal de pago** (capturar/validar email del cliente al cobrar, `updateClientMail()`) | **Portar si el cliente lo quiere** (en los ports ya ejecutados: sí). Fragmeto: campo `#correo_cliente` en modalPago, prefill desde `#email_cliente` (deshabilitado para mostrador), `correo_cliente` en payload, `updateClientMail()` + require `Cliente.php`. **OJO: el modal de pago de venta cambia entre sistemas — revisar su estructura en cada destino antes de insertar el campo** |
| `e558da4` | `validarClienteCCF` (restringir CCF a contribuyente) — anterior al rango | Opcional (útil para cumplimiento). Requiere función PHP `validar_cliente_ccf()` + cases + JS |
| `d994c00` | `showAlertDte` (popup de generación DTE automática tras vender) — anterior al rango | Opcional. Cambia el cierre del POS: requiere `dte_auto.js` incluido en venta.php |
| `74934a0` | Preventa con lotes (`obtener_lotes_factura`, `addPreventaItem`) | Opcional; el destino suele tener su propio `cargar_ref` |
| (varios) | WhatsApp en PDFs (`envia_wa_evolution`) | **NUNCA portar tal cual**: trae credenciales hardcodeadas de otro cliente |

Otros elementos que cambian entre sistemas y NO forman parte del port: tipo de documento por defecto al elegir cliente (TIK vs COF), modelo de descuentos de venta.js, `venta2.php` vs `venta.php`, `cliente.js` vs `funciones_cliente.js`.

**Corrección de una exclusión previa (lección de un port real, 2026-10-27): el JOIN de `credito ... finalizada=0` en `mostrar_cliente()` NO es "semántica de saldo propia de cada sistema" — es un FIX de corrección que el origen ya tiene y que DEBE portarse.** El origen lo escribe como `LEFT JOIN credito AS cr ON ct.id_cliente = cr.id_cliente AND cr.finalizada=0` (condición en el ON); los destinos viejos lo traen como `LEFT JOIN ... WHERE ct.id_cliente = X AND cr.finalizada=0` (condición en el WHERE). Con la condición en el WHERE, un cliente **sin créditos activos** es filtrado por completo (la fila del LEFT JOIN es NULL y `NULL = 0` es falso → 0 filas → la respuesta de `mostrar_cliente` viene con TODOS los campos en null, incluidos nombre y email). El síntoma en producción: la ficha del cliente y el prefill del correo en el modal de pago llegan vacíos precisamente para los clientes sanos (sin crédito pendiente). Portar SIEMPRE el cambio de `WHERE`→`ON` en el/los `mostrar_cliente()` del destino (y auditar cualquier otro LEFT JOIN con condición de la tabla unida en el WHERE). Esta entrada estuvo antes listada como exclusión y provocó que un port saltara el fix y el correo del modal dejara de llegar; verificar la semántica de un JOIN antes de clasificarlo como "diferencia entre sistemas", nunca asumir que dos formas de escribirlo son equivalentes.

---

## 3. GUÍA POR ARCHIVO de los merges finos

### `venta.php`
Portar: input `#datos_extra_obs` (Observaciones DTE), input oculto `#email_cliente` (si se porta el correo), modal `#ticketModal` (iframe + `#btnPrinTik` — `openTicketModal` del dte_auto lo necesita), include `dte_auto.js` (crítico: suele faltar), `$distrito=$_POST["distrito"] ?? ''` + `'id_distrito'=>$distrito` en `agregar_cliente()`, `periodo`/`plazo` en `insertFactPago()` (desde `$det_pago['dias_credito']` para CRE), y (opcional) `updateClientMail()`.
NO portar: reindentaciones masivas (el destino ya está sincronizado en esas zonas), `validar_cliente_ccf` / `obtener_lotes_factura` salvo decisión.

### `venta.js`
Portar: `.not("#distrito")` en init select2, bloque distrito (select2 AJAX + `aplicarDistritoSeleccion` + envío `distrito` en datosCliente), `dias_credito` en `storeTablePagos()` (alimenta periodo/plazo), `datos_extra_obs` en payload, y si se porta el correo: prefill `#correo_cliente` en `activa_modalPago` + `correo_cliente` en payload + `$("#email_cliente").val(d.email)` en `mostrar_cliente`.
NO portar por defecto: renombrado `porcdesc→valorDescuento` y modal `#discountModal` (modelo de descuentos completo, ~15 handlers — adoptarlo todo o nada, nunca a medias), `showAlertDte` (opcional).

### `_helper_ticket_dte.php` / `facturas_pdf.php` / `autocomp_prod.php` / `dte_auto.js`
Verificar antes de tocar: varios de estos diffs entre repos son features ajenas. `dte_auto.js` del origen apunta a `venta2.php` — el destino que usa `venta.php` no debe portar esa línea.

---

## 4. FLUJO DE EJECUCIÓN (orden que funcionó)

1. **BD:** backup → tablas `evento`/`evento_detalle` si faltan → regenerar dump de referencia → `sync_schema.php` → SQL resultante (estructura) → verificar catálogos MH (Cabañas 10/11) → `actualizacion_mh_solo_datos.sql` solo si los catálogos del destino están viejos. **Extras de menú/permisos en el out.sql:** antes de dar por bueno el SQL de salida, verificar contra la BD del destino los registros de `modulo` que el port trae nuevos (p. ej. `descarga_dte_json.php`, ver §1.8 `f996452`): si faltan, agregar su INSERT al final del out.sql resolviendo el `id_menu` contra la BD del destino.
2. **Copias completas** (con diff previo): clases core, endpoints nuevos, JS nuevos, `_utiles.php` (o merge), `factForm.php`, `dte_fse_pdf.php`.
3. **Merges selectivos:** `_helpers.php` → `generar_dte.php` → admins → `venta.php`/`venta.js` → `getAuth.js` → `dte_factura_pdf.php`.
4. **Distritos:** frontend de los 4 frentes (adaptando al JS real de cada formulario) + guardado en backend.
5. **Validación sintáctica** de TODO lo tocado: `php -l` (con la versión PHP del proyecto) y `node --check` para JS.
6. **Pruebas ambiente 00:** COF mostrador, CCF, NC sobre venta, FSE, anulación (COF/NC/FSE), consulta DTE, evento retorno, PDF con marca de agua, envío de correo, reimpresión masiva, ticket POS.

---

## 5. ERRORES COMETIDOS EN EL PRIMER PORT (no repetir)

1. **Portar por simetría en vez de por commit.** La primera pasada copió el estado completo del origen e introdujo features ajenas (`datos_fact_det`, descuentos de ticket, correo, CCF-restrictor, showAlertDte). Se corrigió auditando cada cambio con `git log -S`. **Regla: detectar con diff de estados, decidir con commits.**
2. **Copiar archivos completos sin diff.** `clases/Factura.php` del destino tenía lógica propia (salida de efectivo al anular facturas de otro día) — una copia completa la habría borrado. Siempre `diff` primero; parche por método.
3. **JS olvidado:** `evento_retorno.js` existía solo en el origen; sin él el módulo abre mudo. Listar siempre los JS que cada página nueva requiere.
4. **Dependencias de includes:** `dte_factura_pdf.php` llama `anuladoDTEData()` que vive en `dte/scripts/_utiles.php` — el origen incluye ese archivo; si se porta el código sin el include → fatal. Mapear funciones nuevas → archivo que las define → include necesario.
5. **Sección `extension`:** el JSON v2 ya no la lleva; cualquier `json_value['extension']` sin `?? ''` produce notices y puede fatalear. Buscar en todos los PDFs.
6. **Dump de referencia incompleto:** si las tablas `evento`/`evento_detalle` no existen aún en la base base, el sync_schema no las migrará. Aplicarlas primero (están solo en `log_DTE_Normativa_2_0.txt`) y regenerar el dump.
7. **Catálogos MH:** verificar el estado del destino antes de asumir (Cabañas Este/Oeste 10/11 del catálogo v1.1 07/2026). `distritoMH.id_municipio` = código CAT-013, no id de `municipioMH`.
8. **Envío de correo (Postmark):** tres trampas: (a) `EMAIL_CC=false` en `.env` llega como string truthy → guard `$cc==false || $cc=='false'` en TODOS los flujos; (b) adjunto del envío automático con `output=file`: `Output('F')` no devuelve contenido → `file_get_contents` del PDF guardado en `pdfdtes/`; (c) envolver el envío en try/catch — un fallo de correo no debe romper la respuesta de un DTE/anulación ya procesada.
9. **Permisos del entorno:** `output=file` escribe en el CWD — usar `pdfdtes/` (existe y suele tener permisos) en vez de la raíz del repo.
10. **`|` vs `||`** en `actualizarCorrelativo()` — bug heredado del origen, corregir al portar.
11. **Dependencias NO normativas que la normativa necesita** (port real 2026-10-08): el `Dte.php` del origen requiere `clases/Compra.php` para `generateSignature` de FSE; si el destino no la tiene, la generación de DTE revienta aunque todo "compile". Al portar cada archivo, rastrear TODOS sus `require`/`new Clase`/funciones llamadas y verificar que existan en el destino con firma compatible. Si falta algo no normativo: **adaptar** al ORM/estilo del destino (ej. wrapper liviano), no copiar el subsistema del origen (ej. `framework/` + `MovimientoProducto`).
12. **Colisiones de nombres al añadir includes** (port real 2026-10-08): incluir `_utiles.php` en un admin que define localmente `firmada($id)`/`recibida($id)` choca con las globales `firmada($id,$tipoDte)` — fatal "Cannot redeclare" o "Too few arguments". Solución del origen: borrar las locales y llamar las globales con el tipo (`getVersionTipoDTE($tipodoc,'tipo_dte')`). Revisar TODAS las llamadas del archivo (closures del DataTable y del menú), no solo las columnas.
13. **No añadir filtros que el origen no tiene al portar lógica de reversión** (port real 2026-10-08): en `anular_factura.php` se leyó `movimiento_stock_ubicacion` con `AND anulada=0`, pero el propio proceso acaba de marcar esas filas `anulada=1` → la reversión no restó stock (quedó inflado silenciosamente; el movimiento SALIDA se creaba y hasta registraba stock_actual teórico). Portar las condiciones tal cual el origen; cualquier filtro extra debe justificarse contra el flujo completo.
14. **Fecha del QR de consulta pública** (port real 2026-10-08): `fhprocesamiento` llega como `d-m-Y H:i:s`; `date('Y-m-d', strtotime(...))` lo interpreta m-d-Y e invierte día/mes → MH no encuentra el DTE. Usar el parseo manual por `substr`/regex que ya usan los demás PDFs (`dte_fac/ccf/nc_pdf.php`). Variante 2026-10-13 (port real): el MH también devuelve `d/m/Y H:i:s` **con barras**; con ese valor `strtotime` devuelve `false` directamente → `date(..., false)` imprime `1969-12-31` en label y QR. El parseo por `substr` de los demás PDFs es agnóstico al separador y no sufre esto; si un helper usa regex, aceptar ambos separadores: `/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/`.
15. **Include faltante = fatal en runtime, no en lint** (port real 2026-10-08): `admin_factura_rangos_dt.php` usa `anuladoDTEData()`/`eventosERET()` pero en el origen el propio archivo trae `require dte/scripts/_utiles.php`; si al portar no se replica el include con la ruta del layout destino (allí `_utiles.php` en raíz), el DataTable muere con "undefined function". Mapear función nueva → archivo que la define → include necesario EN CADA página que la llama.
16. **Bug del propio origen: `getFacturaPagoTipo()` devuelve objeto pero `Dte.php` accedía como array** (port real 2026-10-08): facturar a crédito y crear el DTE reventaba con "Cannot use object of type stdClass as array". El origen tenía un bloque corregido (acceso `->periodo`) y otro roto (`['periodo']`). Al portar, unificar TODOS los bloques de la sección pagos con acceso a objeto + guard: `(object)` cast, `periodo>0 ? periodo : 30`, `plazo` vacío → `'01'`. Y corregir el bloque roto TAMBIÉN en el origen, o el siguiente port lo arrastra. No asumir que lo que se copia del origen está probado en todas sus ramas: la venta a crédito del origen no había pasado por ese código.
17. **Correo FSE que nunca se dispara + síntomas de envío** (port real 2026-10-10, corregido en el origen por `eaf6d25`, ver §1.9): (a) el ruteo por `tipodoc` en el JS es parte del flujo: `genera_pdf_save` debe hacer `if(tipodoc=='FSE') url_dte='dte_fse_pdf.php'` en TODOS los JS que lo definan (`getAuth.js` y `dte_auto.js`) — si el JS del destino manda todo a `dte_factura_pdf.php` (que consulta la tabla `factura`, donde una compra no existe), el envío muere en silencio sin error visible. Igual con la rama FSE de `sendMailAnula` (4º parámetro `$tipodoc`, lookup en `jsonDTE` tipoDTE='14'): sin ella, anular un FSE manda "Zero recipients" por leer el JSON equivocado. Verificar ruteo y ramas en CADA archivo que participe. (b) `dte_fse_pdf.php` lee `$tipodoc` de `$_REQUEST`: llamarlo sin `&tipodoc=FSE` genera un notice `Undefined index` que contamina el output y FPDF aborta con "Some data has already been output". (c) Los generadores PDF del destino pueden exigir parámetros que el origen no exige: en un port real el `dte_factura_pdf.php` del destino hacía `die` sin `output` en la URL, y el `dte_auto.js` del destino no mandaba `&output=file` (el origen lo lleva desde mantenimiento pre-normativa) → el correo del DTE automático de venta nunca se generaba. Al diagnosticar "no llega el correo", trazar la llamada HTTP completa (URL + parámetros exactos) hasta el generador, no solo la lógica de envío. Síntomas típicos: "Zero recipients specified" = apikey vacía (include/fallback de `obtener_api_key.php` faltante, ver §1.9) o receptor null por leer el JSON equivocado (rama FSE de anulación sin portar); "Some data has already been output" = notice/warning PHP antes de `$pdf->Output()`; correo jamás disparado = ruteo JS o parámetro faltante.
18. **Dependencias introducidas por HUNKS fusionados en archivos del destino: la traza de endpoints no las ve** (port real 2026-10-27): un hunk fusionado dentro de una vista del destino usaba una clase (`X::getInstance()`) que el archivo portador nunca cargaba — sin autoload, sin require, el fatal ("Class not found") salió en la primera venta real, DESPUÉS del despliegue. La traza de llamadas de la FASE 2 arranca en los puntos de entrada clásicos (generar_dte, PDF, ERET, admins) y ese hunk no está en ninguna de esas cadenas. Regla derivada: **pase mecánico de símbolos nuevos sobre el diff COMPLETO del port** — para cada hunk añadido a un archivo existente, enumerar clases instanciadas/funciones globales/constantes nuevas y verificar que el archivo PORTADOR (o su cadena de includes) las carga, agregando el `require_once` que falte. `php -l` no detecta nada de esto.
19. **Una exclusión mal clasificada en esta guía propagó un bug a un port real** (2026-10-27): la lista de "elementos que cambian entre sistemas y NO forman parte del port" incluía el "JOIN de `credito ... finalizada=0` (semántica de saldo)". No era una diferencia de semántica entre sistemas: era un FIX del origen (condición en el `ON` del LEFT JOIN) que el destino viejo no tenía (condición en el `WHERE`), y saltarlo dejó a `mostrar_cliente()` devolviendo TODOS los campos null para clientes sin crédito activo — el prefill de correo del modal de pago (7c8d0e5) dejó de llegar hasta que el usuario lo reportó. Regla derivada: **antes de clasificar cualquier diferencia SQL como "semántica propia del sistema", ejecutarla mentalmente (o contra la BD) contra un caso límite** — un LEFT JOIN con condición de la tabla unida en el WHERE NO es equivalente a ponerla en el ON; si hay duda de equivalencia, la diferencia NO es cosmética y se investiga. Corregido en §2: el fix ahora figura como "portar SIEMPRE".
20. **JOIN de detalle a tabla de movimientos duplica los ítems al anular el documento** (port real 2026-10-27): `ver_compra.php` (modal "Ver detalle" del admin de compras) unía `detalle_compra JOIN movimiento_producto ON movimiento_producto.id_compra = detalle_compra.id_compra` — join que NO aportaba ninguna columna al SELECT. Al anularse la compra, la ENTRADA de reversa crea una SEGUNDA fila en `movimiento_producto` para el mismo `id_compra` → cada ítem aparece duplicado (el total no, porque se lee de `compra`). El origen ya tiene el query corregido (commits 3670deb: detalle directo desde `detalle_compra`, sin el join a movimientos, con UNION ALL para servicios) pero `ver_compra.php` NO figuraba en ninguna lista de port de esta guía — el archivo cayó fuera del radar porque nadie diff-eó los "ver detalle" modales. Regla derivada: **auditar TODOS los modales/páginas de "ver detalle" (`ver_*.php`) buscando joins de la tabla de detalle a tablas de movimiento/log (`movimiento_producto`, `movimiento_stock_ubicacion`, `jsonDTE`) por id del documento** — todo documento anulado tiene fila(s) de reversa que multiplican el detalle; el join solo es legítimo si aporta columnas al SELECT o filtra por `tipo`. El síntoma visible: ítems duplicados en el modal de una compra/factura anulada, con el total correcto.
21. **Inits de select2 en pugna y bloques insertados fuera de su función: el JS "está" pero no vive donde debe** (port real 2026-10-28): dos defectos de la misma familia, ambos invisibles para `php -l`/`node --check` y para la traza de dependencias. (a) En el port de la búsqueda de distrito se excluyó `#distrito` del init genérico `$('.select').select2()`, pero el archivo también inicializaba `$('.selectt').select2()` — y el select de la vista de EDICIÓN lo construye el helper local `crear_select()` con clase `selectt`: ese segundo init, que corre DESPUÉS del bloque AJAX, pisaba el select2 de búsqueda y lo devolvía a dropdown normal. Síntoma: la búsqueda funciona en `agregar_*.php` (select harcodeado, clase `select`) y no en `editar_*.php`, sin error alguno. En widgets con estado (select2, datepicker, máscaras) la inicialización la gana la ÚLTIMA llamada: la verificación es el ORDEN de TODOS los inits que matchean el elemento por cualquier selector, no la presencia del bloque nuevo. (b) El bloque de prefill de correo (`#correo_cliente` desde `#email_cliente`) se insertó con un ancla de texto (`focus()` del efectivo) y quedó DESPUÉS del `}` que cierra `activa_modalPago`: código muerto a nivel de archivo que se ejecuta una vez al cargar (variables sin definir) y jamás al abrir el modal. Reglas derivadas: al insertar un bloque en una función existente, anclar por el cierre de la función y verificar tras el patch que el bloque quedó DENTRO (línea anterior = cuerpo de la función); al portar un widget, grep de TODOS los inits genéricos del archivo (`$('.select').select2(`, `$('.selectt').select2(`, `$(".select").select2()`) y excluir el elemento de cada uno; y la verificación por comportamiento de la FASE 2.5 debe señalar archivo+línea donde el comportamiento SE DISPARA (dentro del handler/función que corre con el evento), no solo donde el código físicamente quedó.

---

## 6.1 VENTA PARTIDA: `venta.php` + `venta2.php` (cuando el destino no unificó)

En el origen la venta es un solo `venta.php` (modal elige con/sin DTE). Varios
destinos tienen dos archivos casi idénticos: `venta.php` (sin DTE automático) y
`venta2.php` (con DTE automático), cada uno con su JS. Antes de tocar venta:

1. Determinar si `venta2.php` está en uso: menú en BD (dump), `add_routes.php`,
   enlaces desde otros scripts, `funciones_venta2*.js`, y diff entre ambos
   archivos. Si nada lo referencia, es residuo: se registra y no se toca.
2. Si está en uso: **todo** cambio de venta se aplica en AMBOS archivos y en sus
   JS respectivos: observaciones DTE (`datos_extra_obs`), correo en modal de pago,
   distrito en `agregar_cliente`, `periodo/plazo`, `#ticketModal` + `dte_auto.js`,
   `email_cliente`.
3. NO unificar la lógica para imitar al origen. El destino nació partido; se
   porta el cambio, no la reorganización.

---

## 6. CHECKLIST FINAL POR SISTEMA

- [ ] Backup de BD + tablas `evento`/`evento_detalle` + catálogos MH verificados
- [ ] `sync_schema.php` ejecutado, out.sql aplicado
- [ ] Clases core portadas (diff por archivo) + `Contingencia.php` compatible
- [ ] Endpoints: generar_dte (con fix `||`), proxyConsultaDTE, evento_retorno(+JS), update_cor_dte, descarga_dte_json (+ su fila en `modulo` incluida en el out.sql de la migración, §1.8 `f996452`)
- [ ] `_helpers.php` mergeado preservando código propio del cliente
- [ ] **Distritos: 4 frentes (cliente/proveedor/sucursal/venta) con búsqueda y cascada inversa + guardado. Verificación por COMPORTAMIENTO**: en cada frente, señalar archivo/línea del destino donde "buscar distrito → autocompletar departamento y municipio" se materializa; si el JS del origen no existe con ese nombre en el destino, se aplicó la regla de equivalencias funcionales (mapeo anotado), no omisión silenciosa
- [ ] PDFs: marca de agua, distrito, versión, guards `extension`, `pdfdtes/`
- [ ] Correo: guard `EMAIL_CC`, adjunto output=file, try/catch, apikey condicional (§1.9), ruteo FSE en JS y `&tipodoc=FSE` en URLs (§5 error 17)
- [ ] Sesión aislada por proyecto: `session_name(...)` antes de TODOS los `session_start()` del destino (§1.9, `fa74ff5`)
- [ ] ERET con lotes: verificado en el destino si maneja lotes y rama portada en consecuencia (con lotes → reingreso FIFO a los lotes de la venta; sin lotes → solo rama genérica) (§1.10, `6007e66`)
- [ ] Descarga pública del DTE operativa: `downloads/descargadte.php` + `dteqr_pdf.php`/`dteqr_json.php` (QR del PDF apunta a ellos), marca de agua ANULADO y JSON sellado sin escritura a disco (§1.11, `39fa0ec`)
- [ ] Modal de reenvío de DTE operativo (§1.12, `d0ad9de`): endpoint copiado, ítem "Reenviar DTE" con permiso, `tipoDTE()` sin colisión de nombre, JSON `{success,message}` del modal llega íntegro (ob_start), y las TRES vías de envío trazadas (automática / Enviar PDF DTE / Reenviar DTE)
- [ ] Cache-busting en páginas admin tocadas
- [ ] Todo `php -l` + `node --check` en verde (piso mínimo, no suficiente)
- [ ] **Traza de llamadas verificada** por punto de entrada (generación, respuesta MH, anulación, PDF, ERET, admins): cada función/clase/require existe en el destino con firma compatible; dependencias faltantes adaptadas y anotadas
- [ ] **Pase de símbolos nuevos sobre el diff COMPLETO del port**: cada hunk fusionado en un archivo existente no introduce ninguna clase/función/constante que el archivo portador no cargue (requires verificados/agregados; sin autoload, el require es responsabilidad del portador)
- [ ] **Mapa de equivalencias origen→destino** completo (toda discrepancia de nombre de archivo resuelta por ancla, con el ancla documentada) y **lista de verificación por comportamiento** (feature → comportamiento prometido → dónde se materializa en el destino; sin PENDIENTES ocultos)
- [ ] **Coherencia lógica funcional (FASE 2.5, obligatoria)**: inventario de controles de los admins/formularios tocados (y de los que muestren entidades cuyo estado cambió), matriz estado×acción — toda acción visible debe ser ejecutable con éxito en el estado donde se renderiza; etiqueta↔efecto en cada acción (anular anula, eliminar elimina, enviar dispara el correo real por todas sus rutas); todo guard de backend alcanzable desde un click normal se mitiga en el frontend (ocultar el control) sin quitar el guard
- [ ] **Correcciones de coherencia del origen mitigadas y reportadas**: defectos de lógica verificados que venían en el código del origen se arreglaron en el destino (no se copiaron), quedaron en la sección "Correcciones de coherencia lógica aplicadas (no normativas)" del registro, y se enumeraron al usuario en el informe final con recomendación de replicarlos en el origen
- [ ] `venta2.php` clasificado (en uso / residuo); si en uso, cambios de venta aplicados en ambos archivos sin unificar
- [ ] Pruebas ambiente 00 del checklist §4.6
