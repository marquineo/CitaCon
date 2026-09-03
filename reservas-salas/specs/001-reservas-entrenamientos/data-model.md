# Data Model: Sistema de Reservas de Entrenamientos de Gimnasio

**Feature**: 001-reservas-entrenamientos | **Date**: 2026-09-02 | **Source**: [spec.md](./spec.md) + [research.md](./research.md)

## Overview

Modelo con **plantilla semanal recurrente** (`slots` únicas por día+hora, 75 filas máx. L-V 7:00-22:00) y **reservas por semana** (`reservations` con `slot_id + week_start + user_id`). El cupo semanal es derivado; no se persiste. Bloquear una franja afecta a todas las semanas futuras.

```
users 1──∞ reservations ∞──1 slots (plantilla)
```

## Entities

### 1. Usuario (`users`)

Representa cliente o administrador. Autenticado vía Sanctum.

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `id` | BIGINT UNSIGNED PK | AUTO_INCREMENT |  |
| `name` | VARCHAR(255) | NOT NULL |  |
| `email` | VARCHAR(255) | UNIQUE, NOT NULL | Login |
| `email_verified_at` | TIMESTAMP | NULL | Sanctum opcional |
| `password` | VARCHAR(255) | NOT NULL, hashed |  |
| `role` | ENUM('cliente','administrador') | NOT NULL, DEFAULT 'cliente' | RBAC (Principio III) |
| `weekly_hours` | TINYINT UNSIGNED | NOT NULL, DEFAULT 0, CHECK >=0 | Horas semanales asignadas por admin (FR-012, FR-015). 0 = sin cupo |
| `created_at` | TIMESTAMP |  |  |
| `updated_at` | TIMESTAMP |  |  |

**Relaciones**: `User hasMany Reservation`

**Validaciones** (FormRequest / Policy):
- `weekly_hours` solo modificable por `role=administrador` (FR-012, US5 escenario 5).
- `role` solo modificable por admin.

### 2. Franja Horaria / Slot (`slots`) — Plantilla semanal recurrente

Una única fila por combinación `(día, hora)`, reutilizada cada semana. No se generan instancias semanales.

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `id` | BIGINT UNSIGNED PK | AUTO_INCREMENT |  |
| `day_of_week` | TINYINT | NOT NULL, CHECK 1-5 (1=Lunes, 5=Viernes) | FR-001 |
| `start_time` | TIME | NOT NULL, CHECK 07:00-21:00 en punto (`:00`) | 07:00,08:00...21:00; 21:00=21:00-22:00 FR-001 |
| `capacity` | TINYINT UNSIGNED | NOT NULL, DEFAULT 4, CHECK 1-50 | FR-002, aforo configurable |
| `status` | ENUM('abierta','bloqueada') | NOT NULL, DEFAULT 'abierta' | FR-009; bloqueada = no reservable para todas las semanas futuras |
| `created_at` | TIMESTAMP |  |  |
| `updated_at` | TIMESTAMP |  |  |

**Constraints**:
- `UNIQUE(day_of_week, start_time)` — no duplicados (FR duplicado).
- `CHECK (TIME_TO_SEC(start_time) % 3600 = 0 AND start_time BETWEEN '07:00:00' AND '21:00:00')` o validación en `SlotRequest`.
- Índice: `UNIQUE(day_of_week, start_time)` cubre búsqueda; índice adicional en `status` opcional.

**Reglas de negocio**:
- Crear/editar fuera de L-V 7:00-21:00 → rechazado (FR-001).
- `capacity < ocupación_vigente` → rechazar reducción (FR-011). La validación NO cuenta el histórico total acumulado de todas las semanas pasadas. Debe contar solo la ocupación real vigente: reservas confirmadas de la semana actual y futuras. Implementación: dentro de `DB::transaction` + `lockForUpdate` sobre `slots`, ejecutar `SELECT week_start, COUNT(*) AS cnt FROM reservations WHERE slot_id = ? AND week_start >= :currentWeekStart GROUP BY week_start`; si existe algún `cnt > :newCapacity`, rechazar con `422` y mensaje "Aforo (X) no puede ser inferior a reservas existentes (N). Elimine reservas primero." sin modificar la franja. No se filtra por `status` pues solo persiste `'confirmada'`. Las semanas pasadas (`week_start < currentWeekStart`) se ignoran aunque tengan histórico.
- Bloquear (`status→bloqueada`): elimina en transacción todas las `reservations` (todas con `status='confirmada'`, único valor persistido) de esa franja con `week_start >= :currentWeekStart` (todas las semanas futuras, FR-010). El bloqueo afecta a todas las semanas futuras (clarificación); la eliminación es hard DELETE — `cancelada` no se persiste.
- Desbloquear: vuelve a `abierta`, sin recrear reservas eliminadas (hard DELETE, `cancelada` no se persiste).

