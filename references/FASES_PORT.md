# Fases del port (procedimiento paso a paso)

> Contenido verbatim de las fases de la skill. SKILL.md trae el índice; este archivo
> es el detalle operativo que se lee para ejecutar cada fase.

## FASE 1 — Base de datos (siempre primero)

1. **Verificar el dump de ORIGEN antes de confiar en él** (lección real: el
   primer port falló porque el dump base no tenía las tablas `evento`/
   `evento_detalle` — están solo documentadas en `log_DTE_Normativa_2_0.txt`
   del repo origen, líneas 38–116). Revisar que el dump contenga:
   `evento`, `evento_detalle`, `distritoMH`, `municipioMH`, `paisesMH`,
   `id_distrito` en `cliente`/`proveedor`/`sucursal`, `periodo`/`plazo` en
   `factura_pago`, y las columnas `anulado`/`fhprocesamiento`/`version` de `jsonDTE`.
   Si falta algo, indicarle al usuario qué aplicar en la base base y regenerar el dump.
2. Ejecutar el generador de SQL de estructura (desde el repo origen):
   `php db/sync_schema.php <dump_origen> <dump_destino> > <tmp_destino>/migracion_sync_schema_desde_dte_testing.sql`
   Sin flags extra por defecto (los datos de tablas existentes se manejan aparte).

   **Regla dura (decisión del usuario, 2026-10-28): la salida de `sync_schema.php`
   se entrega TAL CUAL — nunca se poda.** No se eliminan del .sql de salida ni
   tablas ni columnas nuevas, NI SIQUIERA las que traigan a commits fuera de la
   lista normativa (features de otros clientes acumuladas en el rango): decidir
   qué esquema queda y qué no es una decisión MANUAL del usuario, tomada
   después de recibir el archivo completo. Al .sql de salida final solo se le
   AGREGA lo que el proceso ya agrega hoy: corrección de catálogos MH
   (distritos cruzados, nombres/códigos mal puestos en el dump destino — el
   bloque idempotente o los UPDATEs puntuales), INSERTs de `modulo` faltantes
   y demás datos correctores. La revisión del punto 3 es para VERIFICAR que lo
   esperado está, no para recortar lo que sobre.
   Motivo (lección de un port real, 2026-10-28): el código normativo copiado
   verbatim del HEAD (p. ej. los generadores de PDF) puede leer columnas de
   features cuyo commit no está en la lista normativa (giros secundarios del
   encabezado de sucursal, columna de lotes en el detalle); una poda "por
   evidencia" con un grep de referencias incompleto dejó el primer PDF roto
   (`Unknown column` → notices que contaminaban el buffer → FPDF abortaba con
   "Some data has already been output"). Dejar una columna de más no cuesta
   nada; quitar una viva rompe producción: el error es asimétrico y la poda
   no lo paga quien la decide.

   **Recordatorio obligatorio al usuario:** al entregar el .sql de salida (al
   cerrar la FASE 1 y de nuevo en el informe final del port), recordar SIEMPRE
   en el chat que le conviene darle una revisada al SQL resultante antes de
   aplicarlo. El aviso es informativo y se redacta como sugerencia — nunca
   como "este paso se volvió manual": el archivo no dará problemas al
   aplicarse (los ALTER son puramente aditivos y MySQL/MariaDB los ejecuta
   sin fallos), pero conviene que el usuario conozca qué tablas y columnas
   nuevas trae y confirme que está de acuerdo con llevarlas todas.

3. **Revisar el SQL generado antes de darlo por bueno:** debe traer las tablas
   nuevas de la normativa (`evento`, `evento_detalle`, `paisesMH`, `compras_json`,
   `distritoMH`/`municipioMH` si el destino no las tiene) y las columnas faltantes.
   Si NO trae algo esperado, no es error del script: el destino ya lo tiene — verificarlo en el dump destino.
4. Estado de catálogos MH del destino: si sus tablas `municipioMH`/`distritoMH`
   ya existen, verificar que traigan el catálogo v1.1 (07/2026): `municipioMH`
   con (31, 10, 'Cabañas Este') y (32, 11, 'Cabañas Oeste'), y `distritoMH` con
   el este→10 / oeste→11. Ancla de Cuscatlán (lección real de un port de
   2026-10: Cuscatlán Norte/Sur quedó cruzado y la muestra
   de Cabañas no lo veía porque es el único grupo que MH valida bien tal cual):
   `distritoMH` de depto 7 debe tener municipio 17 = distritos {6, 7, 9, 10, 15}
   y municipio 18 = {1, 2, 3, 4, 5, 8, 11, 12, 13, 14, 16} — comparar por
   `codigo`, NO por `id` (el `id` varía entre BDs). Si están viejos, aplicar
   `references/actualizacion_mh_solo_datos.sql` DESPUÉS del SQL de estructura
   (los ON DUPLICATE no tienen contra qué actualizar si las tablas no existen).
   Si están al día, no aplicar nada (solo metería nombres abreviados).
   Recordar: `distritoMH.id_municipio` guarda el CÓDIGO CAT-013 del municipio,
   no el id de `municipioMH`.
5. **Catálogo completo contra la fuente de verdad (obligatorio aunque Cabañas
   salga bien).** El chequeo de Cabañas del punto 4 es una MUESTRA, no la
   verificación: el mismo tipo de cruce de ids (códigos intercambiados entre
   municipios hermanos, `id_municipio` apuntando al municipio equivocado) puede
   existir en cualquier otra fila y la muestra no lo ve. Comparar fila a fila
   contra el dump de origen los catálogos que el destino YA tiene:
   `distritoMH` (~263 filas: `id`, `codigo`, `nombre`, `id_departamento`,
   `id_municipio`), `municipioMH` (`codigo`, `nombre`, pertenencia a
   departamento) y `paisesMH` (`codigo`, `nombre`), siempre keyed por `id`.
   **Límite del diff origen-destino (lección real de un port de 2026-10):** si el
   dump de ORIGEN trae el mismo cruce que el destino, el diff da cero
   divergencias y el error pasa desapercibido — comparar dos copias no valida
   el contenido. Por eso el origen de esta skill ya está saneado y validado:
   el catálogo de `references/actualizacion_mh_solo_datos.sql` se contrastó
   contra la fuente primaria (el PDF "Catálogos – Facturación Electrónica"
   en `https://factura.gob.sv/informacion-tecnica-y-funcional/`) y se verificó
   completo ante MH (barrido de los 262 tríos depto/municipio/distrito en
   ambiente 00: todos con sello). Para el port ya NO hace falta re-validar
   emitiendo DTE de prueba: confiar en el catálogo de la skill y aplicar el
   bloque corrector cuando aplique. OJO: la agrupación territorial
   LERM/SSF/Wikipedia NO siempre coincide con lo que MH valida internamente
   (Cabañas: MH acepta este→10 siendo "Oeste" en CAT-013) — no "corregir"
   hacia la ley territorial; correcciones solo desde
   `references/actualizacion_mh_solo_datos.sql`.
   Método canónico (la verificación canónica es contra los dumps, igual que en
   el resto de la skill): extraer el bloque `INSERT INTO \`<tabla>\`` de ambos
   dumps (origen `tmp/oss_dte_testing.sql` y dump destino), normalizar y diff.
   Si hay divergencias, NO inventar correcciones: el `id` manda como clave y el
   origen manda como contenido — generar UPDATEs puntuales y AGREGARLOS AL
   FINAL del dump de salida (`migracion_sync_schema_desde_dte_testing.sql`),
   igual que se hace con los INSERT de `modulo` faltantes (guía §4.1): el SQL
   final debe ser autocontenido, con toda la estructura Y todos los datos
   correctores en un solo archivo aplicable. Como alternativa al UPDATE manual
   sirve el bloque equivalente de `references/actualizacion_mh_solo_datos.sql`
   (idempotente por `ON DUPLICATE KEY UPDATE`) concatenado al final del mismo
   dump de salida. Registrar cada corrección en el registro del port. Si el destino no tenía las tablas, este paso no aplica (nacen limpias
   del propio catálogo del origen).
6. Recordar al usuario: backup de BD antes de aplicar cualquier SQL, y que los
   cambios se aplican cuando él decida (la skill genera, no aplica sobre su BD).

7. **Verificación de DUPLICADOS en el SQL de salida (OBLIGATORIA antes de cerrar
   la FASE 1).** En TODOS los ports reales ejecutados ha aparecido contenido
   duplicado en el out.sql (la lección es sistemática, no puntual): el
   `sync_schema.php` inserta datos de las tablas que CREÓ, y cualquier bloque
   posterior que se concatene (corrector de catálogos, INSERTs de `modulo`,
   datos correctores) puede volver a insertar lo mismo, o pisar con otro
   espacio de ids. El out.sql se entrega sin revisar = el usuario aplica
   redundancias o corrupciones silenciosas. Verificar SIEMPRE, con este método:

   a. **Análisis estático del out.sql:** parsear el archivo y extraer TODAS las
      sentencias. Detectar: (i) dos o más `INSERT INTO \`tabla\`` para la MISMA
      tabla sin un `TRUNCATE`/`DELETE` intermedio que los justifique; (ii)
      tuplas duplicadas: normalizar cada tupla de VALUES y buscar la MISMA
      `(tabla, tupla)` en dos bloques distintos; (iii) bloques muertos: un
      `INSERT` a una tabla que después es truncada y re-insertada (el primer
      bloque nunca aporta y se elimina — esto NO es poda de esquema, es
      eliminar sentencias provadamente superflua; la regla dura del punto 2
      solo protege tablas/columnas, no duplicados de datos).
   b. **Choque de espacios de ids en bloques concatenados:** cuando se agregue
      un bloque corrector o de datos, comparar su espacio de ids contra el de
      la tabla en el dump destino ANTES de concatenarlo. Si difiere (ej. el
      bloque trae una fila extra tipo "Otro País" que desplaza los ids +1, o
      simplemente numeración distinta), su `ON DUPLICATE KEY UPDATE` por `id`
      PISA FILAS EQUIVOCADAS del destino — regenerar las correcciones keyed
      por la clave estable del dominio (`codigo` CAT-013 + `id_departamento`
      para distritos; NUNCA `id` solo; y ojo: `distritoMH.codigo` NO es único
      global, se repite por departamento). Caso real (port 2026-11-12): el
      corrector renombraba "AHUACHAPÁN" (id 1 destino) a "Otro País" (id 1 del
      bloque); detectado solo porque se aplicó el out.sql a una BD de prueba.
   c. **Prueba de aplicación en BD de descartable (la verificación canónica).**
      El análisis estático no alcanza. Cargar el dump DESTINO en una BD
      temporal, aplicar el out.sql completo, y verificar: (1) exit=0 sin
      errores; (2) conteos por tabla nueva/corregida contra lo esperado; (3)
      que las filas preexistentes del destino NO fueron pisadas (spot-check
      por id de filas que el bloque podría tocar); (4) que las correcciones
      quedaron aplicadas (fila a fila contra el catálogo saneado). Eliminar la
      BD temporal al terminar. Si no hay BD disponible, hacer al menos (a)+(b)
      y dejar la prueba (c) como pendiente explícito en el registro.
   d. **Registro:** el registro del port lleva la sección "SQL de salida:
      duplicados detectados y eliminados" con cada hallazgo, su mecanismo
      (quién lo insertó dos veces) y el veredicto tras la prueba (c).

