import { useState, type FormEvent } from "react";
import {
  ArrowRight,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
} from "lucide-react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";

type LoginDestination = {
  from?: {
    pathname?: string;
  };
};

function getLoginErrorMessage(error: unknown): string {
  const message = error instanceof Error ? error.message.toLowerCase() : "";

  if (message.includes("invalid login credentials") || message.includes("invalid email or password")) {
    return "The email address or password is incorrect. Please try again.";
  }

  if (message.includes("email not confirmed")) {
    return "This account is not ready to sign in. Please contact your LFMS administrator.";
  }

  if (
    message.includes("failed to fetch") ||
    message.includes("network") ||
    message.includes("timeout") ||
    message.includes("fetch failed")
  ) {
    return "We couldn't reach the authentication service. Check your internet connection and try again.";
  }

  if (message.includes("supabase is not configured")) {
    return "The login service is not configured. Please contact the system administrator.";
  }

  return "Sign-in failed. Please check your details and try again.";
}

export default function LoginPage() {
  const { signIn } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const destination = (location.state as LoginDestination | null)?.from?.pathname ?? "/dashboard";

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [emailError, setEmailError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setErrorMessage(null);

    const normalizedEmail = email.trim();
    const nextEmailError = !normalizedEmail
      ? "Enter your email address."
      : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)
        ? "Enter a valid email address."
        : null;
    const nextPasswordError = password ? null : "Enter your password.";

    setEmailError(nextEmailError);
    setPasswordError(nextPasswordError);

    if (nextEmailError || nextPasswordError) return;

    setIsSubmitting(true);
    try {
      await signIn(normalizedEmail, password);
      navigate(destination, { replace: true });
    } catch (error) {
      setErrorMessage(getLoginErrorMessage(error));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="flex min-h-dvh items-center justify-center bg-black px-4 py-8 text-white sm:px-6">
      <section
        aria-labelledby="login-heading"
        className="w-full max-w-[400px] rounded-2xl border border-neutral-800 bg-neutral-900 p-6 shadow-xl shadow-black/30 sm:p-7"
      >
        <header className="mb-7 flex items-center gap-3">
          <div
            aria-label="Logo placeholder"
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-dashed border-neutral-700 bg-neutral-800 text-[9px] font-semibold tracking-[0.14em] text-neutral-400"
          >
            LOGO
          </div>
          <div className="min-w-0">
            <p className="text-sm font-semibold tracking-[0.14em] text-white">LFMS</p>
            <p className="mt-0.5 text-xs text-neutral-500">Laboratory &amp; Facility Management</p>
          </div>
        </header>

        <div className="mb-6 space-y-1.5">
          <p className="text-xs font-medium uppercase tracking-[0.12em] text-neutral-500">
            Welcome back
          </p>
          <h1 id="login-heading" className="text-xl font-semibold leading-snug tracking-tight text-white">
            Sign in to your account
          </h1>
          <p className="text-sm leading-5 text-neutral-400">
            Enter your account details to access the LFMS workspace.
          </p>
        </div>

        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="email" className="block text-sm font-medium text-neutral-200">
              Email address
            </label>
            <div className="relative">
              <Mail
                aria-hidden="true"
                className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-500"
              />
              <input
                id="email"
                name="email"
                type="email"
                inputMode="email"
                autoComplete="username"
                value={email}
                onChange={(event) => {
                  setEmail(event.target.value);
                  if (emailError) setEmailError(null);
                }}
                aria-invalid={Boolean(emailError)}
                aria-describedby={emailError ? "email-error" : undefined}
                placeholder="name@university.edu"
                className={`h-11 w-full rounded-lg border bg-neutral-800 pl-10 pr-3.5 text-sm text-white outline-none transition-colors placeholder:text-neutral-500 focus-visible:border-neutral-500 focus-visible:ring-2 focus-visible:ring-neutral-500/25 ${emailError ? "border-red-500/70" : "border-neutral-700"}`}
              />
            </div>
            {emailError && <p id="email-error" className="text-xs text-red-400">{emailError}</p>}
          </div>

          <div className="space-y-1.5">
            <label htmlFor="password" className="block text-sm font-medium text-neutral-200">
              Password
            </label>
            <div className="relative">
              <LockKeyhole
                aria-hidden="true"
                className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-500"
              />
              <input
                id="password"
                name="password"
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                value={password}
                onChange={(event) => {
                  setPassword(event.target.value);
                  if (passwordError) setPasswordError(null);
                }}
                aria-invalid={Boolean(passwordError)}
                aria-describedby={passwordError ? "password-error" : undefined}
                placeholder="Enter your password"
                className={`h-11 w-full rounded-lg border bg-neutral-800 pl-10 pr-12 text-sm text-white outline-none transition-colors placeholder:text-neutral-500 focus-visible:border-neutral-500 focus-visible:ring-2 focus-visible:ring-neutral-500/25 ${passwordError ? "border-red-500/70" : "border-neutral-700"}`}
              />
              <button
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-label={showPassword ? "Hide password" : "Show password"}
                aria-pressed={showPassword}
                className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-700 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-500"
              >
                {showPassword ? (
                  <EyeOff aria-hidden="true" className="h-4 w-4" />
                ) : (
                  <Eye aria-hidden="true" className="h-4 w-4" />
                )}
              </button>
            </div>
            {passwordError && <p id="password-error" className="text-xs text-red-400">{passwordError}</p>}
          </div>

          {errorMessage && (
            <div
              role="alert"
              className="flex gap-2.5 rounded-lg border border-red-900/70 bg-red-950/40 px-3.5 py-3 text-sm leading-5 text-red-300"
            >
              <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-400" />
              <p>{errorMessage}</p>
            </div>
          )}

          <button
            type="submit"
            disabled={isSubmitting}
            className="flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-white px-5 text-sm font-semibold text-black transition-colors hover:bg-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-400 focus-visible:ring-offset-2 focus-visible:ring-offset-neutral-900 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSubmitting ? "Signing in…" : "Sign in"}
            {!isSubmitting && <ArrowRight aria-hidden="true" className="h-4 w-4" />}
            {isSubmitting && (
              <span
                aria-hidden="true"
                className="h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"
              />
            )}
          </button>
        </form>

        <footer className="mt-6 border-t border-neutral-800 pt-4 text-center text-xs leading-5 text-neutral-500">
          Access is restricted to authorized LFMS personnel. Public account registration is disabled.
        </footer>
      </section>
    </main>
  );
}
