# Tasks: Sistema de Reservas de Entrenamientos de Gimnasio

**Input**: Design documents from `/specs/001-reservas-entrenamientos/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ (openapi.yaml, http-mapping.md), quickstart.md
**Tests**: IncluÃ­dos â€” requeridos por ConstituciÃ³n Principio II (TDD estricto, 1 test por regla) y FR-017. Todos los tests se escriben PRIMERO y deben FALLAR antes de implementar.

**Organization**: Tareas agrupadas por historia de usuario para entrega incremental e independiente. Proyecto web `backend/` (Laravel) + `frontend/` (Angular) + MySQL.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable (archivos distintos, sin dependencias)
- **[Story]**: Historia asociada (US1-US5)
- Rutas exactas segÃºn `plan.md` Â§Project Structure

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: InicializaciÃ³n del proyecto segÃºn plan.md y research.md

- [X] T001 Crear estructura base del proyecto segÃºn plan.md â€” directorios `backend/`, `frontend/`, `docker-compose.yml` (si aplica)
- [X] T002 [P] Inicializar backend Laravel 11 en `backend/` con `composer create-project laravel/laravel` y configurar `.env` para MySQL 8.0
- [X] T003 [P] Inicializar frontend Angular 17+ en `frontend/` con `ng new` y configurar proxy a `http://localhost:8000`
- [X] T004 Configurar Laravel Sanctum SPA en `backend/config/sanctum.php` y `backend/config/cors.php` para estado stateful + CSRF (`research.md:1`)
- [X] T005 [P] Configurar lint/format â€” `backend/pint.json` (Laravel Pint) y `frontend/.eslintrc.json` + Prettier
- [X] T006 [P] Configurar testing â€” `backend/phpunit.xml` (Pest/PHPUnit, MySQL test DB, no SQLite) y `frontend/jest.config.js`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Infraestructura core que BLOQUEA todas las historias. Ninguna US puede comenzar hasta completar esta fase.

**âš ï¸ CRITICAL**: No user story work can begin until this phase is complete

- [X] T007 Crear migraciones base en `backend/database/migrations/` â€” `users` (con `role` ENUM, `weekly_hours` TINYINT default 0), `slots` (UNIQUE day_of_week+start_time, capacity default 4, status ENUM), `reservations` (UNIQUE user_id+slot_id+week_start, FKs, INDEX slot_id+week_start, INDEX user_id+week_start) segÃºn `data-model.md`
- [X] T008 Implementar modelos Eloquent en `backend/app/Models/` â€” `User.php` (HasApiTokens, casts role/weekly_hours), `Slot.php` (scopes `abierta`), `Reservation.php` (relaciones user/slot, Ãºnico status='confirmada')
- [X] T009 Implementar autenticaciÃ³n y autorizaciÃ³n base en `backend/app/Http/Middleware/` y `backend/app/Policies/` â€” `ReservationPolicy.php` (view/cancel solo propietario o admin) y `SlotPolicy.php` (solo admin) + middleware `auth:sanctum` y `role` (ConstituciÃ³n III)
- [X] T010 Configurar rutas API base en `backend/routes/api.php` â€” grupo `auth:sanctum`, prefijo `/api`, resource stubs para slots/reservations/users
- [X] T011 Crear FormRequests base en `backend/app/Http/Requests/` â€” `SlotRequest.php` (validaciÃ³n day_of_week 1-5, start_time 07:00-21:00 en punto, capacity) y `ReservationRequest.php` (slot_id, week_start lunes ISO)
- [X] T012 Crear seeder/demo en `backend/database/seeders/DemoSeeder.php` â€” admin, cliente-a (3h), cliente-b (1h), 3-5 slots plantilla + `DatabaseSeeder.php`
- [X] T013 Crear servicios Angular base en `frontend/src/app/core/services/` â€” `auth.service.ts` (Sanctum CSRF + login), `slot.service.ts` y `reservation.service.ts` stubs tipados + `auth.guard.ts`

**Checkpoint**: FundaciÃ³n lista â€” historias pueden comenzar en paralelo (respetando prioridades P1â†’P2). Esquema y auth verificados.

---

