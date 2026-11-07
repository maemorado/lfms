import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import type { Session, User } from "@supabase/supabase-js";
import { api } from "@/lib/api";
import { supabase } from "@/lib/supabase";

type Identity = {
  id: string;
  email: string | null;
};

type AuthContextValue = {
  user: User | null;
  session: Session | null;
  identity: Identity | null;
  loading: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  refreshIdentity: () => Promise<Identity>;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null);
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!supabase) {
      setLoading(false);
      return;
    }

    let active = true;

    void supabase.auth.getSession().then(({ data, error }) => {
      if (!active) return;
      if (error) console.error("Unable to restore the Supabase session:", error.message);
      setSession(data.session);
      setLoading(false);
    });

    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, nextSession) => {
      setSession(nextSession);
      setLoading(false);
    });

    return () => {
      active = false;
      subscription.unsubscribe();
    };
  }, []);

  const refreshIdentity = async (): Promise<Identity> => {
    const response = await api.get<{ success: boolean; data: { user: Identity } }>("/auth/me");
    setIdentity(response.data.data.user);
    return response.data.data.user;
  };

  useEffect(() => {
    if (!session) {
      setIdentity(null);
      return;
    }

    let active = true;
    void api.get<{ success: boolean; data: { user: Identity } }>("/auth/me")
      .then(({ data }) => {
        if (active) setIdentity(data.data.user);
      })
      .catch(() => {
        if (active) setIdentity(null);
      });

    return () => {
      active = false;
    };
  }, [session?.access_token]);

  const value: AuthContextValue = {
    user: session?.user ?? null,
    session,
    identity,
    loading,
    signIn: async (email, password) => {
      if (!supabase) throw new Error("Supabase is not configured. Check the frontend environment variables.");
      const { error } = await supabase.auth.signInWithPassword({ email, password });
      if (error) throw error;
    },
    signOut: async () => {
      if (!supabase) throw new Error("Supabase is not configured. Check the frontend environment variables.");
      const { error } = await supabase.auth.signOut();
      if (error) throw error;
    },
    refreshIdentity,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside an AuthProvider.");
  return context;
}
