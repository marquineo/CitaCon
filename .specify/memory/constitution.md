<!-- Sync Impact Report
Version change: 2.0.0 → 2.0.1
Reason: PATCH — simplificación de secciones secundarias y gobernanza para proyecto individual de aprendizaje; principios I–V se mantienen sin cambios (solo se explicita TDD estricto en II y Sanctum/JWT en V ya presentes).
Modified principles: Sin cambios materiales
  - I. Aislamiento de Datos por Usuario (NON-NEGOTIABLE) — sin cambios
  - II. Cobertura de Reglas de Negocio por Tests Automatizados (NON-NEGOTIABLE) — se mantiene TDD estricto (Red-Green-Refactor) sin cambios
  - III. Modelo de Permisos por Rol — sin cambios
  - IV. Especificación como Fuente Única de Verdad — sin cambios
  - V. Restricción Técnica — Stack Laravel / Angular / MySQL — se explicita Sanctum/JWT en el propio principio (antes solo en Restricciones)
Added sections: Ninguna
Removed sections: Ninguna
Content updates:
  - Restricciones Técnicas y de Plataforma: simplificada — OpenAPI pasa de obligatorio a opcional/recomendado; se eliminan logs de auditoría estructurados obligatorios; se reduce exigencia de versionado con plan de migración a recomendación básica.
  - Flujo de Desarrollo y Puertas de Calidad: simplificado — se reemplaza revisión por pares obligatoria por auto-revisión del autor; se elimina bloqueo por falta de revisor externo.
  - Governance: simplificado para proyecto individual — enmiendas por edición directa sin aprobación de mantenedor; se eliminan auditorías trimestrales; basta con auto-verificación de cumplimiento.
Follow-up TODOs: Ninguno
-->

# Sistema de Reservas de Entrenamientos de Gimnasio Constitution

## Core Principles

### I. Aislamiento de Datos por Usuario (NON-NEGOTIABLE)

Ningún endpoint, vista o exportación MUST exponer datos pertenecientes a un
usuario a otro usuario. Cada petición autenticada MUST filtrar por
`user_id = authenticated_user.id` salvo que el rol sea administrador y la
operación esté explícitamente autorizada en la spec. Las queries, scopes de
Eloquent y serializadores MUST aplicar aislamiento a nivel de persistencia y
de API. Cualquier listado, detalle o búsqueda que devuelva reservas, perfil o
historial de otro usuario es un defecto crítico. La verificación es binaria y
testeable: un usuario A autenticado que solicita `/api/reservations` o
`/api/reservations/{id}` de un usuario B MUST recibir 403 o 404, nunca 200 con
datos ajenos.

### II. Cobertura de Reglas de Negocio por Tests Automatizados (NON-NEGOTIABLE)

Toda regla de negocio MUST estar cubierta por al menos un test automatizado
antes de considerarse completa. No existe regla "completa" sin test que la
demuestre. Se exige TDD estricto con ciclo Red-Green-Refactor: test escrito →
visto fallar → implementación mínima → refactor. Los tests MUST ejecutarse y
MUST fallar si la regla se incumple (aforo de franja, solapamiento de reservas,
ventanas de cancelación, límites por usuario, estados de reserva). Los PRs o
entregas MUST incluir evidencia de cobertura de la regla; si una regla no tiene
test asociado, el cambio MUST ser rechazado.

### III. Modelo de Permisos por Rol — Administrador vs. Usuario

El administrador es la única entidad con permisos de escritura sin restricciones
sobre usuarios, reservas y configuración de franjas. Los usuarios normales solo
pueden gestionar sus propias reservas y su propio perfil. Concretamente:

- Usuarios normales MUST poder crear, consultar y cancelar únicamente sus
  propias reservas, dentro de las franjas y aforos definidos, y MUST recibir
  403 en cualquier intento de escritura sobre recursos ajenos o sobre
  configuración (franjas, horarios, aforos, usuarios).
- El administrador MUST poder crear/editar/eliminar franjas, gestionar aforos y
  horarios, administrar usuarios y gestionar cualquier reserva.
- Toda decisión de autorización MUST estar centralizada en Policies/Gates de
  Laravel y verificada por middleware, nunca solo en el frontend Angular.
- Cualquier nuevo endpoint o acción MUST declarar su matriz de permisos en la
  spec y tener tests de autorización para cada rol (usuario, administrador,
  no autenticado).

### IV. Especificación como Fuente Única de Verdad para Reglas de Negocio

Toda regla de negocio MUST vivir en `spec.md` antes de implementarse. No se
MUST introducir ni improvisar lógica de negocio nueva directamente durante la
implementación. Si una regla no está en la spec, no existe y no se codifica;
si surge durante el desarrollo, MUST volver a la spec, documentarse con
criterios de aceptación y obtener aprobación antes de codificar. La spec es el
contrato que `plan.md`, `tasks.md` y el código MUST trazar. Los revisores
(o auto-revisión) MUST rechazar lógica de dominio no trazable a un apartado
de la spec.

