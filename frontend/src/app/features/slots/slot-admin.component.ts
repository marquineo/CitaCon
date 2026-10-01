import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SlotService, Slot } from '../../core/services/slot.service';
import { DayOfWeekPipe } from '../../core/pipes/day-of-week.pipe';
import { thisMonday as getThisMonday, nextMonday as getNextMonday } from '../../core/utils/week.util';

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
    <p class="text-muted">Solo visible para administradores — el sistema valida los permisos.</p>

    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2 mb-3">
      <div class="btn-group flex-wrap" role="group">
        <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'current'" (click)="selectedWeek = 'current'; loadSlots()">Esta semana</button>
        <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'next'" (click)="selectedWeek = 'next'; loadSlots()">Semana siguiente</button>
      </div>
      <span class="text-muted">Semana del {{ weekStart | date:'dd/MM/yyyy' }}</span>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h3 class="h5 card-title">Crear franja</h3>
        <form (ngSubmit)="onCreate()">
          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Día (1=Lunes ... 5=Viernes):</label>
              <input type="number" class="form-control w-100" [(ngModel)]="newSlot.day_of_week" name="day" min="1" max="5" required />
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Hora inicio (07:00-21:00 en punto):</label>
              <input type="time" class="form-control w-100" [(ngModel)]="newSlot.start_time" name="time" step="3600" required />
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Capacidad:</label>
              <input type="number" class="form-control w-100" [(ngModel)]="newSlot.capacity" name="capacity" min="1" max="50" />
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Entrenador:</label>
              <select class="form-control w-100" [(ngModel)]="newSlot.trainer" name="trainer">
                <option [ngValue]="null">Sin asignar</option>
                <option value="Carlos">Carlos</option>
                <option value="Alicia">Alicia</option>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-3 w-100 w-sm-auto">Crear</button>
        </form>
      </div>
    </div>

    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>
    <div *ngIf="success" class="alert alert-success">{{ success }}</div>

    <h3 class="h5 mt-4">Franjas (semana {{ weekStart | date:'dd/MM/yyyy' }})</h3>
    <p *ngIf="loading" class="text-muted">Cargando...</p>
    <div class="table-responsive">
    <table *ngIf="!loading" class="table table-striped">
      <thead>
        <tr>
          <th>Día</th>
          <th>Hora</th>
          <th>Capacidad</th>
          <th>Ocupación</th>
          <th>Estado</th>
          <th>Entrenador</th>
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
            <span *ngIf="slot.trainer" class="badge bg-info">{{ slot.trainer }}</span>
            <span *ngIf="!slot.trainer" class="text-muted">Sin asignar</span>
          </td>
          <td>
            <div class="d-flex flex-column flex-sm-row gap-1" role="group">
              <button class="btn btn-sm btn-outline-primary" (click)="onEdit(slot)">Editar</button>
              <button class="btn btn-sm btn-outline-danger" (click)="openConfirm('delete', slot)">Eliminar</button>
              <button *ngIf="slot.status === 'abierta'" class="btn btn-sm btn-outline-warning" (click)="openConfirm('block', slot)">Bloquear</button>
              <button *ngIf="slot.status === 'bloqueada'" class="btn btn-sm btn-outline-success" (click)="onUnblock(slot)">Desbloquear</button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    </div>

    <div *ngIf="editingSlot" class="card mt-4">
      <div class="card-body">
        <h3 class="h5 card-title">Editar franja #{{ editingSlot.id }}</h3>
        <div class="row g-3">
          <div class="col-12 col-md-3">
            <label class="form-label">Día:</label>
            <input type="number" class="form-control w-100" [(ngModel)]="editingSlot.day_of_week" min="1" max="5" />
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Hora:</label>
            <input type="time" class="form-control w-100" [(ngModel)]="editingSlot.start_time" step="3600" />
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Capacidad:</label>
            <input type="number" class="form-control w-100" [(ngModel)]="editingSlot.capacity" min="1" max="50" />
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Entrenador:</label>
            <select class="form-control w-100" [(ngModel)]="editingSlot.trainer">
              <option [ngValue]="null">Sin asignar</option>
              <option value="Carlos">Carlos</option>
              <option value="Alicia">Alicia</option>
            </select>
          </div>
        </div>
        <div class="mt-3 d-flex flex-column flex-sm-row gap-2">
          <button class="btn btn-primary" (click)="onUpdate()">Guardar</button>
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

  selectedWeek: 'current' | 'next' = 'current';
  get weekStart(): string {
    return this.selectedWeek === 'current' ? getThisMonday() : getNextMonday();
  }

  newSlot: Partial<Slot> = { day_of_week: 1, start_time: '08:00:00', capacity: 4, trainer: null };
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
    const payload: any = { ...this.newSlot };
    if (payload.start_time && /^\d{2}:\d{2}$/.test(payload.start_time)) {
      payload.start_time += ':00';
    }
    if (payload.trainer === null || payload.trainer === '') {
      payload.trainer = null;
    }
    this.slotService.create(payload).subscribe({
      next: () => {
        this.success = 'Franja creada';
        this.loadSlots();
      },
      error: (err) => {
        this.error = err?.error?.message ?? JSON.stringify(err?.error?.errors ?? err.error);
      }
    });
  }

  onEdit(slot: Slot): void {
    this.editingSlot = { ...slot };
  }

  onUpdate(): void {
    if (!this.editingSlot) return;
    const payload: any = { ...this.editingSlot };
    if (payload.trainer === '') payload.trainer = null;
    this.slotService.update(this.editingSlot.id, payload).subscribe({
      next: () => {
        this.success = 'Franja actualizada';
        this.editingSlot = null;
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al actualizar'
    });
  }

  onDelete(_slot: Slot): void {
    this.openConfirm('delete', _slot);
  }

  onBlock(_slot: Slot): void {
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
}
