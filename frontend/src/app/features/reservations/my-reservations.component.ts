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
    <h2 class="h4 mb-3">Mis reservas</h2>

    <div *ngIf="quota" class="alert alert-info">
      Cupo: {{ quota.used }}/{{ quota.assigned }} usados,
      restante: <strong>{{ quota.remaining }}</strong>
    </div>
    <div *ngIf="quotaError" class="alert alert-danger">{{ quotaError }}</div>

    <p *ngIf="loading" class="text-muted">Cargando reservas...</p>
    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>

    <table *ngIf="!loading && reservations.length > 0" class="table">
      <thead>
        <tr>
          <th>Slot</th>
          <th>Semana</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let r of reservations">
          <td>{{ r.slot_id }}</td>
          <td>{{ r.week_start | date:'dd/MM/yyyy' }}</td>
          <td>
            <button class="btn btn-sm btn-outline-danger" (click)="onCancel(r)" [disabled]="cancellingId === r.id">
              {{ cancellingId === r.id ? 'Cancelando...' : 'Cancelar' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <p *ngIf="!loading && reservations.length === 0" class="text-muted">No tienes reservas.</p>

    <div *ngIf="successMessage" class="alert alert-success mt-2">{{ successMessage }}</div>
    <div *ngIf="errorMessage" class="alert alert-danger mt-2">{{ errorMessage }}</div>
  `,
  styles: []
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
