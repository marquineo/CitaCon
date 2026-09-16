# Implementation Plan: Sistema de Reservas de Entrenamientos de Gimnasio

**Branch**: `001-reservas-entrenamientos` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-reservas-entrenamientos/spec.md`

## Summary

Sistema de reservas para gimnasio con franjas de 1h (L-V 7:00-22:00, plantilla semanal recurrente por día+hora), aforo configurable (def. 4), cupo semanal por cliente asignado por admin y validaciones de aforo/cupo. Roles: cliente (solo sus reservas) y administrador/entrenador (gestión sin restricciones, bloqueo con cascada). Enfoque técnico: **Laravel 11 API REST + Sanctum** (SPA), **Angular 17+** y **MySQL 8** con migraciones, **TDD estricto** y **bloqueo pesimista** (`SELECT ... FOR UPDATE` en transacción) como mecanismo principal anti-sobre-reserva. Contratos y modelo de datos se detallan en `contracts/` y `data-model.md`; correspondencia agnóstica→HTTP se define aquí.

## Technical Context

**Language/Version**: PHP 8.4 + Laravel 11 (compatible desde 8.2, entorno real del desarrollador en 8.4.25 vía Laravel Herd) (backend), TypeScript 5 + Angular 17+ (frontend), MySQL 8.0

**Primary Dependencies**: Laravel Sanctum (SPA cookie/CSRF), Eloquent ORM, Laravel Policies/Gates + middleware `auth:sanctum`, Angular `HttpClient` + `Router` + guards, `date-fns` o equivalente para semana ISO

**Storage**: MySQL 8.0 (única BD transaccional). Tablas: `users`, `slots` (plantilla), `reservations`. Migraciones versionadas Laravel, FKs e índices. Sin Redis/caché en esta iteración.

**Testing**: Backend: Pest / PHPUnit (unit, feature, concurrency), `RefreshDatabase`, tests de aislamiento y autorización por rol. Frontend: Jest/Karma + Testing Library (unit de servicios/guards). TDD estricto: test → visto fallar → implementación mínima → refactor. Cada FR de la spec tiene ≥1 test.

**Target Platform**: Web — backend Linux/servidor PHP-FPM + MySQL; frontend SPA en navegadores modernos (Chrome/Firefox/Safari evergreen). Sin app móvil en esta iteración.

**Project Type**: Web application (backend + frontend separados). `backend/` Laravel, `frontend/` Angular.

**Performance Goals**: Reserva/cancelación p95 < 500ms en red local; listado de franjas/semana < 300ms; soportar 20 peticiones concurrentes a última plaza con 1 solo éxito (SC-004). 75 franjas/semana (15h × 5 días) × aforo 4 = 300 plazas/semana; < 500 usuarios iniciales.

**Constraints**:
- Constitución v2.0.1: I aislamiento por usuario (filtro `user_id`), II TDD, III RBAC admin/cliente, IV spec como fuente única, V stack Laravel/Angular/MySQL + Sanctum/JWT (Sanctum elegido).
- Franja = plantilla semanal `(día, hora)`, no instancias por semana generadas por cron; bloqueo afecta a todas las semanas futuras (clarificación 2026-09-02).
- Concurrencia: bloqueo pesimista (`lockForUpdate()` dentro de `DB::transaction`) como mecanismo principal; constraint único no usado como defensa primaria (decisión confirmada).
- Horario L-V 7:00-22:00, última franja 21:00-22:00; 1 reserva = 1h; cupo semanal reinicia Lunes 00:00 Europe/Madrid, sin acumulación.
- Sin notificaciones, pagos, festivos ni semanas puntuales bloqueables.

**Scale/Scope**: 1 gimnasio, ~75 slots plantilla, ~300 reservas/semana máx., ~100-500 usuarios. 5 User Stories (P1/P2), 17 FRs, 10 SCs. Entrega en iteración única con tests de concurrencia.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **I. Aislamiento de Datos por Usuario (NON-NEGOTIABLE)**: Todas las queries/respuestas filtran por `authenticated_user.id`; Policies `ReservationPolicy` + scopes Eloquent; tests de aislamiento por rol. Aislamiento verificado en `data-model.md` y `contracts/`.
- [x] **II. Cobertura por Tests (TDD estricto)**: Cada FR (aforo, cupo, bloqueo, cascada, concurrencia) tiene test asociado antes de código; ciclo Red-Green-Refactor; `quickstart.md` lista escenarios de verificación. Gate bloquea merge sin test.
- [x] **III. RBAC Admin vs Cliente**: Middleware `auth:sanctum` + `role` + Policies/Gates centralizados en Laravel; matriz de permisos en spec §US1-5 y mapeada a HTTP en plan §Contracts. Frontend solo oculta UI, nunca autoriza.
- [x] **IV. Spec como Fuente Única**: Plan deriva 1:1 de `spec.md` (plantilla recurrente, aforo 4, cupo, bloqueo global, fuera de alcance). No se introduce lógica nueva; clarificaciones 2026-09-02 integradas.
- [x] **V. Stack Laravel/Angular/MySQL + Sanctum**: Backend Laravel REST JSON, frontend Angular exclusivo, MySQL con migraciones. Sanctum SPA confirmado (ver `research.md`); JWT descartado para esta iteración. Desviación requeriría enmienda.
- [x] **Restricciones y Flujo simplificados (v2.0.1)**: OpenAPI opcional, logs básicos, auto-revisión sin auditorías trimestrales — plan respeta nivel básico para proyecto individual.

**Resultado**: PASS — sin violaciones. Complejidad adicional no requerida.

## Project Structure

### Documentation (this feature)

```text
specs/001-reservas-entrenamientos/
├── plan.md              # Este archivo
├── research.md          # Phase 0 — decisiones Sanctum, bloqueo pesimista, plantilla, HTTP mapping
├── data-model.md        # Phase 1 — entidades, esquema, validaciones, transiciones
├── quickstart.md        # Phase 1 — guía de verificación end-to-end
├── contracts/
│   ├── openapi.yaml     # Contrato REST (esqueleto, opcional pero incluido)
│   └── http-mapping.md  # Mapeo agnóstico → HTTP (403/404/201/409/422)
└── tasks.md             # Phase 2 (/speckit.tasks — no creado aquí)
```

### Source Code (repository root)

```text
backend/                          # Laravel 11
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php
│   │   │   ├── SlotController.php
│   │   │   └── ReservationController.php
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   │   ├── User.php              # role, weekly_hours
│   │   ├── Slot.php              # day_of_week, start_time, capacity, status
│   │   └── Reservation.php       # user_id, slot_id, week_start, status
│   ├── Policies/
│   │   ├── SlotPolicy.php
│   │   └── ReservationPolicy.php
│   └── Services/
│       └── ReservationService.php # Transacción + lockForUpdate
├── database/
│   ├── migrations/
│   └── seeders/
├── routes/
│   └── api.php
└── tests/
    ├── Unit/
    ├── Feature/                  # aislamiento, RBAC, aforo, cupo, bloqueo, concurrencia
    └── Concurrency/

frontend/                         # Angular 17+
├── src/
│   ├── app/
│   │   ├── core/
│   │   │   ├── services/
│   │   │   │   ├── auth.service.ts
│   │   │   │   ├── slot.service.ts
│   │   │   │   └── reservation.service.ts
│   │   │   └── guards/
│   │   ├── features/
│   │   │   ├── slots/
│   │   │   └── reservations/
│   │   └── shared/
│   └── environments/
└── tests/

docker-compose.yml                # (opcional) MySQL 8 + backend + frontend dev
```

**Structure Decision**: Web application con `backend/` y `frontend/` separados (Option 2 del template). Se elige sobre single-project porque la constitución exige Laravel y Angular desacoplados vía REST. `backend/` concentra dominio/autorización/persistencia; `frontend/` solo presentación y consumo tipado. Ambos comparten validación de contratos vía `contracts/openapi.yaml` (opcional).

## Complexity Tracking

> No se registran violaciones — el diseño respeta la constitución simplificada v2.0.1 para proyecto individual. No hay 4º proyecto ni patrones adicionales que justificar.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |
