import { createClient } from "@supabase/supabase-js";
const url = import.meta.env.VITE_SUPABASE_URL;
const publishableKey = import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY ??
"sb_publishable_Q1PpH2QbDD324hvUj8MUag_P6ZrC1eE;"
export const supabase = createClient(url, publishableKey);