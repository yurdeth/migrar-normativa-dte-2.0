---
name: migrar-normativa-dte-2.0
description: >-
  Portar la Normativa de Facturación DTE 2.0 (Ministerio de Hacienda, El Salvador)
  desde el sistema base dte_testing hacia un sistema cliente de producción. Usar
  SIEMPRE que el usuario quiera "migrar", "actualizar", "portar" o "llevar" la
  normativa 2.0, los catálogos del MH, distritos, eventos de retorno (ERET) o los
  JSON v2/v4 a otro sistema del ecosistema, o al invocarse con /migrar-normativa-dte-2.0.
  Cubre desde la generación del SQL de estructura con sync_schema.php hasta el port
  selectivo de código por commit, incluyendo la búsqueda de distrito con
  autocompletado. NO sirve para desarrollar normativa nueva en el propio dte_testing.
---

# Migrar Normativa DTE 2.0 a un sistema cliente

## El principio que gobierna todo (leer primero)

**NO se busca simetría `origen <-> destino`.** Ambos sistemas parten de la misma
base pero se ramificaron: cada cliente tiene features propias y el origen
acumuló features de otros clientes que NO son normativa. El objetivo único es:

> Que el sistema destino emita DTE bajo la Normativa 2.0: JSON v2/v4/v3,
> distritos en dirección, eventos de retorno, consulta de DTE y catálogos MH vigentes.

Y una consecuencia que se aprendió probando en un sistema real: **indaga que los
datos son reales, no solo que el código esté.** Antes de dar un módulo por
funcionando, contrástalo contra los datos verdaderos del destino: ¿la tabla
`evento` existe y tiene filas con `selloRecibido`? ¿esa "factura anulada"
realmente tiene su ANU con sello de 40 caracteres? ¿el movimiento de la NC es
ENTRADA con `id_origen=0`? Los diffs dicen qué cambió; los datos dicen si el
cambio tiene efecto. Cuando haya BD accesible del destino (el usuario la ofrece),
verificar en vivo; si no, contra los dumps.

Consecuencia operativa: **cada bloque de código se decide por su commit de
origen, no por su diferencia.** Detectar diferencias con `diff` de estados,
pero decidir con `git log -S '<línea>'` en el repositorio origen: si el commit
que introdujo el bloque está en la lista de normativa (ver `references/GUIA_PORT_NORMATIVA_2_0_CLIENTES.md`,
sección 1), se porta; si no, se registra como decisión del cliente y no se porta
por defecto. La guía embebida en `references/` contiene: los commits normativos
con su alcance exacto por archivo, los commits NO normativos detectados (con su
commit id), los errores del primer port real y el checklist final. **Léela antes
de tocar código** y tenla abierta durante todo el proceso. Para el contexto de
fondo (dónde nació cada cambio, secciones del JSON por tipo de DTE, fases de
implementación, mapa completo de archivos), consultas
`references/ANALISIS_NORMATIVA_DTE_2.0.md` — es el análisis original de los 34
commits; úsalo como referencia de consulta, la guía es la que manda en el alcance.

Otra regla dura: **nunca copiar un archivo completo sin diff previo.** Los
archivos compartidos suelen traer código propio del cliente mezclado (ej.
`clases/Factura.php`). Copiar completo = borrarle funcionalidad al cliente.
Parche por método/fragmento.

Y la regla dura que ata todo lo anterior: **verificar, no suponer.** Antes de
tocar una línea del origen, entender su contexto: qué archivo la carga, qué
hay en el filesystem real, qué flujo la ejecuta. Si algo "no resuelve" o
"parece un bug", eso es el inicio de una investigación (layout de despliegue,
archivos hermanos fuera del repo, `.env` del destino), no una autorización
para modificarlo por iniciativa propia. Toda desviación respecto al commit de
origen debe ser (a) una necesidad comprobada con evidencia, (b) documentada en
el registro como decisión, y (c) reportada al usuario. Ninguna de las tres
puede faltar. Un port lleno de "correcciones" no pedidas es un port roto:
de nada sirve pasar código de un repo a otro si después no funciona.