## FASE 2 — Port de código (selectivo, por commit)

   Leer `references/GUIA_PORT_NORMATIVA_2_0_CLIENTES.md` completo y seguirla como
   checklist. Antes de empezar, verificar contra el HEAD actual del origen que no
   haya commits nuevos fuera del rango documentado (regla de "revisar del último
   commit portado hacia arriba"). Al portar funciones, aplicar la regla de
   **auditoría dual** del final de este documento (regla obligatoria, no opcional:
   sustituir código del origen por helpers del destino sin la auditoría es
   defecto). Resumen del orden que funcionó:

1. Copias completas (CON diff previo para confirmar que no hay código propio del
   destino dentro): `clases/Dte.php`, `clases/EventoDte.php`, `clases/FacturaPago.php`,
   `fpdf185/factForm.php`, `dte_fse_pdf.php`, `evento_retorno.php`,
   `proxyConsultaDTE.php`, `update_cor_dte.php`, `descarga_dte_json.php`,
   `js/funciones/evento_retorno.js` (fácil de olvidar), `pdf_compra.php`,
   `dte/scripts/_utiles.php` (merge si trae algo propio; su `factDetalle`
   reescrita NO es normativa), `admin_compras_fecha_dt.php` (si no hay código propio).
   **Al llegar a los generadores PDF de salidas de esta lista** (`dte_factura_pdf.php`, `dte_fse_pdf.php`,
   `pdf_compra.php`, `factForm.php`, y sus equivalentes en el destino): capturar su estado PRE-port conforme a
   la excepción de copia de references/ENTORNO_Y_LIMITES.md (copia solo si el destino no tiene git; si
   lo tiene, la comparación de cierre se hará con `git diff`). Esto es captura silenciosa, no pregunta al
   usuario: si el PDF trae personalizaciones del cliente, portar los cambios normativos de la forma menos
   invasiva posible (los bloques no-visuales: correo, API key, ruteo FSE, marca de agua, salida `output=file`),
   dejar el diseño/integridad visual intacta y dejar la divergencia documentada para el reporte de cierre de la
   FASE 3.
2. Merges selectivos: `_helpers.php` (ALTO RIESGO: preservar funciones propias del
   cliente; portar `cliente_factura` con telefono/id_distrito, `getNombreDistrito`,
   `getDistritoAutocomplete` + case, `obtenerTextoVersionDTE`, `getDTERecidosFSEMH`;
   NO portar `salidaPdf` con "S" ni insolvencia — **esta exclusión es
   CONDICIONAL: "a menos que se porte el flujo de correo también". Si al final
   del port el flujo de correo del generador quedó portado (es lo habitual:
   `dte_factura_pdf.php` viaja completo), la exclusión DEBE re-evaluarse en la
   FASE 2.6 punto 2 o el correo automático sale con adjunto vacío**), `generar_dte.php` (nueva
   `save_response_mh` + `actualizarCorrelativo` con fix `|`→`||`), admins,
   `venta.php`/`venta.js`, `getAuth.js`, `dte_factura_pdf.php`.
3. Distritos con búsqueda (commit `c93aa10`): los 4 frentes (cliente, proveedor,
   sucursal, venta). En cada JS del formulario: excluir `#distrito` del init
   genérico de select2, inicializarlo como select2 AJAX contra
   `process: 'getDistritoAutocomplete'`, y al elegir un resultado rellenar
   departamento + municipio + distrito automáticamente (cascada inversa).
   **Adaptar al JS real de cada destino**: el JS del formulario puede diferir
   del del origen (ej. el destino usa `funciones_cliente.js` y no `cliente.js`);
   buscar el ancla equivalente (el handler `#municipio change` con
   `process: 'getDistrito'` que el destino ya suele tener) y aplicar ahí.
   Si el JS del origen NO existe con ese nombre en el destino, aplicar la regla
   "Equivalencias funcionales" de references/REGLAS_PORT.md: grep del
   ancla en TODO el destino, portar al archivo equivalente encontrado y anotar
   el mapeo. Hacer esto por CADA uno de los 4 frentes y sus vistas de
   alta/edición (agregar_*, editar_*): un frente solo cuenta como portado si la
   cascada inversa (buscar distrito → autocompletar departamento y municipio)
   quedó materializada en SU archivo real, no solo en el backend.
   Backend: `getDistritoAutocomplete()` + case en `_helpers.php`; guardado con
   `'id_distrito' => $distrito` en los ABM.
   **Excluir `#distrito` de TODOS los inits genéricos de select2, no solo del
   obvio (lección de un port real, 2026-10-28).** El formulario de alta y el de
   edición suelen construir el select con clases DISTINTAS: el alta lo trae
   harcodeado (clase `select`) y la edición lo arma un helper local (p. ej.
   `crear_select()`, que asigna `select2 … selectt select`). Si el JS del
   destino inicializa por VARIOS selectores (`$('.select').select2()` Y
   `$('.selectt').select2()`), excluir `#distrito` solo del primero deja que el
   segundo init —que corre DESPUÉS del bloque AJAX— lo pise y lo devuelva a
   dropdown normal sin búsqueda: en el alta funciona y en la edición no, sin
   ningún error de por medio (la vista de edición parte del helper y por eso
   hereda la clase extra). Reglas derivadas:
   - Al adaptar cada frente, grep de TODOS los `.select2(` genéricos del
     archivo y agregar `.not('#distrito')` a cada uno que pueda matchear el
     select por clase.
   - En widgets con estado (select2, datepicker, máscaras) la inicialización
     la gana la ÚLTIMA llamada: verificar el ORDEN de los inits, no solo su
     presencia. "El bloque AJAX está" no significa "el bloque AJAX manda".
   - Y al INSERTAR un bloque dentro de una función existente (prefill en
     `activa_modalPago`, cascada en un handler), verificar que quedó DENTRO de
     la función contenedora (antes de su `}` de cierre), no después de ella:
     pegado fuera queda como código muerto que se ejecuta una sola vez al
     cargar la página, con variables sin definir, y `node --check` pasa igual.
     Sintaxis verde ≠ bloque vivo: señalar en el registro archivo y línea donde
     el bloque quedó materializado DENTRO de su función.
   - **MODO DE PARSING PHP/HTML (regla general, aplica a TODO el port):**
     antes de insertar cualquier código en un archivo mixto HTML/PHP,
     verificar el MODO DE PARSING del punto de inserción. Los footers, vistas
     y helpers de plantilla abren en PHP y CIERRAN la etiqueta (`?>`) para
     emitir HTML: un ancla de texto tipo `</body></html>` cae casi siempre en
     contexto HTML, y pegar código PHP ahí lo emite como TEXTO LITERAL al
     navegador (ocurrió en un port real, 2026-11-05: el include de
     `aviso_suscripcion.php` quedó como texto visible en los 3 footers; el
     bloque se documentaba como "portado" y ni el usuario lo detectó de
     inmediato porque el aviso estaba DESACTIVADO). Reglas derivadas:
     (a) al insertar PHP en un archivo que contiene `?>`, determinar si el
     ancla está antes o después del último cierre de etiqueta y envolver en
     `<?php ... ?>` cuando corresponda (el propio commit de origen indica el
     modo: si su contexto tiene tags PHP, copiarlos también);
     (b) `php -l` NO detecta este defecto (el archivo sigue siendo PHP
     válido: el texto literal es HTML de salida legítima) — es el gemelo PHP
     del error 21b de la guía; y
     (c) verificación de cierre del port: para CADA hunk añadido a archivos
     con HTML, re-simular el modo de parsing línea a línea (estado
     `<?php`/`?>`) y confirmar que las líneas añadidas caen en el modo
     esperado. Un script de 15 líneas sobre `git diff` basta.
4. Opcionales que el usuario de este flujo suele querer: preguntar explícitamente
   si porta el **correo en modal de pago** (commit `7c8d0e5`; en sistemas con
   modal de pago propio, revisar su estructura antes de insertar el campo) y el
   cache-busting `include_js_once()` en las páginas admin tocadas.
5. Commits recientes del rango que también se portan (guía §1.8): `dbbfcfa`
   (anular() distingue ENTRADA/SALIDA: sin esto, anular una devolución/NC
   revienta con "stock ubicacion ID 0" y el flujo hacia "DTE Anulación" se
   bloquea), `3ccb8ef` (cache-busting de JS en admin cliente/proveedor) y
   `bc1a86a` (sello del evento de retorno visible en el admin: `eventosERET()`
   en `_utiles.php` + bloque en `admin_factura_rangos_dt.php`). El commit
   `bc1a86a` puede que el destino ya lo tenga (se difundió por separado):
   verificar antes de aplicarlo dos veces.
   `050e6607` (fix de coherencia en el admin de compras: la opción "Anular"
   de `admin_compras_fecha_dt.php` solo se renderiza si `anulada==0` — sobre
   una compra ya anulada el único desenlace del click era el rechazo del
   backend; es materialización directa del principio de la FASE 2.5 y portar
   SIEMPRE, junto con el archivo del que forma parte).
6. Correcciones de robustez de correo recomendadas (ver guía §5): guard
   `EMAIL_CC=false`, adjunto del envío `output=file` leyendo el PDF de `pdfdtes/`,
   y try/catch alrededor del envío Postmark para que un fallo de correo no rompa
   un DTE/anulación ya procesada. También de robustez y sin queja de normativa:
   **sesión aislada por proyecto** (guía §1.9, commit `fa74ff5`): anteponer
   `session_name(basename(__DIR__)...)` antes de cada `session_start()` del
   destino para que proyectos hermanos bajo el mismo dominio no compartan
   `$_SESSION`; localizar TODOS los `session_start()` del destino, no copiar
   la lista de archivos del origen. Junto con esa, portar también `fc521e34`
   (guard en `login.php`: con sesión activa redirigir a `dashboard.php` en vez
   de dejar reloguear). De la guía §1.9, portar también el **aviso de
   suscripción pendiente** (`47942220`+`f67299bc` y su evolución `6bfe423`):
   SIEMPRE y sin preguntar, en su estado final DESACTIVADO
   (`'activo' => false`), incluyendo la carpeta `assets/img/` con sus imágenes
   y verificando con `ls` que llegaron al destino (detalle en las filas de la
   guía). El estado final del archivo trae la detección de pestaña duplicada
   (identidad por `window.name` + tipo de navegación/referrer, en ESE orden:
   el referrer llega clonado al duplicar) y el botón "Entendido" blindado
   (span fantasma no interactivo + botón real creado solo al vencer la cuenta
   + vigilante `MutationObserver` que reinstala el modal borrado/oculto sin
   reiniciar la espera; filosofía del aviso: hostigar sin bloquear nunca el
   uso del sistema). Igualmente portar `4f5e25c6`
   (fix COF con retención: `montoTotalOperacion` bruto en `getResumenCOF`).
7. Commits recientes con decisión de adaptación (guía §1.10 post-rango y
   §1.11): `6007e66` — ERET con productos loteados. **Verificar ANTES en el
   destino si se manejan lotes** (¿existe `producto.seguimiento_lote`? ¿los
   movimientos guardan `id_lote` y quedan ligados a la factura?): si NO se
   manejan, portar solo la rama de lote genérico y NO arrastrar las queries de
   lotes; si se manejan, adaptar la búsqueda de lotes de la venta a su esquema.
   `39fa0ec` — descarga pública del DTE (`downloads/dteqr_pdf.php` +
   `dteqr_json.php`, la contraparte del QR de `_helper_ticket_dte.php`, ahora
   con marca de agua ANULADO y JSON sellado sin escritura a disco): verificar
   que el destino tenga el módulo y adaptar las rutas vendor/FPDF a su layout.
   `be61317` — la anulación SIEMPRE busca tipoDTE='ANU' en
   `get_DteJsonNoRecibido()` (`_helper_dte.php`): `getTipoDTEFromDoc('ANU')`
   devuelve null y hacía que se perdiera el filtro `tipoDTE`, así que con una
   fila `tipoDTE='ERET'` pendiente de la misma factura (ERET rechazado por MH,
   p. ej. receptor sin teléfono/correo) el `ORDER BY id DESC` seleccionaba el
   ERET y su JWT se enviaba al endpoint `anulardte` — MH respondía
   "Version/Tipo DTE no es valido", la respuesta se anotaba sobre la fila ANU
   real y la factura quedaba imposibilitada de anularse sin cirugía manual en
   BD. Portar el commit SIEMPRE (es la diferencia entre poder y no poder anular
   una factura con ERET fallido) y trazarlo en la cadena de anulación:
   reproducible en ambiente 00 con un flujo CLI que siga los mismos endpoints
   que la vista (venta insertar → creaJsonDTe → envío → save_response_mh; eret
   guardar_evento → generar_eret).
   `3aa8db1` — NC con items exentos (guía §1.13): portar SIEMPRE el commit completo (no dividir el precio del exento en `notacredito`, `getResumenNC` suma `totalExenta` al `montoTotalOperacion`, saldo de devoluciones excluye anuladas escribiendo `devoluciones.id_factura_dev`). Las devoluciones históricas NO se backfillean: quedan con `id_factura_dev=0` y conservan su conteo histórico (lo antiguo queda como está); el UPDATE es reparación MANUAL caso por caso, solo cuando el documento generador se conoce con certeza (p. ej. NCs de prueba del propio port). Prueba de cierre: NC mixta exenta/gravada con retención en ambiente 00 y segunda devolución tras anular la primera.
   `64a1dac` — identidad de cálculo del cuerpo CCF/NC + descuento prorrateado en NC (guía §1.14): portar SIEMPRE y los 3 archivos JUNTOS (`clases/Dte.php`, `notacredito.php`, `js/funciones/notacredito.js`). Cuerpo CCF/NC con la identidad MH (`ventaGravada = cantidad×precioUni − montoDescu` sobre los valores redondeados; exento sin dividir), `getResumenNC` sin dividir `totalDescu`, y el flujo NC prorrateando el descuento de los items base (`subtotalConDescuento()` a TOP-LEVEL en el JS; el builder de `#btnGuardar` con su propio factor IVA; `factura_detalle.descuento` en las 2 ramas del UNION de `initial()`; columna visible "Desc. a devolver" y fila "Tot. Descuento"). Sin este paquete, una NC sobre una venta con descuento por ítem supera el total del documento relacionado (MH: `MONTO ES MAYOR AL DOCUMENTO RELACIONADO`) y un exento con desc revienta `CALCULO INCORRECTO` con cualquier cantidad. Prueba de cierre en 00: CCF y NC total sobre venta con desc por ítem (exento con desc + gravado con desc y cantidad ≥ 100) — la NC debe caer exactamente al total de la base.
   `d0ad9de` — modal de reenvío de DTE (guía §1.12): endpoint
   `enviar_correo_dte.php` + ítem "Reenviar DTE" + `tipoDTE()` en `_helpers`
   (verificar colisión de nombre) + rework del bloque de correo de
   `dte_factura_pdf.php` (destinatario/CC del modal, validación, respuesta JSON
   en `output=file` con `ob_start`) + handlers `#btnSendMail`/`#btnSaveEmail`
   en `getAuth.js`. Sin el refactor cosmético de índices `dt` del admin; rama
   CRE muerta documentada (el archivo al que apunta no existe ni en el origen).
   7b. `453cf0a` + merge `6747dda4` — mensajes personalizados MH + validación temprana de distrito + guard de token (guía §1.15): portar junto con el rango. `assets/json/mh_mensajes.json` NUEVO (público, el JS lo hace fetch sin sesión; `aee0b71` lo mueve a assets/json), `mensajePersonalizadoMH` PHP en `_helpers.php` (alcanza vía `_core`), validación temprana de sucursal sin distrito en `Dte.php`, guard de token undefined/"undef" en getAuth/dte_auto (antes el forEach de observaciones reventaba en silencio), Swal persistente desde `util.js` — **verificar con grep que cada página que carga getAuth/dte_auto/venta* llegue a un include de `util.js`** (en el origen lo traen footer.php/footer2.php). Los 4 JS van por ANCLAS (los destinos tienen versiones propias). Sin conflicto con §1.13/§1.14: este commit no toca el cálculo del cuerpo.
   7c. `81a21a7` — formulario de compras por archivo JSON (guía §1.16): `compras_json.php` + `js/funciones/compras_json.js` (ambos NUEVOS en el origen, sin contraparte en los destinos): carga toda la info de una compra desde un JSON (proveedor por similitud `cj_match_*`, items con match de producto/equivalencias, validación de documento). Port directo (archivos nuevos) PERO con auditoría dual contra el esquema de compras del destino ANTES de copiar: las funciones `cj_*` consultan tablas/columnas (`compra`, `compra_detalle`, `presentacion_producto`, equivalencias) por SQL plano — cada columna referenciada debe existir en el destino o adaptarse por semántica de datos. Crea su propia tabla `producto_proveedor_codigo` con `CREATE TABLE IF NOT EXISTS` (autónoma, sin migración SQL manual). El JS apunta a `compras_json.php` (URL) y `compras.php` (URL_COMPRAS): verificar que ambos existan en el destino y que `permission_usr` use el nombre del propio script. Requiere el punto de menú correspondiente.
   7d. `60ed2bf` + `b216e9d7` + `a015af67` — evolución del catálogo `mh_mensajes.json` (guía §1.15/§1.16): contenido PURO del JSON, una sola feature que evoluciona por cadena con la base `453cf0a` (§7b): `453cf0a` la crea (JSON en raíz + toda la lógica PHP/JS), `60ed2bf` agrega las entradas DUI/Giro, `aee0b71` mueve el archivo de la raíz a `assets/json/` (actualizando las 2 referencias: `_helpers.php` y `util.js`), `b216e9d7` cambia el pronombre Cliente→Receptor en `receptor_distrito` (título y mensaje, ahora cubre cliente O proveedor) y `receptor_municipio` (título), y `a015af67` agrega la entrada `receptor_telefono` (patrón `RECEPTOR/TELEFONO` — guía para teléfono inválido/ausente del receptor). Port literal del JSON: aplicar TODAS las entradas al archivo del destino, en la ubicación donde el §7b lo dejó (los 3 commits de contenido aplican igual a raíz o a `assets/json/`, es el mismo archivo).
   7e. `5682cc14` — PDF de factura con nombre del cliente manual (guía §1.17): en `headerEmisorReceptor` de
   `dte_factura_pdf.php` la línea "CLIENTE :" prefiere `factura.extra_nombre` (nombre tipeado a mano al vender,
   columna legacy de la venta) con fallback al nombre de la ficha. Una línea dentro del merge del PDF (paso 1);
   verificar contra el destino si su captura de nombre manual vive en otro campo (mapear por ancla) o no existe
   (la línea queda inocua, portar igual para alinear con el origen).
8. **venta2**: aplicar la regla de `venta.php`/`venta2.php` de references/REGLAS_PORT.md
   ("El criterio de parada") — detectar si venta2 está en uso o es residuo y, si está
   en uso, repetir en él todos los cambios de venta (sin unificar).
9. Validación sintáctica de TODO lo tocado: `php -l` para cada PHP y
   `node --check` para cada JS. Es el piso, no el techo: no valida funciones ni
   clases indefinidas.
10. **Verificación de flujo por traza de llamadas (obligatoria antes de dar el
   port por terminado).** Para cada punto de entrada portado, ejecutar el
   procedimiento completo de references/REGLAS_PORT.md ("El criterio de parada"): subir/bajar el
   grafo función→función→clase→archivo→require hasta el botón que dispara todo,
   verificando que cada callee existe en el destino con firma compatible y que
   cada include resuelve en SU layout.

   **La traza empieza en CADA HUNK, no solo en los endpoints.** La lista de
   puntos de entrada mínimos cubre los flujos clásicos, pero un port también
   introduce código FUSIONADO dentro de archivos grandes del destino (una
   función nueva pegada en una vista, un bloque añadido a un helper), y ese
   código no es "punto de entrada" de ninguna lista: su cadena de dependencias
   se revisa igual o se pierde. Pase mecánico obligatorio sobre el diff del
   port completo (`git diff <espejo>..<migración>`), ANTES de la traza de
   endpoints: para CADA hunk añadido a un archivo existente, enumerar los
   símbolos NUEVOS que introduce — clases instanciadas (`new X`, `X::`),
   funciones globales llamadas, constantes, tablas/columnas tocadas — y para
   cada uno verificar: (a) existe en el destino, (b) **está cargado en el
   contexto del archivo portador** (estos sistemas NO tienen autoload: toda
   clase/función que viva en otro archivo exige un `require_once` explícito EN
   EL ARCHIVO PORTADOR o en su cadena de includes — grep de los requires del
   portador y cruce contra la ubicación real del símbolo; si falta, AGREGAR el
   require en el portador), y (c) firma compatible. `php -l` NO detecta nada
   de esto: una clase no cargada pasa el lint y revienta en runtime en la
   primera ejecución del flujo, típicamente ya en producción. Este pase es el
   que detecta el fallo clásico del port: el hunk copiado usa una clase del
   origen, el archivo portador del destino nunca la cargaba, y nadie mira el
   interior del hunk porque "la traza de endpoints no pasa por ahí".
   Este pase verifica EXISTENCIA y CARGA; la dimensión que le falta —
   unicidad de DECLARACIÓN y reutilización de símbolos que el destino ya
   tiene en otra ruta — es la FASE 2.7 (obligatoria, se ejecuta después de
   este pase y antes de la FASE 2.5).
   Puntos de entrada mínimos a trazar:
   - Generación DTE: botón "Crear DTE"/automático → `getAuth.js` (`crea_dte`) →
     `_helper_dte_class.php`/`generar_dte.php` → `clases/Dte.php` (y TODOS sus
     requires: `Compra.php`, `FacturaPago.php`, `DteModel.php`...).
   - Respuesta MH: `save_response_mh` → `actualizarCorrelativo` → `getCaja`/
     `getDatosDTESE`/`CorrelativoDte` (existen y firmas correctas).
   - Anulación: botón eraser → `anular_factura.php`/`anular_dte.php` →
     reversa de stock (ENTRADA/SALIDA) → clases/tablas que toca; con ERET
     pendiente de la misma factura, verificar que `get_DteJsonNoRecibido()`
     seleccione la fila `tipoDTE='ANU'` y no el evento (`be61317`).
   - PDF: botón PDF → `dte_factura_pdf.php`/`dte_fse_pdf.php` →
     `anuladoDTEData`/`getNombreDistrito`/`obtenerTextoVersionDTE` → `_utiles.php`
     incluido en la página que los usa (fatal "undefined function" si falta).
   - Evento retorno: enlace → `evento_retorno.php` + `evento_retorno.js` →
     `clases/EventoDte.php` → sus requires (con `6007e66`, también `Lote`/
     `MovimientoProducto` y sus tablas SI el destino maneja lotes — ver paso 7).
   - Descarga pública: QR del PDF → `downloads/descargadte.php` →
     `dteqr_pdf.php`/`dteqr_json.php` y sus requires (FPDF, vendor, modelos;
     guía §1.11).
   - Admins DataTable: closures de `*_dt.php` → funciones globales (`firmada`,
     `recibida`, `anuladoDTE`...) → colisiones con definiciones locales.
   Dejar constancia en el registro de cada cadena trazada y qué dependencias
   hubo que adaptar. Si una cadena no se puede cerrar (dependencia faltante que
   requiere decisión del cliente), el port NO está terminado: se pregunta.

## FASE 2.5 — Revisión de coherencia lógica funcional (OBLIGATORIA, no opcional)

La traza de llamadas (FASE 2 paso 10) y la verificación de valores prueban que
el código portado **EXISTE y FUNCIONA**. Ninguna de las dos prueba que sea
**LÓGICO**. Un port puede quedar con cero errores de sintaxis, cero dependencias
faltantes y todas las cadenas cerradas, y aun así estar mal: un botón "Anular"
visible sobre un documento ya anulado (que al click solo produce el mensaje de
error del backend), un "Eliminar" que inserta en vez de eliminar, un "Enviar PDF
DTE" que nunca dispara el correo, una opción que promete imprimir en ticketera y
abre el documento en el navegador. Esa clase de defectos NO se detecta
compilando ni siguiendo dependencias: se detecta razonando sobre **ESTADOS**.

Esta revisión es **OBLIGATORIA**: se ejecuta siempre, sin ofrecerla como opción
y sin dar el port por terminado sin ella. "Ya terminé" solo se puede decir
cuando: todo existe, todo compila, los valores son correctos en todas las rutas,
y cada opción visible es coherente con el estado donde se muestra. Lecciones
ejecutadas que este nivel sí habría destapado y las anteriores no: botón de
anulación visible en estado ya anulado; correo que solo salía por una de las
dos rutas que lo prometían; validación de cliente con función no cargada que
jamás se había disparado.

### Principio rector

> En cada estado de una entidad, el conjunto de acciones VISIBLES debe ser
> exactamente el conjunto de acciones EJECUTABLES CON ÉXITO. Si en un estado la
> única salida posible de un control es un mensaje de error del backend, ese
> control no debe renderizarse en ese estado. El guard del backend es defensa
> en profundidad, no licencia para mostrar opciones muertas: que el backend
> rechace la acción no hace correcta la interfaz que la ofreció.

### Método (sobre el código del destino, sin navegador)

1. **Inventario de controles.** Para cada admin/lista/formulario/modal que el
   port TOCÓ — y para cada admin que muestre entidades cuyo ESTADO el port
   puede cambiar (ej.: al portar la anulación ENTRADA/SALIDA, los menús de
   anulación de TODO admin que liste esos documentos, no solo el tocado) —
   extraer cada control de acción (botón, enlace, ítem de menú) con su
   condición de render real, y marcar explícitamente los que NO tienen
   condición `if`: son los sospechosos principales. Grep típico: los
   `menudrop.="<li>..."` de los `*_dt.php`, los
   `$(document).on('click', ...)` de los `js/funciones/*.js`, los
   `<button>`/`<a>` de las vistas.

2. **Estados de la entidad, leídos de datos reales.** Derivar los estados del
   ciclo de vida de las columnas que de verdad los codifican, no de suposiciones.
   P. ej.: compra/venta → `finalizada`/`anulada`; DTE → fila en `jsonDTE` con
   `recibidoMH`, `selloRecibido`, `codigoMsg`, `anulado`; evento →
   `evento.estado`/`selloRecibido`. Un estado solo "existe" para esta revisión
   si hay una consulta/condición de UI capaz de distinguirlo.

3. **Matriz estado × acción (el paso que detecta los defectos).** Por cada
   control, responder dos preguntas por estado: (a) ¿el control se renderiza en
   este estado? (b) en ese estado, ¿la acción puede COMPLETARSE con éxito, o su
   único desenlace es un rechazo del backend? Cada celda donde (a)=sí y (b)=no
   es un **defecto de coherencia**: se mitiga ocultando (o deshabilitando con
   explicación) el control en ese estado, SIN quitar el guard del backend, que
   queda como segunda barrera. Casos reales: "Anular" visible sobre una compra
   con `anulada=1` (único desenlace: el throw de `anular()`); "DTE Anulación"
   visible cuando la invalidación ya fue aceptada por MH (sello presente).

4. **Etiqueta ↔ efecto (lo que la acción promete vs lo que hace).** Por cada
   acción inventariada, seguir la cadena completa (JS → endpoint → clase →
   BD/archivo/correo) y contrastar el EFECTO REAL con lo que la ETIQUETA
   promete: "Anular" anula (reversa correcta y ya no permite repetirlo);
   "Eliminar" elimina y no inserta; "Enviar PDF DTE" dispara el envío real al
   receptor por TODAS sus rutas (verificar contra la guía §5: ruteo FSE,
   `output=file`, apikey en cada uso del archivo); ticket/ticketera produce el
   ticket de impresora que promete; "JSON DTE" descarga el JSON sellado. El
   efecto se verifica en el código y, con BD disponible, en datos (sello,
   fila actualizada, registro de envío).

5. **Los guards del backend como detector sistemático.** Grep de
   `throw new Exception` y respuestas de error en el backend tocado; por cada
   uno preguntar: ¿existe una ruta de UI NORMAL (click en un control visible,
   en el estado donde ese control se muestra) que llegue hasta aquí? Si
   existe, la llegada es un defecto de coherencia de frontend (mitigar como en
   el paso 3), no un "error esperable del backend".

6. **Contrato de parámetros (la intención del llamador vs el valor que viaja).**
   Tercera clase de defecto, distinta de la existencia (todo existe y compila)
   y del estado (el control es legítimamente visible): el llamador quiere el
   efecto A pero le pasa al endpoint el VALOR que produce el efecto B, o
   ninguno. En cada endpoint/handler que el port tocó, enumerar los parámetros
   que SELECCIONAN COMPORTAMIENTO (`process`, `output`, `tipodoc`, `anula`,
   flags de vista/envío...) y, para cada llamador (handler JS, URL de menú,
   cURL interno, llamada entre archivos), verificar que el valor enviado
   corresponde a la intención de quien lo envía. Casos típicos: un modo de
   salida que genera en silencio mientras el llamador espera que además envíe
   (la variante no-correo de la FASE 2.6); un `process=` que cae en un case
   muerto o inexistente (respuesta vacía sin error); un flag que el backend
   compara con tipo distinto al que envía la vista. El método es el mismo de la
   FASE 2.6 punto 2, generalizado: seguir el VALOR desde donde el llamador lo
   fija hasta el `switch`/`if` que lo interpreta, y responder ¿este valor
   ejecuta lo que el llamador intenta? Si no, la fila está rota aunque todo
   compile y todos los controles sean visibles. Para el correo esta matriz ya
   la exige la FASE 2.6; para todo lo demás se aplica aquí.

### Defectos de lógica del origen: se mitigan, no se copian

Si el defecto de coherencia está EN EL CÓDIGO DEL ORIGEN que se está portando
(caso frecuente: el archivo normativo se copia verbatim y el defecto viene con
él), la fidelidad al commit NO lo protege: **un defecto de lógica verificado no
se porta.** Se mitiga en el destino con el mismo criterio de los pasos 3/4, se
documenta en el registro (hallazgo, evidencia del estado y del desenlace, fix
aplicado) y se recomienda al usuario replicar la corrección en el repo origen
para que el siguiente port no la arrastre. Esta es la excepción explícita a
"portar el estado final del origen" — y aplica SOLO a defectos demostrados con
la matriz estado×acción o el contraste etiqueta↔efecto; nunca a decisiones de
diseño ni a features que simplemente no se comparten.

### Salida obligatoria

- El registro debe llevar una sección **"Correcciones de coherencia lógica
  aplicadas (no normativas)"** con cada hallazgo, su evidencia y su fix.
- El informe final al usuario debe ENUMERAR esas correcciones de forma
  explícita: qué se cambió en el destino MÁS ALLÁ de los commits de la skill y
  por qué, para que el usuario sepa exactamente qué difiere del origen y pueda
  replicarlo upstream si quiere.
- Si algún defecto detectado NO se corrige (alcance, decisión del cliente),
  queda en esa misma sección como pendiente con su evidencia: un hallazgo de
  coherencia jamás se silencia.

## FASE 2.6 — Auditoría del contrato de correo (OBLIGATORIA, no opcional)

El envío de correo es el componente con más defectos recurrentes entre ports,
y el único que falla casi siempre EN SILENCIO: un `error_log` en el servidor,
un "Zero recipients", una respuesta JSON `"Datos adjuntos incompletos"` que
nadie mira porque la vista ignora el cuerpo. Además es el componente con más
COPIAS: cada archivo que despacha correo trae su propio bloque (endpoint de
generación, endpoints de anulación con copias viejas de `save_response_mh`,
cada generador PDF, ambos flujos JS), y esas copias evolucionan por separado —
arreglar una no arregla las demás. Objetivo de esta fase: que al cerrar el port
el correo funcione EN LA PRIMERA PASADA, desde todas las acciones que lo
disparan (DTE aceptado en generación automática, anulación aceptada, ERET
aceptado, botón manual "Enviar PDF DTE", modal de reenvío si se portó) y para
todos los tipos de documento. Se ejecuta SIEMPRE, igual que la FASE 2.5; las
pruebas del usuario en ambiente 00 (FASE 3 paso 2) vienen DESPUÉS y la
encuentran hecha.

### Método

1. **Inventario completo de puntos de envío.** Grep en el destino de
   `PostmarkClient`, `sendEmail`, `PostmarkAttachment`, `enviarCorreo`,
   `sendMail`. CADA bloque de envío encontrado es una fila de la "matriz del
   correo" del registro, con estas columnas verificadas una por una:
   - Archivo + línea, y flujo(s) que lo alcanzan: qué acción de UI llega aquí,
     con qué `output` (`text|file|screen`) y qué `tipodoc`. Varias rutas pueden
     converger en un bloque; un archivo puede tener DOS bloques.
   - Fuente del API key: constante `API_MAIL` con fallback `$_ENV` (nunca
     `$_ENV` pelado — lección 2026-10-14: la constante cargada al inicio del
     archivo era pisada 300 líneas después por el bloque de correo).
   - Remitente (`EMAIL_SENDER` en `.env`), guard de CC
     (`$cc==false || $cc=='false'` → null) PRESENTE EN ESE BLOQUE, try/catch
     alrededor del envío. Estas verificaciones son por fila, no "del sistema":
     un bloque corregido no propaga nada a sus copias.
   - Construcción de cada adjunto (columna 2).

2. **El contrato de adjuntos (la causa nº 1 del correo que "no llega").** El
   bloque de correo y el generador del PDF deben estar de acuerdo en el
   CONTRATO DE RETORNO de la generación: `Output(...,"F")` escribe a disco y
   NO devuelve contenido; `Output(...,"S")` devuelve el contenido sin
   escribirlo. Todo bloque de correo portado del origen adjunta el RETORNO
   (`fromRawData($pdfData, ...)`); si el destino conserva su propio helper de
   salida (función que envuelve `Output`, p. ej. una que despacha según el
   parámetro `output`) con rama `file`→"F", cada llamador portado que adjunte
   su retorno despacha un adjunto VACÍO: Postmark lo rechaza o el guard del
   bloque responde "adjuntos incompletos" sin ningún error visible. Verificación
   mecánica por fila: seguir el valor del adjunto desde su generación hasta
   `fromRawData` y responder ¿qué devuelve esta función en ESTE modo de salida,
   EN EL DESTINO? Si la respuesta no es contenido (`%PDF...` para el PDF, el
   `json_value` sellado para el JSON), la fila está rota aunque todo compile.
   Dos resoluciones válidas: (a) la rama del helper devuelve contenido — la
   exclusión "no portar el helper con S a menos que se porte el flujo de
   correo" es condicional y aquí se cierra; (b) el bloque lee el PDF de
   `pdfdtes/` con `file_get_contents` — pero entonces el ORDEN de ejecución
   debe garantizar que el generador escribió el archivo ANTES del envío.
   Nunca dejar las dos mitades con contratos distintos.

3. **Bloques hermanos.** Los bloques de correo son copias entre archivos
   (generación, anulación —incluidos los archivos con copias viejas de
   `save_response_mh`—, cada generador PDF, ambos flujos JS). Tras CUALQUIER
   ajuste de correo, repetir el grep del punto 1 y aplicar el mismo estado a
   todas las copias; una fila corregida y su hermana sin corregir es el defecto
   clásico de "funciona por una ruta y no por la otra".

4. **Ruteo por tipo de documento.** `genera_pdf_save` en TODOS los JS que lo
   definan (manual y automático) debe rutear `tipodoc=='FSE'` al generador FSE,
   y toda URL a un generador debe llevar `&tipodoc=FSE` explícito (un notice
   `Undefined index` contamina la salida y FPDF aborta con "Some data has
   already been output"). `sendMail*` de anulación con rama FSE (parámetro
   extra): sin ella, anular un FSE da "Zero recipients" por leer el JSON
   equivocado — verificar TODOS los call sites. La ruta del modal de reenvío
   (si se portó) no usa el CC global del `.env` por diseño del origen:
   documentarlo, no "corregirlo".

5. **Configuración del destino: verificar, no suponer.** (a) el archivo
   hermano del API key existe en el despliegue (un nivel encima del proyecto —
   ver regla al inicio) y define la constante; o (b) la constante/`API_MAIL`
   está en el `.env` del destino. Ambos ausentes = BLOQUEANTE reportado al
   usuario, jamás "resuelto" moviendo rutas. `EMAIL_SENDER` definido.
   `EMAIL_CC=false` llega como string truthy: el guard es obligatorio.

6. **Decisiones condicionales y contratos de interfaz (cierre).** Toda decisión
   de port con forma "no portar X a menos que Y" queda anotada en el registro
   como CONDICIÓN PENDIENTE en el momento en que se toma, y se RE-EVALÚA aquí,
   al cierre, cuando ya se sabe qué se portó: una decisión condicional nunca se
   da por cerrada cuando se tomó (el estado del port cambió desde entonces).
   Y pase de contratos de interfaz: para cada función que el ORIGEN cambió y el
   DESTINO conservó (la lista de exclusiones del registro es exactamente esa
   lista), verificar que cada llamador PORTADO del origen sea compatible con el
   contrato del destino (retorno, tipos, efectos secundarios). El defecto vive
   en la frontera entre código portado y código conservado: ningún chequeo por
   archivo la cubre; este pase sí.

### Salida obligatoria

- El registro lleva la **matriz del correo** completa: una fila por punto de
  envío con el veredicto de cada columna y la evidencia (qué devuelve el
  generador en cada modo, qué fuente de API key usa ese bloque). "El correo
  funciona" no se afirma: se demuestra fila por fila; una fila rota queda
  listada con su causa o corregida antes del cierre.
- Las re-evaluaciones de decisiones condicionales quedan documentadas en el
  registro (decisión original, condición que la reabre, veredicto final).

## FASE 2.7 — Auditoría de declaraciones: colisiones de símbolos y reutilización (OBLIGATORIA, no opcional)

La traza de llamadas (FASE 2 paso 10) verifica EXISTENCIA: "¿existe la función/
clase que este hunk necesita en el destino?" — y un "sí" la da por buena. Ese
criterio tiene tres huecos que un port real destapó en PRODUCCIÓN (port
2026-11-12, fatal "Cannot declare class Model" en una página de caja días
después del despliegue; ni `php -l`, ni la traza de dependencias, ni el pase de
símbolos lo vieron):

1. **Existencia ≠ unicidad de declaración.** La traza confirma que la clase
   existe; no confirma que el símbolo NUEVO que el port acaba de introducir no
   se declare TAMBIÉN en otra unidad que la misma petición carga. Dos archivos
   físicos distintos que declaran `class Model` pasan lint, pasan trazas, y
   reventan en runtime con "Cannot redeclare" en la primera página que cargue
   ambos.
2. **`require_once` deduplica por RUTA, no por símbolo.** Añadir el require
   "correcto" no salva nada cuando hay DOS archivos distintos declarando el
   mismo nombre (`clases/Model.php` vs `framework/Model.php`): cada uno se
   carga una vez y chocan igual. El instinto "agrego el require que falta" es
   exactamente lo que agrava el caso.
3. **El destino ya tiene el símbolo en OTRA ruta, con otro nombre de archivo y
   a veces otra forma.** El origen centraliza en un helper; el destino lo trae
   inline en cada admin, o al revés. La traza ve "no está en el archivo
   portador → lo porto", cuando en realidad estaba vivo a un include de
   distancia: el resultado es una DUPLICACIÓN funcional que luego diverge en
   mantenimiento (se arregla una copia y la otra queda rota) — la misma
   familia de defecto que los bloques hermanos del correo (FASE 2.6), pero
   nacida en el port en vez de heredada.

La coherencia lógica (FASE 2.5) no cubre nada de esto: es la frontera entre lo
que el port AÑADE y lo que el destino YA TIENE, y hay que auditarla como
contrato propio.

### Método (sobre el destino, sin navegador; sí-lógico: lint + CLI local)

1. **Inventario de símbolos INTRODUCIDOS por el port.** Sobre el diff completo
   del port (mismo pase mecánico del paso 10, esta fase lo extiende): para
   cada hunk añadido a un archivo EXISTENTE del destino, enumerar los símbolos
   GLOBALES nuevos que declara (funciones `function X`, clases `class X`,
   constantes `define`) y los que REQUIERE NUEVAMENTE (requires añadidos).
   Los archivos NUEVOS copiados del origen también cuentan: cada uno trae
   todas sus declaraciones.
2. **Cierre transitivo del conjunto declarado por la cadena de includes.** Por
   cada archivo del destino que ejecutará esos símbolos (el portador y, sobre
   todo, las PÁGINAS reales que lo cargan — vía `_core.php`, `_session.php`,
   footers, helpers), calcular el conjunto UNIÓN de todos los símbolos que su
   cadena de includes completa declara. Herramienta mínima: script PHP/CLI que
   siga `require`/`include` recursivamente (respetando `require_once` por
   ruta) y extraiga `function|class|interface|trait|define` de cada archivo
   alcanzado. El conjunto de la página es lo que importa, no el del archivo
   aislado.
3. **Veredicto por símbolo (tres salidas, obligatorio elegir una y registrarla):**
   - **COLISIÓN** — el símbolo ya se declara en ≥2 archivos físicos que la
     misma página carga (o el port añade una declaración que ya existe en otra
     ruta cargada): el runtime revienta o el comportamiento queda indefinido.
     Resolución: UNIFICAR — los archivos duplicados se convierten en shims que
     delegan (`require_once` a la implementación elegida) o se elimina la
     declaración redundante; elegir la implementación por auditoría dual
     (superset de API verificada función a función, constructor compatible).
     Nunca resolver borrando "el que parece no usarse" sin el pase de
     llamadores completo.
   - **DUPLICADO FUNCIONAL** — el símbolo ya existe en una ruta alcanzable del
     destino (distinto nombre de archivo, misma firma o equivalente): REUSAR.
     El hunk portado se adapta al símbolo del destino (o se extrae el símbolo
     a un punto común y ambas rutas incluyen el mismo), y NUNCA se vuelve a
     declarar. Anotar el mapeo origen→destino en el registro (archivo del
     origen → ruta real del destino → decisión reusar/portar).
   - **NUEVO legítimo** — no existe en ninguna ruta alcanzable: portar con su
     require en el portador (lo que el paso 10 ya hace). Verificar que el
     NOMBRE no colisiona a futuro con archivos del destino que la página NO
     carga hoy pero podría cargar tras otros ports (grep del nombre en todo el
     destino; colisión potencial = nota en registro, no bloqueo).
4. **Harness CLI por página mixta (la verificación canónica; obligatoria para
   toda página que cargue ≥2 archivos tocados por el port).** Es el único
   chequeo que ve el runtime real sin navegador: un script CLI que inicie
   sesión falsa (`$_SESSION` mínima que los guards del destino acepten),
   cargue la MISMA cadena de requires que carga la página en producción (en el
   MISMO orden) y ejecute al final `class_exists`/`function_exists` +
   `(new ReflectionClass('Model'))->getFileName()` para confirmar que cada
   símbolo resuelve a UNA sola definición. Repetir en ambos órdenes de carga
   (legacy-primero y framework-primero): el orden cambia qué archivo gana y
   el port debe ser inmune a él. La página que reventó en producción
   (`cortecaja_dia.php`) habría sido destapada aquí ANTES del despliegue por
   este harness exacto.
5. **Alcance de páginas:** no solo las que el port tocó directamente — toda
   página que cargue archivos cuyo STACK cambió (ej.: si se unificó un ORM,
   TODA página que cargue clases de ambos lados de la unificación; grep de los
   requires de cada `*.php` raíz para armar el mapa, sin fiarse de la lista de
   páginas "obvias"). En destinos con cientos de páginas, cubrir como mínimo:
   las tocadas por el port, las que carguen los archivos cuyo stack cambió, y
   una muestra aleatoria del resto (10%) como sondeo.

### Salida obligatoria

- El registro del port lleva la sección **"Colisiones de declaración y
  reutilizaciones (FASE 2.7)"**: por cada símbolo auditado con veredicto
  COLISIÓN o DUPLICADO FUNCIONAL — qué archivos lo declaraban, qué cadena los
  cargaba, la resolución aplicada (unificación/reuso, con qué evidencia de
  superset/API) y el resultado del harness CLI (página → ambos órdenes de
  carga → símbolo → archivo que resuelve).
- Los símbolos NUEVO legítimos se listan en resumen (los detalles finos ya
  están en la traza del paso 10).
- Si una colisión NO se corrige (decisión de alcance), queda en esa sección
  como pendiente con su evidencia: una colisión latente jamás se silencia —
  es una bomba de tiempo en producción (el caso real saltó DÍAS después del
  port, en una página que nadie del port había tocado).

## FASE 3 — Cierre

### Revisión post-port de los generadores PDF de salidas (OBLIGATORIA, silenciosa)

Al terminar TODO el port (todas las fases anteriores cerradas), y SIN preguntar
nada al usuario en este punto, revisar qué les pasó a los generadores PDF de
salidas (`dte_factura_pdf.php`, `dte_fse_pdf.php`, `pdf_compra.php`,
`fpdf185/factForm.php`, `downloads/dteqr_pdf.php`, `_helper_ticket_dte.php`,
`_helper_dte.php` si imprime, y CUALQUIER otro generador que el port haya
tocado según el registro — el barrido parte de los archivos tocados, no solo de
la lista fija). El objetivo: detectar y reportar personalizaciones del cliente
que el port haya tocado, sobrescrito o perdido, para que el usuario decida
DESPUÉS qué recuperar. Método:

1. **Estado pre-port:** con git (`git -C <destino> diff HEAD -- <archivo>` si el
   archivo estaba commiteado) o contra la copia `tmp/pre_port_pdf/` creada en la
   FASE 2 (destinos sin git). Si el destino es repo git pero el archivo tenía
   cambios sin commitear al inicio del port, la comparación contra HEAD mezcla
   lo propio del cliente con lo del port — en ese caso usar la copia (creada por
   la regla de cambios sin commitear) o documentar la limitación.
2. **Dos comparaciones por archivo** (ambas, no una sola):
   (a) **pre-port vs post-port del destino** → qué cambió el port en el archivo
   (esto valida que el port solo tocó lo normativo); y
   (b) **pre-port del destino vs el mismo archivo del ORIGEN** → qué había
   personalizado el cliente respecto al base (divergencias de diseño, columnas,
   textos, lógica de qué se muestra). El cruce (a)+(b) identifica si alguna
   personalización quedó pisada por la versión del origen o fusionada mal: todo
   lo que aparezca en (b) y haya desaparecido o mutado en (a) es hallazgo.
3. **Si hubo hallazgos y el usuario los quiere recuperar**, restaurar desde la
   copia/git solo los fragmentos personalizados, re-aplicando encima los bloques
   normativos no-visuales (correo, API key, ruteo FSE, marca de agua) — nunca
   restaurar el archivo completo pre-port: perdería la normativa.
4. **Salida obligatoria:** sección **"Personalizaciones PDF detectadas en el
   cierre"** en el registro (archivo, qué difería del origen, qué hizo el port,
   estado final y si el usuario debe decidir algo) y el resumen de estos
   hallazgos en el informe final al usuario. Si no hubo hallazgos, dejar la
   sección con "sin divergencias" y la evidencia de los diffs ejecutados.
   Esta revisión se reporta como INFORME, nunca como pregunta a mitad del port:
   respeta el modo de trabajo que el usuario eligió al inicio.

