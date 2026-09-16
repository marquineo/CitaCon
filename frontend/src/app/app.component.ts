import { Component } from '@angular/core';
import { RouterOutlet, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, CommonModule],
  template: `
    <nav style="padding: 0.75rem; border-bottom: 1px solid #ddd; display: flex; gap: 1rem;">
      <a routerLink="/">Franjas</a>
      <a routerLink="/my-reservations">Mis reservas</a>
      <a routerLink="/login">Login</a>
      <a routerLink="/admin/slots">Admin Slots</a>
      <a routerLink="/admin/quota">Admin Quota</a>
    </nav>
    <main style="padding: 1rem;">
      <router-outlet></router-outlet>
    </main>
  `
})
export class AppComponent {
  title = 'frontend';
}
