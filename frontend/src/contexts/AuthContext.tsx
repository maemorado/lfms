import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import type { Session, User } from "@supabase/supabase-js";
import { api } from "@/lib/api";
import { supabase } from "@/lib/supabase";

export type AuthIdentity = {
  id: string;
  email: string | null;
};

type IdentityStatus = "idle" | "loading" | "ready" | "error";

type AuthContextValue = {
  user: User | null;
  session: Session | null;
  identity: AuthIdentity | null;
  loading: boolean;
  identityStatus: IdentityStatus;
  identityError: string | null;
  isAuthenticated: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  refreshIdentity: () => Promise<AuthIdentity>;
};

type AuthMeResponse = {
  success: boolean;
  data?: {
    user?: AuthIdentity | null;
  };
  message?: string;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

function getErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message) return error.message;
  return "Unable to verify your LFMS account. Please try again.";
}

async function fetchIdentity(): Promise<AuthIdentity> {
  const response = await api.get<AuthMeResponse>("/auth/me");
  const identity = response.data?.data?.user;

  if (!identity || typeof identity.id !== "string") {
    throw new Error("The LFMS API did not return a valid authenticated user.");
  }

  return {
    id: identity.id,
    email: typeof identity.email === "string" ? identity.email : null,
  };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null);
  const [identity, setIdentity] = useState<AuthIdentity | null>(null);
  const [loading, setLoading] = useState(true);
  const [identityStatus, setIdentityStatus] = useState<IdentityStatus>("idle");
  const [identityError, setIdentityError] = useState<string | null>(null);
  const hasReceivedAuthEvent = useRef(false);
  const identityRequestId = useRef(0);

  useEffect(() => {
    if (!supabase) {
      setLoading(false);
      return;
    }

    let active = true;

    const {
      data: { subscription },
    } = supabase.auth.onAuthStateChange((_event, nextSession) => {
      hasReceivedAuthEvent.current = true;
      setSession(nextSession);
      setLoading(false);
    });

    void supabase.auth
      .getSession()
      .then(({ data, error }) => {
        if (!active || hasReceivedAuthEvent.current) return;

        if (error) {
          setSession(null);
        } else {
          setSession(data.session);
        }

        setLoading(false);
      })
      .catch(() => {
        if (!active || hasReceivedAuthEvent.current) return;
        setSession(null);
        setLoading(false);
      });

    return () => {
      active = false;
      subscription.unsubscribe();
    };
  }, []);

  useEffect(() => {
    const requestId = ++identityRequestId.current;
    let active = true;

    if (!session) {
      setIdentity(null);
      setIdentityError(null);
      setIdentityStatus("idle");
      return () => {
        active = false;
      };
    }

    setIdentity(null);
    setIdentityError(null);
    setIdentityStatus("loading");

    void fetchIdentity()
      .then((nextIdentity) => {
        if (!active || identityRequestId.current !== requestId) return;
        setIdentity(nextIdentity);
        setIdentityStatus("ready");
      })
      .catch((error: unknown) => {
        if (!active || identityRequestId.current !== requestId) return;
        setIdentity(null);
        setIdentityError(getErrorMessage(error));
        setIdentityStatus("error");
      });

    return () => {
      active = false;
    };
  }, [session?.access_token, session]);

  const refreshIdentity = useCallback(async (): Promise<AuthIdentity> => {
    const requestId = ++identityRequestId.current;
    setIdentity(null);
    setIdentityError(null);
    setIdentityStatus("loading");

    try {
      const nextIdentity = await fetchIdentity();
      if (identityRequestId.current === requestId) {
        setIdentity(nextIdentity);
        setIdentityStatus("ready");
      }
      return nextIdentity;
    } catch (error) {
      if (identityRequestId.current === requestId) {
        setIdentityError(getErrorMessage(error));
        setIdentityStatus("error");
      }
      throw error;
    }
  }, []);

  const signIn = useCallback(async (email: string, password: string) => {
    if (!supabase) {
      throw new Error("Supabase is not configured. Check the frontend environment variables.");
    }

    const { error } = await supabase.auth.signInWithPassword({
      email: email.trim(),
      password,
    });

    if (error) throw error;
  }, []);

  const signOut = useCallback(async () => {
    if (!supabase) {
      throw new Error("Supabase is not configured. Check the frontend environment variables.");
    }

    const { error } = await supabase.auth.signOut({ scope: "local" });
    if (error) throw error;
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      session,
      identity,
      loading,
      identityStatus,
      identityError,
      isAuthenticated: Boolean(session && identity && identityStatus === "ready"),
      signIn,
      signOut,
      refreshIdentity,
    }),
    [
      session,
      identity,
      loading,
      identityStatus,
      identityError,
      signIn,
      signOut,
      refreshIdentity,
    ],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside an AuthProvider.");
  return context;
}
