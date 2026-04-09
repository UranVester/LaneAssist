# Interactive Target Assignment Module

## Overview
This module provides an interactive drag-and-drop interface for assigning participants to targets during qualification rounds. Changes are staged client-side and only applied to the database when explicitly confirmed.

## Features
- **Drag & Drop Interface**: Visually assign participants to targets by dragging participant cards
- **Auto-Assignment Preview**: Use the existing auto-assignment algorithm to generate assignments without database changes
- **Validation**: Real-time warnings for conflicts (duplicate assignments, unassigned participants)
- **Staged Changes**: All changes are previewed before being applied to the database
- **Reset Functionality**: Revert all staged changes back to the current server state
- **Session & Event Filtering**: Filter by session and division/class patterns

## Files Structure
```
Modules/Custom/LaneAssist/ManageTargets/
├── index.php           # Main UI page
├── api.php             # Backend API endpoints
├── js/
│   └── app.js         # Client-side JavaScript logic
├── css/
│   └── style.css      # Styling
└── README.md          # This file
```

## Usage

### Accessing the Module
1. Open your Ianseo installation
2. Navigate to **Modules** → **Assign Targets (Interactive)**

### Workflow
1. **Select Session**: Choose the qualification session from the dropdown
2. **Set Filters** (optional):
   - Division/Class multi-select filters
   - Target range (From/To)
3. **Load Data**: Data loads automatically when session/filter values change
4. **Assign Targets**:
   - **Manual**: Drag participant cards from the unassigned pool to target slots
   - **Auto**: Click "Auto Assign" to open options, then run preview assignment
5. **Review**: Check for validation warnings (duplicates, unassigned participants)
6. **Apply or Reset**:
   - **Apply Changes**: Write all staged changes to the database
   - **Reset**: Revert all changes back to server state

### Auto-Assignment Options
When using Auto Assign, you can configure:
- **Draw Type**:
   - **Normal**: Standard forward fill through available slots.
   - **Field 3D**: Uses stepped target progression (original field stepping).
   - **ORIS**: Alternates group direction in zig-zag style to spread groups.
   - **ORIS 2**: ORIS variant with the original offset/letter stepping behavior.
- **Group by Division**: Keep divisions separate
- **Group by Class**: Keep classes separate
- **Exclude Assigned**: Only assign currently unassigned participants

## API Endpoints

## Response Envelope

All endpoints return JSON with a common envelope:

- `error`: `0` on success, `1` on error (backward compatibility)
- `status`: `ok` or `error`
- `code`: stable response code string (for programmatic handling)
- `message`: human-readable text (present on errors and many success responses)

Typical success:

```json
{
   "error": 0,
   "status": "ok",
   "code": "GET_CURRENT_OK",
   "participants": []
}
```

Typical error:

```json
{
   "error": 1,
   "status": "error",
   "code": "SESSION_REQUIRED",
   "message": "Session required"
}
```

### `api.php?action=getCurrent`
Fetch current target assignments for a session.

**Parameters:**
- `session` (required): Session number
- `event` (optional): Division/class filter pattern (default: `%`)
- `targetFrom` (optional): Starting target number (default: `1`)
- `targetTo` (optional): Ending target number (default: `99`)

**Returns:** JSON with participants, available targets, and session info

### `api.php?action=validate`
Validate a set of assignments for conflicts.

**Parameters:**
- `session` (required): Session number
- `assignments` (required): JSON array of assignments

**Returns:** JSON with validation status and error list

### `api.php?action=previewAutoAssign`
Generate automatic assignments without database changes.

**Parameters:**
- `session` (required): Session number
- `event`, `targetFrom`, `targetTo`: Same as getCurrent
- `drawType`: 0=Normal, 1=Field3D, 2=ORIS, 3=ORIS2
- `groupByDiv`: 1 to separate divisions
- `groupByClass`: 1 to separate classes
- `excludeAssigned`: 1 to only assign unassigned participants

**Returns:** JSON with proposed assignments

### `api.php?action=apply`
Apply staged changes to the database.

**Parameters:**
- `session` (required): Session number
- `changes` (required): JSON array of changes

**Returns:** JSON with success/error counts and per-item results

## Security
- Requires active tournament session (`CheckTourSession`)
- Requires `pTarget` ACL permission with write access
- Blocked when participant data is locked (`BIT_BLOCK_PARTICIPANT`)

## Technical Details
- **Frontend**: jQuery + jQuery UI (draggable/droppable)
- **Backend**: PHP with existing Ianseo database wrappers
- **Database**: Updates `Qualifications` table (QuTarget, QuLetter, QuBacknoPrinted)
- **Validation**: Uses `createAvailableTargetSQL` for target validation

## Customization
To modify the behavior:
- Edit `js/app.js` for client-side logic
- Edit `api.php` for server-side endpoints
- Edit `css/style.css` for styling
- Adjust auto-assignment options in the UI

## Troubleshooting

### jQuery UI not loading
Ensure jQuery UI exists at `/Common/jQuery/jquery-ui.min.js`

### Permission denied
Check that your user has the `pTarget` ACL permission with write access

### Changes not saving
- Verify the session is not blocked
- Check browser console for JavaScript errors
- Verify API endpoint returns success

## Credits
Built for Ianseo archery competition management system.
Integration with existing target assignment infrastructure.
