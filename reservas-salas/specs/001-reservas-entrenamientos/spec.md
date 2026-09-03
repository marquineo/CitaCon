# Feature Specification: Sistema de Reservas de Entrenamientos de Gimnasio

**Feature Branch**: `001-reservas-entrenamientos`

**Created**: 2026-09-02

**Status**: Draft

**Input**: User description: "Sistema de reservas de entrenamientos de gimnasio. Roles: Cliente (reserva sus propios entrenamientos) y Administrador/entrenador (gestiona usuarios, franjas y reservas sin restricciones). Franjas de 1h lunes-viernes 7:00-22:00, aforo máximo configurable por franja (por defecto 4), límite de horas semanales por cliente asignado por admin, validaciones, cancelaciones, bloqueo de franjas, casos límite de concurrencia y fuera de alcance."

## Clarifications

### Session 2026-09-02

- Q: ¿Cuál es el modelo de Franja Horaria y el alcance del bloqueo? → A: Plantilla semanal recurrente identificada por (día, hora), reutilizada cada semana. Las reservas se asocian a la franja + semana concreta (año-semana ISO). Bloquear una franja afecta a todas las semanas futuras, no solo a la semana actual. Bloquear/desbloquear una semana puntual sin afectar al resto queda fuera de alcance en esta iteración.
- Q: ¿Cómo expresar rechazos sin acoplar a códigos HTTP? → A: Eliminar códigos HTTP concretos (201, 403, 404, 409, 422) de la spec y sustituirlos por descripciones de comportamiento observable (rechazo/confirmación con mensaje indicando el motivo). El mapeo a códigos HTTP se definirá en plan.md.
- Q: ¿Se requiere registro de auditoría/historial de cancelaciones y bloqueos? → A: No en esta iteración. Registro de auditoría/historial de cancelaciones y bloqueos — pospuesto a segunda iteración. Esta iteración usa hard delete al cancelar, sin trazabilidad persistida más allá de los logs estándar de la aplicación.
- Q: ¿El administrador debe respetar aforo y cupo al crear reservas para clientes? → A: No. El administrador, al crear o gestionar una reserva en nombre de un cliente, MUST poder saltarse tanto la restricción de propiedad como las validaciones de aforo máximo de la franja y de cupo semanal del cliente — excepción deliberada del rol admin, no un bug. Solo clientes están sujetos a esas validaciones.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cliente reserva una franja disponible (Priority: P1)

Como cliente, quiero reservar una franja horaria concreta (día + hora) para entrenar, de modo que asegure mi plaza si hay aforo y me quedan horas semanales.

**Why this priority**: Es el flujo de valor central. Sin reserva con validación de aforo y cupo semanal no existe el sistema. Entrega MVP independiente.

**Independent Test**: Crear 2 clientes y 1 franja (Lunes 08:00, aforo 4). Asignar 3h semanales a Cliente A. Cliente A reserva la franja y ve confirmación; el contador de reservas de la franja sube a 1 y su cupo restante baja a 2h.

**Acceptance Scenarios**:

1. **Given** franja Lunes 08:00 con aforo 4 y 1 reserva existente, y cliente con 2h restantes, **When** cliente solicita reservar esa franja, **Then** el sistema crea la reserva, decrementa su cupo semanal en 1h y confirma con estado "confirmada".
2. **Given** franja Lunes 09:00 completa (4/4 reservas), **When** cliente con cupo disponible intenta reservar, **Then** el sistema rechaza la operación con mensaje "Franja completa: aforo máximo alcanzado" y no crea reserva ni consume cupo.
3. **Given** cliente con límite semanal 2h ya con 2 reservas esa semana, **When** intenta una tercera reserva, **Then** el sistema rechaza la operación con mensaje "Límite semanal alcanzado: tienes asignadas 2h y ya has reservado 2h esta semana" y no crea reserva.
4. **Given** cliente con 0h semanales asignadas, **When** intenta reservar cualquier franja con hueco, **Then** el sistema rechaza la operación con mensaje de límite (0h) y no crea reserva.
5. **Given** dos clientes con cupo disponible intentan reservar simultáneamente la última plaza (3/4 → 4/4), **When** ambas peticiones llegan casi a la vez, **Then** solo una tiene éxito y la otra es rechazada con mensaje indicando que la franja está completa, quedando la franja en 4/4.