**Regla específica: el archivo del API key de correo vive UN NIVEL ENCIMA del
directorio del proyecto.** `obtener_api_key.php` NO está dentro del repo
`dte_testing` ni del destino: es un archivo compartido en la carpeta padre que
agrupa los proyectos, y por eso el origen lo referencia con
`$archivo = __DIR__ . '/../obtener_api_key.php';` + guard `file_exists` (commit
`72579ed`). Esa ruta se porta VERBATIM: un nivel encima del directorio de
destino, igual que en el origen. NUNCA "corregirla" al primer nivel ni
eliminarla por parecer rota — un grep dentro del repo no la va a encontrar por
diseño. El fallback `defined('API_MAIL') ? API_MAIL : ($_ENV['API_MAIL'] ?? '')`
es la red de seguridad si el archivo hermano no existe en el despliegue. Verificación
obligatoria al cerrar el port: comprobar en vivo (o contra el layout real) que
`file_exists(__DIR__ . '/../obtener_api_key.php')` da true en el destino y que
`API_MAIL` queda definida — y si el destino no tiene el archivo hermano ni
`API_MAIL` en su `.env`, reportarlo al usuario como bloqueante del envío de
correo, no resolverlo alterando la ruta.

**Regla de redacción de esta skill: la única fuente de la verdad es el repo
remoto `dte_testing`.** Dentro de la skill y sus references NUNCA se mencionan
proyectos cliente concretos por nombre (ni en ejemplos ni en lecciones): las
lecciones se relatan como "un port real" sin identificar el sistema, porque los
destinos cambian y el origen normativo es uno solo. La misma independencia
aplica a otras skills: esta skill es AUTOCONTENIDA — cualquier método o
procedimiento que necesite (trazas de llamadas, mapeo de flujos de prueba,
verificación de datos) se describe completo dentro de ella, sin referenciar ni
depender de otras skills del equipo. Lo mismo aplica para el
registro que genera cada port: los nombres del cliente van en SU registro
local, no en esta skill.

## El criterio de parada: flujo verificado, no checklist tachado

Traer todo lo de los commits de normativa NO es el fin del port. El port termina
cuando **cada funcionalidad portada tiene su flujo completo verificado en el
destino**, y eso se comprueba SIN pruebas de navegador: haciendo ingeniería
inversa del grafo de llamadas, desde el evento de UI hasta la última dependencia.
Y desde un port real (2026-10-23): **flujo verificado también significa
LÓGICAMENTE COHERENTE** — que cada opción visible sea ejecutable con éxito en el
estado donde se muestra y que cada acción produzca lo que su etiqueta promete.
Esa verificación es una fase obligatoria propia (FASE 2.5): existir y compilar
no la sustituye. Y desde otro port real (2026-10-24): **el correo se audita como
contrato propio (FASE 2.6)** — es el componente que más falla entre ports y el
que falla más silenciosamente; "compila y el bloque existe" no significa que el
adjunto lleve contenido.

Lección real de un port ya ejecutado: se portó `clases/Dte.php` "completo"
y la primera generación de DTE reventó porque requiere `clases/Compra.php`, que
existía en el origen pero no en el destino. `Compra.php` no es normativa (viene
del refactor interno del origen), pero la funcionalidad normativa lo necesitaba.
"Que compile" (`php -l`) no valida nada de esto: `php -l` pasa igual con una
función indefinida dentro.

**Cómo se valida (estático, contra el código del destino):**

