import { useState } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { AuthLoadingScreen, AuthProblemScreen } from "@/routes/AuthStatusScreen";

function UnassignedAccess({ onSignOut }: { onSignOut: () => void }) {
  return <main className="flex min-h-screen items-center justify-center bg-slate-50 px-6"><section className="w-full max-w-lg rounded-2xl border border-amber-200 bg-white p-8 shadow-sm">
    <p className="text-sm font-semibold tracking-[0.18em] text-teal-700">LFMS</p><h1 className="mt-4 text-2xl font-semibold text-slate-950">Access has not been assigned</h1>
    <p className="mt-3 text-sm leading-6 text-slate-600">Your sign-in was successful, but this account has no active LFMS role. Contact an administrator to request access. The workspace remains locked until a role is assigned.</p>
    <button type="button" onClick={onSignOut} className="mt-6 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Sign out</button>
  </section></main>;
}
export function ProtectedRoute() {
  const location = useLocation();
  const { loading, session, identity, identityStatus, accessStatus, identityError, refreshIdentity, signOut } = useAuth();
  const [retrying, setRetrying] = useState(false);
  if (loading) return <AuthLoadingScreen message="Restoring your LFMS session…" />;
  if (!session) return <Navigate to="/login" replace state={{ from: location }} />;
  if (identityStatus === "idle" || identityStatus === "loading" || accessStatus === "idle" || accessStatus === "loading") return <AuthLoadingScreen message="Verifying your LFMS account and access…" />;
  if (identityStatus === "error" || !identity || accessStatus === "error") {
    const retry = async () => { setRetrying(true); try { await refreshIdentity(); } catch { /* Error remains visible for retry. */ } finally { setRetrying(false); } };
    return <AuthProblemScreen title="We couldn't verify your LFMS access" description={identityError ?? "Authorization data could not be verified. Please retry."} onRetry={() => void retry()} retrying={retrying} />;
  }
  if (accessStatus === "unassigned") return <UnassignedAccess onSignOut={() => void signOut()} />;
  return <Outlet />;
}
