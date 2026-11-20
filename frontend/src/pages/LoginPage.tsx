import { useState, type FormEvent } from "react";
import { Eye, EyeOff, LockKeyhole } from "lucide-react";
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
      : !/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(normalizedEmail)
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
    <main className="flex min-h-screen items-center justify-center bg-black px-4 py-8 text-white">
      <section className="w-full max-w-md rounded-2xl border border-neutral-800 bg-neutral-900 px-7 py-7 shadow-sm sm:px-8">
        <div className="mb-7 flex items-center gap-3">
          <div
            aria-label="Logo placeholder"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-dashed border-neutral-600 text-[8px] font-semibold tracking-wider text-neutral-400"
          >
            LOGO
          </div>
          <div>
            <p className="text-sm font-semibold tracking-wide text-white">LFMS</p>
            <p className="text-xs text-neutral-400">Laboratory & Facility Management</p>
          </div>
        </div>

        <div className="mb-6 space-y-1.5">
          <h1 className="text-lg font-semibold tracking-tight text-white">Login to your account</h1>
          <p className="text-sm leading-6 text-neutral-400">
            Enter your email below to login to your account.
          </p>
        </div>

        <form onSubmit={handleSubmit} noValidate className="space-y-5">
          <div className="space-y-2">
            <label htmlFor="email" className="text-sm font-medium text-white">Email</label>
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
              className={`h-11 w-full rounded-lg border bg-neutral-800 px-3.5 text-sm text-white outline-none transition-colors placeholder:text-neutral-500 focus-visible:border-neutral-500 focus-visible:ring-2 focus-visible:ring-neutral-500/30 ${emailError ? "border-red-500" : "border-neutral-700"}`}
            />
            {emailError && <p id="email-error" className="text-xs text-red-400">{emailError}</p>}
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
              <label htmlFor="password" className="text-sm font-medium text-white">Password</label>
            </div>
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
                className={`h-11 w-full rounded-lg border bg-neutral-800 pl-10 pr-12 text-sm text-white outline-none transition-colors placeholder:text-neutral-500 focus-visible:border-neutral-500 focus-visible:ring-2 focus-visible:ring-neutral-500/30 ${passwordError ? "border-red-500" : "border-neutral-700"}`}
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
              className="rounded-lg border border-red-900/70 bg-red-950/40 px-3.5 py-3 text-sm leading-5 text-red-300"
            >
              {errorMessage}
            </div>
          )}

          <button
            type="submit"
            disabled={isSubmitting}
            className="flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-neutral-100 px-5 text-sm font-medium text-neutral-950 transition-colors hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-400 focus-visible:ring-offset-2 focus-visible:ring-offset-neutral-900 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSubmitting ? "Signing in…" : "Login"}
            {isSubmitting && (
              <span
                aria-hidden="true"
                className="h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"
              />
            )}
          </button>
        </form>

        <p className="mt-6 text-center text-xs leading-5 text-neutral-500">
          Access is restricted to authorized LFMS personnel.
        </p>
      </section>
    </main>
  );
}
