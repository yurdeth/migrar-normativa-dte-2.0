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

**Regla dura — adaptación justificada y verificada en runtime (port real,
2026-11-09):** el origen manda QUÉ se porta (alcance y commit); la línea se
porta VERBATIM por defecto. Adaptar una línea del origen (sustituir una
función, cambiar `__DIR__` por otra cosa, "hacerla más robusta", generalizar
un caso) exige el MISMO estándar que cualquier decisión del port: necesidad
comprobada contra el código y el runtime REALES del destino. "Suena mejor",
"así cubro un caso futuro" o "así es más robusto" NO son necesidad: son la
señal de investigar, no de reescribir. Caso real: la nota del commit
`fa74ff5` (§1.9 de la guía) — "basename(__DIR__) daría la subcarpeta... usar
la ruta raíz" — se leyó como permiso para reescribir y se sustituyó
`__DIR__` por `getcwd()`, sin verificar que en web SAPI `getcwd()` es el
directorio del script EJECUTADO, no la raíz del proyecto → cada subdirectorio
(`modals/`, `procesos/`, ...) calculaba un nombre de cookie de sesión distinto
→ sesión siempre muerta en todos los XHR de subdirectorios, y el fallo ni
siquiera tenía síntoma propio: se enmascaraba como redirect de login cuyo
HTML (login → dashboard) quedaba pintado dentro de los modales. El origen
tenía la línea correcta y funcionando. Reglas derivadas:

1. **Las advertencias/notas de esta skill y de la guía son disparadores de
   VERIFICACIÓN, nunca de reescritura.** Cuando una nota dice "ojo con X
   caso", la acción obligatoria es comprobar si ese caso existe en el destino
   y cómo lo resuelve el código del origen; si el origen ya lo resuelve, la
   línea viaja tal cual.
2. **Mecanismos con efecto FUERA del código** (cookies/sesión, headers HTTP y
   redirects, rutas web relativas vs absolutas, resolución de `__DIR__`/
   `getcwd()`/`$_SERVER`, permisos de archivos, rewrites de Apache): la
   verificación estática (lint, trazas, pase de símbolos, matrices de
   estados) NO los cubre por construcción. Todo cambio que los toque exige
   una prueba de RUNTIME mínima ANTES del cierre del port, con el VALOR
   RESUELTO visible por caso: para sesión, una petición real (o simulada
   con `php -r`/curl local) desde CADA subdirectorio que ejecute el código,
   comprobando que la identidad resuelta (nombre de cookie, sesión) es la
   misma en todos; para un redirect, el destino final del header resuelto;
   para una ruta relativa, la URL final para un endpoint por cada nivel de
   profundidad. La tabla "caso → valor resuelto" va en el registro.
3. **Si el modo de falla de un cambio es silencioso o se enmascara como otro
   síntoma** (un 404, HTML inyectado donde se esperaba otro contenido,
   "funciona pero raro"), la verificación obligatoria de ese cambio sube de
   nivel: de estática a de comportamiento, aunque el resto del port sea
   solo-lógico. Esto no contradice la regla solo-lógica: son pruebas locales
   (curl/CLI contra el propio destino), sin MH ni certificados.
4. **La decisión se documenta en el registro como el origen lo declara:** qué
   línea del origen viaja verbatim, qué se adaptó, con qué evidencia del
   destino se justificó la adaptación y qué prueba de runtime la validó. Una
   adaptación sin los tres elementos es un defecto pendiente, no una
   decisión.

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

**Y el alcance funcional es igual para lo OBLIGATORIO que para lo opcional
(port real, 2026-11-09):** el origen solo tiene `venta.php`, así que TODO lo
que el port trae para el flujo de venta —normativo (observaciones DTE,
distritos en `agregar_cliente`, periodo/plazo, ticketModal) u opcional
aceptado (correo en modal de pago, `7c8d0e5`)— vive ahí. Pero eso dice DÓNDE
vive EN EL ORIGEN, no dónde debe vivir en el destino. La pregunta que define
el alcance de CUALQUIER cambio portado es funcional, no topográfica: **¿qué
archivos del destino ejecutan ESTE flujo?** Si el destino factura por
`venta.php` Y por `venta2.php` (Y por `preventa`, `venta3`, preventa
convertida a factura...), dejar el cambio en uno solo significa que el
operador que cobra por la otra ruta opera sin la normativa o sin la
funcionalidad: para lo obligatorio es un módulo de venta emitiendo DTE viejos;
para lo opcional, medio feature. Reglas derivadas:
1. **El origen manda el QUÉ (comportamiento y commit); el destino manda el
   DÓNDE** (los archivos en uso que realizan el flujo). Este principio es
   general — el caso venta/venta2 de esta guía es UNA instancia, no el límite.
   El mapa destino de cada feature de venta se determina con el mismo método
   de clasificación de "en uso / residuo" ya definido arriba, aplicado a
   TODOS los puntos de venta del destino (venta, venta2, preventa, venta3,
   venta por lotes, etc.), no solo a los que la guía menciona por nombre.
2. **La lista venta/venta2 de esta guía es el piso, no el techo.** Antes de
   dar por cerrado el port de un cambio de venta, listar los puntos de venta
   del destino (módulos del menú con filename tipo venta*/preventa*,
   handlers de cobro, JS con `process: 'insert'` hacia endpoints de venta) y
   clasificar cada uno. Cada archivo en uso recibe el cambio adaptado a su
   estructura; el que sea residuo, se documenta con las evidencias del
   método.
