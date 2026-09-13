# Quickstart — Validación end-to-end: Sistema de Reservas de Entrenamientos de Gimnasio

**Feature**: 001-reservas-entrenamientos | **Date**: 2026-09-13
> Guía para verificar que la feature funciona sin leer implementación. Referencia `data-model.md` y `contracts/` (no duplica contratos).

## Prerrequisitos

- PHP 8.4 + Composer (Herd 8.4.25), Node 20+, MySQL 8.0 (o Docker)
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
php artisan db:seed --class=DemoSeeder  # 1 admin + 3 clientes (2 con horas, 1 con 0h) + 4 franjas plantilla
php artisan test  # sanity check

# Frontend
cd ../frontend
npm install
ng serve  # http://localhost:4200 (proxy a http://localhost:8000)
# Nota Windows: si php artisan serve falla, usar php -S 127.0.0.1:8000 -t public
```

**Credenciales demo** (DemoSeeder real):
- Admin: `admin@gym.test / password` (`role=administrador`, `weekly_hours=0`)
- Cliente A: `cliente-a@gym.test / password` (`role=cliente`, `weekly_hours=3`)
- Cliente B: `cliente-b@gym.test / password` (`role=cliente`, `weekly_hours=5`)
- Cliente Cero: `cliente-cero@gym.test / password` (`role=cliente`, `weekly_hours=0` — sin cupo, debe ser rechazado)

Franjas demo (plantilla semanal, `week_start` = próximo lunes):
- Lunes 08:00 cap 1 abierta 0/1
- Martes 09:00 bloqueada
- Miércoles 10:00 cap 2 abierta 0/2
- Jueves 11:00 cap 1 abierta 1/1 llena (con reserva de cliente-a, para probar 409 sin pasos previos)

**Cálculo de semana (próximo lunes dinámico, no fecha fija):**
```bash
# Linux/macOS
WEEK_START=$(php -r "echo (new DateTime('next monday'))->format('Y-m-d');")
# Windows PowerShell
$WEEK_START = (Get-Date -Day 1).AddDays(7) # o php -r "echo (new DateTime('next monday'))->format('Y-m-d');"
# O en tests: Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString()
```
Todas las fechas de ejemplo `2026-09-07` quedan obsoletas; usar siempre `$WEEK_START` calculado dinámicamente.

## Flujo de autenticación (Sanctum SPA) — verificado manualmente

Flujo real correcto (no `actingAs`):
```bash
# 1. Obtener cookie CSRF
curl -c cookies.txt http://localhost:8000/sanctum/csrf-cookie
# Respuesta: Set-Cookie: XSRF-TOKEN=...; Set-Cookie: laravel_session=...

# 2. Extraer X-XSRF-TOKEN del cookie (el valor de XSRF-TOKEN url-decodeado)
XSRF=$(grep XSRF-TOKEN cookies.txt | cut -f7 | urldecode)

# 3. Login con cookies
curl -b cookies.txt -c cookies.txt -H "X-XSRF-TOKEN: $XSRF" -H "Content-Type: application/json" \
  -d '{"email":"cliente-a@gym.test","password":"password"}' \
  http://localhost:8000/api/login
# Esperado: 200 { message: "Autenticado.", data: { id, name, email, role } }
# Credenciales incorrectas: 401 { message: "Credenciales incorrectas." }

# 4. Peticiones siguientes incluyen cookies (sesión) y X-XSRF-TOKEN
curl -b cookies.txt -H "X-XSRF-TOKEN: $XSRF" http://localhost:8000/api/users/me/quota
# Esperado: 200 { data: { assigned, used, remaining, week_start } }

