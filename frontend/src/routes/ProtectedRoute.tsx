import { useState } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { AuthLoadingScreen, AuthProblemScreen } from "@/routes/AuthStatusScreen";

export function ProtectedRoute() {
  const location = useLocation();
  const { loading, session, identity, identityStatus, identityError, refreshIdentity } = useAuth();
  const [retrying, setRetrying] = useState(false);

  if (loading) {
    return <AuthLoadingScreen message="Restoring your LFMS session…" />;
  }

  if (!session) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (identityStatus === "idle" || identityStatus === "loading") {
    return <AuthLoadingScreen message="Verifying your account with LFMS…" />;
  }

  if (identityStatus === "error" || !identity) {
    const retry = async () => {
      setRetrying(true);
      try {
        await refreshIdentity();
      } catch {
        // The context retains the retryable error state.
      } finally {
        setRetrying(false);
      }
    };

    return (
      <AuthProblemScreen
        title="We couldn't verify your LFMS account"
        description={identityError ?? "The LFMS API did not return your authenticated identity. Check your connection and try again."}
        onRetry={() => void retry()}
        retrying={retrying}
      />
    );
  }

  return <Outlet />;
}
