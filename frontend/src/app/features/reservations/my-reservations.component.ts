import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReservationService, Reservation, Quota } from '../../core/services/reservation.service';

/**
 * T026: Componente "Mis reservas" con botón cancelar y cupo actualizado tras 200.
 * Solo cortesía de UI: muestra lista, permite cancelar propias, refresca quota.
 * Sin lógica de negocio (aforo/cupo) en Angular — backend decide 200/404/422.
 */
@Component({
  selector: 'app-my-reservations',
  standalone: true,
  imports: [CommonModule],
  template: `
    <h2>Mis reservas</h2>

    <div *ngIf="quota" class="quota">
      Cupo: {{ quota.used }}/{{ quota.assigned }} usados,
      restante: <strong>{{ quota.remaining }}</strong>
    </div>
    <p *ngIf="quotaError" class="error">{{ quotaError }}</p>

    <p *ngIf="loading">Cargando reservas...</p>
    <p *ngIf="error" class="error">{{ error }}</p>

    <ul *ngIf="!loading">
      <li *ngFor="let r of reservations" class="reservation-item">
        <span>
          Reserva #{{ r.id }} — Slot {{ r.slot_id }} — Semana {{ r.week_start }}
        </span>
        <button (click)="onCancel(r)" [disabled]="cancellingId === r.id">
          {{ cancellingId === r.id ? 'Cancelando...' : 'Cancelar' }}
        </button>
      </li>
    </ul>
    <p *ngIf="!loading && reservations.length === 0">No tienes reservas.</p>

    <p *ngIf="successMessage" class="success">{{ successMessage }}</p>
    <p *ngIf="errorMessage" class="error">{{ errorMessage }}</p>
  `,
  styles: [`
    .quota { margin: 0.5rem 0; }
    .reservation-item { display: flex; gap: 1rem; align-items: center; margin: 0.5rem 0; }
    .error { color: #b00020; white-space: pre-wrap; }
    .success { color: #006400; }
    button[disabled] { opacity: 0.5; }
  `]
})
export class MyReservationsComponent implements OnInit {
  reservations: Reservation[] = [];
  quota: Quota | null = null;
  loading = true;
  error: string | null = null;
  quotaError: string | null = null;
  successMessage: string | null = null;
  errorMessage: string | null = null;
  cancellingId: number | null = null;

  weekStart: string = this.getNextMonday();

  constructor(private reservationService: ReservationService) {}

  ngOnInit(): void {
    this.loadAll();
  }

  loadAll(): void {
    this.loadReservations();
    this.loadQuota();
  }

  loadReservations(): void {
    this.loading = true;
    this.error = null;
    this.reservationService.myReservations(this.weekStart).subscribe({
      next: (res) => {
        this.reservations = res.data;
        this.loading = false;
      },
      error: (err) => {
        this.error = err?.error?.message ?? 'Error al cargar reservas';
        this.loading = false;
      }
    });
  }

  loadQuota(): void {
    this.quotaError = null;
    this.reservationService.quota(this.weekStart).subscribe({
      next: (res) => this.quota = res.data,
      error: (err) => this.quotaError = err?.error?.message ?? 'Error al cargar cupo'
    });
  }

  onCancel(reservation: Reservation): void {
    this.successMessage = null;
    this.errorMessage = null;
    this.cancellingId = reservation.id;

    this.reservationService.cancel(reservation.id).subscribe({
      next: (res) => {
        this.successMessage = res.message ?? 'Reserva cancelada.';
        this.cancellingId = null;
        // Refrescar lista y cupo tras 200 — cortesía UI
        this.loadAll();
      },
      error: (err) => {
        // Mostrar mensaje exacto del backend (404/422), no inventar
        const msg = err?.error?.message ?? err?.error?.errors?.week_start?.[0] ?? 'Error al cancelar';
        this.errorMessage = msg;
        this.cancellingId = null;
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
