# Field planning — circles, route plans and Today's route

Console → **Customers → Field planning (circles & routes)**. For admins and group managers (permission: assign customers).
Needs the field map feature in the plan and Google Maps set up (see GUIDE-GOOGLE-SETUP.md).

## 1. Draw each FOS's circle (once)

1. Pick the employee on the left.
2. Click the map at the centre of his area (his home or branch), set the **radius** (e.g. 3 km) and a name → **Save circle**.
3. **Give unassigned customers inside** hands him every customer with a pin inside the circle that has no employee yet.
   Customers of other employees are not moved (move them with Assignment if needed).

The circle centre is also where his day plan starts.

## 2. Plan a day (each morning, or the evening before)

1. Choose the **Day** at the top, then the employee.
2. The customer list shows his customers with a pin: inside the circle first, then follow-ups due, then those not
   visited for the longest time. Tick up to 25 (in the list or by clicking pins on the map).
3. **Save plan** — puts them in a sensible straight-line order. Free.
4. **Optimise route** — asks Google once for the best order by two-wheeler, with total km and time. Pressing it again
   without changing the customers costs nothing.
5. **Publish to phone** — the plan appears on his phone as **Today's route**.

Changing the customers after publishing makes the plan a draft again: save, optimise and publish again.

## 3. On the phone

Field map → gold **TODAY'S ROUTE** card: the stops in order, **Show on map**, **Navigate** (Google Maps app through the
stops still to do). A stop ticks off when he checks in at it (within 100 m of the pin). Showing the plan never costs a
Google request.

A check-in with good GPS (±30 m or better) also corrects the customer's pin ("verified at the door"). That pin is kept on
the next upload of the same address and reused for any other customer at that address.

## 4. Plan vs actual (during the day)

Field planning → **Plan vs actual**: per employee, stops done / planned, next stop, visits made off the plan, last
location time, and alerts:

- **No location for 30+ min** (today, stops still left) — phone off, app closed, or no signal.
- **Fake GPS used** — the phone sent positions from a fake-location app; its check-ins are refused.
- **Behind plan** — one and a half times the planned riding time, plus 20 minutes per stop, has passed since the first check-in.

Click a row for the stop-by-stop list with check-in times. The board refreshes every minute.

## 5. Tips

- Pins come from the addresses automatically after each upload; vague addresses (area only) get no pin — the FOS sets
  them at the door ("Set pin" on the phone).
- All-day location (screen off) needs the **direct** app from `https://<server>/get-app`; the Play Store app tracks only
  while it is open.