0. **Limpieza:** si el destino es un repo git y quedaron archivos de respaldo
   (`.bak`, `*.pre-port.bak`, etc.), eliminarlos antes del registro — la
   reversión/comparación se hace con git (`git diff`, `git restore`), ver
   regla en references/ENTORNO_Y_LIMITES.md. Verificar con
   `git -C <destino> status --short` que solo queden los cambios reales del port.
   (La copia `tmp/pre_port_pdf/` NO se elimina aquí: se usa primero en la
   revisión de arriba y se conserva como evidencia del registro y como backup
   hasta el veredicto del usuario — su eliminación definitiva corresponde al
   paso 5 de limpieza final, al cierre de todo el port.)
1. Registro: escribir/actualizar un `REGISTRO_MIGRACION_NORMATIVA_2_0.md` en el
   `tmp/` del destino con: qué se portó (por archivo y fragmento), qué se dejó
   fuera y por qué (commit de origen), las **cadenas de llamadas verificadas**
   (punto de entrada → dependencias, y qué se adaptó), la sección **"Correcciones
   de coherencia lógica aplicadas (no normativas)"** de la FASE 2.5 (hallazgo,
   evidencia, fix — o pendiente con su evidencia), la **matriz del correo** de
   la FASE 2.6 (fila por punto de envío, con veredictos y evidencia), el cierre
   de las **decisiones condicionales** ("no portar X a menos que Y": decisión
   original, condición que la reabre, veredicto final), el **mapa de
   equivalencias origen→destino** de toda discrepancia de nombre de archivo
   resuelta por ancla (archivo del origen → archivo real del destino → ancla
   usada para encontrarlo; ver regla "Equivalencias funcionales"), la **lista
   de verificación por comportamiento** (feature → comportamiento prometido
   por el commit → archivo/línea del destino donde se materializa, o PENDIENTE
   si no se puede señalar), el estatus de
   `venta2.php` (en uso / residuo, qué se le aplicó y **los comandos con que
   se verificó la clasificación**, per la regla de clasificación de arriba), la
   sección **"Personalizaciones PDF detectadas en el cierre"** de la revisión
   post-port de generadores PDF (arriba),
   y las decisiones pendientes del cliente.
   Este registro es lo que permite auditar y replicar en el siguiente sistema.
