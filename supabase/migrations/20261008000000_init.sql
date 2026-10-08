-- Simple Commerce — schéma initial
-- Principe : le navigateur ne parle jamais directement à la base. Le serveur du portail
-- utilise la clé « service » et vérifie les droits avant chaque opération (lib/access.ts).
-- La Row Level Security est activée partout : sans clé service, on ne voit que ses propres sites,
-- et les identifiants de connexion ne sont lisibles par personne.


-- ---------------------------------------------------------------- profils
create table public.profiles (
  id uuid primary key references auth.users (id) on delete cascade,
  email text not null,
  full_name text not null default '',
  is_super_admin boolean not null default false,
  created_at timestamptz not null default now()
);

create or replace function public.handle_new_user() returns trigger
  language plpgsql security definer set search_path = public as $$
begin
  insert into public.profiles (id, email, full_name)
  values (new.id, new.email, coalesce(new.raw_user_meta_data ->> 'full_name', ''))
  on conflict (id) do update set email = excluded.email;
  return new;
end $$;

create trigger on_auth_user_created
  after insert or update of email on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------- sites
create table public.sites (
  id uuid primary key default gen_random_uuid(),
  slug text not null unique check (slug ~ '^[a-z0-9][a-z0-9-]{1,60}$'),
  name text not null check (char_length(name) between 1 and 120),
  public_url text not null default '',
  connector text not null,
  connector_config jsonb not null default '{}'::jsonb,
  status text not null default 'active' check (status in ('active', 'suspended')),
  created_by uuid references public.profiles (id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  last_check_at timestamptz,
  last_check_ok boolean
);

create table public.site_members (
  site_id uuid not null references public.sites (id) on delete cascade,
  user_id uuid not null references public.profiles (id) on delete cascade,
  role text not null check (role in ('owner', 'editor')),
  created_at timestamptz not null default now(),
  primary key (site_id, user_id)
);
create index site_members_user_idx on public.site_members (user_id);

-- Identifiants chiffrés (AES-256-GCM, clé hors de la base). Aucune policy : illisible sans clé service.
create table public.site_credentials (
  site_id uuid primary key references public.sites (id) on delete cascade,
  ciphertext text not null,
  iv text not null,
  auth_tag text not null,
  key_version integer not null,
  fingerprints jsonb not null default '{}'::jsonb,
  updated_at timestamptz not null default now(),
  updated_by uuid references public.profiles (id) on delete set null
);

create table public.content_schemas (
  id uuid primary key default gen_random_uuid(),
  site_id uuid not null references public.sites (id) on delete cascade,
  version integer not null,
  definition jsonb not null,
  created_by uuid references public.profiles (id) on delete set null,
  created_at timestamptz not null default now(),
  unique (site_id, version)
);

-- ---------------------------------------------------------------- historique
create table public.changes (
  id uuid primary key default gen_random_uuid(),
  site_id uuid not null references public.sites (id) on delete cascade,
  actor_id uuid references public.profiles (id) on delete set null,
  on_behalf_of uuid references public.profiles (id) on delete set null,
  action text not null check (action in ('create', 'update', 'delete', 'reorder', 'revert')),
  section_key text not null,
  entry_id text,
  entry_label text not null default '',
  before jsonb,
  after jsonb,
  before_order jsonb,
  after_order jsonb,
  remote_ref text,
  status text not null default 'pending' check (status in ('pending', 'applied', 'failed')),
  error_message text,
  reverts_change_id uuid references public.changes (id) on delete set null,
  reverted_by_change_id uuid references public.changes (id) on delete set null,
  created_at timestamptz not null default now()
);
create index changes_site_idx on public.changes (site_id, created_at desc);

create table public.drafts (
  id uuid primary key default gen_random_uuid(),
  site_id uuid not null references public.sites (id) on delete cascade,
  section_key text not null,
  entry_id text,
  label text not null default '',
  data jsonb not null,
  base_data jsonb,
  created_by uuid references public.profiles (id) on delete set null,
  updated_by uuid references public.profiles (id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index drafts_site_idx on public.drafts (site_id);

create table public.media (
  id uuid primary key,
  site_id uuid not null references public.sites (id) on delete cascade,
  uploaded_by uuid references public.profiles (id) on delete set null,
  storage_path text not null,
  file_name text not null,
  mime text not null,
  bytes integer not null,
  width integer not null,
  height integer not null,
  alt text not null default '',
  created_at timestamptz not null default now()
);

create table public.invitations (
  id uuid primary key default gen_random_uuid(),
  site_id uuid not null references public.sites (id) on delete cascade,
  email text not null,
  role text not null check (role in ('owner', 'editor')),
  token_hash text not null unique,
  invited_by uuid references public.profiles (id) on delete set null,
  created_at timestamptz not null default now(),
  expires_at timestamptz not null,
  accepted_at timestamptz,
  accepted_by uuid references public.profiles (id) on delete set null
);

create table public.audit_events (
  id uuid primary key default gen_random_uuid(),
  at timestamptz not null default now(),
  actor_id uuid references public.profiles (id) on delete set null,
  on_behalf_of uuid references public.profiles (id) on delete set null,
  site_id uuid references public.sites (id) on delete set null,
  kind text not null,
  details jsonb not null default '{}'::jsonb
);
create index audit_events_at_idx on public.audit_events (at desc);

create table public.impersonations (
  id uuid primary key default gen_random_uuid(),
  admin_id uuid not null references public.profiles (id) on delete cascade,
  target_user_id uuid not null references public.profiles (id) on delete cascade,
  started_at timestamptz not null default now(),
  expires_at timestamptz not null,
  ended_at timestamptz
);

-- ---------------------------------------------------------------- sécurité
create or replace function public.is_super_admin() returns boolean
  language sql stable security definer set search_path = public as $$
  select coalesce((select is_super_admin from public.profiles where id = auth.uid()), false)
$$;

create or replace function public.is_site_member(p_site uuid) returns boolean
  language sql stable security definer set search_path = public as $$
  select exists (select 1 from public.site_members where site_id = p_site and user_id = auth.uid())
$$;

alter table public.profiles enable row level security;
alter table public.sites enable row level security;
alter table public.site_members enable row level security;
alter table public.site_credentials enable row level security;
alter table public.content_schemas enable row level security;
alter table public.changes enable row level security;
alter table public.drafts enable row level security;
alter table public.media enable row level security;
alter table public.invitations enable row level security;
alter table public.audit_events enable row level security;
alter table public.impersonations enable row level security;

-- Personne d'anonyme n'a rien à faire dans ces tables.
revoke all on all tables in schema public from anon;
-- Les écritures passent uniquement par le serveur (clé service).
revoke insert, update, delete on all tables in schema public from authenticated;
revoke all on public.site_credentials from authenticated;

-- Lecture seule, limitée à ses propres sites (seconde barrière en cas d'accès direct).
create policy profiles_read on public.profiles for select to authenticated
  using (id = auth.uid() or public.is_super_admin());
create policy sites_read on public.sites for select to authenticated
  using (public.is_super_admin() or public.is_site_member(id));
create policy members_read on public.site_members for select to authenticated
  using (public.is_super_admin() or public.is_site_member(site_id));
create policy schemas_read on public.content_schemas for select to authenticated
  using (public.is_super_admin() or public.is_site_member(site_id));
create policy changes_read on public.changes for select to authenticated
  using (public.is_super_admin() or public.is_site_member(site_id));
create policy drafts_read on public.drafts for select to authenticated
  using (public.is_super_admin() or public.is_site_member(site_id));
create policy media_read on public.media for select to authenticated
  using (public.is_super_admin() or public.is_site_member(site_id));
create policy audit_read on public.audit_events for select to authenticated
  using (public.is_super_admin());
-- site_credentials, invitations, impersonations : aucune policy → inaccessibles hors serveur.

-- ---------------------------------------------------------------- stockage des photos en attente
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('staging', 'staging', false, 8388608, array['image/webp', 'image/jpeg', 'image/png'])
on conflict (id) do nothing;
-- Pas de policy sur storage.objects pour ce bucket : seul le serveur y accède.