---

### User Story 2 - Cliente cancela su propia reserva y recupera cupo (Priority: P1)

Como cliente, quiero cancelar una de mis reservas antes de que empiece la franja para liberar la plaza y recuperar mi hora semanal.

**Why this priority**: Complementa el flujo crítico de gestión de cupo. Permite corregir errores y reutilizar horas. Testeable sin admin.

**Independent Test**: Cliente con 2h semanales reserva Lunes 08:00 (queda 1h). Cancela esa reserva 1h antes de la franja: la reserva desaparece/cambia a cancelada, su cupo vuelve a 2h y la franja baja de 1/4 a 0/4 permitiendo que otro cliente reserve.

**Acceptance Scenarios**:

1. **Given** cliente tiene reserva para Martes 10:00 futura, **When** solicita cancelar su propia reserva, **Then** el sistema la cancela, libera 1h a su cupo semanal de esa semana y libera 1 plaza en la franja.
2. **Given** cliente intenta cancelar una reserva que no le pertenece, **When** envía la petición, **Then** el sistema rechaza la operación sin modificar nada y devuelve un mensaje indicando que no tiene permiso o que el recurso no existe/sin acceso (aislamiento, sin exponer datos ajenos).
3. **Given** cliente intenta cancelar una franja cuya hora ya pasó (franja en el pasado), **When** solicita cancelación, **Then** el sistema rechaza la operación con mensaje "No se puede cancelar una franja ya iniciada/pasada" — si la política es "cualquier momento antes de la hora", el límite es la hora de inicio.

---

### User Story 3 - Cliente consulta solo sus reservas y franjas disponibles (Priority: P2)

Como cliente, quiero ver qué franjas hay disponibles y cuáles son mis reservas, sin ver nunca datos de otros clientes.

**Why this priority**: Visibilidad y aislamiento. Necesario para que el cliente opere de forma autónoma pero sin filtrar datos ajenos. Valor independiente para consulta.

**Independent Test**: Crear Cliente A con 2 reservas y Cliente B con 1 reserva. Cliente A lista "mis reservas" y solo ve sus 2; lista "franjas disponibles" y ve ocupación (ej. 2/4) pero sin detalles de quién ocupa. Intento directo de acceso a una reserva de B es rechazado sin exponer datos.

**Acceptance Scenarios**:

1. **Given** cliente autenticado, **When** lista sus reservas, **Then** solo recibe reservas donde `user_id = su id`; nunca recibe reservas de otros.
2. **Given** cliente autenticado, **When** consulta detalle de una reserva ajena por ID, **Then** el sistema rechaza la operación sin exponer datos y devuelve un mensaje indicando que no tiene permiso o que el recurso no existe/sin acceso.
3. **Given** cliente consulta franjas de la semana, **When** ve cada franja, **Then** ve día/hora, estado (abierta/bloqueada), aforo y ocupación numérica (ej. 3/4), pero no lista de usuarios ajenos.
4. **Given** cliente sin reservas, **When** consulta sus reservas, **Then** recibe lista vacía y su cupo semanal (asignado vs. usado vs. restante).

---

### User Story 4 - Administrador gestiona franjas horarias y bloqueo con cancelación en cascada (Priority: P1)

Como administrador/entrenador, quiero crear, editar, eliminar, abrir y bloquear franjas horarias de 1h (L-V 7:00-22:00) para adaptar la oferta del gimnasio. Si bloqueo una franja con reservas, el sistema debe cancelarlas y devolver las horas a los clientes.