0. **Verificar el VALOR de los datos críticos a través de TODAS las rutas, no
   solo que el código exista.** La traza de llamadas (puntos 1-6) confirma que
   cada función/clase/require existe con firma compatible; NO confirma que los
   **valores** que viajan por esas llamadas sean correctos en cada ruta. Dos
   casos reales (port real 2026-10-14): (a) un archivo tenía
   la carga del API key por constante AL INICIO (parecía traer el parche
   `72579ed`) pero 300 líneas después el bloque de correo la pisaba con
   `$_ENV[...]` sin fallback → el correo moría solo por la ruta automática
   (el botón manual funcionaba: iban por generadores distintos con bloques de
   correo duplicados); (b) un bloque portado del origen asignaba a un campo
   del JSON una variable que en el ORIGEN era número por su contexto, pero en
   el DESTINO era string por el suyo (un `sprintf` de por medio) → MH lo
   rechazó. Reglas derivadas, generales para cualquier port:
   - **Presencia de un parche en un archivo ≠ parche efectivo.** Cuando un
     parche ya "parece estar" en el destino, seguir el valor de la variable
     desde su punto de carga hasta CADA punto de uso dentro del archivo
     completo (grep del identificador en todo el archivo, no solo el hunk del
     diff). Ej.: `grep -n '\$_ENV\[.X.\]'` de lo que el parche reemplaza,
     cruzado contra los sitios que ya tienen fallback.
   - **Varias rutas de UI hacia el mismo efecto (botón manual vs automático,
     venta vs venta2, dos generadores con bloques duplicados) se trazan
     TODAS**: suelen tener copias del mismo bloque en estados de parche
     distintos. "Funciona por una ruta" no valida el port.
   - **Al portar un bloque que asigna variables, verificar el tipo/valor que
     tiene cada variable EN EL DESTINO en ese punto**, no el que tenía en el
     origen: contextos de código distintos producen tipos distintos.
   - **Cierre del port** = esta traza sintáctica + mapear los flujos de
     prueba de cada funcionalidad portada (ruta de UI, pasos, resultado
     observable y verificación en datos; ver FASE 3 paso 4), ejecutándolos o
     entregando su plan al usuario para que él ejecute los flujos críticos. El
     plan de flujos es lo que habría destapado los dos casos (a)/(b) sin
     esperar a que el usuario tropiece con ellos en producción.

1. Para cada punto de entrada portado (página PHP, endpoint AJAX, handler JS de
   botón/evento): listar lo que llama directamente.
2. Para cada función llamada: verificar que **existe en el destino** y que la
   **firma es compatible** (mismo nº/orden de argumentos, o defaults). Si el
   destino ya tenía una función con el mismo nombre y firma distinta, decidir:
   adaptar las llamadas a la del destino, o renombrar — NUNCA dejar dos
   definiciones (fatal "Cannot redeclare") ni una llamada que no coincide.
3. Para cada clase instanciada (`new X`, `X::`): verificar que el archivo existe
   en el destino y que la clase tiene los métodos usados (`find`, `getFactura`...).
4. Para cada `require`/`include` del archivo portado: verificar que la ruta
   **resuelve en el layout del destino** (el origen usa `dte/scripts/`, algunos
   destinos todo en raíz) y que ese archivo a su vez existe.
5. Repetir recursivamente: si la función A llama a B, y B a C, y C a una clase D,
   verificar B, C y D con sus inputs. Subir (o bajar) por la cadena hasta llegar
   a algo que ya existía en el destino o hasta el botón/vista que dispara todo.
6. Herramientas: `grep -n 'function X('` en el destino, `grep -rn 'new X\b'`,
   seguir los `require`/`include`, y cuando la BD esté disponible, verificar que
   tablas/columnas que tocan las queries existan. Con PHP CLI se puede intentar
   cargar la clase (`php -r 'require "clases/X.php";'`) sabiendo que los guards
   de sesión cortan con "No direct script access allowed" — eso NO es fallo.

**Qué hacer cuando falta una dependencia NO normativa:**

- Se **adapta**, no se copia tal cual. Ejemplo real: el destino no tenía el
  framework `MovimientoProducto` del origen (con `setSalida/addMovimiento/save`),
  así que `Compra.php` se recreó como wrapper liviano del ORM propio del destino
  exponiendo solo lo que `Dte.php` usa (`find()`), y el `anular()` del origen se
  dejó fuera documentándolo.
- Si la adaptación implica reimplementar lógica de negocio grande (no solo un
  wrapper), es decisión del cliente: se documenta en el registro con las dos
  opciones (adaptar vs portar la base que falta) y se pregunta.
