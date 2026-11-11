-- Add only the delete capability required by the Laravel-mediated evidence API.
-- Existing SELECT and staff INSERT policies remain unchanged.
-- The new DELETE policy is restricted to users with incidents.manage.

GRANT DELETE ON public.incident_attachments TO lfms_app;

CREATE POLICY incident_attachments_admin_delete
ON public.incident_attachments
FOR DELETE
TO lfms_app
USING (private.has_permission('incidents.manage'));