## Phase 3: User Story 1 â€” Cliente reserva una franja disponible (Priority: P1) â­ MVP

**Goal**: Cliente reserva franja (dÃ­a+hora) validando aforo < capacity y cupo semanal < weekly_hours; rechazos con mensajes claros; concurrencia Ãºltima plaza solo 1 Ã©xito. Entrega valor central.

**Independent Test**: Crear 2 clientes y 1 franja Lunes 08:00 aforo 4; asignar 3h a Cliente A; Cliente A reserva â†’ 201, ocupaciÃ³n 1/4, quota restante 2h; franja completa (4/4) â†’ 409; lÃ­mite semanal superado â†’ 422; 0h â†’ 422; 20 intentos concurrentes Ãºltima plaza â†’ 1x201 19x409.

### Tests for User Story 1 â€” ESCRIBIR PRIMERO, VER FALLAR

- [X] T014 [P] [US1] Test de concurrencia Ãºltima plaza en `backend/tests/Feature/ConcurrencyTest.php` â€” simula 20 POST concurrentes a Ãºltima plaza, verifica 1 Ã©xito y 19 rechazados (FR-014, SC-004, research bloqueo pesimista)
- [X] T015 [P] [US1] Tests de reserva con validaciones en `backend/tests/Feature/ReservationQuotaCapacityTest.php` â€” Ã©xito, franja completa 409, lÃ­mite semanal 422, 0h 422, franja bloqueada 409 (FR-003/004/005/015, US1 esc.1-4)
- [X] T016 [P] [US1] Contract test de POST /api/reservations en `backend/tests/Feature/ReservationContractTest.php` â€” verifica 201/409/422 segÃºn http-mapping.md

### Implementation for User Story 1

- [X] T017 [US1] Implementar `ReservationService` con transacciÃ³n y bloqueo pesimista en `backend/app/Services/ReservationService.php` â€” `DB::transaction` + `Slot::lockForUpdate()`, validar aforo (count slot+week) y cupo (count user+week) **solo si `auth.role != administrador`** (omitir ambas validaciones para admin, FR-013 excepciÃ³n), validar status abierta, insertar reservation (research.md:2, data-model.md)
- [X] T018 [US1] Implementar `ReservationController::store` en `backend/app/Http/Controllers/Api/ReservationController.php` — auth `auth:sanctum`, delega a `ReservationService`, mapea a HTTP 201/409/422 según `contracts/http-mapping.md`. El controlador debe ignorar cualquier `user_id` enviado en el payload salvo que el usuario autenticado sea administrador; si es cliente, el `user_id` efectivo siempre debe ser `auth()->id()`, independientemente de lo que venga en la petición. `ReservationRequest` valida que `user_id` exista, pero no restringe quién puede usarlo — esa restricción de autorización vive en el controlador.
- [X] T019 [US1] AÃ±adir validaciÃ³n de week_start lunes ISO y franja futura en `backend/app/Http/Requests/ReservationRequest.php` â€” rechaza semana no lunes o franja pasada con 422
- [X] T020 [P] [US1] Crear componente de reserva en `frontend/src/app/features/reservations/reservation-form.component.ts` â€” selecciÃ³n de slot+week, muestra quota restante, maneja mensajes 409/422 sin exponer datos ajenos
- [X] T021 [US1] Integrar listado de slots con ocupaciÃ³n por semana en `frontend/src/app/features/slots/slot-list.component.ts` â€” GET /api/slots?week_start, muestra 3/4 sin detalles de usuarios ajenos

**Checkpoint**: US1 completamente funcional y testeable independiente (incluye TDD y bloqueo pesimista). MVP listo para demo.

---

## Phase 4: User Story 2 â€” Cliente cancela su propia reserva y recupera cupo (Priority: P1)

**Goal**: Cliente cancela propia reserva antes de la hora de inicio; libera plaza y 1h de cupo; no puede cancelar ajenas ni franjas pasadas.

**Independent Test**: Cliente con 2h reserva Lunes 08:00 (queda 1h); cancela 1h antes â†’ DELETE 200, quota vuelve a 2h, ocupaciÃ³n 0/4; intento cancelar ajena â†’ 404; pasado â†’ 422.