- En cualquier caso la dependencia adaptada queda anotada en el registro: qué
  versión del origen reemplaza, qué se recortó y por qué.

**Regla dura: clasificar un archivo como "residuo / no en uso" exige evidencia
EXHAUSTIVA, y el costo del error es asimétrico.** Aplica a CUALQUIER página,
endpoint o JS cuyo port se quiera omitir por considerarlo no usado (venta
partida, módulos viejos, páginas huérfanas). Portar a un archivo realmente
residual no cuesta nada; NO portar a uno que sí está en uso deja un módulo de
producción sin la normativa y revienta después. Por eso el default ante la
duda es "en uso" y se porta. Lección de un port real (2026-10-23): se
clasificó un `venta2.php` EN USO (registrado en el menú del destino,
`mostrarmenu=1`) como residuo y no se le portó nada — el grep de referencias
en código era correcto (nadie lo enlaza por código: su uso viene del menú en
BD), pero el chequeo de menú se hizo con un parser ad-hoc que solo leía el
PRIMER bloque `INSERT INTO \`modulo\`` del dump: mysqldump trocea una misma
tabla en varias sentencias INSERT (por `max_allowed_packet`), el parser vio
134 de 448 filas en silencio, sin error, y el dato parcial se combinó con el
grep para afirmar "residuo". Dos verificaciones parciales no prueban ausencia.

Método obligatorio para la clasificación (todas las verificaciones, no solo la
primera que dé resultado):

1. **Menú/permisos en BD contra el dump COMPLETO, nunca contra un extracto.**
   Para preguntas de PRESENCIA ("¿existe la fila?") no usar parsers
   estructurales anclados al primer bloque: usar grep crudo del patrón de
   tupla sobre el dump entero (`grep -oE "..." dump.sql`), o un parser que
   itere TODOS los bloques `INSERT` de la tabla. Y validar cobertura antes de
   concluir: las filas vistas deben cuadrar con un conteo independiente (ej.
   número de tuplas contadas por grep). Un subconteo silencioso convierte "no
   salió en mi extracto" en una afirmación falsa de "no está en el menú".
   Recordar que el filename puede venir con query string (`venta.php?preventa=1`)
   y que el nombre de menú puede no parecerse al archivo (`Venta DTE Directo`
   → `venta2.php`): buscar por filename exacto y por substring, y cruzar
   también la asignación a usuarios (tabla `usuario_modulo`) y el flag
   `mostrarmenu`.
2. **Toda señal cuenta y basta UNA positiva.** Verificar al menos: (a) tablas
   de menú/permiso del dump destino completo, (b) referencias en código
   (includes, href, load, location, handlers JS), (c) quién renderiza el menú
   real y con qué condiciones, (d) si el archivo evolucionó recientemente.
   Si cualquiera da positivo → en uso, se porta.
3. **Nunca construir una conclusión fuerte combinando verificaciones
   parciales.** "Mi grep no lo encontró" + "mi parser no lo vio" no es
   evidencia de ausencia si ninguna herramienta validó su cobertura. Una
   herramienta que no puede probar ausencia no aporta a la conclusión de
   ausencia.
4. **El registro documenta el método y los comandos exactos** con que se
   clasificó cada archivo en uso/residuo, para que la clasificación sea
   auditable (y replicable) por quien revise el port.

**Venta del origen unificada vs venta partida del destino (`venta.php`/`venta2.php`):****

En el origen todo vive en `venta.php` (el modal pregunta si se genera DTE). En
algunos destinos existen DOS archivos casi idénticos: `venta.php` (venta sin DTE
automático) y `venta2.php` (venta con DTE automático). Reglas:

- Detectar en cada destino si `venta2.php` está **en uso o es residuo** con el
  método obligatorio de la regla de clasificación de arriba: grep crudo sobre el
  dump COMPLETO de las tablas de menú/permisos (filas por filename y por
  substring, incluyendo asignaciones a usuarios y `mostrarmenu`), referencias
  en código (`add_routes.php`, enlaces, includes, `funciones_venta2*.js`), y
  default asimétrico: ante duda o evidencia parcial → en uso y se porta.
  Residuo solo con TODAS las señales negativas y cobertura validada.
