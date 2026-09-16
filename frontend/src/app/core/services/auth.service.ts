import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, switchMap, map } from 'rxjs';

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'cliente' | 'administrador';
  weekly_hours: number;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: 'cliente' | 'administrador';
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  constructor(private http: HttpClient) {}

  csrfCookie(): Observable<void> {
    return this.http.get<void>('/sanctum/csrf-cookie');
  }

  login(email: string, password: string): Observable<{ data: AuthUser }> {
    return this.csrfCookie().pipe(
      switchMap(() => this.http.post<{ data: AuthUser }>('/api/login', { email, password }))
    );
  }

  logout(): Observable<void> {
    return this.http.post<void>('/api/logout', {});
  }

  me(): Observable<User> {
    return this.http.get<{ data: User }>('/api/user').pipe(
      map(res => res.data)
    );
  }
}
