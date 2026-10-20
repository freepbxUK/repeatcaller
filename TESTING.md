# Repeat Caller Installation Testing Checklist

Use this checklist for real FreePBX installation testing on FreePBX 16 and 17
with MySQL/MariaDB.

## Fresh Install

1. Stage the signed release files in
   `/var/www/html/admin/modules/repeatcaller/`. Then install the staged module
   from a neutral directory:

```bash
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

2. Confirm the page loads under Reports > Repeat Caller.
3. Confirm the FreePBX job is registered:

```bash
fwconsole job --list | grep -i repeatcaller
```

4. Confirm current tables exist:

```sql
SHOW TABLES LIKE 'repeatcaller%';
```

Expected v1 fresh-install tables include:

- `repeatcaller_settings`
- `repeatcaller_rules`
- `repeatcaller_rule_schedules`
- `repeatcaller_rule_callers`
- `repeatcaller_rule_dids`
- `repeatcaller_seen_calls`
- `repeatcaller_rule_subject_state`
- `repeatcaller_incidents`
- `repeatcaller_incident_alert_state`
- `repeatcaller_incident_alert_history`
- `repeatcaller_incident_suppression_history`

## Settings and Rules

5. Save Global Settings and confirm reload persists values.
6. Create a rule and reload it through the editor.
7. Confirm Inbound Route DID choices populate from FreePBX inbound routes.
8. Confirm rule-level mode, alert actions, schedules, and recipients persist after save/reload.
9. In the Rules table, confirm each row shows Edit, Status, and X controls on one line. Press Status and confirm the explainer bar turns light grey, shows rule status text for 15 seconds, then reverts.

## Incident and History Pagination

- Confirm Active Incidents, Recent Incidents, Suppressed Incidents, and Alert
	History each show a Previous / range / Next control above the table.
- With more than 25 eligible rows, confirm each table replaces its rows when
	moving between pages and reports the full matching total, including an
	incomplete final page. Empty tables should retain their existing empty-state
	message.
- Sort each data column in both directions. Confirm sorting affects records
	across pages, the selected heading shows its direction, and each table's
	preference survives navigation, a full browser refresh, and returning to the
	module.
- Change data while a table is on a later page and confirm automatic refresh
	retains the selected sort and page when valid, or moves to the final valid
	page if rows were removed.
- Confirm Accept and Clear Suppression continue to work after sorting and
	pagination. Confirm Rules and Schedule retain their existing row-batching
	behaviour.

## Runtime

10. Trigger matching inbound calls and confirm incident creation.
11. Trigger further matching calls and confirm same-incident updates.
12. Confirm initial incident and alert behaviour when configured:
	- GUI incident creation
	- Alert Call when enabled and configured
	- Email when enabled and recipients are valid
13. Confirm reminder timing for the selected Alert Reminders mode and configured alert actions. In alert reminder emails, confirm the reminder line is shown as `Alert Reminder: <mode>`, the older reminder-mode lines are no longer present, and the opening line includes the FreePBX System Identifier when available.
14. Ensure an active incident is available for acceptance testing.
15. Accept an active incident using the GUI and confirm it moves to accepted history/state.
16. If Alert Call is enabled, answer the Alert Call, press 1, and confirm the incident is accepted.
17. Trigger a post-accept matching call and confirm the same accepted incident updates without new reminders.
18. Snooze globally, confirm deferred Alert Call and Email delivery, then select Resume All Rules and confirm deferred delivery.
19. Use Enable All Rules and Disable All Rules to confirm they switch the global engine and every non-deleted rule on or off, while deleted rules remain unchanged.
20. Delete a rule with an active incident and confirm the rule disappears while incident history remains visible.
21. Confirm the available pruning schedule options:
	- Never
	- Hourly
	- Daily (default)
	- Weekly
	- Monthly
	- Yearly
22. Run manual pruning and review returned delete counts.

## Alert Call contract coverage

23. Run the focused documentation and behaviour contracts:

```bash
cd /workspaces/repeatcaller
php tests/repeat_admin_contract.php
php tests/repeat_alerting_contract.php
php tests/repeat_release_contract.php
```

The alerting contract covers first-destination NOANSWER progression, cumulative Ordered stages, mixed Keep Trying values, waiting for all sibling attempts, repeated stages after the final destination, acceptance and late-callback protection, Ring All editor and destination behaviour, and Ignore Callers separation. The admin and release contracts cover the Ring All/Ordered editor round-trip and documentation/help-text parity.

## 1.1.1 Acceptance Notification Regression

Run the alerting, completion, and runtime contracts. They cover GUI and
telephone acceptance, every reminder mode, absent/active/expired suppression,
stale reservations, queued pending/failed/snoozed/sending emails, late decline
callbacks, and acceptance interleaved with marking emails `sending` or delivering them.
Successfully delivered history must remain unchanged. Accepted incidents must
not reserve reminders, and outstanding deliveries must be cancelled wherever
still possible; a transport handover already in progress cannot be recalled.

The runtime contract replays the production acceptance timeline with threshold 3, a 60-minute window,
suppression 0, and 5-minute reminders: calls at 10:55:52, 11:00:39, 11:04:24,
telephone acceptance at 11:06:09, then calls at 11:49:46, 11:50:58, 11:57:49,
and 11:58:05. Expected: one incident, one initial GUI/three-email/Alert Call
cycle, no reminders, all seven seen journeys retained, and a final rolling
count of six. A later below-threshold observation closes the accepted incident;
the next threshold creates a new ID with its own initial alerts. Existing
suppression-enabled lifecycle tests continue to check blocked new episodes.

Before release on FreePBX 16 and 17, verify acceptance with queued work,
continued matching calls, observed clearing/rearming, and a new incident.
Check unchanged unaccepted reminders, snooze, decline, Ordered/Ring All,
Keep Trying, and acceptance stopping sibling calls. Unit interleavings do not
prove every real database/transport race. Suppression-setting reconciliation
is outside this correction: its separate accepted-to-active reactivation issue
can undermine state-based notification eligibility and must be tested separately.

## 1.1.1 BMO Alert Call Dialplan

Repeat Caller must own zero custom Asterisk `.conf` files. After module upgrade
and reload, inspect the launch, playback, summary, and caller-name contexts in
FreePBX-generated `extensions_additional.conf`; fresh installs must not create
`repeatcaller_alert.conf` or add a managed include to `extensions_custom.conf`.
The install contract pins all 86 legacy context/extension/label/application
instructions to their pre-migration digest, including DTMF and hangup paths.

Upgrade and uninstall share selective legacy cleanup, which is entered only
when the old module-owned `repeatcaller_alert.conf` fragment exists. Without
that fragment, `extensions_custom.conf` is not read, created, or modified, and
any includes or lookalike references in it are left untouched. With the
fragment present, test a complete managed
block before/after unrelated dialplan, LF and CRLF files, no final newline,
duplicate blocks, an otherwise empty file, a missing administrator file, and
partial/lookalike/unmanaged includes. Only complete exact managed blocks may
be removed from the administrator file. If an active unmanaged `#include` or
`#tryinclude` of `repeatcaller_alert.conf` would remain afterward, cleanup must
throw an explicit `RuntimeException` before writing anything, leaving
`extensions_custom.conf` and the legacy fragment unchanged. Comments or other
text merely mentioning the filename do not block cleanup. Delete the fragment
only when no active include remains.
Other bytes, files, whitespace, and administrator configuration must remain
unchanged. Run cleanup twice, starting with the fragment both present and
absent, to check idempotence; never delete `extensions_custom.conf` itself.