- Si están en uso: aplicar CADA cambio del port (observaciones DTE, correo en
  modal de pago, distritos, periodo/plazo, `dte_auto.js`, ticketModal...) a
  **TODOS los archivos de la lista** (venta.php, venta2.php y sus JS respectivos).
- **NUNCA unificar la lógica** en un solo archivo para imitar al origen: el
  destino nació partido y así se queda. Solo portar los cambios a cada parte.

**Equivalencias funcionales: cuando el archivo del origen no existe en el destino
(regla general, aplica a CUALQUIER archivo, no solo a venta):**

La regla de venta/venta2 de arriba es un caso particular de un fenómeno general:
el mismo comportamiento vive en archivos con nombres distintos según el sistema
(un JS de formulario que en el origen se llama `X.js` y en el destino `funciones_X.js`;
una vista unificada allá y partida acá; un helper en raíz allá y en subcarpeta acá).
Cuando un cambio a portar vive en un archivo del origen que en el destino **no
existe con ese nombre**, eso NO significa "el cambio no aplica" ni es motivo para
saltarlo en silencio: es un **disparador de investigación**. Procedimiento:

1. **Buscar el equivalente funcional por ancla, no por nombre.** El cambio del
   origen siempre toca algo identificable: un handler de evento (`$(document).on('click', ...)`,
   `$("#X").change(...)`), un valor de `process:` que viaja al backend, un id de
   elemento (`#distrito`, `#modalPago`), una función o endpoint. Grep ESE ancla en
   el destino: quien lo contiene es el archivo equivalente, aunque se llame distinto.
2. **Aplicar el cambio ahí, adaptado al estilo del destino** (objeto manager vs
   estilo funcional, indentación, convención de handlers), no copiar el bloque
   verbatim si su entorno difiere.
3. **Registrar el mapeo origen→destino** en el registro del port (`archivo X del
   origen → archivo Y del destino, por ancla Z`). Si el mapeo no se encuentra,
   eso es un hallazgo a reportar al usuario, jamás una omisión silenciosa.
4. **Nunca cerrar un ítem del checklist por "el archivo no existe"**: o se halló
   el equivalente y se portó, o queda como pendiente visible con la evidencia de
   la búsqueda.

La trampa específica de esta regla es que el error es **silencioso y no revienta**:
la funcionalidad vieja del destino sigue funcionando (la cascada básica, el select
normal), así que "probar que no truena" no la detecta. La única defensa es que el
cierre del port verifique COMPORTAMIENTO (regla siguiente).

**Verificación por comportamiento, no por presencia (el "ya terminé" debe ser
funcional):**

Un ítem del port NO está terminado porque "el código está" ni porque la pantalla
no da errores: está terminado cuando el DESTINO muestra el comportamiento que el
commit de origen prometía. Para cada feature portada, redactar explícitamente en
el registro: (a) qué comportamiento promete el commit (p. ej. "escribir el nombre
de un distrito y que se rellenen automáticamente municipio y departamento", no
"que exista un select de distrito"), y (b) DÓNDE se materializa ese comportamiento
en el destino (qué handler, en qué archivo, con qué ancla) — siguiendo la cadena
completa UI→JS→backend. Si (b) no se puede señalar con archivo y línea, la feature
NO quedó portada aunque existan piezas suyas. Este pase es el que detecta los
puentes rotos por discrepancias de nombre: la pieza backend llegó, el JS que la
debía invocar nunca se tocó porque "ese archivo no existía", y el sistema queda en
un estado intermedio que ni falla ni cumple. Ejecutar este pase ANTES de declarar
terminada la FASE 2 y repetir su lista en el informe final al usuario.


## Parámetros necesarios

