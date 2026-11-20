# Entorno y límites del port (detalle completo)

> Contenido verbatim de la sección "Importante sobre el entorno" de la skill:
> límites de pruebas (solo-lógico y su excepción), backups, catálogos MH embebidos
> y reglas del entorno de ejecución.

## Importante sobre el entorno

- **REGLA DURA — Las pruebas de esta skill son SOLO-LÓGICAS: nunca se transmite
  ante Hacienda ni se toca material de firma... EXCEPCIÓN: cuando el usuario lo
  pide explícitamente.** La verificación por defecto de esta skill es
  verificar que el CAMINO a la meta esté bien, no llegar a la meta y obtener el
  premio: cuando el operador llegue (emita de verdad en ambiente 00 con sus
  certificados), el camino debe estar tan verificado que obtener el sello sea el
  desenlace natural.

  **EXCEPCIÓN a la regla (solo por pedido explícito del usuario):** el usuario
  puede querer que el agente haga pruebas reales ante Hacienda desde CLI — por
  ejemplo porque ya cargó las credenciales del ambiente de PRUEBA 00 y sus
  certificados de prueba, o porque el chat va encaminado a eso y él lo
  solicita. En ese caso SÍ se puede, sin problema: emitir COF/CCF/NC/FSE de
  prueba, anular, consultar, firmar, transmitir — siempre que (a) el usuario lo
  haya pedido o aprobado en esa conversación (un "probara ante hacienda" del
  usuario alcanza; el agente NUNCA lo decide por su cuenta ni lo infiere solo
  del hecho de que haya certificados en el entorno), y (b) sea el ambiente de
  PRUEBA 00 o el que el usuario indique — jamás producción por iniciativa del
  agente. Fuera de esa excepción, el default es el modo solo-lógico descrito
  abajo. La petición explícita del usuario queda documentada en el registro del
  port (qué se probó en 00 y con qué resultado) para que no haya ambigüedad.

  Límites exactos del modo por defecto (solo-lógico), aplicables a TODAS las
  fases (traza de llamadas, segunda revisión, harnesses CLI, pruebas de
  navegador):

  **PROHIBIDO en el modo por defecto (es trabajar ya con Hacienda; la
  excepción de arriba lo habilita solo por pedido explícito del usuario):**
  - Enviar cualquier petición a los endpoints de MH (`apitest.dtes.mh.gob.sv`,
    `api.dtes.mh.gob.sv`, recepciondte, recepcionlote, contingencia, anulardte,
    consultadte, auth/token) — ni de prueba ni "solo para ver qué responde".
  - Usar, leer, copiar o cargar certificados/llaves privadas reales
    (`privKey`, `clv`, `pwd_privkey` de sucursal, archivos .key/.p12): la
    firma (`generateSignature`, `creaEventoERET` hasta su firma) es territorio
    de certificados. Un harness NO debe construir el JWT firmado.
  - Ejecutar los flujos completos de emisión/invalidación "de punta a punta"
    (creaJsonDTe → enviar → save_response_mh) ni simularlos en ambiente 00:
    esa pasada es SIEMPRE del usuario, con sus certificados y su ambiente.
    Si un harness llega por accidente a un paso con material de firma o red
    hacia MH, se detiene ahí y se re-scopea, no se "ya que estoy, termino".

  **PERMITIDO (lo lógico: verificar que todo lo que existe funcione en cadena):**
  - En archivos destino SIN contraparte en el origen (p. ej. un generador PDF propio del cliente) donde la normativa exige añadir un elemento visual nuevo (marca de agua, leyenda, sello): NO improvisar el diseño. Espejar LITERALMENTE el equivalente visual del origen (marcaAguaAnulado/marcaAguaRetorno: gris 220, fuente 90, Rotate 30, sello debajo) para que el documento se vea idéntico al de referencia, y jamás anotar en comentarios ni en el registro "equivalente del origen" si no se copió literal (falsedad documental: el siguiente port confiaría en esa nota).
  - Ejecutar por CLI/HTTP local los CALCULADORES puros: `getCuerpoCOF/CCF/NC`,
    `getResumen*`, `getIdentificacionDte`, `getReceptor*`, `getEmisor`,
    `getFacturaPagoTipo`, `getDocRelacionado` — y verificar los VALORES que
    producen (identidad MH por ítem, totales, prorrateos, documentos, códigos)
    contra datos reales del dump o sintéticos en una BD de copia.
  - Renderizar PDFs locales (`output=file` a `pdfdtes/`), JSON de respuesta,
    contratos de adjuntos — con la API key de correo neutralizada/ vacía para
    que ningún envío sea posible y con display_errors como en el servidor.
  - Verificar dependencias: imports, includes, autoloads, variables, rutas de
    archivos, tablas/columnas que tocan las queries, configuración del `.env`
    (existencia de valores, no su uso), y que cada página/case del dispatcher
    responde con lo esperado (JSON limpio, sin notice).
  - Simular la VALIDACIÓN del resultado (parsear el JSON armado y comprobar
    sus invariantes contra las reglas del manual MH), sin enviarlo.

  Motivación (port real 2026-11-05): en la segunda revisión un harness de
  `creaJsonDTE` construyó y FIRMÓ un JWT con la llave del dump — no hubo
  transmisión, pero cruzó el límite de certificados sin necesidad: los
  invariantes se podían (y se podían mejor) verificar con los métodos
  calculadores puros, sin la firma. La regla evita repetirlo y evita que un
  agente en otro proyecto dé por hecho que "probar el flujo" incluye tocar
  el endpoint de MH.
