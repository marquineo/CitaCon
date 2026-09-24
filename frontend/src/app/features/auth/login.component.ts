import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-4">
        <div class="card">
          <div class="card-body">
            <h2 class="card-title h4 mb-3">Iniciar sesión</h2>
            <form (ngSubmit)="onSubmit()" #loginForm="ngForm">
              <div class="mb-3">
                <label class="form-label">Email:</label>
                <input type="email" class="form-control" [(ngModel)]="email" name="email" required />
              </div>
              <div class="mb-3">
                <label class="form-label">Password:</label>
                <input type="password" class="form-control" [(ngModel)]="password" name="password" required />
              </div>
              <button type="submit" class="btn btn-primary w-100" [disabled]="!email || !password || loading">
                {{ loading ? 'Entrando...' : 'Entrar' }}
              </button>
            </form>
            <div *ngIf="errorMessage" class="alert alert-danger mt-3">{{ errorMessage }}</div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: []
})
export class LoginComponent {
  email = '';
  password = '';
  loading = false;
  errorMessage: string | null = null;

  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  onSubmit(): void {
    if (!this.email || !this.password) return;
    this.loading = true;
    this.errorMessage = null;

    this.authService.login(this.email, this.password).subscribe({
      next: () => {
        this.loading = false;
        this.router.navigate(['/']);
      },
      error: (err) => {
        this.loading = false;
        // Mostrar mensaje exacto de la API (401 Credenciales incorrectas, 422 validación), no inventar
        const apiMessage = err?.error?.message;
        const validationMsg = err?.error?.errors ? Object.values(err.error.errors).flat().join(' ') : null;
        this.errorMessage = apiMessage ?? validationMsg ?? 'Error al iniciar sesión';
        // Para 422, si viene errors.email/password, mostrar el primero
        if (err?.error?.errors?.email) {
          this.errorMessage = err.error.errors.email[0];
        } else if (err?.error?.errors?.password) {
          this.errorMessage = err.error.errors.password[0];
        }
      }
    });
  }
}