2. Pruebas de ambiente 00 (`DTE_ENVIRONMENT=00`): **por defecto el usuario las
   ejecuta; el agente JAMÁS por iniciativa propia** (regla dura de "Importante
   sobre el entorno": nada de endpoints de MH ni material de firma por parte
   del agente). EXCEPCIÓN: si el USUARIO lo pide explícitamente en la
   conversación — p. ej. porque ya cargó credenciales 00 y certificados de
   prueba — el agente puede ejecutar estas mismas pruebas reales ante MH desde
   CLI, con su aval y siempre en 00. La lista de casos: COF
   mostrador, CCF, NC sobre venta (INCLUIR SIEMPRE una NC mixta exenta/gravada con retención: con gravados puros los defectos de `3aa8db1` pasan por accidente),
   NC sobre venta, FSE, anulación de cada tipo (con marca de agua en PDF),
   consulta DTE, evento retorno, envío de correo por TODAS sus rutas (automático
   al aceptar, automático al anular, botón manual, modal de reenvío si existe),
   reimpresión masiva, ticket POS. La guía §4.6 tiene el detalle de qué validar
   en cada uno. Esta pasada es posterior y complementaria: la FASE 2.6 ya dejó
   el correo auditado estáticamente — si algo falla aquí, primero revisar la
   matriz del correo del registro y corregir la fila rota.
