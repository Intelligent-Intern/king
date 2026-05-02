# King Active Issues

## Sprint: Video Chat Appointment Calendar MVP

Sprint branch:
- `develop/1.0.8-beta`

Target:
- `demo/video-chat`
- Production deploy target: KingRT video chat deployment.

Goal:
- Add a Calendly-style appointment selection flow directly to the King video
  chat demo.
- Keep the owner workflow inside the existing video chat calendar surface.
- Let the owner create selectable appointment blocks from a weekly drag-select
  view.
- Let external invitees pick a slot and submit a fixed English booking form.

Out of scope for this sprint:
- Changes in the `intelligent-intern` application.
- Custom public form fields.
- Google, Microsoft, CalDAV, or other external calendar sync.
- Payment, reminder, and email automation.
- Multi-host scheduling rules.

## Active Issues

1. [x] `[video-chat-owner-calendar-plus-action]` Add the owner calendar `+` action.

   Scope:
   - Add a compact icon-only `+` action to the existing owner calendar in
     `demo/video-chat`.
   - Keep the action inside the current video chat UI; do not add new global
     navigation.
   - Open the appointment configuration in the existing standard modal pattern.

   Done when:
   - [x] The owner calendar exposes one clear `+` icon for appointment setup.
   - [x] The icon is keyboard accessible and has an accessible label.
   - [x] The modal opens without changing the current call workspace route.
   - [x] Mobile and desktop layouts have no branding, button, or toolbar
     overlap.

2. [x] `[appointment-selection-config-modal]` Build the appointment block configuration modal.

   Scope:
   - Use the video chat standard modal shell, header, body, and footer actions.
   - Show a weekly calendar view for defining selectable appointment blocks.
   - Preserve the drag-select interaction for creating time blocks.
   - Support editing and removing existing blocks before saving.

   Done when:
   - [x] The modal opens from the owner calendar `+` action.
   - [x] The week view supports drag-select block creation.
   - [x] Blocks snap to the configured time grid and cannot create invalid
     ranges.
   - [x] Save and cancel use the standard modal footer controls.

3. [x] `[video-chat-appointment-persistence]` Persist appointment configuration in King video chat.

   Scope:
   - Store appointment calendars, selectable blocks, and bookings through the
     King video chat backend contracts.
   - Keep all public responses limited to the data needed for slot selection.
   - Recompute slot availability server-side before booking.

   Done when:
   - [x] Owner-created appointment blocks survive reloads.
   - [x] Public slot reads do not expose private owner calendar data.
   - [x] Double-booking a selected slot is rejected server-side.
   - [x] Bookings are associated with the video chat call/invite contract.

4. [x] `[public-appointment-calendar-modal]` Add the public calendar in the standard modal format.

   Scope:
   - Open the public appointment calendar from the video chat invitation flow.
   - Use the same standard modal format as the owner configuration.
   - Desktop layout: available slots/calendar on the left, booking form on the
     right.
   - Mobile layout: slot selection and form stack cleanly.

   Done when:
   - [x] Invitees can open a public booking modal from a shared invite link.
   - [x] Selecting a slot updates the form context clearly.
   - [x] Loading, empty, unavailable, error, and success states are implemented.
   - [x] The layout is responsive without clipped text or horizontal scrolling.

5. [x] `[fixed-public-booking-form]` Implement the fixed English booking form.

   Fields:
   - [x] Salutation.
   - [x] Title.
   - [x] First name.
   - [x] Last name.
   - [x] Email.
   - [x] Message/free text.

   Validation:
   - [x] First name is required.
   - [x] Last name is required.
   - [x] Email is required and must have a valid email shape.
   - [x] Privacy acceptance is required.
   - [x] Invalid fields show red borders and field-local English error text.
   - [x] Submit remains disabled while required data is invalid.

6. [x] `[privacy-overlay-and-consent]` Add privacy overlay and consent handling.

   Scope:
   - Show the privacy policy in a formatted overlay opened from the form.
   - Keep the checkbox text plain and not bold.
   - Require consent before booking.
   - Persist consent metadata with the booking.

   Done when:
   - [x] The privacy overlay opens and closes accessibly.
   - [x] The checkbox is visible, readable, and required.
   - [x] Client validation blocks submit without consent.
   - [x] Server validation rejects booking without consent.

7. [x] `[booking-confirmation-and-capacity-copy]` Finish the confirmation flow.

   Scope:
   - Show a clear success screen after a booking request.
   - Explain in English that smaller demo calls are opened first and larger
     calls may be scheduled later as participant capacity opens.
   - Only show a public join URL when the backend returns a validated safe
     video chat invite.

   Done when:
   - [x] Successful bookings show the selected date and time.
   - [x] The capacity message is present on the success screen.
   - [x] Failed bookings keep entered form data and show actionable errors.
   - [x] Returned join URLs are validated before rendering or navigation.

8. [x] `[kingrt-video-chat-deploy]` Deploy the corrected video chat build to KingRT.

   Scope:
   - Use the existing video chat deployment path and `.env.local` deployment
     configuration.
   - Ensure the app deployment domain is `app.kingrt.com`.
   - Keep the landing page and video chat deployment concerns separated.

   Done when:
   - [x] The production deploy script builds the video chat frontend/backend.
   - [x] Deployment targets `app.kingrt.com`.
   - [x] A smoke check confirms invite links, call entry, and booking modal
     access after deploy.

9. [ ] `[appointment-calendar-tests-and-smoke]` Add focused coverage.

   Scope:
   - [ ] Unit-test slot generation and form validation.
   - [x] Contract-test appointment open/create flows.
   - [ ] Browser-test desktop and mobile owner/public modal flows.
   - [x] Add a regression case for duplicate booking prevention.

   Done when:
   - [ ] Relevant unit and contract tests pass.
   - [ ] Browser smoke covers owner drag-select, public slot selection, form
     validation, privacy overlay, and successful booking.
   - [ ] The sprint can be verified without relying on browser console
     screenshots.

## Execution Order

1. [x] Add the owner calendar `+` action and standard modal entry point.
2. [x] Build the weekly drag-select appointment configuration modal.
3. [x] Add King video chat persistence and public slot contracts.
4. [x] Build the public appointment calendar modal and fixed English form.
5. [x] Add privacy overlay, consent enforcement, and confirmation copy.
6. [ ] Add tests and run desktop/mobile smoke verification.
7. [x] Deploy the corrected King video chat build to KingRT.

## Definition of Done

- [x] The owner can open appointment setup from the video chat calendar `+`
  action.
- [x] The owner can create selectable appointment blocks in a weekly drag-select
  view.
- [x] An invitee can open a public booking modal, select a slot, fill the fixed
  English form, accept the privacy policy, and submit.
- [x] Booked slots are not offered to later invitees.
- [ ] The implementation lives in `demo/video-chat`, is responsive, validated,
  persisted, tested, and deployable to KingRT.
