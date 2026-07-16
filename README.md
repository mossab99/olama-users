# OLAMA Users

OLAMA Users is the central WordPress identity, account provisioning, role, and plugin-access service for the OLAMA ERP ecosystem.

## Initial pilot

- Families are selected from OLAMA Core when they have an active student record in the active academic year.
- Family usernames are exact Oracle family IDs; passwords are normalized mother mobile numbers stored only through WordPress password hashing.
- Mapped family accounts that no longer have an active student in the active academic year, or no longer have a valid mother mobile, are suspended and have their sessions invalidated during Apply. Users and historical content are never deleted, and eligible families are reactivated by a later Apply.
- Olama Oracle Sync imports `/api/employees` into the OLAMA Core employee directory. OLAMA Users provisions accounts only from that canonical local table.
- Employee usernames use `emp{employee_id}` and receive the `Employee — No Access` role by default.
- Mapped employee accounts missing from Core's active employee directory are suspended and have their sessions invalidated during Apply; users and historical content are never deleted.
- The Roles screen lists all WordPress roles, allows administrators to create, duplicate, and rename OLAMA-managed roles, and safely deletes custom roles only after assigned users are moved. Lifecycle and administrator roles are protected.
- Exam Management is the first plugin to declare its module, submenus, tabs, and sensitive actions to the access matrix.

## Safety

Always run Preview before Apply. Existing WordPress users with matching but unmapped usernames are reported as conflicts and are never overwritten.
