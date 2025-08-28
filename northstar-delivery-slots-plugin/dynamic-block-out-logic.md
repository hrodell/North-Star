# NorthStar Delivery Slot – Dynamic Block Out Period Function & Logic Statement

---

## 1. Purpose

Implement a configurable global block out period (in hours) that restricts customer ordering for delivery slots, ensuring sufficient operational lead time.

---

## 2. Core Enforcement Logic

- **Dynamic Block Out Calculation:**  
  - For each slot, the system checks:  
    _Is the slot’s start time at least **[block out hours]** after the current time?_  
    - The **block out period (in hours) is dynamically set by the admin** in the NorthStar admin panel.
    - The value can be changed at any time; all slot availability calculations use the current value.
  - If **NO**, the slot is blocked for customer ordering.

- **Blocked Slot UI:**  
  - Blocked slots are visible but unselectable/greyed out.
  - Display the message:  
    > “This delivery time is currently unavailable. Orders must be placed at least [block out hours] before delivery to allow for preparation.”
  - Block out changes take effect immediately for all future orders.  
  - Existing orders are **not** affected retroactively.
 
The block out period (in hours) is a configurable global setting, settable by the admin in the NorthStar admin panel.
Only slots at least [block out hours] after current time are selectable for customers.
Admin orders bypass block out.


---

## 3. Admin Controls

- **Block Out Time Setter:**
  - Numeric input labeled “Set Block Out Time” (hours) at the top of the admin panel.
  - Input is professionally spaced apart from the “Season Lock” toggle for clarity.
  - “Enter” or “Save” button to update the value.
  - Input validation requires a positive integer.
  - Changes apply instantly to all customer booking flows.

- **Admin Override:**
  - Orders placed or changed via WooCommerce backend by admins are **not restricted** by the block out period.

---

## 4. Slot Editing & Manual Controls

- **Slot Start Time Edit:**  
  - Editing a slot start time recalculates its block out window immediately.
  - Slot’s availability is updated according to the current block out value.
- **Manual Block/Unblock & Capacity:**  
  - Manual block/unblock and capacity controls (e.g., set capacity to zero) operate independently and can override the global block out logic for individual slots.

---

## 5. Tyche/NorthStar Sync Logic

- **Authoritative Source:**  
  - NorthStar is the single source of truth for slot status (block, availability, capacity).
- **Sync Implementation:**  
  - All customer-facing Tyche/ORDDD slot data requests on the storefront are intercepted and replaced with NorthStar API data.
  - Only slots with `blocked == 0` and `remaining > 0` are shown as available.
  - Fallback DOM filter disables/greys out any slot not allowed by NorthStar.
  - Sync occurs on page load, date change, and Tyche UI re-renders.
  - Manual sync before each season; automatic sync for customer actions.
- **Admin actions in WooCommerce backend are not affected by sync logic.**

---

## 6. Error Handling & Race Prevention

- **Time Zone Consistency:**  
  - All time comparisons use unified store/delivery zone time (convert to UTC/local where needed).
- **Graceful Error Handling:**  
  - If NorthStar API fails, fallback to cached slot state or display “Delivery time data temporarily unavailable, please try again soon.”
  - If slot date/time is invalid, display “Invalid slot date/time. Please select a valid slot.”
  - If booking is attempted within the block out window (via API/UI bug), return “Orders must be placed at least [block out hours] before delivery.”
- **Race Condition Prevention:**  
  - Atomic validation on booking:  
    - Re-check slot’s start time vs. current time in backend transaction before confirming.
    - Lock slot row or use optimistic concurrency to avoid double-booking.
  - When slot edits occur, recalculate block out immediately and validate against concurrent bookings.

---

## 7. Audit Logging

- No additional audit logging required for block out changes.

---

## 8. Summary

- **Customer UI:**  
  - Only slots at least [block out hours] in the future are selectable.
  - Blocked slots are visible but unselectable, with clear messaging.
- **Admin flexibility:**  
  - Can manually block/unblock slots, change capacity, and override block out rule in backend.
- **Backend exception:**  
  - Admin orders always permitted, regardless of block out.
- **Sync logic:**  
  - NorthStar overrides Tyche UI; sync script ensures customer UI matches NorthStar’s slot/block/capacity status.

---