### Tests for User Story 2

- [X] T022 [P] [US2] Tests de cancelaciÃ³n en `backend/tests/Feature/ReservationCancelTest.php` â€” cancel propia 200, ajena 404 (aislamiento), pasada 422, verifica hard DELETE libera UNIQUE y cupo (FR-007, US2 esc.1-3)
- [X] T023 [P] [US2] Test de aislamiento de cancelaciÃ³n concurrente en `backend/tests/Feature/IsolationCancelTest.php` â€” cliente A no puede inferir existencia de reserva B

### Implementation for User Story 2

- [X] T024 [US2] Implementar `ReservationController::destroy` en `backend/app/Http/Controllers/Api/ReservationController.php` â€” verifica `ReservationPolicy::delete`, valida `week_start+start_time > now()`, hard DELETE en transacciÃ³n, libera UNIQUE (data-model.md)
- [X] T025 [US2] AÃ±adir endpoint `DELETE /api/reservations/{id}` con mapeo 200/404/422 en `backend/routes/api.php` y `contracts/http-mapping.md`
- [X] T026 [P] [US2] Crear UI de cancelaciÃ³n en `frontend/src/app/features/reservations/my-reservations.component.ts` â€” botÃ³n cancelar solo para propias, muestra quota actualizado tras 200

**Checkpoint**: US1 y US2 funcionan independientes y combinadas (reserva + cancelaciÃ³n + quota).

---

## Phase 5: User Story 4 â€” Administrador gestiona franjas y bloqueo con cascada (Priority: P1)

**Goal**: Admin crea/edita/elimina/abre/bloquea franjas L-V 7:00-22:00; bloquear con reservas cancela en cascada (hard DELETE) y afecta todas las semanas futuras.

**Independent Test**: Admin crea MiÃ©rcoles 18:00 aforo 4 â†’ 201; 2 clientes reservan â†’ 2/4; admin bloquea â†’ 200, ambas eliminadas, quota +1 cada uno, franja bloqueada rechaza nuevas 409; crear fuera de horario sÃ¡bado â†’ 422; editar duplicado â†’ 422.

### Tests for User Story 4

- [X] T027 [P] [US4] Tests de gestiÃ³n de franjas en `backend/tests/Feature/SlotManagementTest.php` â€” crear 201, fuera de horario 422, duplicado 422, editar, eliminar, bloquear/desbloquear (FR-001, FR-009, US4 esc.1-5)
- [X] T028 [P] [US4] Tests de bloqueo en cascada en `backend/tests/Feature/SlotBlockingCascadeTest.php` â€” bloquear con K reservas elimina K filas para week_start>=current, verifica hard DELETE y que semanas pasadas no se tocan (FR-010, SC-006, data-model.md vigente)

### Implementation for User Story 4

- [X] T029 [US4] Implementar `SlotController` completo en `backend/app/Http/Controllers/Api/SlotController.php` â€” CRUD con `SlotPolicy` (solo admin), validaciÃ³n `SlotRequest`, manejo UNIQUE duplicado 422
- [X] T030 [US4] Implementar bloqueo en cascada en `backend/app/Services/SlotService.php` (o en SlotController) â€” `DB::transaction` + `UPDATE slots SET status='bloqueada'` + `DELETE FROM reservations WHERE slot_id=? AND week_start >= :currentWeekStart` (FR-010, data-model.md)
- [X] T031 [US4] AÃ±adir rutas admin de slots en `backend/routes/api.php` â€” `POST/PUT/DELETE /api/slots`, `PATCH /api/slots/{id}/block`, `PATCH /api/slots/{id}/unblock` con middleware role
- [X] T032 [P] [US4] Crear UI admin de franjas en `frontend/src/app/features/slots/slot-admin.component.ts` â€” formulario crear/editar, botÃ³n bloquear con confirmaciÃ³n de cascada, muestra ocupaciÃ³n vigente

**Checkpoint**: US4 independiente; combinado con US1/US2 permite flujo completo de oferta y reserva.

---

## Phase 6: User Story 3 â€” Cliente consulta solo sus reservas y franjas disponibles (Priority: P2)

