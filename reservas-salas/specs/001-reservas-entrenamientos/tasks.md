# Tasks: Sistema de Reservas de Entrenamientos de Gimnasio

**Input**: Design documents from `/specs/001-reservas-entrenamientos/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ (openapi.yaml, http-mapping.md), quickstart.md
**Tests**: Incluídos — requeridos por Constitución Principio II (TDD estricto, 1 test por regla) y FR-017. Todos los tests se escriben PRIMERO y deben FALLAR antes de implementar.

**Organization**: Tareas agrupadas por historia de usuario para entrega incremental e independiente. Proyecto web `backend/` (Laravel) + `frontend/` (Angular) + MySQL.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable (archivos distintos, sin dependencias)
- **[Story]**: Historia asociada (US1-US5)
- Rutas exactas según `plan.md` §Project Structure

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Inicialización del proyecto según plan.md y research.md

- [ ] T001 Crear estructura base del proyecto según plan.md — directorios `backend/`, `frontend/`, `docker-compose.yml` (si aplica)
- [ ] T002 [P] Inicializar backend Laravel 11 en `backend/` con `composer create-project laravel/laravel` y configurar `.env` para MySQL 8.0
- [ ] T003 [P] Inicializar frontend Angular 17+ en `frontend/` con `ng new` y configurar proxy a `http://localhost:8000`
- [ ] T004 Configurar Laravel Sanctum SPA en `backend/config/sanctum.php` y `backend/config/cors.php` para estado stateful + CSRF (`research.md:1`)
- [ ] T005 [P] Configurar lint/format — `backend/pint.json` (Laravel Pint) y `frontend/.eslintrc.json` + Prettier
- [ ] T006 [P] Configurar testing — `backend/phpunit.xml` (Pest/PHPUnit, MySQL test DB, no SQLite) y `frontend/jest.config.js`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Infraestructura core que BLOQUEA todas las historias. Ninguna US puede comenzar hasta completar esta fase.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T007 Crear migraciones base en `backend/database/migrations/` — `users` (con `role` ENUM, `weekly_hours` TINYINT default 0), `slots` (UNIQUE day_of_week+start_time, capacity default 4, status ENUM), `reservations` (UNIQUE user_id+slot_id+week_start, FKs, INDEX slot_id+week_start, INDEX user_id+week_start) según `data-model.md`
- [ ] T008 Implementar modelos Eloquent en `backend/app/Models/` — `User.php` (HasApiTokens, casts role/weekly_hours), `Slot.php` (scopes `abierta`), `Reservation.php` (relaciones user/slot, único status='confirmada')
- [ ] T009 Implementar autenticación y autorización base en `backend/app/Http/Middleware/` y `backend/app/Policies/` — `ReservationPolicy.php` (view/cancel solo propietario o admin) y `SlotPolicy.php` (solo admin) + middleware `auth:sanctum` y `role` (Constitución III)
- [ ] T010 Configurar rutas API base en `backend/routes/api.php` — grupo `auth:sanctum`, prefijo `/api`, resource stubs para slots/reservations/users
- [ ] T011 Crear FormRequests base en `backend/app/Http/Requests/` — `SlotRequest.php` (validación day_of_week 1-5, start_time 07:00-21:00 en punto, capacity) y `ReservationRequest.php` (slot_id, week_start lunes ISO)
- [ ] T012 Crear seeder/demo en `backend/database/seeders/DemoSeeder.php` — admin, cliente-a (3h), cliente-b (1h), 3-5 slots plantilla + `DatabaseSeeder.php`
- [ ] T013 Crear servicios Angular base en `frontend/src/app/core/services/` — `auth.service.ts` (Sanctum CSRF + login), `slot.service.ts` y `reservation.service.ts` stubs tipados + `auth.guard.ts`

**Checkpoint**: Fundación lista — historias pueden comenzar en paralelo (respetando prioridades P1→P2). Esquema y auth verificados.

---

## Phase 3: User Story 1 — Cliente reserva una franja disponible (Priority: P1) ⭐ MVP

**Goal**: Cliente reserva franja (día+hora) validando aforo < capacity y cupo semanal < weekly_hours; rechazos con mensajes claros; concurrencia última plaza solo 1 éxito. Entrega valor central.

