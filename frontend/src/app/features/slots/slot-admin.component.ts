import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SlotService, Slot } from '../../core/services/slot.service';
import { DayOfWeekPipe } from '../../core/pipes/day-of-week.pipe';

declare const bootstrap: any;

/**
 * T032: UI Angular de gestión de franjas para admin — solo cortesía de UX.
 * Sin lógica de negocio: validación de horario/aforo y duplicado vive en SlotRequest/SlotController.
 * Muestra ocupación vigente y confirma cascada al bloquear.
 */
@Component({
  selector: 'app-slot-admin',
  standalone: true,
  imports: [CommonModule, FormsModule, DayOfWeekPipe],
  template: `
    <h2 class="h4 mb-2">Gestión de franjas (Admin)</h2>
    <p class="text-muted">Solo visible para administradores — el backend valida con SlotPolicy.</p>

    <div class="card mb-4">
      <div class="card-body">
        <h3 class="h5 card-title">Crear franja</h3>
        <form (ngSubmit)="onCreate()">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Día (1=Lunes ... 5=Viernes):</label>
              <input type="number" class="form-control" [(ngModel)]="newSlot.day_of_week" name="day" min="1" max="5" required />
            </div>
            <div class="col-md-4">
              <label class="form-label">Hora inicio (07:00-21:00 en punto):</label>
              <input type="time" class="form-control" [(ngModel)]="newSlot.start_time" name="time" step="3600" required />
            </div>
            <div class="col-md-4">
              <label class="form-label">Capacidad:</label>
              <input type="number" class="form-control" [(ngModel)]="newSlot.capacity" name="capacity" min="1" max="50" />
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-3">Crear</button>
        </form>
      </div>
    </div>

    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>
    <div *ngIf="success" class="alert alert-success">{{ success }}</div>

    <h3 class="h5 mt-4">Franjas (semana {{ weekStart | date:'dd/MM/yyyy' }})</h3>
    <p *ngIf="loading" class="text-muted">Cargando...</p>
    <table *ngIf="!loading" class="table table-striped">
      <thead>
        <tr>
          <th>Día</th>
          <th>Hora</th>
          <th>Capacidad</th>
          <th>Ocupación</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let slot of slots">
          <td>{{ slot.day_of_week | dayOfWeek }}</td>
          <td>{{ slot.start_time }}</td>
          <td>{{ slot.capacity }}</td>
          <td>{{ slot.occupation ?? 0 }}/{{ slot.capacity }}</td>
          <td><span class="badge" [ngClass]="slot.status === 'abierta' ? 'bg-success' : 'bg-danger'">{{ slot.status }}</span></td>
          <td>
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-outline-primary" (click)="onEdit(slot)">Editar</button>
              <button class="btn btn-outline-danger" (click)="openConfirm('delete', slot)">Eliminar</button>
              <button *ngIf="slot.status === 'abierta'" class="btn btn-outline-warning" (click)="openConfirm('block', slot)">Bloquear</button>
              <button *ngIf="slot.status === 'bloqueada'" class="btn btn-outline-success" (click)="onUnblock(slot)">Desbloquear</button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>

    <div *ngIf="editingSlot" class="card mt-4">
      <div class="card-body">
        <h3 class="h5 card-title">Editar franja #{{ editingSlot.id }}</h3>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Día:</label>
            <input type="number" class="form-control" [(ngModel)]="editingSlot.day_of_week" min="1" max="5" />
          </div>
          <div class="col-md-4">
            <label class="form-label">Hora:</label>
            <input type="time" class="form-control" [(ngModel)]="editingSlot.start_time" step="3600" />
          </div>
          <div class="col-md-4">
            <label class="form-label">Capacidad:</label>
            <input type="number" class="form-control" [(ngModel)]="editingSlot.capacity" min="1" max="50" />
          </div>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary me-2" (click)="onUpdate()">Guardar</button>
          <button class="btn btn-secondary" (click)="editingSlot = null">Cancelar</button>
        </div>
      </div>
    </div>

    <!-- Modal de confirmación Bootstrap -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" *ngIf="pendingAction === 'block'">
              Bloquear franja de {{ pendingSlot ? (pendingSlot.day_of_week | dayOfWeek) : '' }} {{ pendingSlot?.start_time }}
            </h5>
            <h5 class="modal-title" *ngIf="pendingAction === 'delete'">
              Eliminar franja de {{ pendingSlot ? (pendingSlot.day_of_week | dayOfWeek) : '' }} {{ pendingSlot?.start_time }}
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p *ngIf="pendingAction === 'block'">
              Esta franja dejará de estar disponible para nuevas reservas. Los clientes que ya tuvieran reservada esta franja en semanas futuras recuperarán su hora automáticamente.
            </p>
            <p *ngIf="pendingAction === 'delete'">
              Esta franja se eliminará por completo. Esta acción no se puede deshacer.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" (click)="cancelConfirm()">Cancelar</button>
            <button *ngIf="pendingAction === 'block'" type="button" class="btn btn-warning" (click)="confirmAction()">Bloquear</button>
            <button *ngIf="pendingAction === 'delete'" type="button" class="btn btn-danger" (click)="confirmAction()">Eliminar</button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: []
})
export class SlotAdminComponent implements OnInit {
  slots: Slot[] = [];
  loading = true;
  error: string | null = null;
  success: string | null = null;

  weekStart: string = this.getNextMonday();

  newSlot: Partial<Slot> = { day_of_week: 1, start_time: '08:00:00', capacity: 4 };
  editingSlot: Slot | null = null;

  pendingAction: 'block' | 'delete' | null = null;
  pendingSlot: Slot | null = null;
  private modalInstance: any = null;

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

  openConfirm(action: 'block' | 'delete', slot: Slot): void {
    this.pendingAction = action;
    this.pendingSlot = slot;
    const el = document.getElementById('confirmModal');
    if (el) {
      this.modalInstance = new bootstrap.Modal(el);
      this.modalInstance.show();
    }
  }

  confirmAction(): void {
    if (!this.pendingSlot || !this.pendingAction) return;
    const slot = this.pendingSlot;
    const action = this.pendingAction;
    this.modalInstance?.hide();
    if (action === 'delete') {
      this.slotService.delete(slot.id).subscribe({
        next: () => {
          this.success = 'Franja eliminada';
          this.loadSlots();
        },
        error: (err) => this.error = err?.error?.message ?? 'Error al eliminar'
      });
    } else if (action === 'block') {
      this.slotService.block(slot.id).subscribe({
        next: () => {
          this.success = 'Franja bloqueada (reservas futuras eliminadas)';
          this.loadSlots();
        },
        error: (err) => this.error = err?.error?.message ?? 'Error al bloquear'
      });
    }
    this.pendingAction = null;
    this.pendingSlot = null;
  }

  cancelConfirm(): void {
    this.modalInstance?.hide();
    this.pendingAction = null;
    this.pendingSlot = null;
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

  onDelete(_slot: Slot): void {
    // Deprecated: usar openConfirm('delete', slot) + confirmAction()
    // Mantenido por compatibilidad si se llama programáticamente
    this.openConfirm('delete', _slot);
  }

  onBlock(_slot: Slot): void {
    // Deprecated: usar openConfirm('block', slot) + confirmAction()
    this.openConfirm('block', _slot);
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

  private getNextMonday(): string {
    const now = new Date();
    const day = now.getDay();
    const diffToMonday = day === 0 ? 1 : 1 - day;
    const monday = new Date(now);
    monday.setDate(now.getDate() + diffToMonday + 7);
    return monday.toISOString().slice(0, 10);
  }
}
