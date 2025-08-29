NorthStar Delivery Slot – Dynamic Time Block Feature Requirements (Additive Only)
0. Scope & Purpose
This document ONLY defines the dynamic time block overlay feature for the slot plugin.
It does NOT redefine or interfere with any other aspect of the current slot plugin, except where time block logic is explicitly stated.
All existing plugin logic, slot handling, and order flows remain unchanged unless specifically affected by the new time block rules.

1. Purpose
Implement a configurable global block out period (in hours) that restricts customer ordering for delivery slots, ensuring sufficient operational lead time.

2. Core Enforcement Logic
Dynamic Block Out Calculation:

For each slot, the system checks:
Is the slot’s start time at least [block out hours] after the current time?
The block out period (in hours) is dynamically set by the admin in the NorthStar admin panel.
The value can be changed at any time; all slot availability calculations use the current value.
If NO, the slot is blocked for customer ordering.
Blocked Slot UI:

Blocked slots are visible but unselectable/greyed out.
Display the message:
“This delivery time is currently unavailable. Orders must be placed at least [block out hours] before delivery to allow for preparation.”

Block out changes take effect immediately for all future orders.
Existing orders are not affected retroactively.
2a. Global Application
The block out period is defined once by the admin and applies equally to all products and all delivery slots.
No exceptions are made for individual products, SKUs, categories, or delivery types.
Manual overrides (block/unblock, capacity) are the only way to bypass block out for a specific slot.
3. Admin Controls
Block Out Time Setter:

Numeric input labeled “Set Block Out Time” (hours) at the top of the admin panel.
Input is professionally spaced apart from the “Season Lock” toggle for clarity.
“Enter” or “Save” button to update the value.
Input validation requires a positive integer.
Changes apply instantly to all customer booking flows.
Admin Override:

Orders placed or changed via WooCommerce backend by admins are not restricted by the block out period.
4. Slot Editing & Manual Controls
Slot Start Time Edit:
Editing a slot start time recalculates its block out window immediately.
Slot’s availability is updated according to the current block out value.
Manual Block/Unblock & Capacity:
Manual block/unblock and capacity controls (e.g., set capacity to zero) operate independently and can override the global block out logic for individual slots.
5. Tyche/NorthStar Sync Logic
Authoritative Source:
NorthStar is the single source of truth for slot status (block, availability, capacity).
Sync Implementation:
All customer-facing Tyche/ORDDD slot data requests on the storefront are intercepted and replaced with NorthStar API data.
Only slots with blocked == 0 and remaining > 0 are shown as available.
Fallback DOM filter disables/greys out any slot not allowed by NorthStar.
Sync occurs on page load, date change, and Tyche UI re-renders.
Manual sync before each season; automatic sync for customer actions.
Admin actions in WooCommerce backend are not affected by sync logic.
6. Error Handling & Race Prevention
Time Zone Consistency:
All time comparisons use unified store/delivery zone time (convert to UTC/local where needed).
Graceful Error Handling:
If NorthStar API fails, fallback to cached slot state or display “Delivery time data temporarily unavailable, please try again soon.”
If slot date/time is invalid, display “Invalid slot date/time. Please select a valid slot.”
If booking is attempted within the block out window (via API/UI bug), return “Orders must be placed at least [block out hours] before delivery.”
Race Condition Prevention:
Atomic validation on booking:
Re-check slot’s start time vs. current time in backend transaction before confirming.
Lock slot row or use optimistic concurrency to avoid double-booking.
When slot edits occur, recalculate block out immediately and validate against concurrent bookings.
7. Audit Logging
No additional audit logging required for block out changes.
8. Summary
Customer UI:
Only slots at least [block out hours] in the future are selectable.
Blocked slots are visible but unselectable, with clear messaging.
Admin flexibility:
Can manually block/unblock slots, change capacity, and override block out rule in backend.
Backend exception:
Admin orders always permitted, regardless of block out.
Sync logic:
NorthStar overrides Tyche UI; sync script ensures customer UI matches NorthStar’s slot/block/capacity status.
9. Manual Slot Block/Unblock & Capacity Overrides
Manual Controls Take Precedence:

Admins can manually block or unblock any slot, regardless of block out period or global settings.
Setting a slot’s capacity to zero makes it unavailable, overriding block out logic.
Manual block/unblock and capacity controls are atomic and reflected instantly in customer UI and API.
Customer Messaging:

Manually Blocked Slot:
“This delivery time is currently unavailable, due to a block while we prepare your order.”

(Professional alternative: “This delivery time is currently unavailable. Orders for this slot are temporarily blocked to ensure preparation time.”)
Zero Capacity Slot:
“No delivery slots available for this time.”

Block Out vs. Manual Block:

If a slot is manually blocked or capacity is zero, it becomes unavailable to customers even if outside the block out window.
Manual overrides always take precedence over global block out logic for individual slots.
Admin Flexibility:

Admins may override and edit slots at any time, regardless of block out status.
10. Block Out Logic for Order Types & Admin Override
Scope:
Block out logic applies to all customer-initiated orders—one-time, scheduled, and recurring—when selecting delivery slots.
Admin Override:
Admins can override any time-based block out and create/edit orders for any slot in WooCommerce backend.
If a slot is manually blocked (via NorthStar), admin must first unblock the slot in NorthStar to create a new order for that slot.
Manual block is an explicit lock; time block is a dynamic restriction which admins may bypass.
Clarification:
Time-based block out (based on lead time) is always overridable by admin for any order type.
Manual block must be lifted by admin before creating or editing an order in that slot, regardless of order type.
11. Time Block Change Behavior & Tyche Integration
Customer Experience:

If a customer is in the process of selecting a slot and the block out period is updated, their slot selection is immediately revalidated.
If the chosen slot is now blocked, a warning is shown:
“The delivery slot you selected is no longer available due to updated lead time requirements. Please choose a new slot.”

No audit log or notification of block out changes is required.
Scope:

This feature is strictly limited to time block rules.
Slot capacity and manual block/unblock logic are handled separately and do not interact with the time block feature.
Bulk slot operations do not affect time block enforcement.
Integration:

Tyche/ORDDD must use NorthStar API for enforcing time block rules.
Tyche’s native time block logic is fully replaced by NorthStar’s implementation for all customer-facing slot selection.
12. Technical Considerations for Tyche/NorthStar Time Block Integration
These points are for future dev teams to support robust, maintainable integration:

API Consistency & Reliability
NorthStar is the authoritative source for slot availability and time block status. Tyche should never independently recalculate time blocks.
Sync logic must be atomic: whenever Tyche requests slot data, always use NorthStar’s API response for both display and validation.
If NorthStar API evolves, Tyche must use the correct version; avoid hard-coding endpoints.
Error Handling & Fallbacks
If NorthStar API is unreachable, Tyche should display a fallback message and disable booking for affected slots, not allow invalid bookings.
Cached slot states (if used) should expire quickly to prevent stale enforcement (suggested TTL: 1–5 minutes).
Always revalidate slot availability and block out status in the backend before confirming an order, even if the UI showed it as available.
Data Race & Concurrency
Prevent double-booking by locking slot state or using optimistic concurrency when confirming bookings, especially during high-traffic periods.
On slot edits or block out value changes, ensure all affected customer sessions are invalidated or revalidated.
Partial Slot Data
Ensure Tyche can handle cases where NorthStar API does not return expected fields (e.g., block status, capacity) and fail safely.
UI/UX Consistency
Tyche slot selection UI should update instantly when NorthStar block out value changes, and warn customers if their slot choice is invalidated mid-flow.
Ensure all messaging (blocked slot, manual block, zero capacity) is consistent between Tyche and NorthStar.
Manual & Capacity Overrides
Manual block/unblock and capacity must always override time block logic; both systems must respect this hierarchy.
Testing Edge Cases
Test bulk slot block/unblock and capacity changes to ensure time block overlay logic is not compromised.
Test boundary cases around midnight, daylight saving changes, and time zone conversions.
Integration Documentation
Document integration points, API contracts, error codes, and fallback behaviors for future developers.
Include test cases for slot sync, customer booking flow interruptions, and admin overrides.