### V. Restricción Técnica — Stack Laravel / Angular / MySQL

La implementación MUST respetar el stack prescrito sin excepciones:

- **Backend**: Laravel exponiendo una API REST. Toda lógica de dominio,
  validación, autorización y persistencia MUST residir en Laravel. Las
  respuestas MUST ser JSON con códigos HTTP semánticos. La autenticación MUST
  implementarse con Laravel Sanctum o JWT según lo definido en la spec; todas
  las rutas protegidas MUST requerir autenticación.
- **Frontend**: Angular consume exclusivamente esa API REST. Angular MUST NOT
  implementar reglas de negocio que no estén validadas en backend; su rol es
  presentación, validación UX y consumo de contratos.
- **Persistencia**: MySQL como única base de datos transaccional. Esquema,
  migraciones y constraints de integridad (FKs, índices únicos para evitar
  sobre-reserva) MUST gestionarse con migraciones versionadas de Laravel.
- Cualquier desviación del stack (otro framework, ORM o base de datos) REQUIERE
  una enmienda a esta constitución; de lo contrario MUST ser rechazada en
  revisión.

## Restricciones Técnicas y de Plataforma

Complementan al Principio V con un nivel básico, adecuado para proyecto
individual de aprendizaje:

- Contratos de API se definen en la spec (endpoints, payloads, códigos de
  estado). Generar o mantener un documento OpenAPI es opcional y recomendado,
  pero no bloqueante; basta con que código y spec estén sincronizados.
- Concurrencia y aforo: las operaciones de reserva SHOULD ser transaccionales y
  apoyarse en constraints de MySQL para prevenir sobre-reserva. No se exige
  infraestructura de observabilidad avanzada.
- Logs de auditoría estructurados son opcionales. Para aprendizaje basta con
  logs básicos de Laravel cuando ayuden a depurar reservas y franjas.
- Frontend Angular SHOULD consumir contratos tipados y manejar errores sin
  exponer detalles internos del backend; no se exige cobertura de contrato
  formal.

## Flujo de Desarrollo y Puertas de Calidad

Flujo básico para proyecto individual (sin equipo):

1. **Especificar** — Documentar/actualizar `spec.md` con reglas de negocio,
   matriz de permisos y criterios de aceptación. Sin spec no hay código.
2. **Planificar** — Registrar decisiones de arquitectura y alcance en `plan.md`
   de forma breve.
3. **Desglosar** — Crear `tasks.md` con tareas pequeñas trazables a la spec.
4. **Implementar** — Codificar en commits pequeños respetando los Principios
   I–V. Toda regla nueva detectada MUST volver al paso 1.
5. **Verificar** — Ejecutar tests automatizados (incluidos tests de aislamiento
   y autorización) y comprobar aforo/concurrencia manualmente si aplica. Los
   tests de reglas de negocio MUST pasar antes de dar la feature por completa.
6. **Revisar** — Auto-revisión del autor: verificar trazabilidad spec→test→
   código, aislamiento de datos, matriz de permisos y adherencia al stack. No
   se requiere revisor externo ni aprobación de mantenedor.

No se da por completa una feature con tests fallidos, sin spec vinculada o
violando aislamiento/permisos/stack.

## Governance

Esta constitución prevalece sobre cualquier otra práctica, plantilla o acuerdo
informal. En caso de conflicto, la constitución manda.

- **Procedimiento de Enmienda**: Para proyecto individual, la constitución
  puede enmendarse por edición directa de `.specify/memory/constitution.md`
  añadiendo un Sync Impact Report y justificación breve. No se requiere PR ni
  aprobación de mantenedor; basta con auto-revisión.
- **Política de Versionado**: Versionado semántico. MAJOR por eliminaciones o
  redefiniciones incompatibles de principios/gobernanza; MINOR por nuevos
  principios o ampliaciones materiales; PATCH por clarificaciones, simplificaciones
  o correcciones no semánticas. En cada cambio se MUST actualizar versión,
  fecha de ratificación y fecha de última enmienda.
- **Revisión de Cumplimiento**: El autor MUST auto-verificar el cumplimiento de
  los cinco principios antes de cada entrega. Código no conforme MUST NOT
  considerarse completo. No se exigen auditorías periódicas formales ni
  auditorías trimestrales; se recomienda una revisión rápida al cerrar cada
  feature.
- **Guía en Tiempo de Ejecución**: `.specify/memory/constitution.md` es la
  fuente de verdad para desarrollo humano y asistido por IA; plantillas y
  scripts la leen en tiempo de ejecución.

**Version**: 2.0.1 | **Ratified**: 2026-09-02 | **Last Amended**: 2026-09-02