**Independent Test**: Crear 2 clientes y 1 franja Lunes 08:00 aforo 4; asignar 3h a Cliente A; Cliente A reserva → 201, ocupación 1/4, quota restante 2h; franja completa (4/4) → 409; límite semanal superado → 422; 0h → 422; 20 intentos concurrentes última plaza → 1x201 19x409.

### Tests for User Story 1 — ESCRIBIR PRIMERO, VER FALLAR

- [ ] T014 [P] [US1] Test de concurrencia última plaza en `backend/tests/Feature/ConcurrencyTest.php` — simula 20 POST concurrentes a última plaza, verifica 1 éxito y 19 rechazados (FR-014, SC-004, research bloqueo pesimista)
- [ ] T015 [P] [US1] Tests de reserva con validaciones en `backend/tests/Feature/ReservationQuotaCapacityTest.php` — éxito, franja completa 409, límite semanal 422, 0h 422, franja bloqueada 409 (FR-003/004/005/015, US1 esc.1-4)
- [ ] T016 [P] [US1] Contract test de POST /api/reservations en `backend/tests/Feature/ReservationContractTest.php` — verifica 201/409/422 según http-mapping.md

### Implementation for User Story 1

- [ ] T017 [US1] Implementar `ReservationService` con transacción y bloqueo pesimista en `backend/app/Services/ReservationService.php` — `DB::transaction` + `Slot::lockForUpdate()`, validar aforo (count slot+week) y cupo (count user+week) **solo si `auth.role != administrador`** (omitir ambas validaciones para admin, FR-013 excepción), validar status abierta, insertar reservation (research.md:2, data-model.md)
- [ ] T018 [US1] Implementar `ReservationController::store` en `backend/app/Http/Controllers/Api/ReservationController.php` — auth `auth:sanctum`, delega a `ReservationService`, mapea a HTTP 201/409/422 según `contracts/http-mapping.md`
- [ ] T019 [US1] Añadir validación de week_start lunes ISO y franja futura en `backend/app/Http/Requests/ReservationRequest.php` — rechaza semana no lunes o franja pasada con 422
- [ ] T020 [P] [US1] Crear componente de reserva en `frontend/src/app/features/reservations/reservation-form.component.ts` — selección de slot+week, muestra quota restante, maneja mensajes 409/422 sin exponer datos ajenos
- [ ] T021 [US1] Integrar listado de slots con ocupación por semana en `frontend/src/app/features/slots/slot-list.component.ts` — GET /api/slots?week_start, muestra 3/4 sin detalles de usuarios ajenos

**Checkpoint**: US1 completamente funcional y testeable independiente (incluye TDD y bloqueo pesimista). MVP listo para demo.

---

## Phase 4: User Story 2 — Cliente cancela su propia reserva y recupera cupo (Priority: P1)

**Goal**: Cliente cancela propia reserva antes de la hora de inicio; libera plaza y 1h de cupo; no puede cancelar ajenas ni franjas pasadas.

**Independent Test**: Cliente con 2h reserva Lunes 08:00 (queda 1h); cancela 1h antes → DELETE 200, quota vuelve a 2h, ocupación 0/4; intento cancelar ajena → 404; pasado → 422.

### Tests for User Story 2

- [ ] T022 [P] [US2] Tests de cancelación en `backend/tests/Feature/ReservationCancelTest.php` — cancel propia 200, ajena 404 (aislamiento), pasada 422, verifica hard DELETE libera UNIQUE y cupo (FR-007, US2 esc.1-3)
- [ ] T023 [P] [US2] Test de aislamiento de cancelación concurrente en `backend/tests/Feature/IsolationCancelTest.php` — cliente A no puede inferir existencia de reserva B

### Implementation for User Story 2

- [ ] T024 [US2] Implementar `ReservationController::destroy` en `backend/app/Http/Controllers/Api/ReservationController.php` — verifica `ReservationPolicy::delete`, valida `week_start+start_time > now()`, hard DELETE en transacción, libera UNIQUE (data-model.md)
- [ ] T025 [US2] Añadir endpoint `DELETE /api/reservations/{id}` con mapeo 200/404/422 en `backend/routes/api.php` y `contracts/http-mapping.md`
- [ ] T026 [P] [US2] Crear UI de cancelación en `frontend/src/app/features/reservations/my-reservations.component.ts` — botón cancelar solo para propias, muestra quota actualizado tras 200

**Checkpoint**: US1 y US2 funcionan independientes y combinadas (reserva + cancelación + quota).

---

## Phase 5: User Story 4 — Administrador gestiona franjas y bloqueo con cascada (Priority: P1)

**Goal**: Admin crea/edita/elimina/abre/bloquea franjas L-V 7:00-22:00; bloquear con reservas cancela en cascada (hard DELETE) y afecta todas las semanas futuras.

**Independent Test**: Admin crea Miércoles 18:00 aforo 4 → 201; 2 clientes reservan → 2/4; admin bloquea → 200, ambas eliminadas, quota +1 cada uno, franja bloqueada rechaza nuevas 409; crear fuera de horario sábado → 422; editar duplicado → 422.

### Tests for User Story 4

- [ ] T027 [P] [US4] Tests de gestión de franjas en `backend/tests/Feature/SlotManagementTest.php` — crear 201, fuera de horario 422, duplicado 422, editar, eliminar, bloquear/desbloquear (FR-001, FR-009, US4 esc.1-5)
- [ ] T028 [P] [US4] Tests de bloqueo en cascada en `backend/tests/Feature/SlotBlockingCascadeTest.php` — bloquear con K reservas elimina K filas para week_start>=current, verifica hard DELETE y que semanas pasadas no se tocan (FR-010, SC-006, data-model.md vigente)

### Implementation for User Story 4

- [ ] T029 [US4] Implementar `SlotController` completo en `backend/app/Http/Controllers/Api/SlotController.php` — CRUD con `SlotPolicy` (solo admin), validación `SlotRequest`, manejo UNIQUE duplicado 422
- [ ] T030 [US4] Implementar bloqueo en cascada en `backend/app/Services/SlotService.php` (o en SlotController) — `DB::transaction` + `UPDATE slots SET status='bloqueada'` + `DELETE FROM reservations WHERE slot_id=? AND week_start >= :currentWeekStart` (FR-010, data-model.md)
- [ ] T031 [US4] Añadir rutas admin de slots en `backend/routes/api.php` — `POST/PUT/DELETE /api/slots`, `PATCH /api/slots/{id}/block`, `PATCH /api/slots/{id}/unblock` con middleware role
- [ ] T032 [P] [US4] Crear UI admin de franjas en `frontend/src/app/features/slots/slot-admin.component.ts` — formulario crear/editar, botón bloquear con confirmación de cascada, muestra ocupación vigente

**Checkpoint**: US4 independiente; combinado con US1/US2 permite flujo completo de oferta y reserva.

---

## Phase 6: User Story 3 — Cliente consulta solo sus reservas y franjas disponibles (Priority: P2)

**Goal**: Cliente lista franjas con ocupación numérica 3/4 sin ver usuarios ajenos; lista solo sus reservas; aislamiento estricto (ajenas → 404).

**Independent Test**: Cliente A 2 reservas, B 1 reserva; A GET /api/reservations → 2 propias; GET /api/reservations/{idDeB} → 404; GET /api/slots?week_start → día/hora, abierta/bloqueada, 2/4 sin lista de usuarios.

### Tests for User Story 3

- [ ] T033 [P] [US3] Tests de aislamiento en `backend/tests/Feature/IsolationTest.php` — listado filtra por auth.id, detalle ajeno 404 indistinguible, slots no exponen lista de usuarios (FR-008, SC-007, Constitución I)
- [ ] T034 [P] [US3] Tests de quota y slots en `backend/tests/Feature/QuotaAndSlotListTest.php` — GET /api/users/me/quota y GET /api/slots?week_start devuelven assigned/used/remaining y occupation

### Implementation for User Story 3

- [ ] T035 [US3] Implementar `ReservationController::index` y `show` con aislamiento en `backend/app/Http/Controllers/Api/ReservationController.php` — index filtra `where user_id=auth.id` (cliente) o `?user_id` si admin; show verifica Policy → 404 si ajeno (http-mapping.md)
- [ ] T036 [US3] Implementar `SlotController::index` con ocupación por semana en `backend/app/Http/Controllers/Api/SlotController.php` — calcula `occupation = COUNT reservations WHERE slot_id=? AND week_start=?` (sin status), expone sin usuarios ajenos
- [ ] T037 [US3] Implementar endpoint `GET /api/users/me/quota` en `backend/app/Http/Controllers/Api/UserController.php` — calcula `assigned/used/remaining` por week_start (data-model.md Cupo Semanal)
- [ ] T038 [P] [US3] Crear UI de consulta en `frontend/src/app/features/reservations/my-reservations.component.ts` y `frontend/src/app/features/slots/slot-list.component.ts` — guarda `auth.guard.ts` verifica Sanctum, slots muestran 3/4

