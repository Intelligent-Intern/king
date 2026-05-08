export function permissionsFor(user, membershipRole) {
  const normalizedRole = String(membershipRole || 'member').trim().toLowerCase();
  const isTenantAdmin = normalizedRole === 'owner' || normalizedRole === 'admin';
  const isPlatformAdmin = user?.system_admin === true || String(user?.role || '').trim().toLowerCase() === 'admin';
  const elevated = isTenantAdmin || isPlatformAdmin;
  return {
    platform_admin: isPlatformAdmin,
    tenant_admin: elevated,
    manage_users: elevated,
    manage_organizations: elevated,
    manage_groups: elevated,
    manage_permission_grants: elevated,
    edit_themes: elevated,
    export_import: elevated,
    manage_lobby: elevated,
    admit_participants: elevated,
    reject_participants: elevated,
    kick_participants: elevated,
  };
}
