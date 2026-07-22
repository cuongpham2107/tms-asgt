---
slug: checkpoint-repeater
status: drafting
intent: clear
pending-action: write .omo/plans/checkpoint-repeater.md
approach: Replace custom Blade View with Filament Repeater using ->relationship('checkpoints'), table layout with all fields, addable+deletable. Clean up unused bulk-update route and Blade view.
---

# Draft: checkpoint-repeater

## Components (topology ledger)
| id | outcome (one line) | status | evidence path |
|----|--------------------|--------|---------------|
| C1 | Replace Section custom view with Repeater in TripForm | active | app/Filament/Resources/Trips/Schemas/TripForm.php:131-137 |
| C2 | Cleanup unused bulk-update route & Blade view | active | routes/web.php:23-47, resources/views/filament/resources/trips/components/grouped-checkpoints.blade.php |

## Open assumptions (announced defaults)
| assumption | adopted default | rationale | reversible? |
|------------|-----------------|-----------|-------------|
| Display mode | Individual records (not grouped) | Repeater maps 1:1 to TripCheckpoint records; grouping requires custom mutate logic | yes |
| Fields | All TripCheckpoint fillable fields | User requested "đầy đủ tất cả trường" | yes |
| Layout | Table Repeater with columns | Matches current table-like display, cleaner for many fields | yes |
| Auto-fill context fields | driver_id/shift_id/vehicle_id auto-filled from trip, hidden by default | CheckpointFactory already does this; avoids manual input errors | yes |
| Test strategy | User self-tests, no TDD | User explicitly chose "tự test" | yes |

## Findings (cited - path:lines)
- TripForm.php:131-137 - Section with View::make('checkpoints_grouped') using custom Blade
- TripForm.php:10-11 - Already imports Repeater and TableColumn
- TripCheckpoint model: fillable includes trip_id, order_id, delivery_point_id, checkpoint_type, occurred_at, km_reading, gps_lat, gps_lng, voice_note, driver_id, shift_id, vehicle_id
- Trip::checkpoints() - hasMany relationship, ordered by occurred_at
- CheckpointType enum: 7 cases (started, arrived_pickup, left_pickup, arrived_delivery, completed, driver_swap, end) - implements HasLabel, HasColor
- OrderForm.php:163-191 - Existing deliveryPoints Repeater pattern with relationship(), collapsible(), itemLabel(), reorderableWithDragAndDrop(), orderColumn()
- routes/web.php:23-47 - POST /trips/{trip}/checkpoints/bulk-update - only used by the Blade view, safe to remove
- grouped-checkpoints.blade.php:141 lines - grouping logic + inline Alpine.js edit, no delete functionality

## Decisions (with rationale)
1. **Use `->relationship('checkpoints')`** — Repeater auto-handles CRUD via Eloquent, no custom save logic needed
2. **Table layout** — using `->table([...])` with TableColumn for each visible column, matching current table UX
3. **All fields exposed** — per user request; driver_id/shift_id/vehicle_id default to hidden with `->hidden()` and auto-filled from trip context via `->default()`
4. **Remove bulk-update route** — Repeater handles updates natively; route becomes dead code
5. **Remove Blade view** — replaced entirely by Repeater

## Scope IN
- TripForm.php: Replace Section(131-137) with Repeater using relationship('checkpoints')
- routes/web.php: Remove bulk-update route (lines 22-47)
- Delete grouped-checkpoints.blade.php

## Scope OUT (Must NOT have)
- Do NOT modify CheckpointFactory, TripCheckpointService, or any service class
- Do NOT modify TripCheckpoint model, CheckpointType enum, or Trip model
- Do NOT modify mobile API or any API routes
- Do NOT add new migrations or database changes
- Do NOT write tests (user self-tests)

## Open questions
(none - all resolved)

## Approval gate
status: plan-written
