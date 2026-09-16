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
    <h2>Iniciar sesión</h2>

    <form (ngSubmit)="onSubmit()" #loginForm="ngForm">
      <label>
        Email:
        <input type="email" [(ngModel)]="email" name="email" required />
      </label>

      <label>
        Password:
        <input type="password" [(ngModel)]="password" name="password" required />
      </label>

      <button type="submit" [disabled]="!email || !password || loading">
        {{ loading ? 'Entrando...' : 'Entrar' }}
      </button>
    </form>

    <p *ngIf="errorMessage" class="error">{{ errorMessage }}</p>
  `,
  styles: [`
    form { display: flex; flex-direction: column; gap: 0.75rem; max-width: 320px; }
    label { display: flex; flex-direction: column; font-size: 0.9rem; }
    input { padding: 0.4rem; }
    button[disabled] { opacity: 0.5; }
    .error { color: #b00020; white-space: pre-wrap; margin-top: 0.75rem; }
  `]
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