# 5. Logout
curl -b cookies.txt -c cookies.txt -H "X-XSRF-TOKEN: $XSRF" -X POST http://localhost:8000/api/logout
# Esperado: 200 { message: "Sesión cerrada." }
# Posteriores con la misma cookie vieja: 401 (verificado manualmente con curl/Invoke-WebRequest)
```

En `php artisan test` el flujo se simula con `postJson('/api/login')` + `getJson` sin `actingAs`; el `assertGuest('web')` tras logout es la aserción fiable en ese entorno (ver `AuthTest`).

Ver `contracts/openapi.yaml` y `contracts/http-mapping.md` para códigos esperados.

## Escenarios de validación (mapeados a spec)

Ejecuta cada bloque con `curl` (o Postman) y/o tests `php artisan test`. Cada escenario referencia FR/US y el HTTP mapeado en `http-mapping.md`. Usar siempre `$WEEK_START` dinámico.

### 1. Reserva exitosa (US1 esc.1, FR-003) → 201
```bash
# Como cliente-a (3h), franja Lunes 08:00 cap 1, 0/1
POST /api/reservations { "slot_id": 1, "week_start": "$WEEK_START" }
# Esperado: 201 { data: { status: "confirmada" } }
# Verificación: GET /api/users/me/quota?week_start=$WEEK_START → remaining 2 (3 - 1)
# Verificación: GET /api/slots?week_start=$WEEK_START → slot 1 occupation 1/1
```

### 2. Franja completa → 409 (US1 esc.2, FR-005)
```bash
# Llenar franja Miércoles 10:00 cap 2 a 2/2 con 2 clientes distintos, luego cliente con cupo intenta tercera
POST /api/reservations { "slot_id": 3, "week_start": "$WEEK_START" }
# Esperado: 409 { message: "Franja completa: aforo máximo alcanzado" }
# Nota: slot 2 (Martes 09:00) es bloqueada, no usar para este escenario
# Tests: `php artisan test --filter=ReservationQuotaCapacityTest`
```

### 3. Límite semanal → 422 (US1 esc.3, FR-004) y 0h → 422 (FR-015)
```bash
# Para este escenario, crear un cliente de prueba específico con weekly_hours=1 y ya con 1 reserva en $WEEK_START
# (alternativa realista con cliente-b (5h): llenar 5/5 reservas y probar sexta → mismo 422)
POST /api/reservations { "slot_id": 3, "week_start": "$WEEK_START" }
# Esperado: 422 { message: "Límite semanal alcanzado: tienes asignadas 1h y ya has reservado 1h..." }
# Cliente sin cupo (0h) → mismo 422 con "asignadas 0h" (probar con cliente-cero 0h real del seeder)
```

### 4. Condición de carrera última plaza → solo 1 éxito (US1 esc.5, FR-014, SC-004)
```bash
php artisan test --filter=ConcurrencyTest
# Esperado: 20 intentos concurrentes a última plaza → 1x201, 19x409, ocupación final 4/4
# Implementación usa DB::transaction + lockForUpdate() sobre slots
```

### 5. Cancelar propia y recuperar cupo (US2 esc.1, FR-007) → 200
```bash
DELETE /api/reservations/{id}  # como propietario, franja futura ($WEEK_START)
# Esperado: 200 { message: "Reserva cancelada." }
# Verificación: quota remaining +1, slot occupation -1
# Re-reserva misma franja+semana debe volver a dar 201 (UNIQUE liberado por hard DELETE)
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
# Usar semana pasada: WEEK_PAST=$(php -r "echo (new DateTime('last monday'))->format('Y-m-d');")
DELETE /api/reservations/{idPasado}  # franja cuya week_start+start_time < now()
# Esperado: 422 { message: "No se puede cancelar una franja ya iniciada/pasada." }
```

### 8. Gestión admin franjas y bloqueo en cascada (US4, FR-009/010)
```bash
# Como admin — crear franja nueva para este escenario (no reutilizar demo con capacidad incompatible)
POST /api/slots { "day_of_week": 4, "start_time": "07:00:00", "capacity": 4 } → 201  # nueva, id retornado (ej. 5)
# Crear 2 reservas en esa nueva franja para $WEEK_START, luego bloquearla
PATCH /api/slots/5/block  # franja nueva con 2 reservas en $WEEK_START
# Esperado: 200 { message: "Franja bloqueada." }
# Verificación: hard DELETE solo week_start >= lunes actual; semanas pasadas intactas
# Verificación: GET /api/reservations (como cliente afectado) ya no contiene esas reservas; quota +1
# Intento reserva en bloqueada: POST /api/reservations { "slot_id": 5, "week_start": "$WEEK_START" } → 409 bloqueada
# Desbloquear: PATCH /api/slots/5/unblock → 200
```

### 9. Bajar aforo bajo reservas → 422 (US5 esc.1, FR-011) — solo vigente, histórico ignorado
```bash
# Para este escenario, crear franja nueva con capacidad 4 y 3 reservas vigentes en $WEEK_START
POST /api/slots { "day_of_week": 5, "start_time": "09:00:00", "capacity": 4 } → 201  # nueva, id ej. 6
# Crear 3 reservas en esa franja para $WEEK_START, luego intentar bajar
PUT /api/slots/6 { "capacity": 2 }  # franja nueva con 3 confirmadas en $WEEK_START (vigente) → 422
# Esperado: 422 { message: "El aforo no puede ser inferior a las reservas existentes (3)..." }
# Histórico pasado con 5 reservas en semana pasada no bloquea bajar a 2 (se ignora week_start < lunes actual)
PUT /api/slots/6 { "capacity": 6 } → 200
```

### 10. Cupo y RBAC (US5 esc.3-5, FR-012/013)
```bash
PATCH /api/users/2/weekly-hours { "weekly_hours": 5 } # como admin → 200, aplica inmediato
PATCH /api/users/2/weekly-hours { "weekly_hours": 5 } # como cliente → 403
POST /api/reservations { "slot_id": 3, "week_start": "$WEEK_START", "user_id": 2 } # como admin para otro → 201 (bypass aforo/cupo) — usa slot 3 (Miércoles 10:00 cap 2, 0/2) donde cliente-a no tiene reserva aún, para no chocar con seed (slot 4 ya está 1/1 lleno)
# Nota: el bypass de admin (FR-013) se salta aforo y cupo semanal, pero el constraint UNIQUE(user_id, slot_id, week_start) sigue aplicando siempre, para cualquier rol — el admin no puede crear una reserva duplicada para el mismo cliente en la misma franja+semana (422 "Ya tienes una reserva confirmada...")
GET /api/slots # sin auth → 401
```

### 11. Cupo se reinicia por semana y no acumula (FR-006, SC-009)
```bash
WEEK_NEXT=$(php -r "echo (new DateTime('next monday +7 days'))->format('Y-m-d');")
GET /api/users/me/quota?week_start=$WEEK_START → used 2
GET /api/users/me/quota?week_start=$WEEK_NEXT → used 0 (reiniciado)
```

### 12. CRUD de usuarios (FR-018/019/020) — crear cliente, eliminar cliente con cascada
```bash
# Crear cliente (solo admin) — password obligatorio min 8
POST /api/users { "name": "Nuevo", "email": "nuevo@test.test", "password": "password123", "weekly_hours": 2 } # como admin → 201
POST /api/users { "name": "Nuevo", "email": "nuevo@test.test", "password": "password123", "weekly_hours": 2 } # email duplicado → 422
POST /api/users { "name": "X", "email": "x@test.test", "weekly_hours": 2 } # sin password → 422
POST /api/users { ... } # como cliente → 403

