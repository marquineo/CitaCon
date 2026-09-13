import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SlotService, Slot } from '../../core/services/slot.service';
import { ReservationService } from '../../core/services/reservation.service';

/**
 * T021: Listado de franjas con ocupación y UX deshabilitada si completa/bloqueada.
 * Solo UX: el backend sigue siendo quien realmente rechaza (409/422).
 * No implementa validación de negocio (aforo) en Angular.
 */
@Component({
  selector: 'app-slot-list',
  standalone: true,
  imports: [CommonModule],
  template: `
    <h2>Franjas disponibles</h2>
    <p *ngIf="loading">Cargando franjas...</p>
    <p *ngIf="error" class="error">{{ error }}</p>
    <ul *ngIf="!loading">
      <li *ngFor="let slot of slots" class="slot-item">
        <span class="slot-info">
          Día {{ slot.day_of_week }} - {{ slot.start_time }} |
          Capacidad: {{ slot.capacity }} |
          Ocupación: {{ slot.occupation ?? 0 }}/{{ slot.capacity }} |
          Estado: {{ slot.status }}
        </span>
        <button
          (click)="onReserve(slot)"
          [disabled]="isReserveDisabled(slot)"
          [title]="getDisabledReason(slot)"
          [attr.aria-disabled]="isReserveDisabled(slot)"
        >
          Reservar
        </button>
        <span *ngIf="isReserveDisabled(slot)" class="hint">
          {{ getDisabledReason(slot) }}
        </span>
      </li>
    </ul>
    <p *ngIf="!loading && slots.length === 0">No hay franjas para esta semana.</p>
  `,
  styles: [`
    .slot-item { margin: 0.5rem 0; display: flex; gap: 1rem; align-items: center; }
    button[disabled] { opacity: 0.5; cursor: not-allowed; }
    .hint { font-size: 0.85rem; color: #666; }
    .error { color: #b00020; }
  `]
})
export class SlotListComponent implements OnInit {
  slots: Slot[] = [];
  loading = true;
  error: string | null = null;

  // Semana ISO lunes (próxima semana para demo, evita franja pasada)
  weekStart: string = this.getNextMonday();

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
    const occupation = slot.occupation ?? 0;
    return slot.status === 'bloqueada' || occupation >= slot.capacity;
  }

  getDisabledReason(slot: Slot): string {
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

  private getNextMonday(): string {
    const now = new Date();
    const day = now.getDay(); // 0 dom, 1 lun
    const diffToMonday = day === 0 ? 1 : 1 - day;
    const monday = new Date(now);
    monday.setDate(now.getDate() + diffToMonday + 7); // próxima semana
    return monday.toISOString().slice(0, 10);
  }
}
