# Reglas y lecciones del port (detalle completo)

> Este archivo contiene, verbatim, el detalle de los principios, reglas duras y
> lecciones de ports reales que resumen las primeras secciones de SKILL.md.
> Leerlo completo ANTES de tocar código. SKILL.md es el índice; esto manda en el detalle.

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


