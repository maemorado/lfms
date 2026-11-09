
    insert into public.role_permissions (role_id, permission_id)
    select r.id, p.id
    from public.roles r
    join public.permissions p on p.name = 'borrowing.return'
    where r.name = 'admin'
    on conflict (role_id, permission_id) do nothing;
  