**Why this priority**: Control central del gimnasio. Sin gestión de franjas no hay oferta que reservar. El bloqueo en cascada es regla crítica de negocio.

**Independent Test**: Admin crea franja Miércoles 18:00 aforo 4. Dos clientes reservan (2/4). Admin bloquea la franja: ambas reservas pasan a canceladas, cada cliente recupera 1h de cupo, la franja queda bloqueada y no admite nuevas reservas hasta reabrir.

**Acceptance Scenarios**:

1. **Given** administrador autenticado, **When** crea franja Jueves 07:00 con aforo 4 (defecto) y estado abierta, **Then** la franja aparece disponible para clientes en el horario L-V 7:00-22:00.
2. **Given** administrador intenta crear franja fuera de horario permitido (ej. Sábado 10:00 o Lunes 06:00), **When** envía la petición, **Then** el sistema rechaza la operación con mensaje "Franja fuera del horario permitido (L-V 7:00-22:00, 1h)".
3. **Given** franja existente con 2 reservas, **When** admin la bloquea, **Then** el sistema cancela automáticamente las 2 reservas, devuelve 1h a cada cliente afectado y marca la franja como bloqueada (no reservable) para todas las semanas futuras.
4. **Given** franja bloqueada, **When** cliente intenta reservar, **Then** el sistema rechaza la operación con mensaje "Franja no disponible / bloqueada".
5. **Given** administrador edita una franja para cambiar su hora dentro de L-V 7:00-22:00, **When** guarda, **Then** el cambio se aplica si no genera conflicto de duplicado; si genera solapamiento con otra franja existente, el sistema rechaza la operación con mensaje explicativo.

---

### User Story 5 - Administrador gestiona aforo y cupos semanales con validaciones (Priority: P2)

Como administrador, quiero ajustar el aforo máximo por franja y asignar/modificar las horas semanales por cliente, con validaciones que impidan estados inconsistentes. También puedo gestionar cualquier reserva.

**Why this priority**: Configuración operativa esencial (aforo y cupos). Incluye las validaciones límite que diferencian casos correctos de inconsistencias (bajar aforo bajo reservas).

**Independent Test**: Franja con aforo 4 y 3 reservas. Admin intenta bajar aforo a 2 y el sistema rechaza la operación con mensaje "No se puede reducir aforo por debajo de reservas existentes (3)". Luego sube aforo a 6 y tiene éxito. Cliente con 1h asignada intenta 2ª reserva y es rechazado; admin sube su cupo a 3h y el cliente ya puede reservar.

**Acceptance Scenarios**:

1. **Given** franja con N reservas, **When** admin intenta establecer aforo < N, **Then** el sistema rechaza la operación con mensaje explicativo "Aforo (X) no puede ser inferior a reservas existentes (N). Elimine reservas primero." y no modifica la franja.
2. **Given** franja con N reservas, **When** admin aumenta aforo a valor >= N, **Then** el sistema acepta y nuevas reservas pueden ocupar el aforo ampliado.
3. **Given** cualquier cliente, **When** admin asigna/modifica sus horas semanales (ej. de 2h a 5h o a 0h), **Then** el nuevo límite aplica inmediatamente para validaciones futuras; el cómputo semanal se recalcula (usadas vs. restantes).
4. **Given** administrador, **When** lista reservas de cualquier cliente o crea/cancela una reserva en nombre de un cliente, **Then** la operación tiene éxito (sin restricción de propiedad y sin validar aforo máximo ni cupo semanal — excepción admin) y ajusta cupo del cliente afectado y ocupación de franja correspondientemente.
5. **Given** cliente intenta modificar horas semanales o aforo, **When** envía la petición, **Then** el sistema rechaza la operación indicando que no tiene permisos (solo administrador).

---

### Edge Cases

