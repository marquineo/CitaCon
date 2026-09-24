import { Component } from '@angular/core';
import { RouterOutlet, RouterLink, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { AuthService } from './core/services/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, CommonModule],
  template: `
    <nav class="navbar navbar-expand navbar-dark bg-dark">
      <div class="container">
        <a class="navbar-brand" routerLink="/">CitaCon</a>
        <div class="navbar-nav">
          <ng-container *ngIf="isLoggedIn()">
            <a class="nav-link" routerLink="/">Franjas</a>
            <a class="nav-link" routerLink="/my-reservations">Mis reservas</a>
            <a *ngIf="isAdmin()" class="nav-link" routerLink="/admin/slots">Gestión de Franjas</a>
            <a *ngIf="isAdmin()" class="nav-link" routerLink="/admin/quota">Admin Quota</a>
            <button class="nav-link btn btn-link" (click)="onLogout()">Cerrar sesión</button>
          </ng-container>
          <ng-container *ngIf="!isLoggedIn()">
            <a class="nav-link" routerLink="/login">Login</a>
          </ng-container>
        </div>
      </div>
    </nav>
    <div class="container mt-4">
      <router-outlet></router-outlet>
    </div>
  `
})
export class AppComponent {
  title = 'frontend';

  constructor(public authService: AuthService, private router: Router) {}

  isLoggedIn(): boolean {
    return !!this.authService.getToken();
  }

  isAdmin(): boolean {
    return this.authService.isAdmin();
  }

  onLogout(): void {
    this.authService.logout().subscribe(() => this.router.navigate(['/login']));
  }
}
