import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface Slot {
  id: number;
  day_of_week: number;
  start_time: string;
  capacity: number;
  status: 'abierta' | 'bloqueada';
  occupation?: number;
}

@Injectable({ providedIn: 'root' })
export class SlotService {
  constructor(private http: HttpClient) {}

  list(weekStart?: string): Observable<{ data: Slot[] }> {
    let params = new HttpParams();
    if (weekStart) params = params.set('week_start', weekStart);
    return this.http.get<{ data: Slot[] }>(`${environment.apiUrl}/api/slots`, { params });
  }

  create(data: Partial<Slot>): Observable<{ data: Slot }> {
    return this.http.post<{ data: Slot }>(`${environment.apiUrl}/api/slots`, data);
  }

  update(id: number, data: Partial<Slot>): Observable<{ data: Slot }> {
    return this.http.put<{ data: Slot }>(`${environment.apiUrl}/api/slots/${id}`, data);
  }

  block(id: number): Observable<{ message: string; data: Slot }> {
    return this.http.patch<{ message: string; data: Slot }>(`${environment.apiUrl}/api/slots/${id}/block`, { status: 'bloqueada' });
  }

  unblock(id: number): Observable<{ message: string; data: Slot }> {
    return this.http.patch<{ message: string; data: Slot }>(`${environment.apiUrl}/api/slots/${id}/unblock`, {});
  }

  delete(id: number): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${environment.apiUrl}/api/slots/${id}`);
  }
}