**Goal**: Cliente lista franjas con ocupaciÃ³n numÃ©rica 3/4 sin ver usuarios ajenos; lista solo sus reservas; aislamiento estricto (ajenas â†’ 404).

**Independent Test**: Cliente A 2 reservas, B 1 reserva; A GET /api/reservations â†’ 2 propias; GET /api/reservations/{idDeB} â†’ 404; GET /api/slots?week_start â†’ dÃ­a/hora, abierta/bloqueada, 2/4 sin lista de usuarios.

### Tests for User Story 3

- [X] T033 [P] [US3] Tests de aislamiento en `backend/tests/Feature/IsolationTest.php` â€” listado filtra por auth.id, detalle ajeno 404 indistinguible, slots no exponen lista de usuarios (FR-008, SC-007, ConstituciÃ³n I)
- [X] T034 [P] [US3] Tests de quota y slots en `backend/tests/Feature/QuotaAndSlotListTest.php` â€” GET /api/users/me/quota y GET /api/slots?week_start devuelven assigned/used/remaining y occupation

### Implementation for User Story 3

- [X] T035 [US3] Implementar `ReservationController::index` y `show` con aislamiento en `backend/app/Http/Controllers/Api/ReservationController.php` — index filtra `where user_id=auth.id` (cliente) o `?user_id` si admin; show verifica Policy → 404 si ajeno (http-mapping.md) — NOTA: `index()` ya implementado y verificado en T024-T025 (US2) — `AdminReservationsIndexTest` PASS (admin sin filtro ve todas, cliente solo suyas). Al llegar a Fase 6 solo queda pendiente `show` (404), `GET /api/users/me/quota` y UI.
- [X] T036 [US3] Implementar `SlotController::index` con ocupaciÃ³n por semana en `backend/app/Http/Controllers/Api/SlotController.php` â€” calcula `occupation = COUNT reservations WHERE slot_id=? AND week_start=?` (sin status), expone sin usuarios ajenos
- [X] T037 [US3] Implementar endpoint `GET /api/users/me/quota` en `backend/app/Http/Controllers/Api/UserController.php` â€” calcula `assigned/used/remaining` por week_start (data-model.md Cupo Semanal)
- [X] T038 [P] [US3] Crear UI de consulta en `frontend/src/app/features/reservations/my-reservations.component.ts` y `frontend/src/app/features/slots/slot-list.component.ts` â€” guarda `auth.guard.ts` verifica Sanctum, slots muestran 3/4

**Checkpoint**: Consulta y aislamiento verificados; US3 funciona independiente.

---

## Phase 7: User Story 5 â€” Administrador gestiona aforo y cupos semanales (Priority: P2)

**Goal**: Admin ajusta aforo por franja (no puede bajar bajo ocupaciÃ³n vigente) y asigna horas semanales por cliente; puede gestionar cualquier reserva; cliente no puede.

**Independent Test**: Franja aforo 4 con 3 reservas; bajar a 2 â†’ 422 con mensaje, subir a 6 â†’ 200; cliente 1h intenta 2Âª reserva â†’ 422, admin sube a 3h â†’ reserva OK; cliente intenta PATCH weekly_hours â†’ 403; admin GET/POST reserva ajena â†’ 201/200.

### Tests for User Story 5

- [X] T039 [P] [US5] Tests de aforo vigente en `backend/tests/Feature/SlotCapacityValidationTest.php` â€” bajar bajo max ocupaciÃ³n vigente (week_start>=now) â†’ 422, histÃ³rico pasado ignorado, subir OK (FR-011, data-model.md)
- [ ] T040 [P] [US5] Tests de cupo y RBAC admin en `backend/tests/Feature/AdminQuotaRbacTest.php` â€” PATCH weekly_hours solo admin 200, cliente 403; admin puede crear/cancelar reserva ajena, cliente no (FR-012/013, US5 esc.3-5)

### Implementation for User Story 5

