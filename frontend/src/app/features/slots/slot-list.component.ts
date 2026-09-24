import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SlotService, Slot } from '../../core/services/slot.service';
import { ReservationService } from '../../core/services/reservation.service';
import { DayOfWeekPipe } from '../../core/pipes/day-of-week.pipe';

/**
 * T021: Listado de franjas con ocupación y UX deshabilitada si completa/bloqueada.
 * Solo UX: el backend sigue siendo quien realmente rechaza (409/422).
 * No implementa validación de negocio (aforo) en Angular.
 */
@Component({
  selector: 'app-slot-list',
  standalone: true,
  imports: [CommonModule, DayOfWeekPipe],
  template: `
    <h2 class="h4 mb-3">Franjas disponibles</h2>
    <div class="btn-group mb-3" role="group">
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'current'" (click)="selectedWeek = 'current'; loadSlots()">Esta semana</button>
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'next'" (click)="selectedWeek = 'next'; loadSlots()">Semana siguiente</button>
    </div>
    <span class="text-muted ms-3">Semana del {{ weekStart | date:'dd/MM/yyyy' }}</span>
    <p *ngIf="loading" class="text-muted">Cargando franjas...</p>
    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>
    <table *ngIf="!loading && slots.length > 0" class="table table-striped">
      <thead>
        <tr>
          <th>Día</th>
          <th>Hora</th>
          <th>Capacidad</th>
          <th>Ocupación</th>
          <th>Estado</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let slot of slots">
          <td>{{ slot.day_of_week | dayOfWeek }}</td>
          <td>{{ slot.start_time }}</td>
          <td>{{ slot.capacity }}</td>
          <td>{{ slot.occupation ?? 0 }}/{{ slot.capacity }}</td>
          <td>
            <span class="badge" [ngClass]="slot.status === 'abierta' ? 'bg-success' : 'bg-danger'">{{ slot.status }}</span>
          </td>
          <td>
            <button
              class="btn btn-sm btn-primary"
              (click)="onReserve(slot)"
              [disabled]="isReserveDisabled(slot)"
              [title]="getDisabledReason(slot)"
              [attr.aria-disabled]="isReserveDisabled(slot)"
            >
              Reservar
            </button>
            <small *ngIf="isReserveDisabled(slot)" class="text-muted ms-2">{{ getDisabledReason(slot) }}</small>
          </td>
        </tr>
      </tbody>
    </table>
    <p *ngIf="!loading && slots.length === 0" class="text-muted">No hay franjas para esta semana.</p>
  `,
  styles: []
})
export class SlotListComponent implements OnInit {
  slots: Slot[] = [];
  loading = true;
  error: string | null = null;

  selectedWeek: 'current' | 'next' = 'current';

  get weekStart(): string {
    return this.selectedWeek === 'current' ? this.thisMonday() : this.nextMonday();
  }

  constructor(
    private slotService: SlotService,
    private reservationService: ReservationService
  ) {}

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
        // Mostrar mensaje del backend tal cual, no inventar
        this.error = err?.error?.message ?? 'Error al cargar franjas';
        this.loading = false;
      }
    });
  }

  isReserveDisabled(slot: Slot): boolean {
    if (slot.is_past) return true;
    const occupation = slot.occupation ?? 0;
    return slot.status === 'bloqueada' || occupation >= slot.capacity;
  }

  getDisabledReason(slot: Slot): string {
    if (slot.is_past) return 'Franja ya pasada';
    if (slot.status === 'bloqueada') return 'Franja bloqueada';
    const occupation = slot.occupation ?? 0;
    if (occupation >= slot.capacity) return 'Franja completa';
    return '';
  }

  onReserve(slot: Slot): void {
    if (this.isReserveDisabled(slot)) return;
    // Solo UX: delega al backend, que es quien realmente valida aforo/bloqueo
    this.reservationService.create(slot.id, this.weekStart).subscribe({
      next: () => {
        this.loadSlots(); // refresca ocupación 3/4
      },
      error: (err) => {
        // Mostrar mensaje exacto del backend (409/422), no inventar
        const msg = err?.error?.message ?? err?.error?.errors?.slot_id?.[0] ?? 'Error al reservar';
        this.error = msg;
      }
    });
  }

  private thisMonday(): string {
    const now = new Date();
    const day = now.getDay(); // 0 dom, 1 lun
    const diffToMonday = day === 0 ? -6 : 1 - day;
    const monday = new Date(now);
    monday.setDate(now.getDate() + diffToMonday);
    return monday.toISOString().slice(0, 10);
  }

  private nextMonday(): string {
    const thisMon = this.thisMonday();
    const d = new Date(thisMon);
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
  }
}
