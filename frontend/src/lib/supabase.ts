import { createClient } from "@supabase/supabase-js";

// These are public client settings, not service-role credentials.
// Environment variables can override the defaults for local/staging deployments.
const url =
  import.meta.env.VITE_SUPABASE_URL ??
  "https://bwebicixgcnzyqoaccha.supabase.co";

const publishableKey =
  import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY ??
  "sb_publishable_Q1PpH2QbDD324hvUj8MUag_P6ZrC1eE";

export const supabase = createClient(url, publishableKey);
const url = import.meta.env.VITE_SUPABASE_URL;
const publishableKey = import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY ??
"sb_publishable_Q1PpH2QbDD324hvUj8MUag_P6ZrC1eE;"
export const supabase = createClient(url, publishableKey);