The legacy fragment defined three contexts, not an acceptance-check subroutine.
Its existing summary references to `repeatcaller-alert-accept-check` are
preserved without inventing new dialplan behaviour. Report that pre-existing
undefined reference separately if the legacy summary context is exercised.

## 1.1.1 Internal Alert Call Caller Name

Run the install contract as well as the alerting, admin, and release contracts
above. The install contract simulates the generated caller-name subroutine and
checks additive integration without replacing the existing SIP-header handler:

```bash
php tests/repeat_install_contract.php
```

Coverage includes genuine device technology plus exact dial-string matching,
custom/trunk identifier collisions, missing or mismatched records, unmarked
calls, and independent mixed internal/external channel snapshots. It checks
that only `CONNECTEDLINE(name,i)` changes while callee Caller ID, both numbers,
and allowed, restricted, or unavailable privacy flags remain untouched.

The PJSIP fixture exposes `CHANNEL(endpoint)`, the actual Asterisk endpoint
accessor, rather than the unsupported `CHANNEL(pjsip,endpoint)` combination.
It includes an alphanumeric endpoint independent of the caller number.

These simulations do not validate Asterisk execution, SIP headers, or handset
display, marker inheritance, parent-channel isolation, forwarding, or redirects.
Before release, complete these focused checks on FreePBX/PBXact 16
and 17, recording the Core, framework, Asterisk, driver, and handset versions:

- Install the development version through the normal module upgrade path so
	BMO hook registration is refreshed, then Apply Config. Inspect
	`func-apply-sipheaders`: one guarded Gosub should
	enter `repeatcaller-alert-caller-name`; all existing header commands and
	other modules' additions must remain. Reload again and check for duplicates.
- Confirm the generated subroutine reads `CHANNEL(endpoint)` for PJSIP and
	`CHANNEL(peername)` for chan_sip. Use an actual Repeat Caller Alert Call:
	an ordinary CLI Local originate without the module marker is deliberately
	excluded. Check the matching call's log for execution of the name setter,
	then inspect the initial endpoint INVITE and actual handset display.
- Call a standard PJSIP extension and, on 16 where installed, a chan_sip
	extension with managed-elsewhere enabled. Confirm the handset and initial
	INVITE display `Repeat Caller` without a fabricated number. Inspect From,
	P-Asserted-Identity and Remote-Party-ID where enabled.
- Repeat with an explicit external caller number and with a number mapping to
	a local user. Confirm FreePBX's earlier name rewrite does not defeat the
	endpoint label and all caller numbers match the pre-patch baseline.
- Compare external calls in both Caller ID modes against baseline, including
	route/trunk selection and all identity headers, not just handset display.
- Test a mixed rule with Ring All and Ordered, one Keep Trying progression,
	acceptance cancelling siblings, and an introductory System Recording.
- Test internal CF, Follow Me with simultaneous internal/external legs, and
	handset SIP redirects to internal and external destinations. Internal channels
	that execute the handler should have the label; external identities and parent
	caller identity must match baseline. Capture redirect routing explicitly:
	direct replacement channels can bypass the original `b()` handler and miss
	the label. Record this limitation rather than assuming every redirect is covered.
- Test custom devices whose identifiers and dial strings match trunk endpoint
	names; confirm the `custom` device technology prevents any name assignment.
- Review any endpoint configured as both a genuine SIP/PJSIP device and a
	trunk. Positive device checks alone do not resolve this conflict; it is a
	residual configuration risk, not a case covered by custom-device rejection.
- Check an unrelated internal call, custom device dial string, recording,
	CDR account marker/filtering, and initial plus reminder alert attempts.
	Internal CDR name fields may change; numbers and origin safeguards must not.
- Test restricted and unavailable name/number presentation, plus any endpoint
	From-user override in use. Compare privacy headers and presentation against
	baseline; confirm neither callee Caller ID nor either number is changed.
	Do not change privacy settings as part of this patch; document display limits.

## Additional real FreePBX Alert Call checks

These are additional manual installation checks beyond the contract suites:

24. Confirm that no Alert Reminder launches while an Ordered cycle is active, pending, or still in its 60-second pause.
25. Confirm that the reminder interval begins only after the final Ordered stage completes without acceptance.
26. Confirm that a reminder starts a fresh Ordered sequence at stage 0.
27. Confirm that a reminder cycle cannot overlap another cycle.
28. Confirm that acceptance during any stage prevents later stages and future reminders.
29. Confirm that Ring All schedules its reminder only after its single-stage cycle completes.
30. Configure an Ordered rule with destination 1 enabled, destination 2 disabled, and destination 3 enabled. Confirm that the first stage calls destination 1, the next stage calls destination 1 again when Keep Trying is enabled for destination 1 and introduces destination 2, and the following stage calls destination 1 and destination 3 while destination 2 remains absent.
31. Configure an Ordered rule where the first destination has Keep Trying disabled. Confirm that after its introduction stage, that destination does not return in later stages while the next destination is introduced.
32. Configure a multi-destination Ordered stage and confirm the stage does not advance until every call in that stage has finished without acceptance.
33. Confirm that the next Ordered stage waits approximately 60 seconds after the previous stage completes.
34. Confirm that decline, NOANSWER, BUSY, and answered-without-acceptance outcomes continue progression rather than stop it.
35. Confirm that an explicit ACCEPTED response stops progression and prevents pending later calls from being sent.
36. Confirm that Ring All calls every enabled destination on every cycle.
37. Confirm that Ring All shows Keep Trying as visible, unticked, and disabled.
38. Confirm that Ignore Callers does not prevent an Alert Call destination from being called.

Multilingual Alert Call checks:

- Confirm French Repeat alerts use `conf-thereare`, spoken count, optional
	`telephone-number` and DDI, spoken window and `minutes`, caller information,
	and the unchanged accept/decline prompt in that order.
- Confirm French Invert alerts use `queue-less-than`, spoken threshold, and the
	same DDI, window, caller, and accept/decline structure.
- Confirm French uses no queue quantity or voicemail count prompts.
- Confirm Spanish and German switch the entire generated message to English
	without native fragments.
- Confirm no module-owned translated prompt is referenced or required.
- Confirm spoken counts and windows use `SAY NUMBER`, and caller/DID values use
	`SAY DIGITS`.
- Remove one required French prompt and confirm the optional System Recording
	still plays in French, followed by one coherent English generated message
	with no French fragments.
- Confirm the Alert Call Language table appears in Global Settings above
	Default Country Code and shows Language, Locale, Available Codecs, Alert Call
	Status, Alert Call Language, and Sample columns.
- Confirm the table reflects locale directories under `/var/lib/asterisk/sounds`,
	not available or downloadable FreePBX language packs, and reports audio files
	that Repeat Caller can actually use.
- Confirm a leftover locale directory remains visible after its FreePBX language
	pack is removed, then remove `/var/lib/asterisk/sounds/<locale>` and confirm the
	language no longer appears after refresh.
- If a language pack has been removed but still appears in the Alert Call language
	table, check `/var/lib/asterisk/sounds` for leftover locale directories.
- Confirm Available Codecs lists each format found on required Alert Call prompt
	files once, using a clear comma-separated list when multiple formats are
	available.
- Confirm Available Codecs excludes codecs found only in unrelated files elsewhere
	in the locale directory.
- Add an unrelated audio file in another recognised format and confirm that
	format does not appear in Available Codecs.
- Confirm a partial required prompt set can show Available Codecs while Status
	and Alert Call Language still report fallback behaviour.
- Press each row's Sample play button repeatedly and confirm it rotates through
	a Repeat alert, an Invert alert, and the acceptance prompt.
- Confirm a complete French row plays French audio, unsupported languages play
	the displayed English fallback, and incomplete `en_AU` or `en_NZ` rows play
	their displayed complete English fallback without using partial regional audio.
- Remove a required prompt and confirm the sample is rejected rather than
	playing a partial or invalid message.