- Usar el binario `php` genérico. Si el proyecto destino corre una versión
  distinta de PHP, validar sintaxis con la versión que corresponda a SU runtime
  (averiguar por su documentación/composer), nunca asumir la del sistema.
- **Copias de seguridad de archivos: SOLO si el destino NO es un repositorio
  git.** Antes de sobrescribir/copiar/reemplazar un archivo del destino,
  verificar si el proyecto tiene versionado git (`git -C <destino> rev-parse
  --is-inside-work-tree`). Si es un repo git con los archivos commiteados,
  **omitir crear copias `.bak`/`*.pre-port.bak`**: git ya ofrece comparación
  (`git diff`) y reversión nativa (`git checkout -- <archivo>`, `git restore`),
  que son superiores a un archivo copiado, y los `.bak` ensucian el listado de
  archivos pendientes de commit (y arriesgan commitearse por accidente). En ese
  caso, para comparar contra el estado previo usar `git diff -- <archivo>` y
  para revertir `git restore <archivo>`. Crear copias manuales únicamente si el
  proyecto no tiene git, o si el archivo tiene cambios SIN commitear en el
  working tree (y aún así, preferir `git stash` antes que un `.bak`). Si se
  crearon `.bak` por hábito en un repo git, eliminarlos antes del cierre del
  port (lección real de un port anterior, 2026-10-17).

- **Excepción acotada a la regla anterior: copia pre-port de los generadores PDF
  de salidas en destinos SIN git.** Los generadores PDF (`dte_factura_pdf.php`,
  `dte_fse_pdf.php`, `pdf_compra.php`, `fpdf185/factForm.php`,
  `downloads/dteqr_pdf.php`, `_helper_ticket_dte.php` y los que el barrido de la
  FASE 3 detecte) son los archivos que los clientes personalizan con más
  frecuencia, y su estado previo al port necesita compararse AL FINAL aunque
  durante el port no se pregunte nada (la decisión de qué hacer con las
  personalizaciones se reporta al usuario al cierre, nunca se interrumpe el
  modo de trabajo elegido a mitad del port). En un destino con seguimiento git
  eso no cuesta nada (`git diff` contra HEAD); en un destino SIN git, crear
  ANTES de tocarlos por primera vez una copia SOLO de esos archivos (p. ej.
  `tmp/pre_port_pdf/<archivo>.orig`), fuera de las carpetas de salida
  (`pdfdtes/`, `jsondte/`) y con nombre que impida confundirla con el original.
  Esta copia NO viola la regla general de arriba: es específica de estos
  archivos, existe para la revisión de cierre de la FASE 3 y no sustituye el
  diff previo que ya exige el port de cada archivo. En un destino CON git NO
  crear copia: git la sustituye.
- No usar rutas ni alias propios de una máquina específica: todo se define con
  las rutas que el usuario dio en los parámetros.
- Los archivos `references/actualizacion_mh.sql` (estructura + catálogos) y
  `references/actualizacion_mh_solo_datos.sql` (solo datos, idempotente) son la
  alternativa autocontenida cuando no se usa sync_schema o el cliente está muy
  viejo. Están embebidos en esta skill para que no dependan del repo destino.
  **Su contenido está VALIDADO contra MH** (ambiente 00, barrido completo de
  los 262 tríos depto/municipio/distrito de `distritoMH` → todos con sello):
  son la fuente canónica del catálogo. Uso de cada uno:
  - `actualizacion_mh.sql` (estructura + datos): cuando el destino NO tiene
    las tablas de catálogo (`distritoMH`, `municipioMH`, `paisesMH`) — las crea
    ya con el contenido correcto.
  - `actualizacion_mh_solo_datos.sql` (solo datos, `ON DUPLICATE KEY UPDATE`
    keyed por `id`): cuando el destino YA las tiene — corrige/actualiza su
    contenido, incluidos los cruces del tipo Norte/Sur intercambiados. Aplicar
    DESPUÉS del SQL de estructura. Si además se quiere el SQL de salida del
    port autocontenido, concatenar este bloque al final de
    `migracion_sync_schema_desde_dte_testing.sql` en lugar de aplicarlo aparte.

