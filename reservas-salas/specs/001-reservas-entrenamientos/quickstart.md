# Quickstart — Validación end-to-end: Sistema de Reservas de Entrenamientos de Gimnasio

**Feature**: 001-reservas-entrenamientos | **Date**: 2026-09-02
> Guía para verificar que la feature funciona sin leer implementación. Referencia `data-model.md` y `contracts/` (no duplica contratos).

## Prerrequisitos

- PHP 8.2 + Composer, Node 20+, MySQL 8.0 (o Docker)
- Repos `backend/` (Laravel 11) y `frontend/` (Angular 17+) inicializados según `plan.md` §Project Structure
- MySQL BD de test separada para `lockForUpdate` real (no SQLite)

## Setup (una vez)

```bash
# Backend
cd backend
cp .env.example .env
# configurar DB_DATABASE, DB_USERNAME, DB_PASSWORD (MySQL)
composer install
php artisan key:generate
php artisan migrate           # crea users, slots, reservations
php artisan db:seed --class=DemoSeeder  # opcional: 2 clientes + 1 admin + 75 slots plantilla
php artisan test --filter=Sanity  # sanity check

# Frontend
cd ../frontend
npm install
ng serve  # http://localhost:4200 (proxy a http://localhost:8000)
```

**Credenciales demo** (seeder):
- Admin: `admin@gym.test / password` (`role=administrador`)
- Cliente A: `cliente-a@gym.test / password` (`weekly_hours=3`)
- Cliente B: `cliente-b@gym.test / password` (`weekly_hours=1`)

## Flujo de autenticación (Sanctum SPA)

1. `GET /sanctum/csrf-cookie` → cookie `XSRF-TOKEN`
2. `POST /api/login` con `email/password` → sesión Sanctum
3. Todas las peticiones siguientes incluyen `X-XSRF-TOKEN` y `Cookie`.

Ver `contracts/openapi.yaml` y `contracts/http-mapping.md` para códigos esperados.

## Escenarios de validación (mapeados a spec)

Ejecuta cada bloque con `curl` (o Postman) y/o tests `php artisan test`. Cada escenario referencia FR/US y el HTTP mapeado en `http-mapping.md`.

### 1. Reserva exitosa (US1 esc.1, FR-003) → 201
```bash
# Como cliente-a (3h), franja Lunes 08:00 (id=1) semana 2026-09-07, aforo 4, ocupación 1
POST /api/reservations { "slot_id": 1, "week_start": "2026-09-07" }
# Esperado: 201 { data: { status: "confirmada" } }
# Verificación: GET /api/users/me/quota?week_start=2026-09-07 → remaining 2
# Verificación: GET /api/slots?week_start=2026-09-07 → slot 1 occupation 2/4
```

### 2. Franja completa → 409 (US1 esc.2, FR-005)
```bash
# Llenar franja a 4/4 con 4 clientes distintos, luego cliente con cupo intenta quinta
POST /api/reservations { "slot_id": 2, "week_start": "2026-09-07" }
# Esperado: 409 { message: "Franja completa: aforo máximo (4) alcanzado..." }
# Tests: `php artisan test --filter=CapacityTest`
```

### 3. Límite semanal → 422 (US1 esc.3, FR-004) y 0h → 422 (FR-015)
```bash
# Cliente-b tiene 1h y ya 1 reserva esa semana → segunda reserva
POST /api/reservations { "slot_id": 3, "week_start": "2026-09-07" }
# Esperado: 422 { message: "Límite semanal alcanzado: tienes asignadas 1h y ya has reservado 1h..." }
# Cliente sin cupo (0h) → mismo 422 con "asignadas 0h"
```

### 4. Condición de carrera última plaza → solo 1 éxito (US1 esc.5, FR-014, SC-004)
```bash
php artisan test --filter=ConcurrencyTest
# Esperado: 20 intentos concurrentes a última plaza → 1x201, 19x409, ocupación final 4/4
# Implementación usa DB::transaction + lockForUpdate() sobre slots
```

