import { useState } from "react";
import { Building2, LogOut, ShieldCheck } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

export default function DashboardPage() {
  const { identity, user, signOut } = useAuth();
  const navigate = useNavigate();
  const [signingOut, setSigningOut] = useState(false);
  const [signOutError, setSignOutError] = useState<string | null>(null);

  const handleSignOut = async () => {
    setSigningOut(true);
    setSignOutError(null);

    try {
      await signOut();
      navigate("/login", { replace: true });
    } catch {
      setSignOutError("We couldn't sign you out. Check your connection and try again.");
    } finally {
      setSigningOut(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-teal-300">
              <Building2 aria-hidden="true" className="h-5 w-5" />
            </div>
            <div>
              <p className="font-bold tracking-[0.14em] text-slate-950">LFMS</p>
              <p className="text-xs text-slate-500">Laboratory & Facility Management</p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => void handleSignOut()}
            disabled={signingOut}
            className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <LogOut aria-hidden="true" className="h-4 w-4" />
            {signingOut ? "Signing out…" : "Sign out"}
          </button>
        </div>
      </header>

      <section className="mx-auto max-w-7xl px-5 py-12 sm:px-8">
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 text-teal-800">
            <ShieldCheck aria-hidden="true" className="h-6 w-6" />
          </div>
          <p className="mt-6 text-sm font-semibold text-teal-700">Authentication successful</p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">Your LFMS workspace is protected.</h1>
          <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
            You have an active Supabase session and your identity has been verified by the Laravel API. This is the secured landing page for Phase 2; operational dashboard modules will be added in later phases.
          </p>

          <div className="mt-8 rounded-xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Signed-in account</p>
            <p className="mt-2 break-all text-sm font-medium text-slate-900">{identity?.email ?? user?.email ?? "Email unavailable"}</p>
            <p className="mt-1 break-all text-xs text-slate-500">User ID: {identity?.id ?? user?.id}</p>
          </div>

          {signOutError && (
            <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
              {signOutError}
            </p>
          )}
        </div>
      </section>
    </main>
  );
}