- Confirm `Native and Original` means all required Alert Call prompts exist and
	the original voice pack is used, regardless of active state.
- Confirm `Native but Adapted` means all required Alert Call prompts exist and an
	approved alternative mapping of source filenames or equivalent prompts is used;
	complete French rows use this status and Alert Call Language shows `French`.
- Confirm `Rejected → Fallback` means the locale was evaluated against the
	required prompt set and failed it; verify Spanish, German, and incomplete
	English/French locales use this status.
- Confirm `Untested → Fallback` means the locale exists locally but has not been
	evaluated; Alert Call Language shows the other language that will actually play.
- Confirm the table column order is Language, Locale, Available Codecs, Status,
	Alert Call Language, and Sample before and after a manual refresh.
- Confirm English and French remain maintainer-supported native profiles even
	when inventory reports one incomplete, with adaptation reported in Status.
- Confirm installed Spanish or German files show `Rejected → Fallback` and no
	developer language override is exposed to admins.
- Confirm active supported and unsupported languages show their native or exact
	English fallback behaviour, installed unsupported languages remain visible,
	no rule language selector exists, and generated language follows the active
	FreePBX language.
- Remove one required English prompt and confirm Alert Call activation is
	blocked with the fallback-language error even when a language directory and a
	complete French native profile exist.
- Restore a complete `en`, `en_GB`, `en_AU`, or `en_NZ` inventory and confirm Alert Call
	can be enabled and unsupported languages resolve to that one complete locale.
- Confirm English locale lookup only uses exact installed candidate directories.
	Confirm `en`, `en_GB`, `en_AU`, and `en_NZ` are selected only when that exact
	directory exists and contains the complete required English prompt inventory.
- Confirm `en`, `en_GB`, `en_AU`, and `en_NZ` retain the established English
	playback, retry, acceptance, decline, timeout, and accepted-elsewhere behaviour.

Timeout and stage-timing checks:

- Confirm an unanswered Alert Call attempt is capped at approximately 30
	seconds, even when the downstream destination is configured for a
	longer ring interval.
- In Ordered mode, confirm the next stage is not attempted immediately after a
	non-accepting outcome and that real wall-clock stage-to-stage delay can be
	longer than exactly 60 seconds due to scheduler pickup timing.

## Upgrade and Preservation

39. On a FreePBX test system, update an installation from the official
repository's existing Git checkout. Do not run Git commands against an
installation that is not a Git checkout. Check for local changes and back
them up before the hard reset; it discards local modifications and commits
that are not on `origin/main`:

```bash
git config --global --get-all safe.directory | grep -Fxq '/var/www/html/admin/modules/repeatcaller' \
	|| git config --global --add safe.directory /var/www/html/admin/modules/repeatcaller
cd /var/www/html/admin/modules/repeatcaller
git status --short --branch
git fetch origin
git reset --hard origin/main
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
```
Confirm both version checks report the expected version before testing
signature state.
40. Confirm rules, incidents, and history remain intact.
41. Review uninstall warning and cleanup expectations before removal.

## Caller ID Managed Elsewhere Check

42. Create or edit a rule with Alert Call enabled.
43. Enter an Alert Call Caller ID.
44. Tick `Caller ID managed elsewhere`.
45. Confirm the Caller ID field becomes blank and disabled and the placeholder disappears.
46. Untick it and confirm the previous value returns.
47. Tick it again and save.
48. Reopen the rule and confirm the field is blank and the previous value has been forgotten.

## Final 1.1.0 Regression Checks

