# User guides

## A. Administrator guide (Windows desktop app)

**First start.** Install the desktop app, then enter your company server address (https). Sign in with your email and password. On first sign-in, scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, etc.) and type the 6-digit code. You need that code at every sign-in.

**Add people.** Go to *Employees & teams*, then *Add employee*:

- The **User ID** is what the employee types on the phone.
- For callback calling, enter the employee's **work phone number**.
- Choose a role and a team. Supervisors see only the teams where they are marked *Supervisor*.

**Approve a phone.**

1. The employee opens the app and connects. The phone shows a code like `K7QM-4XP2`.
2. In *Devices & approvals*, click *Review* on that request.
3. Ask the employee to read the code aloud, then type it in.
4. Choose the employee, team, asset tag and an optional device policy, then *Approve*.

The code only proves you are approving the phone in front of you. It is not a password.

**Policies.** In *Policy profiles*:

1. Create a profile and add controls.
2. *Save as draft*. The system lists conflicts: blocking ones must be fixed, and warnings must be accepted.
3. *Publish*.
4. *Assign* the profile to the company (the default), a team or a device. A device override beats a team override, and a team override beats the company default.

The device view shows the effective value of every control and where it came from. It also shows whether the control is *Supported*, *Unsupported* or *Requires setup* on that phone. A phone is compliant only when every mandatory control is applied **and verified**. Until then, the employee cannot see customers or call. *Roll back* republishes the previous version.

**Customers.** In *Customer imports*:

1. Download the sample template, fill it in, and upload it (CSV or XLSX).
2. Check the column mapping and *Preview*.
3. *Validate whole file*. Download the error report if rows are rejected.
4. *Commit*.

In *Customers & assignment*, select customers to assign them to someone else or withdraw them. Withdrawn customers disappear from the phone at its next sync; their call history is kept.

**Calls and recordings.** *Calls & recordings* lists each call with ring, talk and total time, its outcome, recording status and disposition. A completed call may still have a failed recording: filter on *Recording: failed*, and use *Retry fetch* once the telephony storage is fixed. Playing or downloading a recording uses a link valid for 5 minutes, and every play and download is audited.

**Supervision.**

- *Employee location*: shows status as observed (connection, in-app state, battery, last contact) and location fixes for a day. Signed in does not mean working. Viewing locations is audited.
- *Live screen sessions* and *Camera session requests*: choose a signed-in employee and state the purpose, then wait for them to accept and approve Android's prompt. Screenshots are possible only during an active screen session, and only if policy allows. Either side can stop the session, and it also ends on timeout.
- *Employee chat*: messages show sent, delivered and read status.

**Lost phone or leaver.** Open the device and choose *Revoke access*. This blocks all work access immediately. It does **not** wipe the phone, and company restrictions stay. Wiping is a separate action (permission *devices.wipe*). You must type the asset tag to confirm it, and it works only on Android Management devices.

**Other administration.** *Storage & retention* estimates storage; deleting expired recordings needs a plan and approval by a **different** administrator. *Reports & exports* downloads call CSVs. *Audit logs* records every privileged action. *System health* shows each integration, and a simulated driver is clearly flagged.

## B. Employee guide (Android app)

1. **Connect.** On a new company phone, the app opens with your company's server already filled in. Tap *Connect / Register*.
2. **Wait for approval.** The phone shows a code. Read it to your administrator. When they approve, the app moves on by itself.
3. **Sign in** with your User ID and password.
4. **Policy check.** The app applies company settings. If something is still pending, you see what it is: tap *Check again*, or contact IT.
5. **Calling.**
   - Tap a customer, then *Call primary* (or *alternate*).
   - In callback mode, **your phone rings first**: answer it and you are connected to the customer.
   - After the call, choose an outcome, add notes and an optional follow-up, then *Save outcome*.
   - Calls need an internet connection. The app never switches to SIM calling by itself.
6. **Offline.** You can keep working offline for the period your company allows. The app shows how old your data is and how many updates are waiting, and sends them when you are back online. When the offline period ends, work is locked until you reconnect.
7. **What your company can see.** Tap *Monitoring* to see exactly what is enabled:
   - call recording
   - location while you are signed in
   - whether screen or camera sessions can be requested
8. **Screen or camera requests.** You always see who is asking and why, and you can decline. If you accept, Android asks for permission too. While sharing:
   - a red banner shows in the app
   - a notification with *Stop* stays visible
   - Android shows its own indicator

   Nothing is captured in the background.
9. **Signing out** ends your work session. The phone stays a managed company phone.
