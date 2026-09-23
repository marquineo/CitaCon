import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface Reservation {
  id: number;
  user_id: number;
  slot_id: number;
  week_start: string;
  status: 'confirmada';
  created_at: string;
}

export interface Quota {
  week_start: string;
  assigned: number;
  used: number;
  remaining: number;
}

@Injectable({ providedIn: 'root' })
export class ReservationService {
  constructor(private http: HttpClient) {}

  myReservations(weekStart?: string): Observable<{ data: Reservation[] }> {
    let params = new HttpParams();
    if (weekStart) params = params.set('week_start', weekStart);
    return this.http.get<{ data: Reservation[] }>(`${environment.apiUrl}/api/reservations`, { params });
  }

  create(slotId: number, weekStart: string, userId?: number): Observable<{ data: Reservation }> {
    const body: any = { slot_id: slotId, week_start: weekStart };
    if (userId) body.user_id = userId; // solo admin
    return this.http.post<{ data: Reservation }>(`${environment.apiUrl}/api/reservations`, body);
  }

  cancel(id: number): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${environment.apiUrl}/api/reservations/${id}`);
  }

  quota(weekStart?: string): Observable<{ data: Quota }> {
    let params = new HttpParams();
    if (weekStart) params = params.set('week_start', weekStart);
    return this.http.get<{ data: Quota }>(`${environment.apiUrl}/api/users/me/quota`, { params });
  }
}
