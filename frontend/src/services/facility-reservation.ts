import { api } from "@/lib/api";

export type Pagination = { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
type Envelope<T> = { success: boolean; message: string; data: T; meta?: { pagination?: Pagination } };
export type Facility = {
  id: string; building_id: string; facility_type_id: string; name: string; code: string; capacity: number;
  description: string | null; status: "available" | "unavailable" | "maintenance" | "inactive";
  building: { id: string; name: string; location: string | null };
  facility_type: { id: string; name: string };
  features?: { id: string; name: string; description: string | null }[];
  created_at: string; updated_at: string;
};
export type FacilityLookups = {
  buildings: { id: string; name: string; location: string | null }[];
  facility_types: { id: string; name: string; description: string | null }[];
  features: { id: string; name: string; description: string | null }[];
  statuses: Facility["status"][];
};
export type Reservation = {
  id: string; facility_id: string; requester_id: string; start_at: string; end_at: string; purpose: string;
  subject_course: string | null; participant_count: number; status: "pending" | "approved" | "rejected" | "cancelled" | "completed";
  cancellation_reason: string | null; facility: { id: string; name: string; code: string; status: string };
  requester: { id: string; full_name: string };
  approvals?: { decision: string; remarks: string | null; reviewed_at: string; reviewer_name: string }[];
};
export type ReservationInput = { facility_id: string; start_at: string; end_at: string; purpose: string; subject_course?: string | null; participant_count: number };
export type ReservationFilters = { page: number; per_page: number; status?: string; facility_id?: string; from_date?: string; to_date?: string; requester_id?: string; search?: string };
export function getApiError(error: unknown): string {
  const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
  const errors = value.response?.data?.errors;
  if (errors) return Object.values(errors).flat()[0] ?? "Check the submitted information.";
  return value.response?.data?.message ?? value.message ?? "The request could not be completed.";
}
export async function listFacilities(params: Record<string, unknown> = {}) {
  const response = await api.get<Envelope<Facility[]>>("/v1/facilities", { params });
  return { items: response.data.data, pagination: response.data.meta?.pagination };
}
export async function getFacility(id: string) {
  const response = await api.get<Envelope<{ facility: Facility }>>(`/v1/facilities/${id}`);
  return response.data.data.facility;
}
export async function checkFacilityAvailability(id: string, start_at: string, end_at: string) {
  const response = await api.get<Envelope<{ facility_id: string; facility_status: string; is_available: boolean; has_approved_conflict: boolean; capacity: number; checked_start_at: string; checked_end_at: string }>>(`/v1/facilities/${id}/availability`, { params: { start_at, end_at } });
  return response.data.data;
}
export async function getFacilityLookups() {
  const response = await api.get<Envelope<FacilityLookups>>("/v1/facilities/lookups");
  return response.data.data;
}
export async function createFacility(input: Partial<Facility> & Pick<Facility,"building_id"|"facility_type_id"|"name"|"code">) {
  const response = await api.post<Envelope<{ facility: Facility }>>("/v1/facilities", input);
  return response.data.data.facility;
}
export async function updateFacility(id: string, input: Partial<Facility>) {
  const response = await api.patch<Envelope<{ facility: Facility }>>(`/v1/facilities/${id}`, input);
  return response.data.data.facility;
}
export async function listReservations(params: ReservationFilters) {
  const response = await api.get<Envelope<Reservation[]>>("/v1/reservations", { params });
  return { items: response.data.data, pagination: response.data.meta?.pagination };
}
export async function getReservation(id: string) {
  const response = await api.get<Envelope<{ reservation: Reservation }>>(`/v1/reservations/${id}`);
  return response.data.data.reservation;
}
export async function createReservation(input: ReservationInput) {
  const response = await api.post<Envelope<{ reservation: Reservation }>>("/v1/reservations", input);
  return response.data.data.reservation;
}
export async function updateReservation(id: string, input: Partial<ReservationInput>) {
  const response = await api.patch<Envelope<{ reservation: Reservation }>>(`/v1/reservations/${id}`, input);
  return response.data.data.reservation;
}
export async function cancelReservation(id: string, cancellation_reason: string) {
  const response = await api.patch<Envelope<{ reservation: Reservation }>>(`/v1/reservations/${id}/cancel`, { cancellation_reason });
  return response.data.data.reservation;
}
export async function decideReservation(id: string, decision: "approved" | "rejected", remarks?: string) {
  const response = await api.patch<Envelope<{ reservation: Reservation }>>(`/v1/reservations/${id}/decision`, { decision, remarks });
  return response.data.data.reservation;
}
