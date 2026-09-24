import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../environments/environment';

interface Client {
  id: number;
  name: string;
  email: string;
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

    <p *ngIf="loading" class="text-muted">Cargando clientes...</p>
    <div *ngIf="error" class="alert alert-danger">{{ error }}</div>

    <table *ngIf="!loading && clients.length > 0" class="table table-striped">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Horas actuales</th>
          <th>Nuevo cupo</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <tr *ngFor="let client of clients">
          <td>{{ client.name }}</td>
          <td>{{ client.email }}</td>
          <td><span class="badge bg-primary">{{ client.weekly_hours }}</span></td>
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
  loading = true;
  error: string | null = null;

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.loadClients();
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