Al invocar la skill, reúne estos parámetros. Pide SOLO los que falten en el
mensaje del usuario, en una sola ronda de preguntas; si el usuario dijo
"continúa" o similar, infiere lo razonable y procede documentando supuestos:

1. **Ruta del repositorio ORIGEN** (dte_testing). Si la skill se abrió desde el
   propio repo origen, es el directorio actual — no preguntes. Si se abrió desde
   otro lado, pídela (o clona `dte_testing` desde su remoto si el usuario no la tiene).
2. **Ruta del repositorio DESTINO** (el sistema cliente a actualizar).
3. **Dump SQL de ORIGEN** (referencia, base actualizada a normativa). Sugerir
   dejarlo/copiarlo en `tmp/` del repo origen; suele llamarse como la BD (`tmp/oss_dte_testing.sql` o similar).
4. **Dump SQL de DESTINO** (el estado actual del cliente). Sugerir `tmp/` del repo
   destino, nombrado como su BD real.
5. (Opcional) Acceso a una BD del destino para verificaciones en vivo. Solo es
   útil si el usuario la ofrece; NO es requisito — la verificación canónica es
   contra los dumps.

Salida del SQL de estructura: `tmp/migracion_sync_schema_desde_dte_testing.sql`
en el `tmp/` del repo destino (o el nombre que el usuario prefiera).

**Regla de orden: si el usuario invoca la skill sin pasar los parámetros,
PREGUNTÁRSELOS SIEMPRE al inicio — no escanear carpetas para adivinarlos.** El
descubrimiento interno (buscar `dte_testing`, dumps, etc. en el filesystem) es
plan B, solo cuando el usuario responde que no sabe dónde está algo.

## Importante sobre el entorno

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
- No usar rutas ni alias propios de una máquina específica: todo se define con
  las rutas que el usuario dio en los parámetros.
- Los archivos `references/actualizacion_mh.sql` (estructura + catálogos) y
  `references/actualizacion_mh_solo_datos.sql` (solo datos, idempotente) son la
  alternativa autocontenida cuando no se usa sync_schema o el cliente está muy
  viejo. Están embebidos en esta skill para que no dependan del repo destino.

## Modo de trabajo

Pregunta al usuario al inicio (o respeta lo que ya dijo): **"¿trabajamos paso a
paso (te muestro cada fase antes de ejecutarla) o continúo solo hasta el final?"**
En modo paso a paso, presenta el plan de cada fase y espera confirmación; en modo
continuo, ejecuta las fases en orden y reporta al final de cada una.

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
3. **Revisar el SQL generado antes de darlo por bueno:** debe traer las tablas
   nuevas de la normativa (`evento`, `evento_detalle`, `paisesMH`, `compras_json`,
   `distritoMH`/`municipioMH` si el destino no las tiene) y las columnas faltantes.
   Si NO trae algo esperado, no es error del script: el destino ya lo tiene — verificarlo en el dump destino.
4. Estado de catálogos MH del destino: si sus tablas `municipioMH`/`distritoMH`
   ya existen, verificar que traigan el catálogo v1.1 (07/2026): `municipioMH`
   con (31, 10, 'Cabañas Este') y (32, 11, 'Cabañas Oeste'), y `distritoMH` con
   el este→10 / oeste→11. Si están viejos, aplicar
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

## FASE 2 — Port de código (selectivo, por commit)

Leer `references/GUIA_PORT_NORMATIVA_2_0_CLIENTES.md` completo y seguirla como
checklist. Resumen del orden que funcionó:

1. Copias completas (CON diff previo para confirmar que no hay código propio del
   destino dentro): `clases/Dte.php`, `clases/EventoDte.php`, `clases/FacturaPago.php`,
   `fpdf185/factForm.php`, `dte_fse_pdf.php`, `evento_retorno.php`,
   `proxyConsultaDTE.php`, `update_cor_dte.php`, `descarga_dte_json.php`,
   `js/funciones/evento_retorno.js` (fácil de olvidar), `pdf_compra.php`,
   `dte/scripts/_utiles.php` (merge si trae algo propio; su `factDetalle`
   reescrita NO es normativa), `admin_compras_fecha_dt.php` (si no hay código propio).
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
   "Equivalencias funcionales" de la sección "El criterio de parada": grep del
   ancla en TODO el destino, portar al archivo equivalente encontrado y anotar
   el mapeo. Hacer esto por CADA uno de los 4 frentes y sus vistas de
   alta/edición (agregar_*, editar_*): un frente solo cuenta como portado si la
   cascada inversa (buscar distrito → autocompletar departamento y municipio)
   quedó materializada en SU archivo real, no solo en el backend.
   Backend: `getDistritoAutocomplete()` + case en `_helpers.php`; guardado con
   `'id_distrito' => $distrito` en los ABM.
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
   de dejar reloguear). De la guía §1.9, revisar además el **aviso de
   suscripción pendiente** (`47942220`+`f67299bc`, OPCIONAL — preguntar al
   cliente; por defecto portarlo apagado: `'activo' => false`).
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
   `d0ad9de` — modal de reenvío de DTE (guía §1.12, HEAD del origen): endpoint
   `enviar_correo_dte.php` + ítem "Reenviar DTE" + `tipoDTE()` en `_helpers`
   (verificar colisión de nombre) + rework del bloque de correo de
   `dte_factura_pdf.php` (destinatario/CC del modal, validación, respuesta JSON
   en `output=file` con `ob_start`) + handlers `#btnSendMail`/`#btnSaveEmail`
   en `getAuth.js`. Sin el refactor cosmético de índices `dt` del admin; rama
   CRE muerta documentada (el archivo al que apunta no existe ni en el origen).
8. **venta2**: aplicar la regla de `venta.php`/`venta2.php` de la sección "El
   criterio de parada" — detectar si venta2 está en uso o es residuo y, si está
   en uso, repetir en él todos los cambios de venta (sin unificar).
9. Validación sintáctica de TODO lo tocado: `php -l` para cada PHP y
   `node --check` para cada JS. Es el piso, no el techo: no valida funciones ni
   clases indefinidas.
10. **Verificación de flujo por traza de llamadas (obligatoria antes de dar el
   port por terminado).** Para cada punto de entrada portado, ejecutar el
   procedimiento completo de la sección "El criterio de parada": subir/bajar el
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

## FASE 3 — Cierre

0. **Limpieza:** si el destino es un repo git y quedaron archivos de respaldo
   (`.bak`, `*.pre-port.bak`, etc.), eliminarlos antes del registro — la
   reversión/comparación se hace con git (`git diff`, `git restore`), ver
   regla en "Importante sobre el entorno". Verificar con
   `git -C <destino> status --short` que solo queden los cambios reales del port.
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
   se verificó la clasificación**, per la regla de clasificación de arriba),
   y las decisiones pendientes del cliente.
   Este registro es lo que permite auditar y replicar en el siguiente sistema.
2. Pruebas del usuario en ambiente 00 (`DTE_ENVIRONMENT=00`): COF mostrador, CCF,
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
   destino, no solo que esté. Al cerrar el port, preguntarle al
   usuario en estos términos:

   > "Verifiqué que todo lo portado existe, sus dependencias resuelven, la
   > sintaxis está en verde y la coherencia lógica de estados/controles quedó
   > revisada (FASE 2.5). Puedo hacer además una **segunda revisión lógica a
   > detalle de valores**: seguir los flujos de cada funcionalidad modificada de
   > punta a punta (botón → JS → endpoint → clase → BD → respuesta) para
   > confirmar los datos que viajan por cada ruta, no solo que el código esté.
   > ¿La hago?"

   Si el usuario acepta: ejecutar la revisión por flujo (generación DTE, respuesta
   MH, anulación, PDF/correo, ERET, admins, venta/venta2), reportar hallazgos y
   corregir lo que la revisión destape. Si declina: dejarlo anotado como
   pendiente en el registro. El port no se da por "funcional" hasta que esta
   revisión se haya hecho o el usuario la haya declinado explícitamente: un
   checklist tachado no equivale a código que funciona.
