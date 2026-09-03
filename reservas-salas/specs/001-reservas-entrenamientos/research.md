# Research: Sistema de Reservas de Entrenamientos de Gimnasio

**Feature**: 001-reservas-entrenamientos | **Date**: 2026-09-02 | **Status**: Complete

> Todas las decisiones siguientes resuelven `NEEDS CLARIFICATION` del Technical Context y las ambigüedades de la spec (plantilla recurrente, HTTP agnóstico). No quedan `NEEDS CLARIFICATION` abiertos.

## 1. Autenticación — Laravel Sanctum (SPA)

- **Decision**: Usar **Laravel Sanctum** en modo SPA (cookie `XSRF-TOKEN` + CSRF, `auth:sanctum`) para backend y frontend en el mismo ecosistema. No usar JWT como mecanismo principal en esta iteración.
- **Rationale**: Sanctum es la solución oficial de Laravel para SPAs del mismo dominio/subdominio, con menor complejidad que JWT (sin refresh tokens manuales, sin almacenamiento inseguro en `localStorage`). Se alinea con la constitución v2.0.1 (Principio V: Sanctum o JWT; se elige Sanctum) y con la confirmación del usuario. Permite `Policies/Gates` y `auth:sanctum` sin paquete adicional. Adecuado para proyecto individual de aprendizaje.
- **Alternatives considered**:
  - *JWT (tymon/jwt-auth o laravel-jwt)*: válido para APIs stateless multi-cliente, pero añade gestión de refresh/blacklist y CSRF no necesario para SPA del mismo origen. Descartado por sobre-ingeniería.
  - *Passport (OAuth2)*: excesivo para 2 roles y sin terceros OAuth.
  - *Session sin Sanctum*: no expone API tokenizable y complica `EnsureFrontendRequestsAreStateful` para SPA.

## 2. Concurrencia — Bloqueo pesimista `SELECT ... FOR UPDATE` en transacción

- **Decision**: Prevenir sobre-reserva con **bloqueo pesimista** dentro de `DB::transaction`: `Slot::where(...)->lockForUpdate()->first()`, luego contar reservas confirmadas de la franja+semana, validar aforo y cupo, e insertar `Reservation`. No usar constraint único MySQL como mecanismo principal.
- **Rationale**: El requerimiento FR-014 y SC-004 exigen que 20 intentos concurrentes a la última plaza den exactamente 1 éxito. El bloqueo pesimista serializa la ventana crítica (lectura de ocupación → validación → inserción) sin depender de violación de constraint para flujo normal. Es explícito, testeable con tests de concurrencia y compatible con MySQL InnoDB `REPEATABLE READ`. La decisión fue confirmada por el usuario ("no usar constraint único como mecanismo principal").
- **Alternatives considered**:
  - *Constraint único `(slot_id, week_start, user_id)` o `(slot_id, week_start, capacity)`*: útil como defensa secundaria, pero no previene aforo >4 sin truco (no hay límite de filas por constraint). Requeriría tabla de plazas o lógica adicional. Descartado como primario por instrucción.
  - *Bloqueo optimista (`version`/`lock_version`)*: no aplica a inserción de reservas (no hay fila previa que versionar); solo útil para edición de aforo.
  - *Redis lock / semáforo distribuido*: innecesario para escala de 300 plazas/semana y añade infraestructura fuera de constitución.

## 3. Modelo de Franja Horaria — Plantilla semanal recurrente

- **Decision**: **Una única fila `slots` por (día, hora)** identificada por `(day_of_week, start_time)`, con `status` (`abierta/bloqueada`) y `capacity`. **No se generan filas por semana**. `reservations` referencia `slot_id` + `week_start` (lunes de la semana ISO, `DATE`) + `user_id`. Bloquear una franja (`status=bloqueada`) afecta a todas las semanas futuras; desbloquear revierte. Bloquear una semana puntual queda fuera de alcance.
- **Rationale**: Clarificación 2026-09-02 confirma plantilla reutilizada cada semana. Evita explosión de filas (75 slots vs 75×52/año) y simplifica gestión admin (crear/bloquear una vez afecta futuro). El cómputo de ocupación y cupo se hace por `week_start`. Se alinea con spec §Key Entities y §Out of Scope.
- **Alternatives considered**:
  - *Instancias semanales generadas por cron/seed*: genera 75 filas/semana, facilita bloqueo puntual pero multiplica datos y requiere job semanal. Descartado por simplicidad y por quedar fuera de alcance el bloqueo puntual.
  - *Franja con rango datetime único por ocurrencia*: pierde noción de plantilla y obliga a recrear franjas cada semana.
  - *Plantilla + instancias materializadas bajo demanda*: más complejo; diferido a iteración futura si se necesita bloqueo puntual.