- **Condición de carrera última plaza**: Dos peticiones concurrentes a la última plaza de una franja. Solo una MUST tener éxito mediante transacción/bloqueo pesimista o constraint único; la otra es rechazada con mensaje de aforo completo. Testeable con tests de concurrencia.
- **Cliente 0h**: Cliente con 0h asignadas (valor por defecto si admin no ha asignado) intenta reservar → rechazo con mensaje de límite semanal, no por aforo.
- **Bajar aforo bajo reservas**: Admin intenta reducir aforo de 4 a 1 cuando hay 2 reservas → el sistema rechaza la operación con mensaje explicativo, sin cambios.
- **Franja bloqueada con reservas**: Bloquear franja con K reservas cancela K reservas en transacción atómica y devuelve K horas a los cupos semanales de la misma semana de la franja; si falla una devolución, todo se revierte. El bloqueo afecta a todas las semanas futuras.
- **Franja duplicada/solapada**: Intentar crear dos franjas idénticas (mismo día+hora) → el sistema rechaza la operación con mensaje de duplicado.
- **Semana y horas no acumulables**: Reserva del lunes cuenta para la semana lunes-domingo de esa franja; al pasar a la semana siguiente, el contador semanal se reinicia a 0 usadas, las horas no usadas se pierden.
- **Cancelación libera exactamente 1h**: Cada reserva equivale a 1h; cancelar devuelve 1h solo a la semana de la franja cancelada, no a la semana actual si son distintas.
- **Franja en el pasado**: No se puede reservar ni reabrir efectivamente una franja cuya hora ya pasó; el sistema rechaza la operación o la oculta según política.
- **Aislamiento estricto**: Cualquier intento de enumerar o adivinar IDs de reservas ajenas es rechazado sin exponer datos y devuelve un mensaje indicando que no tiene permiso o que el recurso no existe/sin acceso, indistinguible para no filtrar existencia.
- **Horario límite 22:00**: Última franja válida es 21:00-22:00; 22:00-23:00 es inválida (fuera de 7:00-22:00).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST ofrecer franjas horarias de 1 hora únicamente de lunes a viernes entre 7:00 y 22:00 (última franja 21:00–22:00). Cualquier creación/edición fuera de ese rango MUST ser rechazada con mensaje explicativo.
- **FR-002**: Cada franja MUST tener un aforo máximo configurable; si no se especifica, el defecto MUST ser 4.
- **FR-003**: El sistema MUST permitir a un cliente reservar una franja concreta solo si se cumplen simultáneamente: (a) aforo de la franja < aforo máximo y (b) reservas del cliente en esa semana < horas semanales asignadas por admin.
- **FR-004**: Si el cliente ha alcanzado su límite semanal, el sistema MUST rechazar la reserva con mensaje claro que indique el límite asignado y las horas ya usadas esa semana.
- **FR-005**: Si la franja ha alcanzado el aforo máximo, el sistema MUST rechazar la reserva con mensaje "franja completa / aforo máximo alcanzado" y no consumir cupo.
- **FR-006**: Las horas semanales no usadas MUST perderse al finalizar la semana; el sistema MUST NOT acumular remanente a la semana siguiente. El cómputo semanal MUST reiniciarse cada lunes 00:00.
- **FR-007**: Un cliente MUST poder cancelar su propia reserva en cualquier momento antes de la hora de inicio de la franja; al cancelar, el sistema MUST liberar 1 plaza en la franja y devolver 1h al cupo semanal disponible de la semana de la franja.
- **FR-008**: Un cliente MUST NOT poder ver, listar, cancelar ni modificar reservas de otros clientes. Cualquier intento MUST ser rechazado sin exponer datos ajenos, devolviendo un mensaje que indique que no tiene permiso o que el recurso no existe/sin acceso (aislamiento por usuario).
- **FR-009**: El administrador MUST poder crear, editar y eliminar franjas horarias, y abrir (disponible) o bloquear (no reservable) una franja en cualquier momento. Bloquear una franja afecta a todas las semanas futuras.
- **FR-010**: Si el administrador bloquea una franja que tiene reservas, el sistema MUST cancelar automáticamente todas esas reservas en transacción atómica y devolver 1h al cupo semanal de cada cliente afectado (correspondiente a la semana de la franja).
- **FR-011**: El sistema MUST impedir que el administrador reduzca el aforo de una franja por debajo del número de reservas existentes en esa franja; en ese caso MUST rechazar la operación con mensaje explicativo y no modificar la franja. El admin debe eliminar/cancelar reservas primero si desea bajar el aforo.
- **FR-012**: El administrador MUST poder asignar o modificar el número de horas semanales de cualquier cliente; el nuevo valor MUST aplicarse inmediatamente a validaciones futuras.
- **FR-013**: El administrador MUST poder ver, crear, modificar o eliminar la reserva de cualquier cliente (gestión sin restricciones), ajustando correctamente ocupación de franja y cupo del cliente afectado. Cuando la petición la realiza un administrador, el sistema MUST saltarse tanto la restricción de propiedad como las validaciones de aforo máximo de la franja (FR-005) y de cupo semanal del cliente (FR-004/FR-015) — excepción deliberada del rol admin; solo las reservas de clientes están sujetas a esas validaciones.
- **FR-014**: El sistema MUST garantizar que dos clientes intentando reservar la última plaza concurrentemente solo permitan un éxito; el otro MUST ser rechazado con mensaje de aforo completo mediante control de concurrencia transaccional.
- **FR-015**: Un cliente con 0h semanales asignadas MUST ser rechazado al intentar cualquier reserva, con el mismo mensaje de límite semanal.
- **FR-016**: El sistema MUST exponer para cada franja su día, hora de inicio (y fin implícito +1h), estado (abierta/bloqueada), aforo máximo y ocupación actual; y para cada cliente su cupo semanal (asignado, usado, restante) de la semana consultada.
- **FR-017**: Toda regla de negocio anterior MUST estar cubierta por al menos un test automatizado antes de considerarse completa (principio de constitución).

