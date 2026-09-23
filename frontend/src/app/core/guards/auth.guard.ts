import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { catchError, map, of } from 'rxjs';
import { environment } from '../../../environments/environment';

export const authGuard: CanActivateFn = () => {
  const http = inject(HttpClient);
  const router = inject(Router);
  return http.get(`${environment.apiUrl}/api/user`).pipe(
    map(() => true),
    catchError(() => {
      router.navigate(['/login']);
      return of(false);
    })
  );
};

export const adminGuard: CanActivateFn = () => {
  const http = inject(HttpClient);
  const router = inject(Router);
  return http.get<any>(`${environment.apiUrl}/api/user`).pipe(
    map(user => {
      if (user.role === 'administrador') return true;
      router.navigate(['/']);
      return false;
    }),
    catchError(() => {
      router.navigate(['/login']);
      return of(false);
    })
  );
};
