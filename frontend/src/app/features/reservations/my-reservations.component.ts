import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReservationService, Reservation, Quota } from '../../core/services/reservation.service';
import { DayOfWeekPipe } from '../../core/pipes/day-of-week.pipe';
import { thisMonday as getThisMonday, nextMonday as getNextMonday } from '../../core/utils/week.util';

declare const bootstrap: any;

/**
 * T026: Componente "Mis reservas" con botón cancelar y cupo actualizado tras 200.
 * Solo cortesía de UI: muestra lista, permite cancelar propias, refresca quota.
 * Sin lógica de negocio (aforo/cupo) en Angular — backend decide 200/404/422.
 */
@Component({
  selector: 'app-my-reservations',
  standalone: true,
  imports: [CommonModule, DayOfWeekPipe],
  template: `
    <h2 class="h4 mb-3">Mis reservas</h2>

    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2 mb-3">
      <div class="btn-group flex-wrap" role="group">
        <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'current'" (click)="selectedWeek = 'current'; loadAll()">Esta semana</button>
        <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'next'" (click)="selectedWeek = 'next'; loadAll()">Semana siguiente</button>
      </div>
      <span class="text-muted">Semana del {{ weekStart | date:'dd/MM/yyyy' }}</span>
    </div>

    <div *ngIf="quota" class="alert alert-info">
      Cupo: {{ quota.used }}/{{ quota.assigned }} usados,
      restante: <strong>{{ quota.remaining }}</strong>
    </div>
    <div *ngIf="quotaError" class="alert alert-danger">{{ quotaError }}</div>

    <p *ngIf="loading" class="text-muted">Cargando reservas...</p>
    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>

    <div class="table-responsive">
    <table *ngIf="!loading && reservations.length > 0" class="table">
      <thead>
        <tr>
          <th>Franja</th>
          <th>Semana</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let r of reservations">
          <td>
            <span *ngIf="r.slot">{{ r.slot.day_of_week | dayOfWeek }} {{ r.slot.start_time }}</span>
            <span *ngIf="!r.slot">Franja no disponible</span>
          </td>
          <td>{{ r.week_start | date:'dd/MM/yyyy' }}</td>
          <td>
            <button class="btn btn-sm btn-outline-danger" (click)="openCancelConfirm(r)" [disabled]="cancellingId === r.id">
              {{ cancellingId === r.id ? 'Cancelando...' : 'Cancelar' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
    <p *ngIf="!loading && reservations.length === 0" class="text-muted">No tienes reservas.</p>

    <div *ngIf="successMessage" class="alert alert-success mt-2">{{ successMessage }}</div>
    <div *ngIf="errorMessage" class="alert alert-danger mt-2">{{ errorMessage }}</div>

    <!-- Modal de confirmación de cancelación -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Cancelar reserva</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>¿Seguro que quieres cancelar tu reserva? Se liberará tu cupo semanal.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" (click)="cancelConfirm()">Cancelar</button>
            <button type="button" class="btn btn-danger" (click)="confirmCancel()">Sí, cancelar</button>
          </div>
        </div>
      </div>
    </div>
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

  selectedWeek: 'current' | 'next' = 'current';

  get weekStart(): string {
    return this.selectedWeek === 'current' ? getThisMonday() : getNextMonday();
  }

  pendingReservation: Reservation | null = null;
  private modalInstance: any = null;

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

  openCancelConfirm(reservation: Reservation): void {
    this.pendingReservation = reservation;
    const el = document.getElementById('cancelModal');
    if (el) {
      this.modalInstance = new bootstrap.Modal(el);
      this.modalInstance.show();
    }
  }

  confirmCancel(): void {
    if (!this.pendingReservation) return;
    const reservation = this.pendingReservation;
    this.modalInstance?.hide();
    this.pendingReservation = null;

    this.successMessage = null;
    this.errorMessage = null;
    this.cancellingId = reservation.id;

    this.reservationService.cancel(reservation.id).subscribe({
      next: (res) => {
        this.successMessage = res.message ?? 'Reserva cancelada.';
        this.cancellingId = null;
        this.loadAll();
      },
      error: (err) => {
        const msg = err?.error?.message ?? err?.error?.errors?.week_start?.[0] ?? 'Error al cancelar';
        this.errorMessage = msg;
        this.cancellingId = null;
      }
    });
  }

  cancelConfirm(): void {
    this.modalInstance?.hide();
    this.pendingReservation = null;
  }

  onCancel(reservation: Reservation): void {
    // Compatibilidad: ahora el flujo pasa por openCancelConfirm -> confirmCancel
    this.openCancelConfirm(reservation);
  }
}
