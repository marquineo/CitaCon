import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { SlotService, Slot } from '../../core/services/slot.service';
import { ReservationService, Reservation } from '../../core/services/reservation.service';
import { DayOfWeekPipe } from '../../core/pipes/day-of-week.pipe';
import { thisMonday as getThisMonday, nextMonday as getNextMonday } from '../../core/utils/week.util';
import { AuthService } from '../../core/services/auth.service';
import { environment } from '../../../environments/environment';

interface Client {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  role: string;
  weekly_hours: number;
}

/**
 * T021: Listado de franjas con ocupación y UX deshabilitada si completa/bloqueada.
 * Solo UX: el backend sigue siendo quien realmente rechaza (409/422).
 * No implementa validación de negocio (aforo) en Angular.
 */
@Component({
  selector: 'app-slot-list',
  standalone: true,
  imports: [CommonModule, FormsModule, DayOfWeekPipe],
  template: `
    <h2 class="h4 mb-3">Franjas disponibles</h2>
    <div class="btn-group mb-3" role="group">
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'current'" (click)="selectedWeek = 'current'; onWeekChange()">Esta semana</button>
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'next'" (click)="selectedWeek = 'next'; onWeekChange()">Semana siguiente</button>
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
        <ng-container *ngFor="let slot of slots">
          <tr>
            <td>{{ slot.day_of_week | dayOfWeek }}</td>
            <td>{{ slot.start_time }}</td>
            <td>{{ slot.capacity }}</td>
            <td>{{ slot.occupation ?? 0 }}/{{ slot.capacity }}</td>
            <td>
              <span class="badge" [ngClass]="slot.status === 'abierta' ? 'bg-success' : 'bg-danger'">{{ slot.status }}</span>
            </td>
            <td>
              <ng-container *ngIf="!isAdmin(); else adminAction">
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
              </ng-container>
              <ng-template #adminAction>
                <button class="btn btn-sm btn-outline-secondary" (click)="toggleInspect(slot.id)">Inspeccionar</button>
              </ng-template>
            </td>
          </tr>
          <tr *ngIf="expandedSlotId === slot.id">
            <td colspan="6">
              <div class="collapse show">
                <div class="card card-body mt-2">
                  <h6>Reservas para {{ slot.day_of_week | dayOfWeek }} {{ slot.start_time }} (semana del {{ weekStart | date:'dd/MM/yyyy' }})</h6>
                  <div *ngIf="getReservationsForSlot(slot.id).length === 0" class="text-muted">No hay reservas para esta franja.</div>
                  <ul *ngIf="getReservationsForSlot(slot.id).length > 0" class="list-group mb-3">
                    <li *ngFor="let r of getReservationsForSlot(slot.id)" class="list-group-item d-flex justify-content-between align-items-center">
                      <span>{{ getClientById(r.user_id)?.name ?? ('Usuario #' + r.user_id) }} ({{ getClientById(r.user_id)?.email ?? 'sin email' }})</span>
                      <button class="btn btn-sm btn-outline-danger" (click)="onRemoveReservation(r)">Quitar</button>
                    </li>
                  </ul>
                  <div class="d-flex gap-2 align-items-end">
                    <div class="flex-grow-1">
                      <label class="form-label">Añadir cliente:</label>
                      <select class="form-control" [(ngModel)]="selectedClientForSlot[slot.id]">
                        <option [ngValue]="null">-- selecciona --</option>
                        <option *ngFor="let c of getAvailableClientsForSlot(slot.id)" [ngValue]="c.id">
                          {{ c.name }} ({{ c.email }}) — cupo {{ getRemainingForClient(c) }} restante
                        </option>
                      </select>
                    </div>
                    <button class="btn btn-primary" (click)="onAddReservation(slot)" [disabled]="!selectedClientForSlot[slot.id]">Añadir</button>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        </ng-container>
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
    return this.selectedWeek === 'current' ? getThisMonday() : getNextMonday();
  }

  clients: Client[] = [];
  reservations: Reservation[] = [];
  expandedSlotId: number | null = null;
  selectedClientForSlot: Record<number, number | null> = {};

  constructor(
    private slotService: SlotService,
    private reservationService: ReservationService,
    private authService: AuthService,
    private http: HttpClient
  ) {}

  ngOnInit(): void {
    this.loadSlots();
    if (this.isAdmin()) {
      this.loadClients();
      this.loadReservations();
    }
  }

  onWeekChange(): void {
    this.loadSlots();
    if (this.isAdmin()) {
      this.loadReservations();
      this.expandedSlotId = null;
    }
  }

  isAdmin(): boolean {
    return this.authService.isAdmin();
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

  loadClients(): void {
    this.http.get<{ data: Client[] }>(`${environment.apiUrl}/api/users`).subscribe({
      next: (res) => this.clients = res.data,
      error: () => this.clients = []
    });
  }

  loadReservations(): void {
    this.reservationService.myReservations(this.weekStart).subscribe({
      next: (res) => this.reservations = res.data,
      error: () => this.reservations = []
    });
  }

  toggleInspect(slotId: number): void {
    this.expandedSlotId = this.expandedSlotId === slotId ? null : slotId;
  }

  getReservationsForSlot(slotId: number): Reservation[] {
    return this.reservations.filter(r => r.slot_id === slotId);
  }

  getClientById(userId: number): Client | undefined {
    return this.clients.find(c => c.id === userId);
  }

  getAvailableClientsForSlot(slotId: number): Client[] {
    return this.clients.filter(c => {
      if (c.role !== 'cliente') return false;
      const hasReservation = this.reservations.some(r => r.user_id === c.id && r.slot_id === slotId);
      if (hasReservation) return false;
      const used = this.reservations.filter(r => r.user_id === c.id).length;
      const remaining = c.weekly_hours - used;
      return remaining > 0;
    });
  }

  getRemainingForClient(client: Client): number {
    const used = this.reservations.filter(r => r.user_id === client.id).length;
    return client.weekly_hours - used;
  }

  onAddReservation(slot: Slot): void {
    const clientId = this.selectedClientForSlot[slot.id];
    if (!clientId) return;
    this.reservationService.create(slot.id, this.weekStart, Number(clientId)).subscribe({
      next: () => {
        this.loadReservations();
        this.loadSlots();
        this.selectedClientForSlot[slot.id] = null;
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al añadir reserva'
    });
  }

  onRemoveReservation(reservation: Reservation): void {
    this.reservationService.cancel(reservation.id).subscribe({
      next: () => {
        this.loadReservations();
        this.loadSlots();
      },
      error: (err) => this.error = err?.error?.message ?? 'Error al quitar reserva'
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
    this.reservationService.create(slot.id, this.weekStart).subscribe({
      next: () => {
        this.loadSlots();
        if (this.isAdmin()) this.loadReservations();
      },
      error: (err) => {
        const msg = err?.error?.message ?? err?.error?.errors?.slot_id?.[0] ?? 'Error al reservar';
        this.error = msg;
      }
    });
  }
}