**Estado**: `abierta` ↔ `bloqueada` (transición admin).

### 3. Reserva (`reservations`)

Asociación cliente ↔ franja en una semana concreta (año-semana ISO).

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `id` | BIGINT UNSIGNED PK | AUTO_INCREMENT |  |
| `user_id` | BIGINT UNSIGNED FK | NOT NULL, FK → users.id ON DELETE CASCADE | Propietario (FR-008 aislamiento) |
| `slot_id` | BIGINT UNSIGNED FK | NOT NULL, FK → slots.id ON DELETE CASCADE | Plantilla |
| `week_start` | DATE | NOT NULL | Lunes 00:00 de la semana ISO (Europe/Madrid) de la franja. Ej. `2026-09-07` para semana L07-D13 |
| `status` | ENUM('confirmada') | NOT NULL, DEFAULT 'confirmada' | Solo persiste 'confirmada'; cancelar es hard DELETE (FR-007, FR-010). Valor 'cancelada' no se persiste — ver Out of Scope auditoría |
| `created_at` | TIMESTAMP |  |  |
| `updated_at` | TIMESTAMP |  |  |

**Constraints e índices**:
- **Constraint único definitivo**: `UNIQUE(user_id, slot_id, week_start)` **SÍ existe** en base de datos como red de seguridad ante duplicados, además del bloqueo pesimista `lockForUpdate()` sobre `slots` que previene la condición de carrera de aforo. Ambos conviven: el lock serializa la validación de aforo/cupo, el constraint garantiza que nunca se inserte un duplicado `(mismo usuario, misma franja, misma semana)` aunque el lock falle o haya retry.
- **Convivencia con hard DELETE**: Dado que `status` solo puede ser `'confirmada'` y cancelar es hard DELETE, las reservas canceladas **no permanecen** y por tanto no bloquean una nueva reserva del mismo `user_id, slot_id, week_start`. El flujo es: `DELETE FROM reservations WHERE id=?` → nueva `INSERT` misma tupla permitida por el `UNIQUE`. El estado `cancelada` no existe en persistencia; la auditoría queda pospuesta (Out of Scope) más allá de logs estándar.
- Índices adicionales: `INDEX(slot_id, week_start)`, `INDEX(user_id, week_start)` para consultas de cupo y ocupación vigentes (sin filtrar por `status`, pues solo hay un valor).
- FKs con `ON DELETE CASCADE`.

**Reglas de negocio**:
- 1 reserva = 1h de cupo en `week_start` y 1 plaza en `slot_id + week_start` (FR-003).
- Crear solo si `slot.status='abierta'` y `slot.start_time` futuro respecto a ahora para esa semana (franja en el pasado → rechazado).
- Validaciones atómicas dentro de `DB::transaction` + `Slot::lockForUpdate()`:
  1. `SELECT ... FOR UPDATE` sobre `slots` fila.
  2. `count = SELECT COUNT(*) FROM reservations WHERE slot_id=? AND week_start=?` → si `count >= slot.capacity` → rechazar "franja completa" (FR-005, `409`) — **omitido cuando `auth.role='administrador'`** (FR-013 excepción admin).
  3. `used = SELECT COUNT(*) FROM reservations WHERE user_id=? AND week_start=?` → si `used >= user.weekly_hours` → rechazar "límite semanal" (FR-004/FR-015, `422`) — **omitido cuando `auth.role='administrador'`**.
  4. Si `slot.status='bloqueada'` → rechazar `409` (aplica a ambos roles; admin debe desbloquear primero).
  5. Insertar `reservation` con `status='confirmada'` (admin puede exceder aforo/cupo — excepción deliberada, no bug).
