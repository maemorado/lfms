import { useState, type FormEvent } from "react";
import {
  ArrowRight,
  Building2,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  ShieldCheck,
} from "lucide-react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import ThemeToggle from "@/components/layout/ThemeToggle";

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
    <main className="relative min-h-screen bg-slate-50 dark:bg-background lg:grid lg:grid-cols-[minmax(0,1.05fr)_minmax(420px,0.95fr)]">
      <div className="absolute right-4 top-4 z-20 lg:right-8"><ThemeToggle /></div>
      <section className="relative hidden min-h-screen overflow-hidden bg-slate-950 px-12 py-14 text-white dark:bg-card lg:flex lg:flex-col lg:justify-between xl:px-20">
        <div className="absolute -right-32 -top-24 h-96 w-96 rounded-full border border-teal-300/15" />
        <div className="absolute -bottom-48 -left-24 h-[34rem] w-[34rem] rounded-full border border-teal-300/10" />
        <div className="relative z-10 flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-400 text-slate-950">
            <Building2 aria-hidden="true" className="h-6 w-6" />
          </div>
          <div>
            <p className="text-lg font-bold tracking-[0.16em]">LFMS</p>
            <p className="text-xs text-slate-400">Laboratory & Facility Management</p>
          </div>
        </div>

        <div className="relative z-10 max-w-xl py-16">
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-teal-300">A more organized campus</p>
          <h1 className="mt-6 text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
            Manage spaces, equipment, and resources with confidence.
          </h1>
          <p className="mt-6 max-w-lg text-base leading-8 text-slate-300">
            One secure workspace for the people responsible for keeping campus facilities and laboratories running.
          </p>
          <div className="mt-10 flex items-center gap-3 text-sm text-slate-300">
            <ShieldCheck aria-hidden="true" className="h-5 w-5 text-teal-300" />
            Authorized personnel access only
          </div>
        </div>

        <p className="relative z-10 text-xs text-slate-500">LFMS · Laboratory and Facility Management System</p>
      </section>

      <section className="flex min-h-screen items-center justify-center px-5 py-16 sm:px-8">
        <div className="w-full max-w-md">
          <div className="mb-10 flex items-center gap-3 lg:hidden">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-teal-300">
              <Building2 aria-hidden="true" className="h-5 w-5" />
            </div>
            <div>
              <p className="font-bold tracking-[0.16em] text-slate-950">LFMS</p>
              <p className="text-xs text-slate-500">Laboratory & Facility Management</p>
            </div>
          </div>

          <div className="mb-8">
            <p className="text-sm font-semibold text-teal-700">Welcome back</p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-950 dark:text-foreground">Sign in to LFMS</h2>
            <p className="mt-3 text-sm leading-6 text-slate-600">
              Use the account provided by your system administrator to continue.
            </p>
          </div>

          <form onSubmit={handleSubmit} noValidate className="space-y-5">
            <div>
              <label htmlFor="email" className="mb-2 block text-sm font-medium text-slate-800">
                Email address
              </label>
              <div className="relative">
                <Mail aria-hidden="true" className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  id="email"
                  name="email"
                  type="email"
                  inputMode="email"
                  autoComplete="username"
                  autoFocus
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value);
                    if (emailError) setEmailError(null);
                  }}
                  aria-invalid={Boolean(emailError)}
                  aria-describedby={emailError ? "email-error" : undefined}
                  placeholder="you@university.edu"
                  className={`min-h-12 w-full rounded-xl border bg-white pl-10 pr-4 text-sm text-slate-950 dark:border-input dark:bg-card dark:text-foreground outline-none transition placeholder:text-slate-400 focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 ${emailError ? "border-red-400" : "border-slate-200"}`}
                />
              </div>
              {emailError && <p id="email-error" className="mt-1.5 text-xs text-red-600">{emailError}</p>}
            </div>

            <div>
              <label htmlFor="password" className="mb-2 block text-sm font-medium text-slate-800">
                Password
              </label>
              <div className="relative">
                <LockKeyhole aria-hidden="true" className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
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
                  className={`min-h-12 w-full rounded-xl border bg-white pl-10 pr-12 text-sm text-slate-950 dark:border-input dark:bg-card dark:text-foreground outline-none transition placeholder:text-slate-400 focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 ${passwordError ? "border-red-400" : "border-slate-200"}`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((visible) => !visible)}
                  aria-label={showPassword ? "Hide password" : "Show password"}
                  aria-pressed={showPassword}
                  className="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"
                >
                  {showPassword ? <EyeOff aria-hidden="true" className="h-4 w-4" /> : <Eye aria-hidden="true" className="h-4 w-4" />}
                </button>
              </div>
              {passwordError && <p id="password-error" className="mt-1.5 text-xs text-red-600">{passwordError}</p>}
            </div>

            {errorMessage && (
              <div role="alert" className="flex gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-800">
                <span className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-red-500" />
                <p>{errorMessage}</p>
              </div>
            )}

            <button
              type="submit"
              disabled={isSubmitting}
              className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {isSubmitting ? "Signing in…" : "Sign in"}
              {!isSubmitting && <ArrowRight aria-hidden="true" className="h-4 w-4" />}
              {isSubmitting && <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />}
            </button>
          </form>

          <p className="mt-8 text-center text-xs leading-5 text-slate-500">
            Access is restricted to authorized LFMS personnel. Public account registration is disabled.
          </p>
        </div>
      </section>
    </main>
  );
}
