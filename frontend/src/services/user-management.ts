import { api } from "@/lib/api";

export type LfmsUser = {
  id: string;
  full_name: string;
  email: string;
  employee_id: string | null;
  status: "active" | "inactive" | "suspended";
  roles: string[];
  created_at: string;
  updated_at: string;
};
export type LfmsRole = { name: "admin" | "staff_sa"; description: string | null };
export type Pagination = { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
type ApiEnvelope<T> = { success: boolean; message: string; data: T; meta?: { pagination?: Pagination } };

export async function listUsers(params: { page: number; per_page: number; search?: string; role?: string; status?: string }) {
  const response = await api.get<ApiEnvelope<LfmsUser[]>>("/v1/users", { params });
  return { users: response.data.data, pagination: response.data.meta?.pagination };
}
export async function getAssignableRoles() {
  const response = await api.get<ApiEnvelope<{ roles: LfmsRole[] }>>("/v1/users/roles");
  return response.data.data.roles;
}
export async function createUser(input: { full_name: string; email: string; employee_id?: string; role: string }) {
  const response = await api.post<ApiEnvelope<{ user: LfmsUser }>>("/v1/users", input);
  return response.data.data.user;
}
export async function updateUser(id: string, input: { full_name?: string; employee_id?: string | null; role?: string }) {
  const response = await api.patch<ApiEnvelope<{ user: LfmsUser }>>(`/v1/users/${id}`, input);
  return response.data.data.user;
}
export async function updateUserStatus(id: string, status: "active" | "inactive") {
  const response = await api.patch<ApiEnvelope<{ user: LfmsUser }>>(`/v1/users/${id}/status`, { status });
  return response.data.data.user;
}
export async function getOwnProfile() {
  const response = await api.get<ApiEnvelope<{ profile: { id: string; full_name: string; email: string; employee_id: string | null; status: string; created_at: string; updated_at: string } }>>("/v1/profile");
  return response.data.data.profile;
}
export async function updateOwnProfile(input: { full_name?: string; employee_id?: string | null }) {
  const response = await api.patch<ApiEnvelope<{ profile: Awaited<ReturnType<typeof getOwnProfile>> }>>("/v1/profile", input);
  return response.data.data.profile;
}
export function getApiError(error: unknown): string {
  const candidate = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
  const validation = candidate.response?.data?.errors;
  if (validation) return Object.values(validation).flat()[0] ?? "Please check the submitted information.";
  return candidate.response?.data?.message ?? candidate.message ?? "The request could not be completed.";
}
