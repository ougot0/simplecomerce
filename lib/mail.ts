import "server-only";
import { log } from "./log";

/**
 * E-mails envoyés par le portail lui-même (invitations).
 * Les e-mails de connexion (lien magique, confirmation) sont envoyés par Supabase Auth,
 * à configurer avec un SMTP à votre nom dans le tableau de bord Supabase.
 * Sans RESEND_API_KEY, rien n'est envoyé : le lien est affiché pour être copié.
 */
export async function sendMail(to: string, subject: string, text: string): Promise<boolean> {
  const key = process.env.RESEND_API_KEY;
  const from = process.env.MAIL_FROM;
  if (!key || !from) return false;
  try {
    const res = await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/json" },
      body: JSON.stringify({ from, to, subject, text }),
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) log.warn("envoi d'e-mail refusé", { status: res.status });
    return res.ok;
  } catch (err) {
    log.warn("envoi d'e-mail impossible", err);
    return false;
  }
}