- [X] T041 [US5] Implementar validaciÃ³n de aforo vigente en `backend/app/Services/SlotService.php` â€” `SELECT week_start, COUNT(*) ... WHERE week_start >= :currentWeekStart GROUP BY week_start` y rechaza si `newCapacity < maxCount` (data-model.md FR-011)
- [X] T042 [US5] Implementar `UserController::updateWeeklyHours` en `backend/app/Http/Controllers/Api/UserController.php` — `PATCH /api/users/{id}/weekly-hours` con Policy admin, aplica inmediato — NOTA: controlador y UserPolicy ya estaban implementados antes de tiempo (Fase 6, T037), test `AdminUpdateWeeklyHoursTest` añadido y verificado en esta sesión (PASS 3/3, confirma auto-discovery de Policy en Laravel 11 sin AuthServiceProvider).
- [ ] T043 [US5] Extender `ReservationController` para gestiÃ³n admin de reservas ajenas en `backend/app/Http/Controllers/Api/ReservationController.php` â€” si `auth.role=administrador` permite `user_id` en POST y DELETE de cualquier id y **omite validaciones de aforo mÃ¡ximo (FR-005) y cupo semanal (FR-004/015) en `ReservationService`** (excepciÃ³n deliberada FR-013, no bug)
- [X] T044 [P] [US5] Crear UI admin de aforo y cupos en `frontend/src/app/features/admin/admin-quota.component.ts` — input capacity con mensaje de error vigente, input weekly_hours por usuario — NOTA: capacity ya existe en slot-admin.component.ts (T032), aquí solo se implementó weekly_hours con GET /api/users + PATCH /api/users/{id}/weekly-hours, mostrando error 422 tal cual API — NOTA2: GET /api/users fue endpoint no planificado originalmente, implementado sin pausa previa para aprobación (contra proceso esperado), pero revisado y aprobado por el usuario después del hecho, con test `AdminUsersListTest` añadido para cerrar cobertura.

**Checkpoint**: US5 independiente; todas las US ahora funcionales.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Refinos que afectan mÃºltiples historias y validaciÃ³n final segÃºn quickstart.md y constituciÃ³n simplificada (auto-revisiÃ³n).

- [ ] T045 [P] Actualizar `contracts/openapi.yaml` con ejemplos reales y validar sincronÃ­a con implementaciÃ³n (opcional, recomendado) en `specs/001-reservas-entrenamientos/contracts/openapi.yaml`
- [ ] T046 [P] Documentar y verificar `hard DELETE` vs auditorÃ­a pospuesta en `specs/001-reservas-entrenamientos/spec.md` Out of Scope y `data-model.md` (ya hecho, verificar consistencia)
- [ ] T047 Revisar isolation + RBAC global en `backend/app/Policies/` â€” auditorÃ­a manual de que ningÃºn endpoint filtra sin `auth.id` o Policy (ConstituciÃ³n I & III)
- [ ] T048 Ejecutar validaciÃ³n completa con `quickstart.md` â€” 11 escenarios end-to-end con curl/Postman, verificar 201/409/422/403/404/401 segÃºn `contracts/http-mapping.md` en `specs/001-reservas-entrenamientos/quickstart.md`
- [X] T049 [P] AÃ±adir tests unit adicionales en `backend/tests/Unit/` para `Slot.php` y `Reservation.php` (validaciones de enum y UNIQUE)
- [ ] T050 Optimizar frontend tipado en `frontend/src/app/core/services/` â€” asegurar contratos tipados y manejo de errores sin exponer internals (ConstituciÃ³n restricciÃ³n Angular)
- [ ] T051 Ejecutar `php artisan test` completo y `npm test` en `frontend/` â€” asegurar 100% de FRs con â‰¥1 test (ConstituciÃ³n II, FR-017)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Sin dependencias â€” inicio inmediato
- **Foundational (Phase 2)**: Depende de Setup â€” BLOQUEA todas las US
- **User Stories (Phase 3+)**: Dependen de Foundational; luego pueden ir en paralelo (si hay equipo) o secuencial P1â†’P2
  - Orden recomendado por prioridad: US1 (P1) â†’ US2 (P1) â†’ US4 (P1) â†’ US3 (P2) â†’ US5 (P2)
  - Alternativa por dependencia lÃ³gica: US4 puede ir antes de US1 si se prefiere crear franjas vÃ­a API en lugar de seed, pero US1 es independiente vÃ­a seed/Factory
