import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SlotService, Slot } from '../../core/services/slot.service';

/**
 * T032: UI Angular de gestión de franjas para admin — solo cortesía de UX.
 * Sin lógica de negocio: validación de horario/aforo y duplicado vive en SlotRequest/SlotController.
 * Muestra ocupación vigente y confirma cascada al bloquear.
 */
@Component({
  selector: 'app-slot-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <h2>Gestión de franjas (Admin)</h2>
    <p class="hint">Solo visible para administradores — el backend valida con SlotPolicy.</p>

    <form (ngSubmit)="onCreate()" class="create-form">
      <h3>Crear franja</h3>
      <label>Día (1=Lunes ... 5=Viernes):
        <input type="number" [(ngModel)]="newSlot.day_of_week" name="day" min="1" max="5" required />
      </label>
      <label>Hora inicio (07:00-21:00 en punto):
        <input type="time" [(ngModel)]="newSlot.start_time" name="time" step="3600" required />
      </label>
      <label>Capacidad:
        <input type="number" [(ngModel)]="newSlot.capacity" name="capacity" min="1" max="50" />
      </label>
      <button type="submit">Crear</button>
    </form>

    <p *ngIf="error" class="error">{{ error }}</p>
    <p *ngIf="success" class="success">{{ success }}</p>

    <h3>Franjas (semana {{ weekStart }})</h3>
    <p *ngIf="loading">Cargando...</p>
    <ul *ngIf="!loading">
      <li *ngFor="let slot of slots" class="slot-row">
        <span>
          {{ dayName(slot.day_of_week) }} {{ slot.start_time }} —
          Capacidad {{ slot.capacity }} — Ocupación {{ slot.occupation ?? 0 }}/{{ slot.capacity }} —
          Estado: {{ slot.status }}
        </span>
        <button (click)="onEdit(slot)">Editar</button>
        <button (click)="onDelete(slot)">Eliminar</button>
        <button *ngIf="slot.status === 'abierta'" (click)="onBlock(slot)">Bloquear</button>
        <button *ngIf="slot.status === 'bloqueada'" (click)="onUnblock(slot)">Desbloquear</button>
      </li>
    </ul>

    <div *ngIf="editingSlot" class="edit-form">
      <h3>Editar franja #{{ editingSlot.id }}</h3>
      <label>Día: <input type="number" [(ngModel)]="editingSlot.day_of_week" min="1" max="5" /></label>
      <label>Hora: <input type="time" [(ngModel)]="editingSlot.start_time" step="3600" /></label>
      <label>Capacidad: <input type="number" [(ngModel)]="editingSlot.capacity" min="1" max="50" /></label>
      <button (click)="onUpdate()">Guardar</button>
      <button (click)="editingSlot = null">Cancelar</button>
    </div>
  `,
  styles: [`
    .create-form, .edit-form { border: 1px solid #ccc; padding: 1rem; margin: 1rem 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .slot-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin: 0.5rem 0; }
    .error { color: #b00020; white-space: pre-wrap; }
    .success { color: #006400; }
    .hint { font-size: 0.9rem; color: #666; }
  `]
})
export class SlotAdminComponent implements OnInit {
  slots: Slot[] = [];
  loading = true;
  error: string | null = null;
  success: string | null = null;

  weekStart: string = this.getNextMonday();

  newSlot: Partial<Slot> = { day_of_week: 1, start_time: '08:00:00', capacity: 4 };
  editingSlot: Slot | null = null;

  constructor(private slotService: SlotService) {}

  ngOnInit(): void {
    this.loadSlots();
  }

  loadSlots(): void {
    this.loading = true;
    this.error = null;
    this.slotService.list(this.weekStart).subscribe({
      next: (res) => {
        this.slots = res.data;
        this.loading = false;
      },
      error: (err) => {
        this.error = err?.error?.message ?? 'Error al cargar franjas';
        this.loading = false;
      }
    });
  }

  onCreate(): void {
    this.error = null;
    this.success = null;
    // Normalizar hora a HH:00:00 si viene como HH:MM
    const payload = { ...this.newSlot };
    if (payload.start_time && /^\d{2}:\d{2}$/.test(payload.start_time)) {
      payload.start_time += ':00';
    }
    this.slotService.create(payload).subscribe({
      next: () => {
        this.success = 'Franja creada';
        this.loadSlots();
      },
      error: (err) => {
        // Mostrar mensaje exacto del backend (422 duplicado, etc.), no inventar
        this.error = err?.error?.message ?? JSON.stringify(err?.error?.errors ?? err.error);
      }
    });
  }

  onEdit(slot: Slot): void {
    this.editingSlot = { ...slot };
  }

  onUpdate(): void {
    if (!this.editingSlot) return;
    this.slotService.update(this.editingSlot.id, this.editingSlot).subscribe({
      next: () => {
        this.success = 'Franja actualizada';
        this.editingSlot = null;
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al actualizar'
    });
  }

  onDelete(slot: Slot): void {
    if (!confirm(`¿Eliminar franja Día ${slot.day_of_week} ${slot.start_time}?`)) return;
    this.slotService.delete(slot.id).subscribe({
      next: () => {
        this.success = 'Franja eliminada';
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al eliminar'
    });
  }

  onBlock(slot: Slot): void {
    if (!confirm(`¿Bloquear franja Día ${slot.day_of_week} ${slot.start_time}? Se cancelarán en cascada las reservas futuras (hard DELETE) y se liberará el cupo. ¿Continuar?`)) return;
    this.slotService.block(slot.id).subscribe({
      next: () => {
        this.success = 'Franja bloqueada (reservas futuras eliminadas)';
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al bloquear'
    });
  }

  onUnblock(slot: Slot): void {
    this.slotService.unblock(slot.id).subscribe({
      next: () => {
        this.success = 'Franja desbloqueada';
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al desbloquear'
    });
  }

  dayName(day: number): string {
    return ['?', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][day] ?? String(day);
  }

  private getNextMonday(): string {
    const now = new Date();
    const day = now.getDay();
    const diffToMonday = day === 0 ? 1 : 1 - day;
    const monday = new Date(now);
    monday.setDate(now.getDate() + diffToMonday + 7);
    return monday.toISOString().slice(0, 10);
  }
}
