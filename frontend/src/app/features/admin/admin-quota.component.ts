import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../environments/environment';
import { ReservationService, Reservation } from '../../core/services/reservation.service';
import { thisMonday as getThisMonday, nextMonday as getNextMonday } from '../../core/utils/week.util';

interface Client {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  role: string;
  weekly_hours: number;
  _editValue?: number;
  _error?: string | null;
  _saving?: boolean;
}

/**
 * T044 (alcance reducido): Gestión de cupos semanales por cliente para admin.
 * - Lista clientes vía GET /api/users (solo admin, lectura)
 * - Muestra weekly_hours actuales e input para modificar vía PATCH /api/users/{id}/weekly-hours
 * - Muestra mensaje de error tal cual devuelve la API (422) sin inventar validación propia
 * - Capacidad de franjas ya existe en slot-admin.component.ts, no se duplica aquí
 */
@Component({
  selector: 'app-admin-quota',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <h2 class="h4 mb-2">Gestión de cupos semanales (Admin)</h2>
    <p class="text-muted">Solo para administradores — el backend valida con UserPolicy.</p>

    <div class="btn-group mb-3" role="group">
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'current'" (click)="selectedWeek = 'current'; onWeekChange()">Esta semana</button>
      <button type="button" class="btn btn-outline-primary" [class.active]="selectedWeek === 'next'" (click)="selectedWeek = 'next'; onWeekChange()">Semana siguiente</button>
    </div>
    <span class="text-muted ms-3">Semana del {{ weekStart | date:'dd/MM/yyyy' }}</span>

    <div class="card mb-4">
      <div class="card-body">
        <h3 class="h5 card-title">Crear cliente</h3>
        <form (ngSubmit)="onCreateClient()">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Nombre:</label>
              <input type="text" class="form-control" [(ngModel)]="newClient.name" name="newName" required />
            </div>
            <div class="col-md-4">
              <label class="form-label">Email:</label>
              <input type="email" class="form-control" [(ngModel)]="newClient.email" name="newEmail" required />
            </div>
            <div class="col-md-4">
              <label class="form-label">Teléfono (opcional):</label>
              <input type="text" class="form-control" [(ngModel)]="newClient.phone" name="newPhone" maxlength="20" placeholder="600123456" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Contraseña:</label>
              <input type="password" class="form-control" [(ngModel)]="newClient.password" name="newPassword" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Horas semanales:</label>
              <input type="number" class="form-control" [(ngModel)]="newClient.weekly_hours" name="newHours" min="0" max="50" required />
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-3" [disabled]="creating">{{ creating ? 'Creando...' : 'Crear cliente' }}</button>
        </form>
        <div *ngIf="createError" class="alert alert-danger mt-2">{{ createError }}</div>
        <div *ngIf="createSuccess" class="alert alert-success mt-2">{{ createSuccess }}</div>
      </div>
    </div>

    <p *ngIf="loading" class="text-muted">Cargando clientes...</p>
    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>

    <table *ngIf="!loading && clients.length > 0" class="table table-striped">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Teléfono</th>
          <th>Horas actuales</th>
          <th>Usadas esta semana</th>
          <th>Restantes</th>
          <th>Nuevo cupo</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let client of clients">
          <td>{{ client.name }}</td>
          <td>{{ client.email }}</td>
          <td>{{ client.phone ?? '—' }}</td>
          <td><span class="badge bg-primary">{{ client.weekly_hours }}</span></td>
          <td><span class="badge bg-secondary">{{ getUsedForClient(client.id) }}</span></td>
          <td><span class="badge" [ngClass]="getRemainingForClient(client) < 0 ? 'bg-danger' : 'bg-success'">{{ getRemainingForClient(client) }}</span></td>
          <td>
            <input type="number" class="form-control form-control-sm" [(ngModel)]="client._editValue" [attr.min]="0" [attr.max]="50" style="width: 80px;" />
            <div *ngIf="client._error" class="alert alert-danger mt-1 p-1 small">{{ client._error }}</div>
          </td>
          <td>
            <button class="btn btn-sm btn-primary" (click)="onSave(client)" [disabled]="client._saving">
              {{ client._saving ? 'Guardando...' : 'Guardar' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <p *ngIf="!loading && clients.length === 0" class="text-muted">No hay clientes.</p>
  `,
  styles: []
})
export class AdminQuotaComponent implements OnInit {
  clients: Client[] = [];
  reservations: Reservation[] = [];
  loading = true;
  error: string | null = null;

  selectedWeek: 'current' | 'next' = 'current';
  get weekStart(): string {
    return this.selectedWeek === 'current' ? getThisMonday() : getNextMonday();
  }

  newClient = {
    name: '',
    email: '',
    password: '',
    weekly_hours: 3,
    phone: ''
  };
  creating = false;
  createError: string | null = null;
  createSuccess: string | null = null;

  constructor(private http: HttpClient, private reservationService: ReservationService) {}

  ngOnInit(): void {
    this.loadClients();
    this.loadReservations();
  }

  onWeekChange(): void {
    this.loadReservations();
  }

  loadClients(): void {
    this.loading = true;
    this.error = null;
    this.http.get<{ data: Client[] }>(`${environment.apiUrl}/api/users`).subscribe({
      next: (res) => {
        this.clients = res.data.map(c => ({ ...c, _editValue: c.weekly_hours, _error: null, _saving: false }));
        this.loading = false;
      },
      error: (err) => {
        // Mostrar mensaje tal cual devuelve la API (ej. 403 si no es admin)
        this.error = err?.error?.message ?? 'Error al cargar clientes';
        this.loading = false;
      }
    });
  }

  loadReservations(): void {
    this.reservationService.myReservations(this.weekStart).subscribe({
      next: (res) => this.reservations = res.data,
      error: () => this.reservations = []
    });
  }

  getUsedForClient(clientId: number): number {
    return this.reservations.filter(r => r.user_id === clientId).length;
  }

  getRemainingForClient(client: Client): number {
    return client.weekly_hours - this.getUsedForClient(client.id);
  }

  onCreateClient(): void {
    this.createError = null;
    this.createSuccess = null;
    this.creating = true;

    const payload: any = {
      name: this.newClient.name,
      email: this.newClient.email,
      password: this.newClient.password,
      weekly_hours: this.newClient.weekly_hours
    };
    const phoneTrimmed = this.newClient.phone?.trim();
    if (phoneTrimmed) {
      payload.phone = phoneTrimmed;
    }

    this.http.post<{ data: Client }>(`${environment.apiUrl}/api/users`, payload).subscribe({
      next: () => {
        this.createSuccess = 'Cliente creado';
        this.creating = false;
        this.newClient = { name: '', email: '', password: '', weekly_hours: 3, phone: '' };
        this.loadClients();
        this.loadReservations();
      },
      error: (err) => {
        const apiMessage = err?.error?.message;
        const validationMsg = err?.error?.errors ? Object.values(err.error.errors).flat().join(' ') as string : null;
        const phoneMsg = err?.error?.errors?.phone?.[0];
        this.createError = phoneMsg ?? validationMsg ?? apiMessage ?? 'Error al crear cliente';
        this.creating = false;
      }
    });
  }

  onSave(client: Client): void {
    client._error = null;
    client._saving = true;
    const value = client._editValue;

    // No validación propia en Angular — se envía tal cual y se muestra el error de la API si es 422
    this.http.patch<{ data: Client }>(`${environment.apiUrl}/api/users/${client.id}/weekly-hours`, { weekly_hours: value }).subscribe({
      next: (res) => {
        client.weekly_hours = res.data.weekly_hours;
        client._error = null;
        client._saving = false;
      },
      error: (err) => {
        // Mostrar mensaje tal cual devuelve la API (422 con errors.weekly_hours)
        const apiMessage = err?.error?.message;
        const validationMsg = err?.error?.errors?.weekly_hours?.[0];
        client._error = validationMsg ?? apiMessage ?? 'Error al guardar';
        client._saving = false;
      }
    });
  }
}