3. No commitear ni subir nada sin instrucción del usuario: los cambios quedan en
   su copia local para que él revise y suba.
4. **OFERTAR la segunda revisión lógica a detalle (OPCIONAL — preguntar siempre
   al terminar, no ejecutarla sin consentimiento).** Esta revisión es
   COMPLEMENTARIA y DISTINTA de la FASE 2.5: la 2.5 (obligatoria) verifica
   coherencia de estados y controles; esta profundiza en los VALORES y TIPOS que
   viajan por cada flujo portado de punta a punta (quién dispara qué, con qué
   valores, qué archivo llama a cuál, qué datos viajan por cada ruta). El
   método, autocontenido:
   ingeniería inversa desde el control de UI (grep del `id="btnX"` en las vistas,
   handlers `$(document).on` de los `js/funciones/*.js`, enlaces `<a href=` a
   endpoints) hacia abajo por la cadena de llamadas (JS → endpoint PHP → función →
   clase → query/BD) y de vuelta por el callback/respuesta, siguiendo el diff
   con la lista de elementos tocados del registro; clasificar cada flujo como
   NUEVO (verifica que funciona) o REGRESIÓN (verifica que sigue haciendo lo que
   hacía, leyendo el código eliminado del diff); y por cada flujo documentar
   ruta de UI, pasos, resultado observable en pantalla y verificación en datos
   (stock, sello, tablas nuevas). Los errores que este nivel destapa y la traza
   de existencias no: variables pisadas más abajo en el mismo archivo, varias
   rutas de UI hacia el mismo efecto con estados de parche distintos, tipos que
   cambian de contexto, valores que el destino produce distintos a los del
   origen. La revisión busca que el código portado realmente FUNCIONE en el
   destino, no solo que esté. **Alcance obligatorio de esta revisión (regla
   dura "solo-lógico" de references/ENTORNO_Y_LIMITES.md): los harnesses y
   pruebas de esta revisión se detienen en los calculadores puros (cuerpo,
   resumen, identificación, receptor, pagos) y en la salida local (PDF,
   JSON, contratos); JAMÁS construyen el JWT firmado (es material de
   certificados) ni tocan un endpoint de MH. "Punta a punta" significa
   hasta la frontera del sello, no cruzarla: se valida que el camino a la
   meta esté bien, no que se llegue a la meta.** Al cerrar el port, preguntarle al
   usuario en estos términos:

   > "Verifiqué que todo lo portado existe, sus dependencias resuelven, la
   > sintaxis está en verde y la coherencia lógica de estados/controles quedó
   > revisada (FASE 2.5). Puedo hacer además una **segunda revisión lógica a
   > detalle de valores**: seguir los flujos de cada funcionalidad modificada
   > por la cadena lógica (botón → JS → endpoint → clase → BD → respuesta,
   > hasta la frontera del sello: cálculos, JSON armado, PDF, contratos — sin
   > firmar con certificados ni transmitir ante Hacienda) para confirmar los
   > datos que viajan por cada ruta, no solo que el código esté. ¿La hago?"

   Si el usuario acepta: ejecutar la revisión por flujo (generación DTE, respuesta
   MH, anulación, PDF/correo, ERET, admins, venta/venta2), reportar hallazgos y
   corregir lo que la revisión destape. Si declina: dejarlo anotado como
   pendiente en el registro. El port no se da por "funcional" hasta que esta
   revisión se haya hecho o el usuario la haya declinado explícitamente: un
   checklist tachado no equivale a código que funciona.
