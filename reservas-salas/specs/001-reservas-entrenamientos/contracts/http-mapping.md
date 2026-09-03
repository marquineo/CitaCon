# HTTP Mapping — Comportamiento agnóstico (spec) → Códigos HTTP (plan)

**Feature**: 001-reservas-entrenamientos | **Date**: 2026-09-02
> La spec describe rechazos de forma agnóstica ("el sistema rechaza la operación con mensaje indicando el motivo"). Este documento fija el mapeo concreto a HTTP para implementación y tests. Ver también `openapi.yaml`.

## Principios generales

- Toda respuesta es `application/json` con `{ message, errors?, data? }`.
- Autenticación vía Sanctum: sin cookie/CSRF → `401 Unauthorized`.
- Aislamiento indistinguible (FR-008): acceder a ID ajeno devuelve `404 Not Found` (no `403`) para no filtrar existencia. Acciones sobre recurso conocido como ajeno (ej. `POST /slots` como cliente) → `403 Forbidden`.
- Validaciones de negocio (aforo, cupo, horario, capacidad) → `409` si es conflicto de estado del recurso, `422` si es violación de regla de negocio/entidad.

## Mapeo por comportamiento (spec → plan)

| Comportamiento (spec) | Endpoint(s) | HTTP | Cuerpo |
|-----------------------|-------------|------|--------|
| **Reserva creada con éxito** (US1 esc.1) | `POST /api/reservations` | `201 Created` | `{ data: Reservation }` |
| **Franja completa — aforo máximo alcanzado** (FR-005, US1 esc.2, FR-014 carrera) | `POST /api/reservations` | `409 Conflict` | `{ message: "Franja completa: aforo máximo (4) alcanzado en Lunes 09:00 (semana 2026-09-07). Ocupación 4/4." }` |
| **Límite semanal alcanzado** (FR-004, US1 esc.3) | `POST /api/reservations` | `422 Unprocessable Entity` | `{ message: "Límite semanal alcanzado: tienes asignadas 2h y ya has reservado 2h esta semana (semana 2026-09-07).", errors: { weekly_hours: [...] } }` |
| **Cliente 0h** (FR-015, US1 esc.4) | `POST /api/reservations` | `422` | Mismo formato que límite semanal con `asignadas 0h` |
| **Franja bloqueada / no disponible** (US4 esc.4, FR-009) | `POST /api/reservations` | `409` | `{ message: "Franja no disponible / bloqueada." }` |
| **Franja fuera de horario L-V 7:00-22:00** (FR-001) | `POST /api/slots`, `PUT /api/slots/{id}` | `422` | `{ message: "Franja fuera del horario permitido (L-V 7:00-22:00, 1h).", errors: { day_of_week/start_time: [...] } }` |
| **Franja duplicada** (mismo día+hora) | `POST /api/slots` | `422` | `{ message: "Ya existe una franja para Lunes 08:00.", errors: { ... } }` |
| **Bajar aforo bajo reservas existentes** (FR-011, US5 esc.1) | `PUT /api/slots/{id}` | `422` | `{ message: "Aforo (2) no puede ser inferior a reservas existentes (3). Elimine reservas primero." }` |
| **Aumentar aforo** (US5 esc.2) | `PUT /api/slots/{id}` | `200 OK` | `{ data: Slot }` |
| **Cancelar reserva propia con éxito** (FR-007, US2 esc.1) | `DELETE /api/reservations/{id}` o `POST /api/reservations/{id}/cancel` | `200 OK` | `{ message: "Reserva cancelada. Se ha liberado 1 plaza y 1h de tu cupo semanal.", data: Reservation }` |
| **Cancelar reserva ajena / ver/listar ajenas** (FR-008, US2 esc.2, US3 esc.2) | `DELETE /api/reservations/{id}`, `GET /api/reservations/{id}`, `GET /api/reservations` (filtrado) | `404 Not Found` (consulta por ID ajeno, indistinguible) | `{ message: "No encontrado." }` — Para listado, simplemente no aparece. Si se detecta intento de enumerar, `404`. |
| **Acción de admin intentada por cliente** (US5 esc.5, `POST /api/slots`, `PUT /api/users/{id}/weekly-hours`) | cualquier endpoint admin | `403 Forbidden` | `{ message: "No tienes permisos para realizar esta acción." }` |
| **Franja en el pasado / cancelar pasada** (US2 esc.3) | `DELETE /api/reservations/{id}`, `POST /api/reservations` con semana pasada | `422` | `{ message: "No se puede cancelar/reservar una franja ya iniciada/pasada." }` |
| **No autenticado** | cualquier endpoint protegido | `401 Unauthorized` | `{ message: "No autenticado." }` |
| **Bloqueo de franja con cascada** (FR-010, US4 esc.3) | `PATCH /api/slots/{id}/block` | `200 OK` | `{ message: "Franja bloqueada. 2 reservas canceladas y horas devueltas.", data: Slot }` |
| **Crear/editar franja OK** (US4 esc.1, esc.5) | `POST /api/slots`, `PUT /api/slots/{id}` | `201` / `200` | `{ data: Slot }` |
| **Listar franjas / mis reservas / cupo** (US3) | `GET /api/slots?week_start=...`, `GET /api/reservations`, `GET /api/users/me/quota?week_start=...` | `200 OK` | `{ data: [...] }` |
| **Admin gestiona reserva ajena** (FR-013, US5 esc.4) | `POST /api/reservations` (con `user_id`), `DELETE /api/reservations/{id}` como admin | `201` / `200` | Mismo que cliente pero sin restricción de propiedad |

## Notas de implementación

- `GET /api/reservations` como cliente filtra automáticamente `WHERE user_id = auth.id`; como admin acepta `?user_id=` opcional.
- `GET /api/reservations/{id}` verifica `ReservationPolicy::view`: si `auth.role=cliente && reservation.user_id != auth.id` → `404` (no `403`) por aislamiento.
- `POST /api/reservations` recibe `{ slot_id, week_start }`; `week_start` es lunes ISO. El servicio calcula `used` y `count` dentro de `DB::transaction` + `lockForUpdate` sobre `slots`.
- `DELETE /api/reservations/{id}` es idempotente: si ya está `cancelada`, responde `200` con mensaje o `404` según política; para esta iteración se devuelve `200` con estado actual.
- Errores de validación Laravel (`422`) usan formato estándar `{ message, errors: { field: [msg] } }`.
