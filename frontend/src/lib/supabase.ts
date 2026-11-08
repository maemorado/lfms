import { createClient } from "@supabase/supabase-js";

// Browser configuration is intentionally environment-only. A missing value
// must not silently connect a deployment to another Supabase project.
const url = import.meta.env.VITE_SUPABASE_URL?.trim();
const publishableKey = import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY?.trim();

export const supabase = url && publishableKey
  ? createClient(url, publishableKey)
  : null;
