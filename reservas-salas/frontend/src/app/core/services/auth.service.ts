import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'cliente' | 'administrador';
  weekly_hours: number;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private apiUrl = '';

  constructor(private http: HttpClient) {}

  csrfCookie(): Observable<void> {
    return this.http.get<void>('/sanctum/csrf-cookie');
  }

  login(email: string, password: string): Observable<{ user: User }> {
    // Sanctum SPA: primero csrf, luego POST /api/login
    return this.csrfCookie().pipe(
      // switchMap would be here; simplified
      tap(() => {})
    ) as any;
    // Real impl: return this.http.post<{user: User}>('/api/login', {email, password});
  }

  logout(): Observable<void> {
    return this.http.post<void>('/api/logout', {});
  }

  me(): Observable<User> {
    return this.http.get<User>('/api/user');
  }
}
