import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { KeyRound, ShieldCheck } from "lucide-react";
import { supabase } from "@/lib/supabase";
import ThemeToggle from "@/components/layout/ThemeToggle";

export default function InviteAcceptancePage() {
  const navigate = useNavigate();
  const [checking,setChecking] = useState(true);
  const [ready,setReady] = useState(false);
  const [password,setPassword] = useState("");
  const [confirm,setConfirm] = useState("");
  const [saving,setSaving] = useState(false);
  const [error,setError] = useState<string|null>(null);
  useEffect(()=>{let active=true;if(!supabase){setError("Supabase is not configured.");setChecking(false);return;}void supabase.auth.getSession().then(({data,error:sessionError})=>{if(!active)return;if(sessionError)throw sessionError;if(!data.session){setError("This invitation link is invalid, expired, or already used. Ask an administrator to send a new invitation.");return;}setReady(true);}).catch(()=>{if(active)setError("We couldn't verify this invitation. Please request a new invitation.");}).finally(()=>{if(active)setChecking(false);});return()=>{active=false;};},[]);
  const submit=async(event:FormEvent)=>{event.preventDefault();setError(null);if(password.length<8){setError("Use a password with at least 8 characters.");return;}if(password!==confirm){setError("The passwords do not match.");return;}if(!supabase)return;setSaving(true);try{const {error:authError}=await supabase.auth.updateUser({password});if(authError)throw authError; navigate("/dashboard",{replace:true});}catch(cause){setError(cause instanceof Error?cause.message:"The password could not be set. Try the invitation link again.");}finally{setSaving(false);}};
  return <main className="relative flex min-h-screen items-center justify-center bg-background px-5 py-16">
    <div className="absolute right-4 top-4"><ThemeToggle /></div><section className="w-full max-w-md rounded-2xl border border-border bg-card p-7 shadow-sm"><div className="flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 text-teal-800"><ShieldCheck size={24}/></div><p className="mt-5 text-xs font-semibold tracking-[0.18em] text-teal-700">LFMS INVITATION</p><h1 className="mt-2 text-2xl font-semibold text-foreground">Set up your account</h1><p className="mt-2 text-sm leading-6 text-muted-foreground">Choose a password to finish activating your LFMS account.</p>{checking?<p className="mt-6 text-sm text-muted-foreground">Verifying invitation…</p>:ready?<form onSubmit={submit} className="mt-6 space-y-4"><label className="block text-sm text-muted-foreground">New password<input type="password" required minLength={8} autoComplete="new-password" value={password} onChange={e=>setPassword(e.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2.5 text-foreground"/></label><label className="block text-sm text-muted-foreground">Confirm password<input type="password" required minLength={8} autoComplete="new-password" value={confirm} onChange={e=>setConfirm(e.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2.5 text-foreground"/></label><button disabled={saving} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white dark:bg-primary dark:text-primary-foreground disabled:opacity-50"><KeyRound size={16}/>{saving?"Saving password…":"Complete account setup"}</button></form>:null}{error&&<p role="alert" className="mt-5 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</p>}</section></main>;
}
