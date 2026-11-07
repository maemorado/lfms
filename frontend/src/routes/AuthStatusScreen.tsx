import { AlertCircle, LoaderCircle, RefreshCw } from "lucide-react";

export function AuthLoadingScreen({ message = "Checking your session…" }: { message?: string }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
      <div className="flex flex-col items-center gap-4 text-center">
        <LoaderCircle aria-hidden="true" className="h-8 w-8 animate-spin text-teal-700" />
        <p className="text-sm font-medium text-slate-600">{message}</p>
      </div>
    </main>
  );
}

export function AuthProblemScreen({
  title,
  description,
  onRetry,
  retrying = false,
}: {
  title: string;
  description: string;
  onRetry: () => void;
  retrying?: boolean;
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-6 py-12">
      <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-700">
          <AlertCircle aria-hidden="true" className="h-6 w-6" />
        </div>
        <h1 className="mt-5 text-xl font-semibold text-slate-950">{title}</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
        <button
          type="button"
          onClick={onRetry}
          disabled={retrying}
          className="mt-6 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <RefreshCw aria-hidden="true" className={`h-4 w-4 ${retrying ? "animate-spin" : ""}`} />
          Try again
        </button>
      </section>
    </main>
  );
}