# Eliminar cliente con reservas (cascada hard DELETE, sin auditoría)
DELETE /api/users/{id} # como admin → 200 { message: "Usuario eliminado." }
# Verificación: GET /api/users/{id} → 404, Reservation::where(user_id) == 0 (pasadas y futuras eliminadas)
DELETE /api/users/{id} # como cliente → 403
DELETE /api/users/99999 # no existe → 404
```

## Tests automatizados (TDD)

```bash
cd backend
php artisan test
# Suites reales (16+):
# - IsolationTest (FR-008, SC-007)
# - IsolationCancelTest
# - ReservationQuotaCapacityTest (FR-003/004/005/015, incluye duplicado 422 "Ya tienes...")
# - ReservationCancelTest (FR-007, hard DELETE)
# - ReservationContractTest (201/409/422 + admin bypass FR-013)
# - ReservationRequestTest (week_start lunes ISO + franja pasada)
# - SlotCapacityValidationTest (FR-011 vigente vs histórico + concurrencia reserva vs reducción)
# - SlotBlockingCascadeTest (FR-010 cascada + concurrencia reserva vs bloqueo)
# - SlotManagementTest (FR-001, FR-009)
# - ConcurrencyTest (FR-014, SC-004) — requiere MySQL real por lockForUpdate
# - AuthTest (login 200/401, logout, validación)
# - AdminUsersCrudTest (FR-018/019/020, password obligatorio)
# - AdminUsersListTest (GET /api/users 200/403)
# - AdminUpdateWeeklyHoursTest (PATCH weekly_hours 200/403/422)
# - AdminReservationsIndexTest (admin ve todas, cliente solo suyas)
# - QuotaAndSlotListTest (quota assigned/used/remaining, occupation)
# - ReservationCancelTest, etc.

# Frontend
cd ../frontend
npm test  # o npx ng test
# Servicios: auth.service, slot.service, reservation.service + componentes slot-list, reservation-form, my-reservations, slot-admin, admin-quota
```

## Criterios de éxito (SC)

Valida `SC-001` a `SC-010` de la spec: reserva <30s, 100% rechazos aforo/cupo, 0% fuga, cancelación <2s, bloqueo 4→0 huérfanas, aforo nunca excedido, semana reiniciada, concurrencia sin fantasma. Usa los escenarios 1-12 como evidencia.

## Troubleshooting

- `401` en todo → revisa flujo Sanctum SPA: `GET /sanctum/csrf-cookie` → extraer `XSRF-TOKEN` → `POST /api/login` con `X-XSRF-TOKEN` y cookies. Ver `AuthTest` para flujo real.
- `ConcurrencyTest` / `SlotBlockingCascadeTest` pasan solo con MySQL (no SQLite) por `FOR UPDATE` (`lockForUpdate`).
- `week_start` debe ser lunes ISO (`YYYY-MM-DD`); si no, `422` con `week_start debe ser un lunes`.
- `php artisan serve` puede fallar en Windows (permisos/puerto) — alternativa que sí funciona: `php -S 127.0.0.1:8000 -t public` desde `backend/` (verificado el 2026-09-13 con Herd PHP 8.4.25).
- La invalidación completa de sesión se verifica manualmente (curl/Invoke-WebRequest), no mediante el test automatizado.
- `slot-admin` y `admin-quota` son solo cortesía UI; la protección real vive en `SlotPolicy`/`UserPolicy` y `ReservationService` (bypass admin FR-013).