- Cancelar (FR-007): solo propietario o admin; solo si existe fila y `week_start + slot.start_time` > `now()`; dentro de transacción se **elimina la fila** (hard DELETE) — libera cupo/plaza por recálculo y libera el `UNIQUE(user_id, slot_id, week_start)` para permitir re-reserva. `cancelada` no se persiste.
- Bloqueo de franja (FR-010): `UPDATE slots SET status='bloqueada'` + `DELETE FROM reservations WHERE slot_id=? AND week_start >= :currentWeekStart` en una transacción; cada eliminación libera cupo de su `week_start` correspondiente. Las semanas pasadas no se tocan.

### 4. Cupo Semanal (derivado, no tabla)

No persistido. Cálculo por `user_id + week_start`:

```
asignado = users.weekly_hours
usado    = COUNT(reservations WHERE user_id=? AND week_start=?)
restante = asignado - usado   // FR-006, FR-016 — solo filas 'confirmada' persisten
```

- Reinicia a 0 cada `week_start` nuevo (lunes 00:00).
- No acumulable.
- Expuesto en `GET /api/users/me/quota?week_start=YYYY-MM-DD` y en listado de reservas/franjas.

## Relationships Summary

- `User 1──∞ Reservation` (via `user_id`)
- `Slot 1──∞ Reservation` (via `slot_id` + `week_start` partición lógica)
- `Slot` es plantilla; sin tabla de instancias semanales.

## Validation Matrix (trazabilidad a spec)

| Spec FR | Entidad/Campo | Validación |
|---------|---------------|------------|
| FR-001 | `slots.day_of_week, start_time` | CHECK 1-5, 07:00-21:00 en punto; Request rechaza fuera de rango 422 |
| FR-002 | `slots.capacity` | DEFAULT 4, CHECK >=1 |
| FR-003 | `reservations` | Transacción con lock + aforo y cupo checks |
| FR-004/015 | `users.weekly_hours` vs `COUNT` | `used >= assigned` → 422 con mensaje límite |
| FR-005 | `slot.capacity` vs `COUNT` | `count >= capacity` → 409 franja completa |
| FR-006 | `week_start` | `week_start = lunes` de la semana; sin rollover |
| FR-007 | `reservations` (hard DELETE) + tiempo | Solo propietario/admin, antes de inicio, elimina fila y libera 1 plaza/1h |
| FR-008 | `reservations.user_id` | Policy: `view/cancel` solo si `user_id == auth.id` o admin; listado filtra por `auth.id` |
| FR-009/010 | `slots.status` | Admin puede crear/editar/bloquear; bloqueo cascada transaccional |
| FR-011 | `slots.capacity` | Rechazar si `new_capacity < max(COUNT por week_start >= currentWeekStart)` → 422 (solo ocupación vigente, no histórico; sin filtro por status) |
| FR-012 | `users.weekly_hours` | Solo admin, aplica inmediato |
| FR-014 | Concurrencia | `lockForUpdate` + transacción |

## State Transitions

**Slot**: `abierta` → `bloqueada` → `abierta` (admin, en cualquier momento). Efecto cascada al bloquear: `reservations` con `status='confirmada'` se eliminan (hard DELETE; `cancelada` no se persiste).

**Reservation**: `confirmada` → `eliminada` (cancelación por cliente propietario antes de inicio, por admin, o por bloqueo de slot). La re-reserva tras cancelar crea nueva fila con la misma clave `(user_id, slot_id, week_start)` ya liberada por el DELETE + `UNIQUE`. No hay transición `cancelada` persistida como fila bloqueante.

## Indexes & Performance

- `slots`: PK `id`, UNIQUE `(day_of_week, start_time)`
- `reservations`: PK `id`, **UNIQUE `(user_id, slot_id, week_start)`** (red de seguridad) + INDEX `(slot_id, week_start, status)`, INDEX `(user_id, week_start, status)` — cubren validaciones de aforo/cupo sin full scan y garantizan no duplicado por usuario/franja/semana.
- Cancel es hard DELETE, por lo que el `UNIQUE` no bloquea re-reservas tras cancelar.
- Todas las validaciones usan índices; `lockForUpdate` solo bloquea fila `slots`, no toda la tabla, permitiendo concurrencia entre franjas distintas. El `UNIQUE` es defensa adicional, no mecanismo primario de aforo.