3. **Feature opcional aceptado = feature completo en todas las rutas.** No se
   pregunta "¿lo quieres también en venta2?" por separado: la aceptación del
   cliente cubre el flujo completo; si alguna ruta del destino no puede
   llevarlo (estructura muy distinta), eso se reporta como excepción con
   evidencia, nunca se asume por defecto.
4. La síntoma de este olvido es un gap de "lógica humana" (no de código): el
   sistema sigue funcionando y nada revienta — simplemente el operador del
   archivo B no tiene lo que el del archivo A sí (o factura sin la
   normativa). Al cerrar el port, para CADA cambio de venta responder:
   "¿esto está presente ESTÉ DONDE ESTÉ cobrando?"

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

**Cómo preguntar: mediante los diálogos interactivos nativos del entorno, no en
prosa.** Los CLIs de agentes traen un componente de preguntas al usuario que
renderiza un formulario en la terminal: campo de texto editable, opciones
navegables con flechas, y confirmación con Enter/submit (en ZCode es
`AskUserQuestion`; en Claude Code y otros, el equivalente de "pregunta
interactiva"). Al faltar parámetros, NO redactar una lista de preguntas en un
mensaje plano esperando que el usuario conteste en texto libre: emitir UNA
llamada a ese componente que agrupe todas las preguntas faltantes, con este
mapeo por tipo:

- **Rutas** (origen, destino, dumps): pregunta con entrada de TEXTO LIBRE — el
  usuario escribe o pega la ruta y confirma con Enter. La etiqueta debe decir
  qué es y un ejemplo de formato: "Ruta del repositorio ORIGEN (dte_testing),
  ej.: /home/usuario/proyectos/dte_testing".
- **Decisiones entre opciones conocidas** (¿BD en vivo sí/no?, ¿modo paso a
  paso o continuo?, ¿portar el correo en modal de pago?): pregunta de
  SELECCIÓN con las opciones listadas, para que el usuario navegue con flechas
  y elija con Enter. Marcar la opción recomendada primero.
- **Preguntas condicionales u "otra respuesta"**: todo componente de este tipo
  ofrece escape libre ("Other"/"Otro"); usarse antes que pedir texto en prosa.

La misma regla aplica a las demás preguntas que la skill manda hacer durante
el port (modo de trabajo, opcionales de la FASE 2, la segunda revisión lógica
de la FASE 3, decisiones condicionales que requieran al usuario): cada una se
emite por el componente interactivo, no como párrafo interrogativo. Si el
entorno donde corre la skill NO tiene ningún componente de preguntas
interactivas, caer en prosa estructurada numerada (una pregunta por número,
ordenada por parámetro) y esperar todas las respuestas antes de continuar.

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
   la excepción de copia de la sección "Importante sobre el entorno" (copia solo si el destino no tiene git; si
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
   "Equivalencias funcionales" de la sección "El criterio de parada": grep del
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
   suscripción pendiente** (`47942220`+`f67299bc`): SIEMPRE y sin preguntar,
   en su estado final DESACTIVADO (`'activo' => false`), incluyendo la carpeta
   `assets/img/` con sus imágenes y verificando con `ls` que llegaron al
   destino (detalle en la fila de la guía). Igualmente portar `4f5e25c6`
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
   `3aa8db1` — NC con items exentos (guía §1.13, HEAD del origen): portar SIEMPRE el commit completo (no dividir el precio del exento en `notacredito`, `getResumenNC` suma `totalExenta` al `montoTotalOperacion`, saldo de devoluciones excluye anuladas escribiendo `devoluciones.id_factura_dev`). Las devoluciones históricas NO se backfillean: quedan con `id_factura_dev=0` y conservan su conteo histórico (lo antiguo queda como está); el UPDATE es reparación MANUAL caso por caso, solo cuando el documento generador se conoce con certeza (p. ej. NCs de prueba del propio port). Prueba de cierre: NC mixta exenta/gravada con retención en ambiente 00 y segunda devolución tras anular la primera.
   `64a1dac` — identidad de cálculo del cuerpo CCF/NC + descuento prorrateado en NC (guía §1.14, HEAD del origen): portar SIEMPRE y los 3 archivos JUNTOS (`clases/Dte.php`, `notacredito.php`, `js/funciones/notacredito.js`). Cuerpo CCF/NC con la identidad MH (`ventaGravada = cantidad×precioUni − montoDescu` sobre los valores redondeados; exento sin dividir), `getResumenNC` sin dividir `totalDescu`, y el flujo NC prorrateando el descuento de los items base (`subtotalConDescuento()` a TOP-LEVEL en el JS; el builder de `#btnGuardar` con su propio factor IVA; `factura_detalle.descuento` en las 2 ramas del UNION de `initial()`; columna visible "Desc. a devolver" y fila "Tot. Descuento"). Sin este paquete, una NC sobre una venta con descuento por ítem supera el total del documento relacionado (MH: `MONTO ES MAYOR AL DOCUMENTO RELACIONADO`) y un exento con desc revienta `CALCULO INCORRECTO` con cualquier cantidad. Prueba de cierre en 00: CCF y NC total sobre venta con desc por ítem (exento con desc + gravado con desc y cantidad ≥ 100) — la NC debe caer exactamente al total de la base.
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
   regla en "Importante sobre el entorno". Verificar con
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
   dura "solo-lógico" de "Importante sobre el entorno"): los harnesses y
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