## 4. Mapeo de comportamientos agnósticos → Códigos HTTP

- **Decision**: Definir el mapeo en `contracts/http-mapping.md` y `openapi.yaml` (este plan). No se deja a implementación ad-hoc. La spec permanece agnóstica; el plan fija los códigos.
- **Rationale**: La clarificación 2026-09-02 exige eliminar `403/404/201/409/422` de la spec y definirlos en el plan. Centralizar el mapeo garantiza trazabilidad `spec → plan → tests` y cumple Principio IV (spec como fuente única) sin filtrar detalle HTTP a la spec.
- **Alternatives considered**:
  - *Definir códigos directamente en spec*: acopla negocio a transporte, rechazado por clarificación.
  - *Dejar códigos implícitos en código*: genera inconsistencia entre endpoints; descartado.

**Mapeo confirmado** (detalle en `contracts/http-mapping.md`):
- Reserva creada con éxito → `201 Created`
- Franja completa / aforo máximo → `409 Conflict` (alternativa `422` documentada, se elige `409` por semántica de conflicto de estado)
- Límite semanal / 0h alcanzado → `422 Unprocessable Entity` (regla de negocio, no conflicto de recurso)
- Sin permiso (cliente sobre recurso ajeno o acción admin) → `403 Forbidden`
- Recurso no existe o no pertenece (aislamiento indistinguible) → `404 Not Found` (para no filtrar existencia, se usa `404` en lugar de `403` cuando sea consulta por ID ajeno; ver `http-mapping.md`)
- Franja fuera de horario / duplicado / bajar aforo bajo reservas → `422`
- Franja bloqueada → `409` o `422` según contexto (reserva en bloqueada = `409`)

## 5. Validación de entrada, autorización y semana ISO

- **Decision**: Validación en `FormRequest` (horario, aforo, `day_of_week` 1-5, `start_time` 07:00-21:00), autorización en `Policies` + middleware, y cálculo de semana como `week_start = lunes 00:00 Europe/Madrid` de la fecha de la franja (o de la semana consultada). Cupo = `weekly_hours - count(reservations confirmadas en week_start)`.
- **Rationale**: Cumple Principios I y III (aislamiento y RBAC centralizados en Laravel). La semana ISO es el estándar para gimnasio L-D. Zona horaria fija evita desalineación de cupo.
- **Alternatives considered**:
  - *Validar en frontend*: descartado por Principio V (frontend no autoriza).
  - *Semana domingo-sábado o rolling 7 días*: descartado por no coincidir con spec L-D.

## 6. Testing y TDD

- **Decision**: TDD estricto con `Pest` (o `PHPUnit`) + `RefreshDatabase`. Tests por FR: `ReservationTest`, `SlotTest`, `CapacityTest`, `WeeklyQuotaTest`, `IsolationTest`, `ConcurrencyTest` (20 hilos simulados con `DB::transaction` y `Http::concurrent` o `paratest`). Frontend con `Jest` para servicios/guards.
- **Rationale**: Constitución Principio II exige ≥1 test por regla y Red-Green-Refactor. `research.md` confirma que no hay `NEEDS CLARIFICATION` en testing; se usará MySQL de test (no SQLite) para `FOR UPDATE` real.
- **Alternatives considered**:
  - *SQLite en memoria para tests*: no soporta `FOR UPDATE` igual que MySQL; descartado para tests de concurrencia.
  - *Dusk/E2E en plan*: diferido a `quickstart.md` manual + tests feature como validación principal para proyecto individual.

---

**Conclusión**: Todas las incógnitas técnicas están resueltas. No quedan `NEEDS CLARIFICATION`. Artefactos de Phase 1 (`data-model.md`, `contracts/`, `quickstart.md`) pueden generarse sin bloqueos.