### Key Entities

- **Usuario**: Persona autenticada. Atributos: identificador, nombre/email, rol (cliente | administrador), horas semanales asignadas (entero >=0, por defecto 0). Relación: posee múltiples reservas propias; su cupo semanal se calcula por semana.
- **Franja Horaria (Slot)**: Plantilla semanal recurrente reutilizada cada semana, identificada de forma única por (día de semana, hora de inicio). Representa un intervalo reservable de 1h entre L-V 7:00-22:00 (hora inicio 7:00..21:00). Atributos: día (L-V), hora inicio, estado (abierta/bloqueada — el bloqueo afecta a todas las semanas futuras), aforo máximo (por defecto 4), ocupación actual por semana (reservas confirmadas en esa semana). Las reservas se asocian a la franja + semana concreta (año-semana ISO). No duplicable. Bloquear/desbloquear solo una semana puntual queda fuera de alcance en esta iteración.
- **Reserva**: Asociación entre un cliente y una franja en una semana concreta. Atributos: cliente, franja, semana (año-semana ISO), estado (confirmada/cancelada), timestamp de creación. Reglas: una reserva consume 1h del cupo semanal del cliente en esa semana y 1 plaza del aforo de la franja en esa semana; al cancelarse/bloquearse se libera ambos.
- **Cupo Semanal**: Concepto derivado (no entidad persistida independiente). Cálculo por cliente y semana: `restante = horas_asignadas - reservas_confirmadas_en_semana`. No acumulable entre semanas.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un cliente con cupo disponible puede completar una reserva válida en menos de 30 segundos desde que selecciona la franja (flujo de reserva exitoso medido en pruebas de usabilidad).
- **SC-002**: El 100% de los intentos de reservar superando el límite semanal son rechazados con mensaje que indica límite asignado y uso actual; 0 reservas fuera de cupo se crean en pruebas.
- **SC-003**: El 100% de los intentos de reservar en franja completa son rechazados con mensaje de aforo; la ocupación nunca supera el aforo máximo configurado en ninguna franja durante pruebas de carga y concurrencia.
- **SC-004**: En pruebas de condición de carrera con 20 intentos concurrentes a la última plaza, exactamente 1 tiene éxito y 19 son rechazados, con ocupación final = aforo máximo.
- **SC-005**: Cancelar una reserva propia devuelve la hora al cupo y libera la plaza en menos de 2 segundos y queda reflejado inmediatamente en consultas posteriores del mismo cliente.
- **SC-006**: Bloquear una franja con 4 reservas cancela las 4 en una sola operación y devuelve 1h a cada cliente afectado; 0 reservas huérfanas permanecen asociadas a franja bloqueada.
- **SC-007**: 0% de fuga de datos entre usuarios en pruebas de aislamiento: ningún cliente puede listar, ver o inferir reservas de otro (todas las sondas a IDs ajenos son rechazadas sin exponer datos, con mensaje de falta de permiso o inexistencia).
- **SC-008**: El 100% de los intentos de bajar aforo por debajo de reservas existentes son rechazados con mensaje explicativo y sin efectos colaterales.
- **SC-009**: Tras el cambio de semana (lunes 00:00), el 100% de los clientes tienen su contador semanal reiniciado a 0 usadas y las horas no usadas de la semana anterior no están disponibles.
- **SC-010**: El 95% de los administradores completan tareas de gestión (crear franja, asignar horas, gestionar reserva ajena) sin errores en el primer intento en pruebas guiadas.