### 5. Cancelar propia y recuperar cupo (US2 esc.1, FR-007) → 200
```bash
DELETE /api/reservations/{id}  # como propietario, franja futura
# Esperado: 200 { message: "Reserva cancelada..." }
# Verificación: quota remaining +1, slot occupation -1
```

### 6. Aislamiento — cancelar/ver ajena → 404 (US2 esc.2, US3 esc.2, FR-008)
```bash
# Cliente-a intenta DELETE /api/reservations/{idDeB}
# Esperado: 404 { message: "No encontrado." } (indistinguible, sin filtrar existencia)
GET /api/reservations  # como cliente-a → solo sus reservas (verifica no aparecen las de B)
GET /api/reservations/{idDeB} → 404
```

### 7. Franja en el pasado → 422 (US2 esc.3)
```bash
DELETE /api/reservations/{idPasado}  # franja cuya week_start+start_time < now()
# Esperado: 422 { message: "No se puede cancelar una franja ya iniciada/pasada." }
```

### 8. Gestión admin franjas y bloqueo en cascada (US4, FR-009/010)
```bash
# Como admin
POST /api/slots { "day_of_week": 4, "start_time": "07:00:00", "capacity": 4 } → 201
PATCH /api/slots/1/block { "status": "bloqueada" }  # franja con 2 reservas
# Esperado: 200 { message: "Franja bloqueada. 2 reservas canceladas..." }
# Verificación: GET /api/reservations?slot_id=1 → esas 2 ahora canceladas; quota de cada cliente +1
POST /api/reservations { "slot_id": 1, "week_start": "2026-09-14" } # semana futura → 409 bloqueada
```

### 9. Bajar aforo bajo reservas → 422 (US5 esc.1, FR-011)
```bash
PUT /api/slots/1 { "capacity": 2 }  # franja con 3 confirmadas
# Esperado: 422 { message: "Aforo (2) no puede ser inferior a reservas existentes (3)..." }
PUT /api/slots/1 { "capacity": 6 } → 200
```

### 10. Cupo y RBAC (US5 esc.3-5, FR-012/013)
```bash
PATCH /api/users/2/weekly-hours { "weekly_hours": 5 } # como admin → 200, aplica inmediato
PATCH /api/users/2/weekly-hours { "weekly_hours": 5 } # como cliente → 403
POST /api/reservations { "slot_id": 4, "week_start": "2026-09-07", "user_id": 2 } # como admin para otro → 201
GET /api/slots # sin auth → 401
```

### 11. Cupo se reinicia por semana y no acumula (FR-006, SC-009)
```bash
GET /api/users/me/quota?week_start=2026-09-07 → used 2
GET /api/users/me/quota?week_start=2026-09-14 → used 0 (reiniciado)
```

## Tests automatizados (TDD)

```bash
cd backend
php artisan test --testsuite=Feature
# Cobertura mínima por FR:
# - IsolationTest (FR-008, SC-007)
# - WeeklyQuotaTest (FR-004, FR-015, SC-002)
# - CapacityTest (FR-005, FR-011, SC-003/008)
# - ConcurrencyTest (FR-014, SC-004) — requiere MySQL real
# - BlockingCascadeTest (FR-010, SC-006)
# - SlotValidationTest (FR-001, FR-002)
# Frontend
cd ../frontend
npm test  # guards, slot.service, reservation.service
```

## Criterios de éxito (SC)

Valida `SC-001` a `SC-010` de la spec: reserva <30s, 100% rechazos aforo/cupo, 0% fuga, cancelación <2s, bloqueo 4→0 huérfanas, aforo nunca excedido, semana reiniciada. Usa los escenarios 1-11 como evidencia.

## Troubleshooting

- `401` en todo → revisa `GET /sanctum/csrf-cookie` y `X-XSRF-TOKEN`.
- `ConcurrencyTest` pasa solo con MySQL (no SQLite) por `FOR UPDATE`.
- `week_start` debe ser lunes ISO (`YYYY-MM-DD`); si no, `422`.
