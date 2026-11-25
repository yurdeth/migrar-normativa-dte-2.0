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

## Estructura de esta skill (cómo leerla)

Esta SKILL.md es el **índice operativo**: flujo de trabajo, reglas duras resumidas y
parámetros. El detalle completo vive en `references/` y SE LEE bajo demanda en este orden:

1. **`references/REGLAS_PORT.md`** — los principios que gobiernan todo, las reglas duras
   con sus lecciones completas de ports reales (adaptación en runtime, clasificación
   en uso/residuo, venta partida, equivalencias funcionales, verificación por
   comportamiento). **Leerlo completo ANTES de tocar código** y tenerlo abierto todo el proceso.
2. **`references/ENTORNO_Y_LIMITES.md`** — límites de las pruebas (solo-lógico y su
   excepción), catálogos MH embebidos y validados, reglas de backups y del entorno.
3. **`references/FASES_PORT.md`** — el detalle operativo de cada fase: FASE 1 (BD),
   FASE 2 (port por commit), FASE 2.5 (coherencia lógica), FASE 2.6 (contrato de correo),
   FASE 2.7 (colisiones de símbolos) y FASE 3 (cierre). Es el procedimiento paso a paso.
4. **`references/GUIA_PORT_NORMATIVA_2_0_CLIENTES.md`** — los commits normativos con su
   alcance exacto por archivo, los NO normativos, los errores del primer port y el
   checklist final. **La guía manda en el alcance**; léerse completa en la FASE 2.
5. **`references/ANALISIS_NORMATIVA_DTE_2.0.md`** — el análisis original de los 34
   commits; referencia de consulta, no manda en el alcance.

## El principio que gobierna todo (resumen — detalle y lecciones en references/REGLAS_PORT.md)

- **NO se busca simetría `origen <-> destino`.** Objetivo único: que el destino emita
  DTE bajo la Normativa 2.0 (JSON v2/v4/v3, distritos, ERET, consulta, catálogos MH).
- **Cada bloque se decide por su COMMIT de origen, no por su diferencia:** detectar con
  `diff` de estados, decidir con `git log -S` contra la lista de commits de la guía (§1).
- **Indaga que los datos son reales, no solo que el código esté:** contrastar contra los
  datos verdaderos del destino (dumps o BD en vivo si el usuario la ofrece).
- **Nunca copiar un archivo completo sin diff previo:** los compartidos traen código
  propio del cliente; parche por método/fragmento.
- **Verificar, no suponer.** Toda desviación respecto al commit de origen exige los tres
  elementos: necesidad comprobada con evidencia del destino real, registro documentado,
  reporte al usuario. Ninguno falta.
- **La línea se porta VERBATIM por defecto.** Las notas/advertencias de la skill son
  disparadores de VERIFICACIÓN, nunca de reescritura. "Más robusto" / "por si acaso" no
  son necesidad. Los mecanismos con efecto FUERA del código (cookies/sesión, redirects,
  rutas, `__DIR__`/`getcwd()`) exigen prueba de RUNTIME con el valor resuelto visible.
- **Clasificar un archivo como residuo exige evidencia exhaustiva** (menú contra el dump
  COMPLETO, referencias en código, default asimétrico: ante duda → en uso y se porta).
- **El origen manda el QUÉ; el destino manda el DÓNDE:** todo cambio portado se aplica a
  TODOS los archivos en uso que ejecutan ese flujo en el destino (venta/venta2/preventa...),
  sin unificar nunca la venta partida. Archivo del origen sin equivalente por nombre =
  disparador de investigación por ancla (equivalencias funcionales), jamás omisión silenciosa.
- **Un ítem está terminado por COMPORTAMIENTO, no por presencia:** el destino debe mostrar
  el comportamiento que el commit promete, materializado en archivo y línea señalables.
- **El API key de correo vive UN NIVEL ENCIMA del directorio del proyecto**
  (`__DIR__ . '/../obtener_api_key.php'` + guard `file_exists`): esa ruta viaja VERBATIM,
  nunca se "corrige" ni se elimina por parecer rota.
- **Redacción:** la única fuente de verdad es el repo remoto `dte_testing`; la skill y sus
  references NUNCA nombran proyectos cliente (las lecciones se relatan como "un port real")
  y son AUTOCONTENIDAS (sin depender de otras skills). Los nombres del cliente van solo en
  el registro local de cada port.

**El criterio de parada: flujo verificado, no checklist tachado.** El port termina cuando
cada funcionalidad portada tiene su flujo completo verificado en el destino (traza de
llamadas desde el evento de UI hasta la última dependencia, partiendo de CADA hunk del
diff), más las tres revisiones OBLIGATORIAS: FASE 2.5 (coherencia lógica estado×acción),
FASE 2.6 (contrato de correo fila por fila) y FASE 2.7 (colisiones de declaración de
símbolos). `php -l`/`node --check` son el piso, no el techo. Detalle completo en
`references/REGLAS_PORT.md` y `references/FASES_PORT.md`.

## Importante sobre el entorno (resumen — detalle completo en references/ENTORNO_Y_LIMITES.md)

- **Las pruebas por defecto son SOLO-LÓGICAS:** jamás transmitir ante Hacienda, jamás
  tocar endpoints de MH ni certificados/llaves (la firma es territorio de certificados),
  jamás ejecutar flujos de emisión punta a punta. **EXCEPCIÓN: solo por pedido explícito
  del usuario en la conversación** (el agente NUNCA lo decide por su cuenta), y siempre
  en ambiente de PRUEBA 00 o el que el usuario indique. Permitido: calculadores puros
  (`getCuerpo*`, `getResumen*`, `getIdentificacionDte`, `getReceptor*`...), PDFs locales
  con `output=file`, verificación de dependencias/includes/tablas/`.env`, simulación de
  validación sin envío.
- **Backups de archivos: SOLO si el destino NO es repo git** (con git: `git diff`/`git restore`).
  Excepción acotada: captura pre-port de los generadores PDF de salidas (ver detalle).
- Los SQL `references/actualizacion_mh.sql` y `actualizacion_mh_solo_datos.sql` están
  VALIDADOS contra MH (262 tríos con sello): son la fuente canónica del catálogo.
- Usar el binario `php` que corresponda al RUNTIME del proyecto destino, nunca asumir
  el del sistema.
- No usar rutas ni alias propios de una máquina específica: solo las rutas que el usuario dio.

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


## Modo de trabajo

Pregunta al usuario al inicio (o respeta lo que ya dijo): **"¿trabajamos paso a
paso (te muestro cada fase antes de ejecutarla) o continúo solo hasta el final?"**
En modo paso a paso, presenta el plan de cada fase y espera confirmación; en modo
continuo, ejecuta las fases en orden y reporta al final de cada una.


## Las fases del port (índice — procedimiento paso a paso en references/FASES_PORT.md)

Ejecutar en orden. Cada fase se ejecuta leyendo SU sección completa en
`references/FASES_PORT.md` — este índice solo orienta:

- **FASE 1 — Base de datos (siempre primero):** verificar el dump de origen, generar el
  SQL de estructura con `sync_schema.php` (la salida se entrega TAL CUAL, nunca se poda),
  verificar/corregir catálogos MH contra la fuente, y verificación OBLIGATORIA de
  duplicados del out.sql antes de cerrarla.
- **FASE 2 — Port de código (selectivo, por commit):** leer la guía completa
  (`references/GUIA_PORT_NORMATIVA_2_0_CLIENTES.md`) y seguirla como checklist. Copias
  completas con diff previo, merges selectivos, distritos con búsqueda en los 4 frentes,
  opcionales preguntados, commits post-rango, `php -l`/`node --check` y traza de llamadas
  por punto de entrada Y por hunk. **Antes de empezar, verificar contra el HEAD del origen
  que no haya commits nuevos fuera del rango documentado** (regla de "revisar del último
  commit portado hacia arriba" — los últimos incorporados están en la guía §1.18
  (lote 2026-11-16: `e82d5af0`, `84629642`, `4f0b74cd`) y §1.19 (lote 2026-11-20:
  `df6079d5`, `6593cd05`). **Cache-busting obligatorio**: todo JS de
  `js/funciones/` que el port cree o modifique se incluye con
  `include_js_once()` en TODAS las páginas del destino que lo importan, para
  que el cliente no tenga que borrar caché (guía §1.20, FASE 2 paso 5b).
- **FASE 2.5 — Revisión de coherencia lógica funcional (OBLIGATORIA):** matriz
  estado×acción, etiqueta↔efecto, contratos de parámetros; los defectos del origen se
  mitigan, no se copian.
- **FASE 2.6 — Auditoría del contrato de correo (OBLIGATORIA):** matriz del correo fila
  por fila (bloques hermanos, contrato de adjuntos, ruteo FSE, config del destino).
- **FASE 2.7 — Auditoría de declaraciones (OBLIGATORIA):** colisiones de símbolos,
  cierre transitivo por cadena de includes, harness CLI por página mixta.
- **FASE 3 — Cierre:** revisión post-port de generadores PDF (silenciosa), limpieza de
  `.bak`, registro del port, pruebas ambiente 00 (por el usuario salvo pedido explícito),
  oferta de segunda revisión lógica a detalle, limpieza final de temporales.

**Modo de trabajo paso a paso o continuo se define al inicio** (ver "Modo de trabajo" arriba);
en ambos modos las fases se ejecutan completas y en orden.