## Assumptions

- **Definición de semana**: Semana de lunes 00:00 a domingo 23:59 (ISO 8601). El cupo semanal se calcula por año-semana ISO. Zona horaria del servidor/gimnasio (por defecto Europe/Madrid) se documentará en `plan.md`.
- **Granularidad de franja**: Todas las franjas son exactamente de 1 hora, alineadas a la hora en punto (07:00-08:00, ..., 21:00-22:00). No hay medias horas ni duraciones variables en esta iteración.
- **Franjas como plantilla semanal**: Las franjas se modelan como plantilla semanal recurrente identificada por (día, hora) reutilizada cada semana. Bloquear una franja afecta a todas las semanas futuras; bloquear/desbloquear una semana puntual sin afectar al resto está fuera de alcance y se tratará en `plan.md` solo como restricción, no como feature.
- **Horas semanales por defecto**: Un cliente nuevo tiene 0h asignadas hasta que el admin le asigne un valor (entero >=0). No hay valor global por defecto distinto de 0.
- **Equivalencia reserva-hora**: 1 reserva = 1 hora de cupo. No hay reservas de duración múltiple.
- **Autenticación y roles**: Existen solo dos roles: cliente y administrador (entrenador). La autenticación precede a cualquier operación de reserva/franja.
- **Cancelación sin penalización**: En esta iteración no hay ventana mínima de antelación para cancelar; se permite hasta la hora de inicio de la franja. Lo contrario está explícitamente fuera de alcance.
- **Sin acumulación ni notificaciones**: Horas no usadas no se acumulan; no se envían notificaciones/recordatorios (fuera de alcance declarado).
- **Concurrencia**: Se asume base de datos transaccional con capacidad de bloqueo pesimista/optimista o constraint para garantizar atomicidad de la última plaza.

## Out of Scope

- Restricción de antelación mínima para cancelar.
- Acumulación de horas no usadas entre semanas.
- Notificaciones o recordatorios (email/push) de reservas o cancelaciones.
- Pagos, tarifas o facturación por horas.
- Gestión de festivos o cierres excepcionales más allá de bloquear franjas manualmente.
- Soporte para franjas de fin de semana o fuera de 7:00-22:00.
- Bloquear o desbloquear una semana puntual de una franja sin afectar a todas las semanas futuras (el bloqueo es global).
- Registro de auditoría/historial de cancelaciones y bloqueos de reservas — pospuesto a una segunda iteración. Esta iteración usa hard delete al cancelar, sin trazabilidad persistida más allá de los logs estándar de la aplicación.