**Checkpoint**: Consulta y aislamiento verificados; US3 funciona independiente.

---

## Phase 7: User Story 5 — Administrador gestiona aforo y cupos semanales (Priority: P2)

**Goal**: Admin ajusta aforo por franja (no puede bajar bajo ocupación vigente) y asigna horas semanales por cliente; puede gestionar cualquier reserva; cliente no puede.

**Independent Test**: Franja aforo 4 con 3 reservas; bajar a 2 → 422 con mensaje, subir a 6 → 200; cliente 1h intenta 2ª reserva → 422, admin sube a 3h → reserva OK; cliente intenta PATCH weekly_hours → 403; admin GET/POST reserva ajena → 201/200.

### Tests for User Story 5

- [ ] T039 [P] [US5] Tests de aforo vigente en `backend/tests/Feature/SlotCapacityValidationTest.php` — bajar bajo max ocupación vigente (week_start>=now) → 422, histórico pasado ignorado, subir OK (FR-011, data-model.md)
- [ ] T040 [P] [US5] Tests de cupo y RBAC admin en `backend/tests/Feature/AdminQuotaRbacTest.php` — PATCH weekly_hours solo admin 200, cliente 403; admin puede crear/cancelar reserva ajena, cliente no (FR-012/013, US5 esc.3-5)

### Implementation for User Story 5

- [ ] T041 [US5] Implementar validación de aforo vigente en `backend/app/Services/SlotService.php` — `SELECT week_start, COUNT(*) ... WHERE week_start >= :currentWeekStart GROUP BY week_start` y rechaza si `newCapacity < maxCount` (data-model.md FR-011)
- [ ] T042 [US5] Implementar `UserController::updateWeeklyHours` en `backend/app/Http/Controllers/Api/UserController.php` — `PATCH /api/users/{id}/weekly-hours` con Policy admin, aplica inmediato
- [ ] T043 [US5] Extender `ReservationController` para gestión admin de reservas ajenas en `backend/app/Http/Controllers/Api/ReservationController.php` — si `auth.role=administrador` permite `user_id` en POST y DELETE de cualquier id y **omite validaciones de aforo máximo (FR-005) y cupo semanal (FR-004/015) en `ReservationService`** (excepción deliberada FR-013, no bug)
- [ ] T044 [P] [US5] Crear UI admin de aforo y cupos en `frontend/src/app/features/admin/admin-quota.component.ts` — input capacity con mensaje de error vigente, input weekly_hours por usuario

**Checkpoint**: US5 independiente; todas las US ahora funcionales.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Refinos que afectan múltiples historias y validación final según quickstart.md y constitución simplificada (auto-revisión).

- [ ] T045 [P] Actualizar `contracts/openapi.yaml` con ejemplos reales y validar sincronía con implementación (opcional, recomendado) en `specs/001-reservas-entrenamientos/contracts/openapi.yaml`
- [ ] T046 [P] Documentar y verificar `hard DELETE` vs auditoría pospuesta en `specs/001-reservas-entrenamientos/spec.md` Out of Scope y `data-model.md` (ya hecho, verificar consistencia)
- [ ] T047 Revisar isolation + RBAC global en `backend/app/Policies/` — auditoría manual de que ningún endpoint filtra sin `auth.id` o Policy (Constitución I & III)
- [ ] T048 Ejecutar validación completa con `quickstart.md` — 11 escenarios end-to-end con curl/Postman, verificar 201/409/422/403/404/401 según `contracts/http-mapping.md` en `specs/001-reservas-entrenamientos/quickstart.md`
- [ ] T049 [P] Añadir tests unit adicionales en `backend/tests/Unit/` para `Slot.php` y `Reservation.php` (validaciones de enum y UNIQUE)
- [ ] T050 Optimizar frontend tipado en `frontend/src/app/core/services/` — asegurar contratos tipados y manejo de errores sin exponer internals (Constitución restricción Angular)
- [ ] T051 Ejecutar `php artisan test` completo y `npm test` en `frontend/` — asegurar 100% de FRs con ≥1 test (Constitución II, FR-017)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Sin dependencias — inicio inmediato
- **Foundational (Phase 2)**: Depende de Setup — BLOQUEA todas las US
- **User Stories (Phase 3+)**: Dependen de Foundational; luego pueden ir en paralelo (si hay equipo) o secuencial P1→P2
  - Orden recomendado por prioridad: US1 (P1) → US2 (P1) → US4 (P1) → US3 (P2) → US5 (P2)
  - Alternativa por dependencia lógica: US4 puede ir antes de US1 si se prefiere crear franjas vía API en lugar de seed, pero US1 es independiente vía seed/Factory
