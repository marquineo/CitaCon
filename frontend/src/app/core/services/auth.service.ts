import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map, tap, finalize } from 'rxjs';
import { environment } from '../../../environments/environment';

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

export interface LoginResponse {
  message: string;
  token: string;
  data: AuthUser;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly TOKEN_KEY = 'citacon_token';

  constructor(private http: HttpClient) {}

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${environment.apiUrl}/api/login`, { email, password })
      .pipe(
        tap(res => {
          if (res?.token) {
            localStorage.setItem(this.TOKEN_KEY, res.token);
          }
        })
      );
  }

  logout(): Observable<void> {
    return this.http.post<void>(`${environment.apiUrl}/api/logout`, {}).pipe(
      finalize(() => {
        localStorage.removeItem(this.TOKEN_KEY);
      })
    );
  }

  getToken(): string | null {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  me(): Observable<User> {
    return this.http.get<{ data: User }>(`${environment.apiUrl}/api/user`).pipe(
      map(res => res.data)
    );
  }
}
