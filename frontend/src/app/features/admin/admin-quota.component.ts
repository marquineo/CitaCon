import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';

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
    <h2>Gestión de cupos semanales (Admin)</h2>
    <p class="hint">Solo para administradores — el backend valida con UserPolicy.</p>

    <p *ngIf="loading">Cargando clientes...</p>
    <p *ngIf="error" class="error">{{ error }}</p>

    <ul *ngIf="!loading" class="client-list">
      <li *ngFor="let client of clients" class="client-row">
        <span class="client-info">
          {{ client.name }} ({{ client.email }}) — Horas actuales: <strong>{{ client.weekly_hours }}</strong>
        </span>
        <label>
          Nuevo cupo:
          <input type="number" [(ngModel)]="client._editValue" [attr.min]="0" [attr.max]="50" />
        </label>
        <button (click)="onSave(client)" [disabled]="client._saving">
          {{ client._saving ? 'Guardando...' : 'Guardar' }}
        </button>
        <span *ngIf="client._error" class="error">{{ client._error }}</span>
      </li>
    </ul>
    <p *ngIf="!loading && clients.length === 0">No hay clientes.</p>
  `,
  styles: [`
    .client-list { list-style: none; padding: 0; }
    .client-row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin: 0.75rem 0; padding: 0.5rem; border: 1px solid #eee; }
    .client-info { min-width: 250px; }
    input[type=number] { width: 80px; }
    .error { color: #b00020; white-space: pre-wrap; font-size: 0.9rem; }
    .hint { font-size: 0.9rem; color: #666; }
  `]
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
    this.http.get<{ data: Client[] }>('/api/users').subscribe({
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
    this.http.patch<{ data: Client }>(`/api/users/${client.id}/weekly-hours`, { weekly_hours: value }).subscribe({
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
