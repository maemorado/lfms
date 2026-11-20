import { useState, type FormEvent } from "react";
import {
  ArrowRight,
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
    <main className="relative grid min-h-screen bg-background text-foreground lg:grid-cols-2">
      <section className="relative flex min-h-screen flex-col px-6 py-7 sm:px-10 sm:py-9 lg:px-12 xl:px-20">
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div
              aria-label="Logo placeholder"
              className="flex h-11 w-11 items-center justify-center rounded-md border border-dashed border-border bg-muted text-[9px] font-semibold tracking-[0.14em] text-muted-foreground"
            >
              LOGO
            </div>
            <div>
              <p className="text-sm font-semibold tracking-[0.16em]">LFMS</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Laboratory & Facility Management</p>
            </div>
          </div>
          <ThemeToggle />
        </div>

        <div className="flex flex-1 items-center justify-center py-12">
          <div className="w-full max-w-sm">
            <div className="mb-8 space-y-2">
              <p className="text-sm font-medium text-muted-foreground">Welcome back</p>
              <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Sign in to your account</h1>
              <p className="text-sm leading-6 text-muted-foreground">
                Enter your account details to access the LFMS workspace.
              </p>
            </div>

            <form onSubmit={handleSubmit} noValidate className="space-y-5">
              <div className="space-y-2">
                <label htmlFor="email" className="text-sm font-medium">Email address</label>
                <div className="relative">
                  <Mail
                    aria-hidden="true"
                    className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                  />
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
                    placeholder="name@university.edu"
                    className={`h-11 w-full rounded-md border bg-background pl-10 pr-4 text-sm outline-none transition-colors placeholder:text-muted-foreground/70 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/20 ${emailError ? "border-destructive" : "border-input"}`}
                  />
                </div>
                {emailError && <p id="email-error" className="text-xs text-destructive">{emailError}</p>}
              </div>

              <div className="space-y-2">
                <label htmlFor="password" className="text-sm font-medium">Password</label>
                <div className="relative">
                  <LockKeyhole
                    aria-hidden="true"
                    className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
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
                    className={`h-11 w-full rounded-md border bg-background pl-10 pr-12 text-sm outline-none transition-colors placeholder:text-muted-foreground/70 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/20 ${passwordError ? "border-destructive" : "border-input"}`}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((visible) => !visible)}
                    aria-label={showPassword ? "Hide password" : "Show password"}
                    aria-pressed={showPassword}
                    className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    {showPassword ? (
                      <EyeOff aria-hidden="true" className="h-4 w-4" />
                    ) : (
                      <Eye aria-hidden="true" className="h-4 w-4" />
                    )}
                  </button>
                </div>
                {passwordError && <p id="password-error" className="text-xs text-destructive">{passwordError}</p>}
              </div>

              {errorMessage && (
                <div
                  role="alert"
                  className="flex gap-2.5 rounded-md border border-destructive/30 bg-destructive/5 px-3.5 py-3 text-sm leading-5 text-destructive"
                >
                  <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-destructive" />
                  <p>{errorMessage}</p>
                </div>
              )}

              <button
                type="submit"
                disabled={isSubmitting}
                className="flex h-11 w-full items-center justify-center gap-2 rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-60"
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

            <p className="mt-8 text-center text-xs leading-5 text-muted-foreground">
              Access is restricted to authorized LFMS personnel. Public account registration is disabled.
            </p>
          </div>
        </div>

        <p className="text-center text-xs text-muted-foreground sm:text-left">
          © {new Date().getFullYear()} LFMS. All rights reserved.
        </p>
      </section>

      <aside className="relative hidden min-h-screen overflow-hidden bg-neutral-950 text-white dark:bg-neutral-900 lg:flex lg:flex-col lg:justify-between">
        <div aria-hidden="true" className="absolute inset-0">
          <div className="absolute -right-40 -top-40 h-[34rem] w-[34rem] rounded-full border border-white/10" />
          <div className="absolute -right-20 -top-20 h-[24rem] w-[24rem] rounded-full border border-white/10" />
          <div className="absolute -bottom-56 -left-40 h-[38rem] w-[38rem] rounded-full border border-white/10" />
          <div className="absolute inset-0 opacity-[0.08] [background-image:linear-gradient(rgba(255,255,255,0.45)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.45)_1px,transparent_1px)] [background-size:56px_56px]" />
        </div>

        <div className="relative z-10 flex items-center justify-between px-12 py-9 xl:px-16">
          <p className="text-xs font-medium uppercase tracking-[0.22em] text-white/60">
            Campus operations
          </p>
          <ShieldCheck aria-hidden="true" className="h-5 w-5 text-white/70" />
        </div>

        <div className="relative z-10 max-w-2xl px-12 pb-20 xl:px-16">
          <p className="text-xs font-medium uppercase tracking-[0.24em] text-white/50">
            Laboratory & Facility Management System
          </p>
          <h2 className="mt-6 max-w-xl text-4xl font-semibold leading-[1.12] tracking-tight xl:text-5xl">
            A clearer way to manage your campus resources.
          </h2>
          <p className="mt-6 max-w-lg text-base leading-7 text-white/60">
            One secure workspace for coordinating facilities, reservations, equipment, and the people who keep them running.
          </p>
          <div className="mt-10 flex items-center gap-3 text-sm text-white/65">
            <span className="h-px w-8 bg-white/50" />
            Authorized personnel access only
          </div>
        </div>

        <div className="relative z-10 flex items-center justify-between border-t border-white/10 px-12 py-6 text-xs text-white/40 xl:px-16">
          <span>LFMS workspace</span>
          <span>Secure access</span>
        </div>
      </aside>
    </main>
  );
}