49. In the rule editor, add an Alert Call destination using Add, then repeat using Enter; confirm both methods behave the same.
50. Confirm Alert Call destination changes keep the associated Ignore these callers entry consistent without creating duplicate destinations.
51. In Caller Includes and Caller Excludes, enter values using a mix of spaces, commas, and new lines; save and reload; confirm include/exclude meaning is preserved.
52. Reload the Repeat Caller page and confirm Run Now shows the correct initial availability state.
53. Add an Alert Call destination and confirm the self-trigger warning remains visible for approximately six seconds before disappearing.
54. With Alert Call enabled and Caller ID managed elsewhere disabled, set or change an Alert Call Caller ID and save; confirm the Caller ID is added to Ignore these callers as an external return-path safeguard when the safeguard is triggered, without creating duplicate entries.
55. Remove a Caller ID-generated Ignore these callers entry, change an unrelated rule setting, save, and confirm the Ignore entry is not silently recreated.

56. Set Email From Address to `asterisk@demodomain.name`; confirm From and Reply-To use the dashboard brand and Return-Path uses that address. Repeat with an empty brand and confirm `Repeat Caller` is used.
57. Set it to `JaCoTec TK-System <pbx@mydomain.de>`; confirm From and Reply-To preserve the explicit name and Return-Path uses the extracted address.
58. Test the FreePBX 17 value `PBX-123 &lt;asterisk@demodomain.name&gt;`; confirm the decoded identity is used.
59. Test malformed brackets, invalid addresses, and raw or HTML-encoded CR/LF injection; confirm sending fails with the existing missing From address message.
60. Confirm comma, semicolon, and whitespace-separated recipients, deduplication, and invalid-recipient filtering behave as before.
61. Confirm Alert History still renders an `accepted` Alert Call status as `Incident accepted` after the redundant JavaScript condition was simplified.
62. Inspect module metadata and confirm the dependency minimum is FreePBX 16.0 while supported versions list FreePBX 16.0 and 17.0 separately.
63. Open the rule editor and confirm DID Scope offers `Include All DIDs` (`all`) and `Select DIDs` (`selected`). Include All DIDs must grey out the full route editor without clearing valid saved selections and must match every route. Select DIDs must show `All DIDs` and `No DIDs` for empty lists. With no real rows, either action must be available. Once Included Routes or Excluded Routes contains a real route, the opposite action must remain disabled until the final route is removed. A route in either list must disappear from the selector and return when removed. Confirm synthetic defaults are never saved and the resulting configuration survives scope changes and save/reload. On upgrade, confirm legacy All-DIDs rules with exclusions become Select DIDs while preserving those exclusions and effective matching.
64. Open an existing saved rule whose saved Email Recipients contain at least one valid address and confirm Test Email becomes available.
65. Click Test Email and confirm the message is sent to the rule's saved recipients, not to any unsaved value in the field.
66. Inspect the received message headers and confirm From and Reply-To use the same resolved identity as production alert email.
67. Confirm the body begins `Repeat Caller test email from <system identifier>` using the configured FreePBX System Identifier, and includes the current time and `Source: manual test`.
68. Confirm the success message states the local mailer accepted the message and does not claim external delivery.
69. Edit the Email Recipients field without saving and confirm Test Email becomes unavailable.
70. Reload the saved rule, then separately add and remove a DID route, add and remove a schedule row, and add, remove, and drag/drop reorder an Alert Call destination; confirm each change makes Test Email unavailable.
71. Save the rule, reopen it, and confirm Test Email becomes available again when valid saved recipients exist.
72. Open a rule with no valid saved recipients and confirm Test Email cannot be used.

Run the focused sender contract before the existing PHP contracts:

```bash
php tests/repeat_email_from_contract.php
```

## Useful Checks

Run the job manually:

```bash
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
```

Inspect current incidents:

```sql
SELECT id, rule_id, subject_key, state, first_matched_at, last_matched_at, matched_call_count
FROM repeatcaller_incidents
ORDER BY updated_at DESC;
```

Inspect alert history:

```sql
SELECT incident_id, rule_id, action_type, event_type, stage_n, delivery_status, created_at
FROM repeatcaller_incident_alert_history
ORDER BY created_at DESC;
```

## Uninstall Reminder

Uninstall drops Repeat Caller-owned tables, including incidents and alert history.
Back up data first if you need to preserve installation-test results.