5. **Limpieza final de archivos temporales del port (después de TODO lo demás,
   incluida la revisión PDF y el veredicto del usuario sobre ella).** Durante
   el port la skill puede haber creado archivos temporales de respaldo o
   comparación — la copia `tmp/pre_port_pdf/` de los generadores PDF en
   destinos sin git, y CUALQUIER otro archivo que el propio port haya creado
   con ese fin (copias `.orig`/`.bak` de comparación, copias de trabajo). Al
   cerrar todo: (a) **inventariar** los temporales creados por la skill (listar
   rutas exactas, distinguirlos de los dumps SQL de origen/destino y demás
   insumos que el USUARIO aportó — esos NUNCA se tocan); (b) **reportarlo al
   usuario en el informe final**, en estos términos: existen archivos de copia
   temporal que sirven de backup mientras él revisa los hallazgos (PDFs
   incluidos), y NO deben commitearse ni subirse — no pintan nada en el
   repositorio y un commit accidental los arrastraría; (c) **esperar el
   veredicto final del usuario** (¿hay algo que recuperar de las copias?); y
   (d) una vez dado ese veredicto, **eliminarlos el propio agente** sin pedir
   otra confirmación por archivo — el listado del punto (a) ya fue su
   autorización. Si el usuario indica que aún quiere conservarlos, quedan como
   pendiente de limpieza anotado en el registro. El cierre del port debe dejar
   el working tree del destino con SOLO los cambios reales del port
   (`git status --short` limpio de temporales, o sin archivos huérfanos en
   destinos sin git).

