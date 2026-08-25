# OLAMA Users

OLAMA Users is the central WordPress identity, account provisioning, role, and plugin-access service for the OLAMA ERP ecosystem.

## Initial pilot

- Families are selected from OLAMA Core when they have an active student record in the active academic year.
- Family usernames are exact Oracle family IDs; passwords use the optional family prefix plus the normalized mother mobile number and are stored only through WordPress password hashing.
- Mapped family accounts that no longer have an active student in the active academic year, or no longer have a valid mother mobile, are suspended and have their sessions invalidated during Apply. Users and historical content are never deleted, and eligible families are reactivated by a later Apply.
- Olama Oracle Sync imports `/api/employees` into the OLAMA Core employee directory. OLAMA Users provisions accounts only from that canonical local table.
- Employee usernames use `emp{employee_id}` and receive the `Employee — No Access` role by default.
- Mapped employee accounts missing from Core's active employee directory are suspended and have their sessions invalidated during Apply; users and historical content are never deleted.
- The Roles screen allows administrators to create, duplicate, rename, and safely delete every role except WordPress Administrator. Deletion moves assigned users to Subscriber and clears a matching family or employee import default, locking Apply until a replacement is selected. Deleted roles stay suppressed even when a legacy OLAMA plugin previously created them automatically.
- The Capabilities screen is role-first: select a role, select a declared plugin from the left panel, and grant only that plugin's declared capabilities. Administrator always receives every declared capability.
- Selecting any declared child capability automatically grants its plugin's parent access capability, so a role can open the plugin without requiring every sibling tab or action.
- Active OLAMA admin menus and submenus are discovered automatically. Plugins can declare nested `submenus`, `tabs`, `actions`, and `items`; every declared navigation row remains visible even when multiple rows use the same underlying capability.
- OLAMA Users is the only authority allowed to create OLAMA roles, assign users to roles, and grant declared plugin capabilities.
- Access is denied by default for every non-Administrator. A user must receive an OLAMA-approved role and that role must be granted the selected plugin capability through the Capabilities screen.
- Undeclared `olama_` and `os_` services are denied to non-Administrators, forcing plugins to register their functionality with OLAMA Users before access can be granted.
- Family and employee synchronization previews show the resolved WordPress display name. On both account creation and update, synchronization writes the full display name and populates WordPress' native first-name and last-name profile fields from the canonical full name.
- Password settings allow an optional prefix for each identity type. Family passwords use `prefix + normalized mother mobile`; employee passwords use `prefix + normalized employee mobile`. Apply synchronization updates matching existing accounts to the current formula without exposing passwords in results or logs.
- Settings require explicit default roles for both family and employee imports. Preview remains available, but Apply is blocked until both selections point to valid non-Administrator roles.
- Exam Management is the first plugin to declare its module, submenus, tabs, and sensitive actions to the access matrix.

## Safety

Always run Preview before Apply. Existing WordPress users with matching but unmapped usernames are reported as conflicts and are never overwritten.
