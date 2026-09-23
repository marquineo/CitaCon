import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { catchError, map, of } from 'rxjs';
import { environment } from '../../../environments/environment';

export const authGuard: CanActivateFn = () => {
  const router = inject(Router);
  const token = localStorage.getItem('citacon_token');
  if (!token) {
    router.navigate(['/login']);
    return of(false);
  }

  const http = inject(HttpClient);
  return http.get(`${environment.apiUrl}/api/user`).pipe(
    map(() => true),
    catchError(() => {
      router.navigate(['/login']);
      return of(false);
    })
  );
};

export const adminGuard: CanActivateFn = () => {
  const router = inject(Router);
  const token = localStorage.getItem('citacon_token');
  if (!token) {
    router.navigate(['/login']);
    return of(false);
  }

  const http = inject(HttpClient);
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