- **Polish (Phase 8)**: Depende de todas las US deseadas completas

### User Story Dependencies

- **US1 (P1)**: Solo Foundational — sin dependencias de otras US (franja creada en test/seed)
- **US2 (P1)**: Depende de US1 (necesita reserva existente para cancelar) pero testeable con reserva creada en test
- **US4 (P1)**: Solo Foundational — sin dependencias de otras US (gestión de plantilla)
- **US3 (P2)**: Depende de US1/US4 para datos, pero testeable con seed
- **US5 (P2)**: Depende de US1/US4 para aforo/cupos, pero testeable independiente

### Within Each User Story

- Tests TDD primero → Fallan → Implementación → Refactor
- Modelos ya en Foundational, servicios antes de controladores, controladores antes de UI
- Validar checkpoint independiente antes de pasar a siguiente prioridad

### Parallel Opportunities

- T002 y T003, T005 y T006 en Setup pueden ir en paralelo
- T008, T009 (modelo+policy), T011 en Foundational son paralelizables (archivos distintos)
- Una vez Foundational completo, T014-T016 (tests US1) en paralelo, y US1, US4, US3 podrían trabajarse en paralelo por 3 devs
- Tests de cada US (T014, T015, T016) paralelizables entre sí

---

## Parallel Example: User Story 1

```bash
# Lanzar tests de US1 en paralelo (TDD, deben fallar primero):
Task T014: "Test de concurrencia en backend/tests/Feature/ConcurrencyTest.php"
Task T015: "Tests de quota/capacity en backend/tests/Feature/ReservationQuotaCapacityTest.php"
Task T016: "Contract test POST /api/reservations en backend/tests/Feature/ReservationContractTest.php"

# Implementación en paralelo tras tests:
Task T020: "Componente Angular reservation-form.component.ts"
# (requiere backend, pero UI puede mockear contratos)
```

---

## Implementation Strategy

### MVP First (Solo US1)

1. Completar Phase 1: Setup
2. Completar Phase 2: Foundational (CRÍTICO — bloquea todo)
3. Completar Phase 3: US1 (reserva con aforo/cupo + concurrencia)
4. **STOP y VALIDAR**: Ejecutar `ConcurrencyTest` y `ReservationQuotaCapacityTest` + `quickstart.md` escenarios 1-4; demo MVP

### Incremental Delivery

1. Setup + Foundational → base lista
2. US1 → MVP reserva (P1)
3. US2 → cancelación + quota (P1) → valor completo de cliente
4. US4 → gestión franjas + bloqueo cascada (P1) → control admin
5. US3 → consulta aislada (P2) → visibilidad segura
6. US5 → aforo vigente + cupos admin (P2) → configuración completa
7. Polish → validación quickstart 11 escenarios, auto-revisión constitución

### Parallel Team Strategy

Con 2-3 devs tras Foundational:
- Dev A: US1 + US2 (flujo cliente)
- Dev B: US4 + US5 (gestión admin)
- Dev C: US3 + Polish (consulta y aislamiento)

Cada US se integra sin romper anteriores (hard DELETE + UNIQUE + lockForUpdate garantizan consistencia).

---

## Notes

- [P] = archivos distintos, sin dependencias — paralelizable
- [Story] mapea a US de spec.md para trazabilidad spec→test→código (Constitución IV)
- Cada US es independientemente testeable y entregable
- Verificar que tests fallen antes de implementar (TDD)
- Commit tras cada tarea o grupo lógico
- Parar en cualquier checkpoint para validar historia independiente
- Evitar: tareas vagas, conflictos en mismo archivo, dependencias cruzadas que rompan independencia
- Total: 51 tareas (incluye tests TDD). MVP = T001-T021 (21 tareas).
