import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReservationService, Quota } from '../../core/services/reservation.service';
import { SlotService, Slot } from '../../core/services/slot.service';

/**
 * T020: Formulario de reserva con cupo y manejo 409/422.
 * - Muestra cupo semanal restante (GET /api/users/me/quota)
 * - Maneja 409/422 mostrando mensaje exacto de la API, no inventa
 * - No implementa validación de negocio (aforo, cupo) en Angular
 * - Acciones de administrador no incluidas aquí (pertenecen a US4/US5)
 */
@Component({
  selector: 'app-reservation-form',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <h2>Reservar entrenamiento</h2>

    <div *ngIf="quota" class="quota">
      Cupo semanal: {{ quota.used }}/{{ quota.assigned }} usados,
      restante: <strong>{{ quota.remaining }}</strong>
      <span *ngIf="quota.remaining === 0">(sin horas disponibles)</span>
    </div>
    <p *ngIf="!quota && !quotaError">Cargando cupo...</p>
    <p *ngIf="quotaError" class="error">{{ quotaError }}</p>

    <label>
      Semana (lunes ISO):
      <input type="date" [(ngModel)]="weekStart" (change)="onWeekChange()" />
    </label>

    <label>
      Franja:
      <select [(ngModel)]="selectedSlotId">
        <option [ngValue]="null">-- selecciona --</option>
        <option *ngFor="let slot of slots" [ngValue]="slot.id">
          Día {{ slot.day_of_week }} {{ slot.start_time }} — {{ slot.occupation ?? 0 }}/{{ slot.capacity }} ({{ slot.status }})
        </option>
      </select>
    </label>

    <button (click)="onSubmit()" [disabled]="!selectedSlotId">Reservar</button>

    <p *ngIf="successMessage" class="success">{{ successMessage }}</p>
    <p *ngIf="errorMessage" class="error">{{ errorMessage }}</p>
  `,
  styles: [`
    .quota { margin: 0.5rem 0; }
    .error { color: #b00020; white-space: pre-wrap; }
    .success { color: #006400; }
  `]
})
export class ReservationFormComponent implements OnInit {
  weekStart: string = this.getNextMonday();
  slots: Slot[] = [];
  selectedSlotId: number | null = null;

  quota: Quota | null = null;
  quotaError: string | null = null;

  successMessage: string | null = null;
  errorMessage: string | null = null;

  constructor(
    private reservationService: ReservationService,
    private slotService: SlotService
  ) {}

  ngOnInit(): void {
    this.loadQuota();
    this.loadSlots();
  }

  loadQuota(): void {
    this.quotaError = null;
    this.reservationService.quota(this.weekStart).subscribe({
      next: (res) => this.quota = res.data,
      error: (err) => this.quotaError = err?.error?.message ?? 'Error al cargar cupo'
    });
  }

  loadSlots(): void {
    this.slotService.list(this.weekStart).subscribe({
      next: (res) => this.slots = res.data,
      error: (err) => this.errorMessage = err?.error?.message ?? 'Error al cargar franjas'
    });
  }

  onWeekChange(): void {
    this.loadQuota();
    this.loadSlots();
  }

  onSubmit(): void {
    if (!this.selectedSlotId) return;
    this.successMessage = null;
    this.errorMessage = null;

    // No validación de aforo/cupo aquí — solo se envía al backend, que decide
    this.reservationService.create(this.selectedSlotId, this.weekStart).subscribe({
      next: (res) => {
        this.successMessage = 'Reserva confirmada';
        this.loadQuota();
        this.loadSlots();
      },
      error: (err) => {
        // Mostrar mensaje exacto de la API (409/422), no inventar
        const apiMessage = err?.error?.message;
        const validationMsg = err?.error?.errors ? Object.values(err.error.errors).flat().join(' ') : null;
        this.errorMessage = apiMessage ?? validationMsg ?? 'Error al reservar';
        if (err?.error?.errors?.slot_id) {
          this.errorMessage = err.error.errors.slot_id[0];
        }
        if (err?.error?.errors?.weekly_hours) {
          this.errorMessage = err.error.errors.weekly_hours[0];
        }
      }
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