### Regla: auditoría dual al portar funciones; la sustitución solo si la equivalencia está demostrada (2026-11-06)

Al portar una función del origen que "parece hacer lo mismo" que algo ya existente en el destino, el DEFAULT es copiar el origen tal cual. Antes de elegir cualquier camino distinto del copy, se ejecuta una **auditoría dual en vivo**:

1. **Auditoría dual:** leer AMBAS implementaciones (origen y destino) y contrastarlas sobre el catálogo completo de insumos del dominio — no solo el camino feliz. Para DTE: los alias de tipo (`COF/CCF/NC/FSE/ND/REM/**ANU**/**ERET**`), versiones 2.0 vs legacy, y efectos secundarios (orden de filas, filtros omitidos). Dos funciones que coinciden en COF/CCF pueden divergir en ANU (`version_tipo_DTE` devolvía `''` y el filtro se omitía) o ERET (devolvía `'01'` apuntando a la fila equivocada) — cada uno provocó un bug real en ambiente 00. La comparación sin este catálogo es teatro, no auditoría.
2. **Tres resultados posibles, con camino obligatorio para cada uno.** Regla general de comentarios: los comentarios del código explican QUÉ HACE la función para un lector que no conoce ninguna migración — sin referencias a sistemas origen/destino, commits ni "equivalente de"; la proveniencia y las decisiones del port viven SOLO en el registro de migración:
   - **El destino no cubre todos los casos** (típico: perdió un caso de borde u orden) → **copy literal del origen**, incluidos sus helpers (`getTipoDTEFromDoc`, `getDte`, etc.). La decisión y sus motivos se documentan en el registro de migración, nunca en el código.
   - **El destino cubre todos los casos** (equivalencia demostrada por la auditoría) → **usar el del destino** y NO duplicar la función del origen. Anotar en el registro que se auditó y qué catálogo se contrastó.
   - **Cubre parcialmente por semántica de datos del destino** (precios con/sin IVA, descuento por unidad, retención en otra columna) → **adaptar**. Los comentarios del código explican QUÉ HACE la función, no su proveniencia (no documentar en el código qué se tomó del origen/destino ni los motivos del port: eso va en el registro de migración). Nunca adaptar lógica de selección/filtrado/orden que el origen ya corrigió sin motivo semántico.
3. **Sustituir sin auditoría es defecto, igual que improvisar:** usar un helper propio "porque hace lo mismo" sin haber recorrido ambos caminos es una violación de este protocolo, no una decisión de diseño. El resultado esperable es perder fixes del origen (hallazgo real: `get_DteJsonNoRecibido` portado con helper propio perdió el caso ANU + `ORDER BY id DESC` que SÍ estaba en el snapshot origen, be613173 ⊂ aee0b71 — falló con "Version/Tipo DTE no es valido").
4. **Cierre de port por diff, no por ejecución:** al final del port, diff por función (`git show <origen>:<archivo>` vs lo portado) verificando que cada divergencia tiene su justificación de la rama 2 (cubre el destino / semántica de datos). `php -l` + trazas validan que corre, NO que sea el código.
