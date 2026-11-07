import axios, { type InternalAxiosRequestConfig } from "axios";
import { supabase } from "@/lib/supabase";

type RetryableRequestConfig = InternalAxiosRequestConfig & {
  _authRetry?: boolean;
};

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: { Accept: "application/json", "Content-Type": "application/json" },
  timeout: 15000,
});

api.interceptors.request.use(async (config) => {
  if (!supabase) return config;

  const { data, error } = await supabase.auth.getSession();
  if (error) throw error;

  const accessToken = data.session?.access_token;
  if (accessToken) {
    config.headers.Authorization = `Bearer ${accessToken}`;
  } else {
    delete config.headers.Authorization;
  }

  return config;
});

async function clearLocalSession(): Promise<void> {
  if (!supabase) return;

  try {
    await supabase.auth.signOut({ scope: "local" });
  } catch {
    // Preserve the original API error; the auth state listener will sync on success.
  }
}

api.interceptors.response.use(
  (response) => response,
  async (error: unknown) => {
    if (!axios.isAxiosError(error) || error.response?.status !== 401 || !supabase) {
      return Promise.reject(error);
    }

    const config = error.config as RetryableRequestConfig | undefined;
    if (!config) return Promise.reject(error);

    if (config._authRetry) {
      await clearLocalSession();
      return Promise.reject(error);
    }

    config._authRetry = true;

    const {
      data: { session },
      error: sessionError,
    } = await supabase.auth.getSession();

    if (sessionError || !session) {
      return Promise.reject(error);
    }

    const { data, error: refreshError } = await supabase.auth.refreshSession();

    if (refreshError) {
      if (refreshError.status === 400) await clearLocalSession();
      return Promise.reject(error);
    }

    if (!data.session?.access_token) {
      await clearLocalSession();
      return Promise.reject(error);
    }

    config.headers.Authorization = `Bearer ${data.session.access_token}`;
    return api.request(config);
  },
);
