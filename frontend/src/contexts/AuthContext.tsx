import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import type { Session, User } from "@supabase/supabase-js";
import { api } from "@/lib/api";
import { supabase } from "@/lib/supabase";

export type AuthIdentity = { id: string; email: string | null };
export type LfmsProfile = { id: string; full_name: string; email: string; employee_id: string | null; status: string };
type IdentityStatus = "idle" | "loading" | "ready" | "error";
type AccessStatus = "idle" | "loading" | "ready" | "unassigned" | "error";
type AuthContextValue = {
  user: User | null; session: Session | null; identity: AuthIdentity | null; profile: LfmsProfile | null;
  roles: string[]; permissions: string[]; loading: boolean; identityStatus: IdentityStatus;
  accessStatus: AccessStatus; identityError: string | null; isAuthenticated: boolean;
  hasRole: (role: string) => boolean; can: (permission: string) => boolean;
  signIn: (email: string, password: string) => Promise<void>; signOut: () => Promise<void>;
  refreshIdentity: () => Promise<AuthIdentity>;
};
type MeResponse = { success: boolean; data?: { user?: AuthIdentity | null } };
type AccessResponse = { success: boolean; data?: { profile: LfmsProfile; roles: string[]; permissions: string[] } };
const AuthContext = createContext<AuthContextValue | undefined>(undefined);
const errorMessage = (error: unknown) => error instanceof Error ? error.message : "Unable to verify your LFMS account.";

async function fetchIdentity(): Promise<AuthIdentity> {
  const response = await api.get<MeResponse>("/auth/me");
  const value = response.data?.data?.user;
  if (!value || typeof value.id !== "string") throw new Error("The LFMS API did not return a valid identity.");
  return { id: value.id, email: typeof value.email === "string" ? value.email : null };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null);
  const [identity, setIdentity] = useState<AuthIdentity | null>(null);
  const [profile, setProfile] = useState<LfmsProfile | null>(null);
  const [roles, setRoles] = useState<string[]>([]);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [identityStatus, setIdentityStatus] = useState<IdentityStatus>("idle");
  const [accessStatus, setAccessStatus] = useState<AccessStatus>("idle");
  const [identityError, setIdentityError] = useState<string | null>(null);
  const authEvent = useRef(false);
  const requestId = useRef(0);

  const loadAccess = useCallback(async () => {
    setAccessStatus("loading");
    setProfile(null); setRoles([]); setPermissions([]);
    try {
      const response = await api.get<AccessResponse>("/auth/access");
      const access = response.data?.data;
      if (!access || !Array.isArray(access.roles) || !Array.isArray(access.permissions)) throw new Error("Invalid authorization response.");
      setProfile(access.profile); setRoles(access.roles); setPermissions(access.permissions); setAccessStatus("ready");
    } catch (error) {
      const response = (error as { response?: { status?: number; data?: { code?: string } } })?.response;
      if (response?.status === 403 && response.data?.code === "role_not_assigned") { setAccessStatus("unassigned"); return; }
      setAccessStatus("error");
      throw error;
    }
  }, []);

  useEffect(() => {
    if (!supabase) { setLoading(false); return; }
    let active = true;
    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, next) => {
      authEvent.current = true; setSession(next); setLoading(false);
    });
    void supabase.auth.getSession().then(({ data, error }) => {
      if (!active || authEvent.current) return;
      setSession(error ? null : data.session); setLoading(false);
    }).catch(() => { if (active && !authEvent.current) { setSession(null); setLoading(false); } });
    return () => { active = false; subscription.unsubscribe(); };
  }, []);

  useEffect(() => {
    const id = ++requestId.current;
    let active = true;
    if (!session) {
      setIdentity(null); setProfile(null); setRoles([]); setPermissions([]);
      setIdentityError(null); setIdentityStatus("idle"); setAccessStatus("idle");
      return () => { active = false; };
    }
    setIdentity(null); setIdentityError(null); setIdentityStatus("loading"); setAccessStatus("loading");
    void fetchIdentity().then(async (next) => {
      if (!active || requestId.current !== id) return;
      setIdentity(next); setIdentityStatus("ready");
      try { await loadAccess(); } catch { if (active && requestId.current === id) setAccessStatus("error"); }
    }).catch((error: unknown) => {
      if (!active || requestId.current !== id) return;
      setIdentity(null); setIdentityError(errorMessage(error)); setIdentityStatus("error"); setAccessStatus("error");
    });
    return () => { active = false; };
  }, [session?.access_token, session, loadAccess]);

  const refreshIdentity = useCallback(async () => {
    const id = ++requestId.current;
    setIdentity(null); setIdentityError(null); setIdentityStatus("loading");
    try {
      const next = await fetchIdentity();
      if (requestId.current === id) { setIdentity(next); setIdentityStatus("ready"); await loadAccess(); }
      return next;
    } catch (error) {
      if (requestId.current === id) { setIdentityError(errorMessage(error)); setIdentityStatus("error"); }
      throw error;
    }
  }, [loadAccess]);

  const signIn = useCallback(async (email: string, password: string) => {
    if (!supabase) throw new Error("Supabase is not configured.");
    const { error } = await supabase.auth.signInWithPassword({ email: email.trim(), password });
    if (error) throw error;
  }, []);
  const signOut = useCallback(async () => {
    if (!supabase) throw new Error("Supabase is not configured.");
    const { error } = await supabase.auth.signOut({ scope: "local" });
    if (error) throw error;
  }, []);

  const value = useMemo<AuthContextValue>(() => ({
    user: session?.user ?? null, session, identity, profile, roles, permissions, loading,
    identityStatus, accessStatus, identityError,
    isAuthenticated: Boolean(session && identity && identityStatus === "ready"),
    hasRole: (role) => roles.includes(role), can: (permission) => permissions.includes(permission),
    signIn, signOut, refreshIdentity,
  }), [session, identity, profile, roles, permissions, loading, identityStatus, accessStatus, identityError, signIn, signOut, refreshIdentity]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside AuthProvider.");
  return value;
}