- **Polish (Phase 8)**: Depende de todas las US deseadas completas

### User Story Dependencies

- **US1 (P1)**: Solo Foundational â€” sin dependencias de otras US (franja creada en test/seed)
- **US2 (P1)**: Depende de US1 (necesita reserva existente para cancelar) pero testeable con reserva creada en test
- **US4 (P1)**: Solo Foundational â€” sin dependencias de otras US (gestiÃ³n de plantilla)
- **US3 (P2)**: Depende de US1/US4 para datos, pero testeable con seed
- **US5 (P2)**: Depende de US1/US4 para aforo/cupos, pero testeable independiente

### Within Each User Story

- Tests TDD primero â†’ Fallan â†’ ImplementaciÃ³n â†’ Refactor
- Modelos ya en Foundational, servicios antes de controladores, controladores antes de UI
- Validar checkpoint independiente antes de pasar a siguiente prioridad

### Parallel Opportunities

- T002 y T003, T005 y T006 en Setup pueden ir en paralelo
- T008, T009 (modelo+policy), T011 en Foundational son paralelizables (archivos distintos)
- Una vez Foundational completo, T014-T016 (tests US1) en paralelo, y US1, US4, US3 podrÃ­an trabajarse en paralelo por 3 devs
- Tests de cada US (T014, T015, T016) paralelizables entre sÃ­

---

## Parallel Example: User Story 1

```bash
# Lanzar tests de US1 en paralelo (TDD, deben fallar primero):
Task T014: "Test de concurrencia en backend/tests/Feature/ConcurrencyTest.php"
Task T015: "Tests de quota/capacity en backend/tests/Feature/ReservationQuotaCapacityTest.php"
Task T016: "Contract test POST /api/reservations en backend/tests/Feature/ReservationContractTest.php"

# ImplementaciÃ³n en paralelo tras tests:
Task T020: "Componente Angular reservation-form.component.ts"
# (requiere backend, pero UI puede mockear contratos)
```

---

## Implementation Strategy

### MVP First (Solo US1)

1. Completar Phase 1: Setup
2. Completar Phase 2: Foundational (CRÃTICO â€” bloquea todo)
3. Completar Phase 3: US1 (reserva con aforo/cupo + concurrencia)
4. **STOP y VALIDAR**: Ejecutar `ConcurrencyTest` y `ReservationQuotaCapacityTest` + `quickstart.md` escenarios 1-4; demo MVP

### Incremental Delivery

1. Setup + Foundational â†’ base lista
2. US1 â†’ MVP reserva (P1)
3. US2 â†’ cancelaciÃ³n + quota (P1) â†’ valor completo de cliente
4. US4 â†’ gestiÃ³n franjas + bloqueo cascada (P1) â†’ control admin
5. US3 â†’ consulta aislada (P2) â†’ visibilidad segura
6. US5 â†’ aforo vigente + cupos admin (P2) â†’ configuraciÃ³n completa
7. Polish â†’ validaciÃ³n quickstart 11 escenarios, auto-revisiÃ³n constituciÃ³n

### Parallel Team Strategy

Con 2-3 devs tras Foundational:
- Dev A: US1 + US2 (flujo cliente)
- Dev B: US4 + US5 (gestiÃ³n admin)
- Dev C: US3 + Polish (consulta y aislamiento)

Cada US se integra sin romper anteriores (hard DELETE + UNIQUE + lockForUpdate garantizan consistencia).

---

## Notes

- [P] = archivos distintos, sin dependencias â€” paralelizable
- [Story] mapea a US de spec.md para trazabilidad specâ†’testâ†’cÃ³digo (ConstituciÃ³n IV)
- Cada US es independientemente testeable y entregable
- Verificar que tests fallen antes de implementar (TDD)
- Commit tras cada tarea o grupo lÃ³gico
- Parar en cualquier checkpoint para validar historia independiente
- Evitar: tareas vagas, conflictos en mismo archivo, dependencias cruzadas que rompan independencia
- Total: 51 tareas (incluye tests TDD). MVP = T001-T021 (21 tareas).












