insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
  'lfms-evidence',
  'lfms-evidence',
  false,
  10485760,
  array['image/jpeg','image/png','image/webp','application/pdf','text/plain']
);

create policy "lfms_evidence_select_related"
on storage.objects
for select
to authenticated
using (
  bucket_id = 'lfms-evidence'
  and (
    (
      (storage.foldername(name))[1] = 'maintenance'
      and (
        (select private.has_permission('maintenance.manage'))
        or (
          (select private.has_permission('maintenance.view'))
          and exists (
            select 1 from public.maintenance_requests mr
            where mr.id::text = (storage.foldername(name))[2]
              and mr.reported_by = (select auth.uid())
          )
        )
      )
    )
    or (
      (storage.foldername(name))[1] = 'incidents'
      and (
        (select private.has_permission('incidents.manage'))
        or (
          (select private.has_permission('incidents.view'))
          and exists (
            select 1 from public.incident_reports ir
            where ir.id::text = (storage.foldername(name))[2]
              and ir.reported_by = (select auth.uid())
          )
        )
      )
    )
  )
);

create policy "lfms_evidence_insert_related"
on storage.objects
for insert
to authenticated
with check (
  bucket_id = 'lfms-evidence'
  and (
    (
      (storage.foldername(name))[1] = 'maintenance'
      and (
        (select private.has_permission('maintenance.manage'))
        or (
          (select private.has_permission('maintenance.create'))
          and exists (
            select 1 from public.maintenance_requests mr
            where mr.id::text = (storage.foldername(name))[2]
              and mr.reported_by = (select auth.uid())
          )
        )
      )
    )
    or (
      (storage.foldername(name))[1] = 'incidents'
      and (
        (select private.has_permission('incidents.manage'))
        or (
          (select private.has_permission('incidents.create'))
          and exists (
            select 1 from public.incident_reports ir
            where ir.id::text = (storage.foldername(name))[2]
              and ir.reported_by = (select auth.uid())
          )
        )
      )
    )
  )
);

create policy "lfms_evidence_admin_update"
on storage.objects
for update
to authenticated
using (
  bucket_id = 'lfms-evidence'
  and (
    ((storage.foldername(name))[1] = 'maintenance' and (select private.has_permission('maintenance.manage')))
    or
    ((storage.foldername(name))[1] = 'incidents' and (select private.has_permission('incidents.manage')))
  )
)
with check (
  bucket_id = 'lfms-evidence'
  and (
    ((storage.foldername(name))[1] = 'maintenance' and (select private.has_permission('maintenance.manage')))
    or
    ((storage.foldername(name))[1] = 'incidents' and (select private.has_permission('incidents.manage')))
  )
);

create policy "lfms_evidence_admin_delete"
on storage.objects
for delete
to authenticated
using (
  bucket_id = 'lfms-evidence'
  and (
    ((storage.foldername(name))[1] = 'maintenance' and (select private.has_permission('maintenance.manage')))
    or
    ((storage.foldername(name))[1] = 'incidents' and (select private.has_permission('incidents.manage')))
  )
);
