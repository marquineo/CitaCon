import { Routes } from '@angular/router';
import { LoginComponent } from './features/auth/login.component';
import { SlotListComponent } from './features/slots/slot-list.component';
import { SlotAdminComponent } from './features/slots/slot-admin.component';
import { AdminQuotaComponent } from './features/admin/admin-quota.component';
import { MyReservationsComponent } from './features/reservations/my-reservations.component';
import { authGuard, adminGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  { path: 'login', component: LoginComponent },
  { path: '', component: SlotListComponent, canActivate: [authGuard] },
  { path: 'admin/slots', component: SlotAdminComponent, canActivate: [adminGuard] },
  { path: 'admin/quota', component: AdminQuotaComponent, canActivate: [adminGuard] },
  { path: 'my-reservations', component: MyReservationsComponent, canActivate: [authGuard] },
];
