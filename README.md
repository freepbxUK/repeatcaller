# Repeat Caller 1.1.1 for FreePBX 16 and 17

Repeat Caller turns qualifying inbound call activity into actionable incidents
on FreePBX/PBXact. Repeat mode detects when the same caller reaches a configured
threshold; Invert mode detects when fewer than the expected number of matching
calls occur. Incidents can be reviewed in the GUI and can trigger optional
email and Alert Call notifications.

## Release Status

Repeat Caller 1.1.0 was the first signed FreePBX UK release. It is signed with
the author's developer GPG key, which is certified by the FreePBX Module
Signing v2 master key. FreePBX uses this certification to verify the module's
authenticity and integrity.

The official project repository is
[freepbxUK/repeatcaller](https://github.com/freepbxUK/repeatcaller). Use its
`main` branch for the stable release.

## Introduction

Repeat Caller supports two distinct operating modes, and the correct mode
depends on the use case:

- Repeat mode: detects when the same caller reaches the configured threshold
  within the selected time window. Suitable for repeated contact attempts,
  urgent repeat enquiries, or nuisance/repeat calling scenarios.
- Invert mode: detects when fewer than the configured number of matching calls
  occur within the selected time window. Suitable for expected check-ins,
  lone-worker workflows, welfare checks, scheduled contact, or missing
  expected activity.

It turns qualifying inbound call journeys into actionable incidents for GUI
review, optional Alert Call notifications, and optional email notifications on
FreePBX/PBXact 16 and 17.

Rules support repeat and invert detection modes, caller and DID scoping,
schedule windows, Alert Reminder scheduling, and suppression controls. The
admin page presents active and historical lifecycle views so operators can
review incidents, acceptances, alerts, and suppression decisions in one place.

## Compatibility

Use with FreePBX/PBXact 16 or 17.

- FreePBX/PBXact 16 and 17
- PHP 7.4+
- MariaDB 5.5-compatible schema (utf8/InnoDB key-size compatible)

### Upgrade compatibility

Repeat Caller 1.0.0 is not a supported upgrade source for later releases.
Upgrade compatibility guarantees begin with the first stable release.

## Requirements

- FreePBX/PBXact 16 or 17
- CDR rows available in asteriskcdrdb.cdr
- Inbound Routes configured for DID/CID route matching
- FreePBX Job runner enabled for scheduled background processing
- FreePBX mail support configured for email notifications
- Email From Address configured in Advanced Settings for email sending, using
  either `asterisk@demodomain.name` or
  `PBX-123 <asterisk@demodomain.name>`. A bare address uses the configured
  FreePBX dashboard brand as its display name, an explicit display name is
  preserved, and `Repeat Caller` is the final display-name fallback. Malformed
  or unsafe From identities are rejected.
- Asterisk Manager access available for Alert Call originate
- Optional FreePBX System Recordings for introductory Alert Call playback

## Installing

Repeat Caller is a community module and is not currently listed in the
FreePBX online module repository. Install the signed release files from the
official FreePBX UK project repository or from a trusted pre-staged module
directory.

Do not use:

```sh
fwconsole ma installlocal repeatcaller
```

Use `fwconsole ma install repeatcaller` with one of the methods below.
For each method, ensure module files are present at
`/var/www/html/admin/modules/repeatcaller/` before running the fwconsole
commands. The fwconsole commands intentionally run from a neutral directory
(`cd ~`).

### Option 1: Install signed or pre-staged release files

Download the signed 1.1.0 module files, or place a trusted pre-staged copy, in
`/var/www/html/admin/modules/repeatcaller/`, then run:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

### Option 2: Install from GitHub

Git commands require the modules/repository directory context. After cloning,
switch back to a neutral directory before running fwconsole commands.

FreePBX 16 / PBXact 16 (CentOS 7)

Check whether Git is installed:

```sh
rpm -q git
```

If Git is not installed:

```sh
yum install -y git
```

FreePBX 17 / PBXact 17 (Debian 12)

Check whether Git is installed:

```sh
dpkg -l git
```

If Git is not installed:

```sh
apt update
apt install -y git
```

Then run the following commands as root:

```sh
cd /var/www/html/admin/modules
git clone https://github.com/freepbxUK/repeatcaller.git repeatcaller
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

### Option 3: Install from a local copy

Copy or symlink a local `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

The module appears under Reports > Repeat Caller.

## Updating Repeat Caller

Do not uninstall during an update. Uninstall removes Repeat Caller tables,
rules, settings, incidents, alert state, alert history, suppression history,
the managed alert dialplan fragment/include, and the deployed AGI script.
Uninstalling therefore deletes stored module data; use an update operation
instead.

Check version before and after updating:

```sh
fwconsole ma list | grep -i repeatcaller
grep "<version>" /var/www/html/admin/modules/repeatcaller/module.xml
```

### Option 1: Update from signed release files or a pre-staged module directory

Replace the module files in `/var/www/html/admin/modules/repeatcaller/`, then
run the fwconsole commands from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

### Option 2: Update from GitHub

After the first installation, `fwconsole chown` may cause Git to reject the
module directory when it is owned by the FreePBX web user rather than root.
Run the Git commands as the same administrative account used to update the
module, and add the directory to that account's safe-directory list once:

```sh
git config --global --get-all safe.directory | grep -Fxq '/var/www/html/admin/modules/repeatcaller' \
  || git config --global --add safe.directory /var/www/html/admin/modules/repeatcaller
```

Check for local changes before updating. Do not discard local changes without
reviewing or backing them up. Then update:

```sh
cd /var/www/html/admin/modules/repeatcaller
git status --short --branch
git fetch origin
git reset --hard origin/main
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Only run Git update commands when the installed module directory is a Git
checkout. Check for local changes before updating and back them up if needed;
the hard reset above discards local modifications and commits not present on
`origin/main`.

### Option 3: Update from a local copy

Re-copy or re-link your local `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

After updating, open Reports > Repeat Caller and confirm rules, settings,
active/recent incidents, alert history, and suppression history are still
present.

### Verify the update

After any update, check both the FreePBX module listing and the installed
module metadata:

```sh
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
```

If either command does not report the expected version, the module has not
updated successfully. Correct the update source or module directory first,
then rerun the update before troubleshooting signing or signature verification.

### If the GitHub update did not complete

If the `module.xml` command still reports an older version, the source update
did not complete. An unusual dirty or local Git working-directory condition,
such as a local file named `FETCH_HEAD`, can make `git reset --hard FETCH_HEAD`
ambiguous. Use an explicit remote branch reference such as
`git reset --hard origin/main` instead.

Before any hard reset, inspect the checkout and back up any local changes you
need. A hard reset discards uncommitted changes and local commits not present
on the selected remote branch.

Use Git commands only when `/var/www/html/admin/modules/repeatcaller/` is a Git
checkout containing a `.git` directory. These commands do not apply to an
installation made from unpacked module files. Rerun the documented update:

```sh
cd /var/www/html/admin/modules/repeatcaller
git status --short --branch
git fetch origin
git reset --hard origin/main
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Then verify the version again. Do not proceed to signature troubleshooting
until `module.xml` reports the expected release version.

#### `git fetch origin` cannot find a remote branch

If `git fetch origin` fails with `fatal: Couldn't find remote ref
refs/heads/...`, an older local checkout may have `remote.origin.fetch` pinned
to an obsolete development branch that no longer exists. This is a local Git
checkout configuration condition.

Inspect the configured refspecs with:

```sh
cd /var/www/html/admin/modules/repeatcaller
git config --get-all remote.origin.fetch
```

A normal Repeat Caller checkout fetches all remote branches using:

```text
+refs/heads/*:refs/remotes/origin/*
```

If the fetch configuration points to an obsolete branch, restore the normal
fetch configuration and fetch remote branches again:

```sh
cd /var/www/html/admin/modules/repeatcaller
git status --short --branch
git remote set-url origin https://github.com/freepbxUK/repeatcaller.git
git config --unset-all remote.origin.fetch
git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'
git fetch --prune origin
git branch -r
```

Confirm that `origin/main` is present. Check for local changes and back them up
before the hard reset; the reset below discards local modifications and
commits not reachable from `origin/main`.

```sh
cd /var/www/html/admin/modules/repeatcaller
git reset --hard origin/main
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

If `git fetch origin` fails, fix the Git checkout before running
`fwconsole ma install`; otherwise, FreePBX will install whatever older module
files are already on disk. Verify the installed version again:

```sh
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
```

### Signature warnings after updating an older unsigned release

Some existing installations upgraded from an older unsigned Repeat Caller
release may initially show a signature warning after upgrading to signed
Repeat Caller 1.1.0. This is advisory troubleshooting guidance and does not
mean the issue has been reproduced with Repeat Caller.

First confirm that the signed release files were actually installed. Check the
FreePBX module listing, the installed `module.xml`, and the signature file:

```sh
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
ls -l /var/www/html/admin/modules/repeatcaller/module.sig
```

If the version is not `1.1.0`, this is an update problem rather than a
signature problem. Resolve the source update before continuing. Confirm that
the installed files match the signed release, not just that a `module.sig`
file exists.

Once the correct signed files and version are installed, first try:

```sh
fwconsole ma refreshsignatures
fwconsole chown
fwconsole reload
```

Then recheck Module Admin. If it still says unsigned, continue with the
`Module is Unsigned` procedure below.

#### Module is Unsigned

If the correct Repeat Caller 1.1.0 files are installed but Module Admin still
reports the module as unsigned after the signature refresh, repeat the version,
metadata, and signature-file checks above. If the installed version is not
`1.1.0` or the correct signed files are not present, return to the update
troubleshooting above. Do not troubleshoot signatures until the correct files
are installed.

#### Signed by unknown or untrusted key

A correctly signed module can still be reported as signed by an unknown or
untrusted key if the Asterisk GnuPG keyring lacks the certification needed to
trust the developer key. The developer's public signing key signs the module.
The FreePBX Module Signing v2 master key certifies the developer's public key.
That certification may be absent from the local Asterisk GnuPG keyring even
when the developer public key itself is already present.

The Repeat Caller developer key is the same key used for Registration Watch.
Check its signatures in the Asterisk keyring:

```sh
sudo -u asterisk gpg \
  --homedir /home/asterisk/.gnupg \
  --check-sigs DD6DC4210FD028092D4981A56079D3C0D58322EE
```

If the FreePBX Module Signing v2 master-key certification is missing, preserve
third-party signatures when importing the developer public key:

```sh
grep -qxF 'keyserver-options no-self-sigs-only,no-import-clean' /home/asterisk/.gnupg/gpg.conf \
  || echo 'keyserver-options no-self-sigs-only,no-import-clean' >> /home/asterisk/.gnupg/gpg.conf
```

If `/home/asterisk/.gnupg/gpg.conf` does not already exist, `grep` may display:

```text
grep: /home/asterisk/.gnupg/gpg.conf: No such file or directory
```

This is harmless and expected on systems without an existing GnuPG
configuration file. The subsequent `echo` command automatically creates the
file with the required setting. No additional action is necessary.

Continue with the import and check the signatures again:

```sh
chown asterisk:asterisk /home/asterisk/.gnupg/gpg.conf

curl -s "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x6079D3C0D58322EE" \
  | sudo -u asterisk gpg --homedir /home/asterisk/.gnupg --import

sudo -u asterisk gpg \
  --homedir /home/asterisk/.gnupg \
  --check-sigs DD6DC4210FD028092D4981A56079D3C0D58322EE
```

The second signature check should show the FreePBX Module Signing v2 master-key
certification, identified by `1588A7366BD35B34`. This procedure is advisory
troubleshooting guidance for affected existing installations, not a normal
installation step.

After confirming the certification, refresh signatures and verify the result
in Module Admin:

```sh
fwconsole ma refreshsignatures
fwconsole chown
fwconsole reload
```

Troubleshooting order:

1. Verify the installed module version and `module.xml`.
2. If the version is old, resolve the incomplete source update.
3. If `git fetch origin` fails because an obsolete remote branch is configured,
   restore the normal fetch configuration, fetch branches again, and confirm
   that `origin/main` exists and contains the expected release.
4. Only after the correct signed files are installed, investigate an unsigned
   status and refresh FreePBX's module signatures.
5. If the module is signed but reported as unknown or untrusted, check the
   developer-key signatures and confirm the master-key certification.
6. Refresh signatures and verify the final status in Module Admin.

## Background Processing

Repeat Caller registers a FreePBX job class and task:

```text
repeatcaller :: monitor
```

Useful checks:

```sh
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
```

The job runs on a one-minute schedule and executes the monitor pipeline that
scans CDR journeys, evaluates rules, updates incidents, and processes alerts.

Repeat Caller does not install a daemon, systemd service, custom AMI listener,
webhook sender, or SMS sender.

## Data Model

Canonical Repeat Caller tables:

- `repeatcaller_settings`: module settings, engine status timestamps, global
  Alert Reminder, suppression, and pruning controls, snooze state, and recipients.
- `repeatcaller_rules`: rule definitions, detection mode, thresholds/windows,
  caller/DID scope, Alert Reminder override, suppression override, and alert action
  toggles.
- `repeatcaller_rule_schedules`: per-rule day/time windows.
- `repeatcaller_rule_callers`: per-rule caller include/exclude lists.
- `repeatcaller_rule_dids`: per-rule inbound route include/exclude rows. With
  Select DIDs, exclusions apply only when no explicit includes exist; explicit
  includes form the exact route set. Empty lists are stored as no rows; `All
  DIDs` and `No DIDs` are visual editor defaults only.
- `repeatcaller_seen_calls`: deduplicated inbound call journeys already
  processed.
- `repeatcaller_rule_subject_state`: per-rule/subject evaluation state,
  threshold state, suppression expiry, and active incident linkage.
- `repeatcaller_incidents`: incident lifecycle rows (active, accepted,
  suppressed, expired, closed) with timestamps and acceptance metadata.
- `repeatcaller_incident_alert_state`: per-incident reminder scheduling state.
- `repeatcaller_incident_alert_history`: per-action alert attempts and outcomes
  (GUI/Alert Call/email) with dedupe keys and delivery status.
- `repeatcaller_incident_suppression_history`: suppression audit rows recording
  prevented qualifying incident attempts, timestamps, and clear state.

## Detection and Incident Behaviour

Repeat Caller evaluates inbound journeys collapsed from CDR rows and matches
them against enabled rules.

Fresh installation boundary: a new Repeat Caller installation starts
processing calls from the time it is installed. Calls already present in CDR
data before that time do not retrospectively create incidents, alerts, or
suppression history. Existing installations and normal upgrades retain their
current processing continuity.

- Repeat mode: creates an incident when matching call count reaches threshold
  within the configured window.
- Invert mode: creates an incident when a full configured window completes
  without reaching threshold.

Repeat mode threshold window:

- Repeat mode uses a rolling observation window: the "most recent N minutes"
  where N is the configured observation window in minutes.
- Each call evaluated creates a fresh rolling window from that call's time,
  going back N minutes.
- Later matching calls do not extend the previous window. Each call gets its
  own rolling window evaluation.
- Example rule: Repeat mode, 3 matching calls within 30 minutes, with 24-hour
  suppression.
  - Calls at 09:00, 09:05, and 09:10 meet the rule and create an incident.
  - The incident is accepted.
  - Further calls at 09:20, 09:25, and 09:30 remain part of the same
    continuous qualifying episode because there are still at least 3 matching
    calls in the latest rolling 30-minute window.
  - Those calls update the accepted incident and do not create additional
    suppression-history rows.
  - Once the rolling 30-minute count falls below 3 and the monitor observes
    that clear state, the rule is re-armed.
  - If the caller later reaches 3 matching calls within 30 minutes again
    while the 24-hour suppression period is still active, that new qualifying
    episode is blocked and recorded in Suppressed Incidents.
- Calls or observation windows that belong to the same continuous qualifying
  episode do not create repeated Suppressed Incident rows. Suppression records
  a later, genuinely new qualifying episode after the original condition has
  cleared and re-armed.

Invert mode window evaluation:

- Invert mode evaluates fixed, non-rolling observation windows that advance
  in configured chunks (e.g., 30-minute blocks).
- When a completed window contains fewer than the configured threshold number
  of matching calls, the condition is triggered.

Invert activation timing:

- A newly created enabled Invert rule starts its first observation window from
  when it becomes active, subject to its configured schedule.
- It does not retrospectively evaluate completed windows from before creation
  or activation.
- Re-enabling an Invert rule establishes a new activation boundary.
- Changing an enabled rule from Repeat to Invert, or changing an enabled
  Invert rule's observation window or schedule, re-anchors the Invert
  observation window from the change time.
- Existing persisted Invert window state is preserved across ordinary monitor
  runs and upgrades.

Matching can include:

- caller scope (any, withheld-only, specific caller lists). Caller lists accept spaces, commas or new lines as separators and save back as comma-separated values.
- inbound route scope (Include All DIDs, or Select DIDs where an empty include
  list means all routes minus exclusions and explicit includes form the exact
  route set)
- schedule windows (day/time segments)

Subject identity is tracked per rule and matched caller/route context so the
same rule can independently track separate journeys.

Incident lifecycle behaviour includes:

- creating incidents only when rule conditions are met
- updating matched call counts/timestamps on existing tracked incidents
- accepting incidents by GUI or Alert Call action
- closing incidents when condition-clear logic is observed
- expiring eligible incidents after suppression expiry handling

For repeat incidents, timestamp semantics are:

- First Matched: earliest matching call in the tracked window that
  contributes to that incident.
- Last Matched: most recent matching call contributing to that incident.

Processed call journeys are recorded in `repeatcaller_seen_calls` to prevent
duplicate incident creation from the same journey.

## Alerting

Repeat Caller provides incident visibility and two optional notification
methods:

- GUI incidents, which are always recorded
- Alert Call, which is optional per rule and can be answered from the phone
- Email notifications, which are optional per rule

Alert attempts are reserved in alert history with dedupe keys so the same
incident stage/action/recipient combination is not repeatedly scheduled.

Email recipients are configured per rule, and email delivery is enabled or
disabled per rule.

Alert Call Destinations accepts one or more internal extensions and/or
external telephone numbers separated by commas. External numbers should
normally be entered in the same national dialling format an administrator
would use from a FreePBX extension. The example/placeholder follows the
configured Default Country Code.

When an Alert Call destination is added in the rule editor, Repeat Caller
automatically adds the same value to Ignore these callers and shows a one-time
warning. Alert Call destinations are automatically added to Ignore these
callers to reduce the risk of self-triggering if an alert call routes back
through a monitored DID.

When Alert Call is enabled and Caller ID managed elsewhere is disabled, Repeat
Caller may also add the configured Alert Call Caller ID to Ignore these
callers as an additional self-trigger safeguard for external return paths.
Administrators can remove the Ignore entry if it is not appropriate.

Alert reminder emails now show one reminder line:

- Alert Reminder: displays the Alert Reminder schedule used for that alert, such as Never, Hourly, Daily, or Escalating.

Alert reminder emails also start with the FreePBX System Identifier when it is available, for example: Repeat Caller incident alert from MY-PBX-NAME. If the identifier is unavailable, the email uses a sensible fallback.

Alert Call Caller ID sets the caller ID presented on outbound alert calls. The
preferred format is international E.164 with a leading +, for example
+447812345678. The example/placeholder follows the configured Default Country
Code.

For marked Alert Calls to genuine SIP/PJSIP devices, the module sets the local
outgoing connected-line name to `Repeat Caller` when FreePBX's called-channel
SIP-header handler runs, regardless of the Caller ID managed elsewhere setting.
No caller number is generated or changed, and callee Caller ID and privacy
flags are not modified. Normal outbound caller identity processing remains
in place for external destinations.

The name is applied on the actual called channel after FreePBX has selected
its caller identity, not in the alert launch context. The module uses an
additive BMO dialplan hook in FreePBX's existing called-channel SIP-header
handler; it does not replace shared hooks or change routing or Dial options.
Only marked Repeat Caller calls whose actual endpoint matches both the
FreePBX `DEVICE` technology and exact SIP/PJSIP dial string receive the name.
Local channels, custom devices, and missing or mismatched device records are
excluded, as are trunk endpoints without a matching genuine device record.
Only `CONNECTEDLINE(name,i)` is set, with update propagation inhibited; the
parent channel is not directly modified. If the same endpoint is configured
as both a genuine SIP/PJSIP device and a trunk, these positive device checks
do not separately resolve that conflict; review such configuration before testing.

Forwarding and SIP redirects require live validation. Independent channel
snapshots do not establish real forwarding behavior or identity isolation.
Direct SIP redirect replacement channels can bypass the original called-channel
handler and may therefore miss the label. Test CF, Follow Me, redirects, and
mixed destinations against baseline; external identities must remain unchanged.
Initial SIP identity, endpoint privacy, From-user overrides, and handset
display policies also require PBX testing.

Alert Call destinations and Alert Call caller ID values are administrator-
controlled PBX configuration. Only use trusted values that are appropriate for
your dialplan, routing, and outbound calling policy.

Alert Call progression follows the selected strategy. Ordered stages add one
new destination at a time. Earlier destinations remain eligible in later stages
only when their own Keep Trying option is enabled; for example, destinations
1/2/3 with settings enabled/disabled/enabled produce 1, then 1+2, then 1+3,
then 1+3 repeatedly. Each completed stage pauses for 60 seconds before the
next one. A multi-destination stage advances only after every attempt in that
stage finishes without acceptance. Only an explicit ACCEPTED response stops
progression; NOANSWER, BUSY, DECLINED, answered-no-response, unavailable,
congestion, and failed attempts do not stop progression. Ring All includes
every enabled destination on every cycle. Keep Trying is not applicable to
Ring All and is shown unticked and disabled. Ignore Callers applies only to
inbound detection and never filters Alert Call destinations. Acceptance
cancels pending future attempts and late callbacks cannot restart escalation.

Alert Call originate currently sets a fixed AMI unanswered timeout of 30000 ms
(approximately 30 seconds). This provides a bounded fail-safe so a
single attempt cannot ring indefinitely, but it can end an unanswered call
attempt before a longer downstream FreePBX destination timer (for example ring
group, queue, external route, or custom destination) would naturally finish.

For Ordered strategy, this timeout is part of stage completion timing. Stage
advancement waits for attempt completion, then applies the configured 60-second
inter-stage defer, and is finally picked up by the background scheduler cycle.
As a result, real wall-clock delay between Ordered stages may be longer than an
exact 60 seconds.

Repeat Caller also marks internally originated Alert Call legs and excludes
those marked internal legs from detection. This internal marker is useful for
on-box call legs only and does not survive a call that leaves through a
carrier and re-enters as a new inbound journey. Carrier rewriting and caller
presentation differences may still require administrator judgement.

Alert Call supports optional introductory System Recording playback followed by
a spoken summary of incident details such as caller and DID where available.
The selected System Recording language takes priority over the global FreePBX
Sound Languages setting and remains independent of the generated summary
language. Native multilingual Alert Calls are assembled only from installed
standard Asterisk and FreePBX sounds; caller and DID values use `SAY DIGITS`,
while counts and observation windows use `SAY NUMBER`.

French is an officially supported native profile with a maintainer-approved
adapted prompt mapping for its installed standard prompt vocabulary. Repeat alerts use
`conf-thereare`, followed by the spoken count,
optional `telephone-number` and DDI digits, the spoken observation window and
`minutes`, and caller information where available. Invert alerts use
`queue-less-than` and the spoken threshold with the same DDI, window, and caller
structure. Queue quantity and voicemail prompts are not used.

Spanish and German currently use the coherent whole-message English fallback
because their standard inventories do not provide a straightforward complete
mapping for all required summary and terminal behaviour without misleading
semantics or omitted information.

Repeat Caller never substitutes native words piecemeal and does not ship or
require translated sound recordings. Prompt discovery inspects each locale
directory independently and preserves nested paths; approved locale candidates
are tried explicitly by the capability resolver. If a selected language has no
complete profile, prompt discovery is unavailable, or a required prompt is missing,
Repeat Caller plays the optional System Recording in its selected language and
then switches the entire generated message to English. French is the supported
native non-English generated Alert Call profile in this release.

The Alert Call Language table in Global Settings gives administrators visibility
of language availability, resolved playback language, and sample playback. Its
columns appear in this order:

- **Language:** Friendly language name.
- **Locale:** Raw Asterisk/FreePBX locale identifier.
- **Available Codecs:** Shows the audio codecs available for the required Alert
  Call prompt set for this language/locale.
- **Status:** Whether playback is Native and Original, Native but Adapted,
  Rejected → Fallback, or Untested → Fallback.
- **Alert Call Language:** The actual language Repeat Caller will play.
- **Sample:** Allows administrators to test the generated Alert Call audio.

Active-row highlighting and candidate ordering do not determine the status.
Rules do not configure language, and production does not expose developer
profile overrides.

## Alert Call Language Detection

Languages are detected from locale directories under:

```text
/var/lib/asterisk/sounds
```

Detection is based on audio files that Repeat Caller can actually use for
required Alert Call prompts. This is not the list of available or downloadable
FreePBX language packs.

If a language pack has been removed but still appears in the Alert Call language
table:

- Check `/var/lib/asterisk/sounds` for leftover locale directories.
- Remove `/var/lib/asterisk/sounds/<locale>` if that language should no longer
  appear.

### Native and Original

A complete required Alert Call prompt set is available from the original voice
pack.

### Native but Adapted

A complete required Alert Call prompt set is available through an approved
alternative mapping of source filenames or equivalent prompts.

### Rejected → Fallback

The locale has been evaluated against the required Alert Call prompt set but
does not meet the requirements.

### Untested → Fallback

The locale exists locally but has not yet been evaluated against the required
Alert Call prompt set.

For both fallback states:

- The Alert Call Language column shows the language that will actually be used.
- Fallback selection behaviour is unchanged.

### Available Codecs

Available Codecs is scoped to audio files whose names belong to the required
Alert Call prompt set. It does not represent every codec installed in Asterisk,
every audio format present elsewhere in the language directory, or unrelated
sound files outside the Alert Call prompt set. A listed codec shows that required
prompt files are available in that format; read it together with Status and Alert
Call Language to determine whether the complete native set is usable.

## Alert Call Samples

The Sample button previews the Alert Call experience using generated audio
sequences. Samples rotate through:

- Repeat
- Invert
- Acceptance

Acceptance previews the completion sequence, including the accepted-elsewhere
message, thank you prompt, and goodbye prompt.

Samples are previews only and do not simulate a live Alert Call interaction.
They do not include DTMF input, retries, escalation, or the full call handling
flow.

Generated messages automatically use the active FreePBX language when its
native profile is complete, otherwise they use English. Alert Call cannot be
enabled unless one complete English `en`, `en_GB`, `en_AU`, or `en_NZ` fallback inventory
is available. This validates every required summary, caller/DDI, interaction,
and terminal prompt rather than only checking that a language directory exists.
System Recordings remain independent and can still play in their selected
language.

Caller ID managed elsewhere is enabled by default for new rules. While it is
enabled, Repeat Caller does not set Alert Call Caller ID, leaves the field
blank and disabled, hides the example placeholder, and keeps any typed value
only for the current editor session. Unticking it restores the previous
unsaved value and makes Alert Call Caller ID mandatory when Alert Call is
enabled. Saving with it checked persists a blank Caller ID and forgets the
prior value. Caller presentation may still be managed elsewhere by routing,
trunks, or providers.

DTMF behaviour during Alert Call:

- Press 1: accepts the incident (Alert Call acceptance source)
- Press 2: declines that Alert Call attempt
- Invalid digit: plays retry prompt and retries while attempts remain
- No valid response: records an answered-no-response outcome and leaves the
  incident unaccepted

Declining affects that call attempt path and does not accept or close the
incident. Alert Reminder delivery can continue while the incident remains
active according to Alert Reminder scheduling and eligibility.

## Alert Reminder Modes

Repeat Caller lets each rule schedule Alert Reminders using one of these modes:

- Never
  - Initial alert only.
- Every 5 minutes
  - Repeats every 5 minutes while the incident remains active.
- Hourly
  - Repeats every hour while the incident remains active.
- Daily
  - Repeats every 24 hours while the incident remains active.
- Escalating
  - Uses a Fibonacci-style escalating backoff schedule, starting with shorter
    reminders and gradually increasing the interval up to daily. The wait between
    reminders follows the escalating sequence on a 5-minute base:

    5 min, 5 min, 10 min, 15 min, 25 min, 40 min, 65 min, 105 min, …

    Capped at 24 hours once the interval reaches the daily ceiling.

Stored legacy reminder values from earlier builds are treated as Escalating.

## Suppression

Suppression is tracked per rule and subject using the default 1440 minute
(24 hour) suppression period or an optional per-rule suppression override.

Rule suppression values behave as follows:

- blank: use the default 1440 minute (24 hour) suppression period
- numeric value: override the default for that rule
- 0: disable automatic suppression for that rule

When a qualifying new incident attempt occurs while suppression is active,
Repeat Caller blocks that incident creation and writes a suppression audit row.
Suppression rows are not future placeholders; they represent prevented,
qualifying attempts.

Suppression lifecycle timing:

- Suppression starts when an incident is created. The suppression period is
  calculated from the moment of incident creation and stored immediately on
  the incident row.
- Accepting an incident records responsibility and controls further alerts for
  the existing incident. Acceptance does not start, extend, or reset the
  suppression period.
- While the qualifying condition remains true (rolling call count for Repeat,
  or continuing failed windows for Invert), further matching activity updates
  the accepted incident and does not create suppression-history rows.
- The qualifying condition must first clear: the rolling call count must fall
  below the configured threshold (Repeat), or a window must meet or exceed
  the threshold (Invert).
- The monitor must observe that clear state and re-arm the threshold latch.
- If a new qualifying episode for the same rule and subject occurs after the
  condition has cleared and the latch has re-armed, but before suppression
  expires, Repeat Caller blocks that new episode and records it in Suppressed
  Incidents.
- The threshold latch is intentional and prevents duplicate suppression rows
  while one unbroken qualifying condition is still in progress. After clear
  is observed, new qualifying thresholds can trigger again.

Suppressed Incidents view shows audit rows including matching count, threshold
window context, suppression expiry, and related incident.

The Suppressed Incidents view shows prevented qualifying incident attempts, not
every subject that is currently under suppression.

Clear Suppression is available per suppression row. Clearing suppression allows
immediate retrigger on the next qualifying condition and preserves the audit
row by setting its cleared timestamp.

Suppression history has its own prune policy and can be pruned independently
from incident and alert history.

## Data Retention

Global Settings includes three pruning controls:

- Prune Incident History removes old completed incident records.
- Prune Alert History removes old notification and Alert Call delivery records.
- Prune Suppression History removes old suppression audit records.

Pruning affects historical records only. It does not remove rules, settings,
active incidents, or current monitoring state.

Repeat Caller automatically removes old internal detection records during
pruning to prevent unnecessary database growth.

Retention policies determine how long records are kept. The pruning schedule
determines how often expired records are removed.

Never disables automatic pruning.

Available pruning schedule options are:

- Never
- Hourly
- Daily (default)
- Weekly
- Monthly
- Yearly

## Snooze Monitoring

Snooze Monitoring is a global control in Engine Status.

Available durations are 5 minutes, 15 minutes, 30 minutes, 1 hour, 3 hours, 6 hours,
12 hours, and 24 hours.

- While snoozed, detection and incident lifecycle processing continue.
- Alert Call and Email deliveries are deferred while snooze is active.
- Resume All Rules can be triggered manually before snooze expiry.
- There is no per-rule or per-incident snooze.

## User Interface

Reports > Repeat Caller includes these main sections:

- Engine Status: enabled rules, active incidents, last run, run state, PBX
  time, Enable All Rules/Disable All Rules, Snooze, Resume All Rules, Run Now.
- Global Settings: country code, lookback, global suppression, global Alert Reminder default,
  pruning policies, and maintenance actions. Default Country Code must contain a genuine international country calling code before Repeat Caller can enable any rule, including individual rule enables, Start as Enabled, and Enable All Rules. Disabled rules may still be created and edited.
- Rules: summary table plus Add Rule editor for mode/threshold/window, caller
  and route scope, schedules, Alert Reminder setting, suppression setting, rule-level
  email recipients, and alert actions. Blank suppression uses the default
  24 hours (1440 minutes) period; 0 disables automatic suppression for that rule.
  The Start as control is used only when creating a new rule to choose whether
  it starts enabled or disabled.
  The actions checklist order is GUI, Alert Call, then Email, and the email
  recipient field appears directly above Save Rule. The editor title switches
  to Editing Rule when modifying an existing rule. DID scope uses Include All
  DIDs for unrestricted matching, or Select DIDs for route filtering. In Select
  DIDs, an empty Included Routes list means all routes minus Excluded Routes.
  Use Included Routes or Excluded Routes; once either list contains a route, the
  opposite action is disabled until the final route is removed.
- Rule controls: each rule row includes Status, Edit, and X (delete). While an
  existing rule is being edited, those row actions are greyed out and cannot be
  used until editing is cancelled or saved.
- Rule explanation rows: plain-language explanation beneath each rule row, with
  disabled and edit highlighting. Selecting Status temporarily replaces the
  explainer sentence with a plain-English status summary for that rule. Status
  wording is split into progress within the configured alert window, incident
  lifecycle state, and monitor freshness, then restores the default explanation
  after 15 seconds.
- Active Incidents: live incident table with an Action column for acceptance.
- Recent Incidents: historical/accepted lifecycle view.
- Alert History: per-event action history and delivery outcomes, with incident
  ID shown first.
- Suppressed Incidents: suppression audit rows with clear-suppression action.
- Active Incidents, Recent Incidents, Suppressed Incidents, and Alert History
  use independent 25-record server-side pages with sortable columns and
  persistent sorting preferences. Rules and Schedule retain their existing
  row-batching behaviour.

UI refresh behaviour uses AJAX command allowlisting and section change tokens so
only changed sections refresh. Tables remain in local responsive wrappers with
horizontal scroll where needed.

## Design Notes and Development Considerations

### FreePBX Routing and Dialling

Repeat Caller is an administrator-configured FreePBX/PBXact module. Alert Call
destinations, caller ID, recordings, and outbound routing remain governed by
the PBX configuration. Repeat Caller does not replace FreePBX outbound routes
or impose an alternative dialling policy. The PBX administrator remains
responsible for ensuring configured destinations, caller presentation, and
routing behaviour is appropriate for the deployment.

### Domain-focused Components

Repeat Caller keeps core incident, alerting, and persistence logic close to
the operational workflows they support. Some components intentionally contain
multiple related operations because they represent a single domain area,
rather than splitting closely related behaviour across many small classes with
limited value.

### CDR-based Detection

Repeat Caller uses CDR-based analysis rather than attempting to replace the
live call-processing path. This provides reliable historical context for
repeat activity windows while remaining compatible with the wide variety of
FreePBX routing configurations.

Repeat Caller detects caller activity from PBX CDR data. Highly customised
dialplans, unusual call flows, incomplete CDR records, or carrier-specific
number presentation can affect how calls are observed and matched. Production
rules should be tested with representative calls before they are relied on for
operational handling.

### Administrator Trust Boundary

As with FreePBX itself, Repeat Caller assumes configuration is performed by a
trusted administrator. It does not attempt to duplicate outbound route
controls, dial plan policy, or PBX security decisions.

### Administrative Configuration

The module is designed for trusted PBX administrators. It provides sensible
handling for common inputs, but it does not attempt to prevent every possible
configuration choice that a PBX administrator may intentionally apply.

### Incident Acceptance Behaviour

Accepting an incident records responsibility and controls alert delivery for
that incident. It does not start, extend, or reset the suppression period.
While the qualifying condition remains active, matching activity continues to
update the accepted incident and no additional alerts or reminders are sent.
Once the condition clears and the latch re-arms, any new qualifying episode is
a separate event: if suppression has not yet expired it is blocked and recorded
in Suppressed Incidents; if suppression has already expired the new episode
creates a new incident and follows the normal alert process.

GUI and telephone acceptance stop all further notification cycles for that
incident, regardless of reminder mode, disabled suppression, or suppression
expiry. Queued email and Alert Call attempts are cancelled where still possible;
previously delivered alert history is preserved. State is checked again before
email delivery, but a message already handed to the mailer cannot be recalled,
and acceptance can race with the final external transport handover.

### Compatibility and Development Notes

Legacy compatibility handling remains where required for upgrades and existing
stored data. Internal compatibility identifiers may remain in code or stored
values where needed, while operator-facing text uses current product language.
This is intentional so upgrades can preserve data integrity without carrying
legacy terminology into active administration workflows.

### FreePBX-Native Design

Repeat Caller is built around existing FreePBX concepts rather than
attempting to create a separate telephony layer. It works with established
FreePBX components including inbound routes, scheduled jobs, CDR data, System
Recordings, and administrator-controlled routing.

## Security Model

- Fixed AJAX command allowlist in controller dispatch.
- Module-owned session CSRF token required for AJAX handlers.
- Input normalization and bounded numeric validation for settings/rule fields.
- Settings writes are key-allowlisted.
- SQL operations use prepared statements in repository/controller paths.
- Reconcile lock uses database named locks to prevent concurrent monitor runs.
- Alert and suppression records use dedupe/uniqueness constraints to reduce
  duplicate processing.
- Alert Call originate uses Asterisk Manager access and does not spawn shell
  commands.
- Passive page load does not force monitor execution; monitor is job-driven or
  manually triggered.

## Current Limitations

- Detection is periodic (job cadence), not event-driven.
- Detection depends on CDR visibility/completion timing.
- Very short call flaps between job runs may be missed.
- Caller and DID fidelity depends on CDR/source data quality.
- Withheld or malformed caller identifiers can reduce matching precision.
- Alert Call delivery depends on AMI availability, dialplan deployment,
  playback assets, and destination reachability.
- Alert Call unanswered timeout is fixed at 30000 ms; this is
  intentional for bounded escalation behaviour but may be shorter than some
  downstream destination timing policies.
- Email delivery depends on FreePBX mail configuration and downstream relays.
- Snooze is global rather than per rule/incident.
- No webhook or SMS delivery channel is implemented.

Future release consideration:

- A configurable Alert Call ring timeout is being evaluated for a future minor
  release so administrators can better align escalation timing with downstream
  routing policy while preserving bounded fail-safe behaviour.

## Release History

### 1.1.1, patch release, 20 October 2026

- Stops accepted incidents from generating further GUI, email, or Alert Call
  notifications, including after suppression expiry or with suppression disabled.
  Cancels outstanding email work and checks state again before delivery, while
  preserving accepted-call statistics, clearing/rearming, and new-incident alerts.
- Adds the production acceptance timeline regression and acceptance/queue
  interleaving checks without changing normal active-incident reminder timing.
- Sets the local outgoing connected-line name to `Repeat Caller` on marked
  SIP/PJSIP Alert Call channels matching a genuine FreePBX device's technology
  and dial string, after normal FreePBX caller identity processing.
- Leaves callee Caller ID, caller numbers, privacy flags, existing rule settings,
  destination routing, and internal-origin safeguards unchanged.
- Adds focused generated-dialplan contract coverage for internal, external,
  mixed, custom, unmarked, and privacy-state channel snapshots. Forwarding,
  redirects, and actual SIP presentation remain live PBX test requirements.

### 1.1.0, minor release, 20 October 2026

- First signed FreePBX UK release, published from the official
  `freepbxUK/repeatcaller` repository.
- Updates publisher, licence, project metadata, and release presentation for
  FreePBX UK.
- Preserves explicitly configured display names in FreePBX Email "From:"
  Address values, including HTML-encoded identities on FreePBX 17. Bare
  addresses use the configured FreePBX dashboard brand, with `Repeat Caller`
  as the final fallback, while retaining address and header-injection
  validation.
- Corrects FreePBX 16 and 17 compatibility declarations in module metadata.
- Improves Select DIDs route selection, mutual exclusion between include and
  exclude lists, and compatibility with legacy All-DIDs exclusions without
  changing their effective matching.
- Adds a per-rule Test Email action using the rule's saved recipients, the
  production email transport and resolved From identity, and the configured
  FreePBX System Identifier. It does not add a global recipient setting.
- Removes a redundant Alert History accepted-state JavaScript condition
  without changing behaviour.
- Adds independent 25-record server-side pagination for Active Incidents,
  Recent Incidents, Suppressed Incidents, and Alert History. Sorting is applied
  across the database result set, and each table's sorting preference is
  persisted independently in the database.
- Preserves the existing 15-row Rules and Schedule batching behaviour,
  including keeping each Rules row with its explainer row.
- Expands regression coverage for pagination beyond 300 records, full-dataset
  sorting, preference persistence and races, suppression filtering, email
  identity and delivery, route selection, Test Email, and FreePBX compatibility.

### 1.0.2, patch release, 24 August 2026

- Improves Alert Call language handling for non-English Asterisk sound
  languages.
- Adds a concise native French Alert Call profile using standard installed
  prompts without incorrect queue quantity or voicemail substitutions.
- Uses native non-English Alert Call audio only where installed standard
  Asterisk and FreePBX prompts reproduce the complete established behaviour.
- Uses one coherent English generated-message fallback for incomplete language
  profiles, without module-owned translated recordings or mixed-language audio.
- Prevents mixed-language generated Alert Call audio by using a coherent
  English fallback when prompt discovery is unavailable or a complete native
  prompt set is not installed.
- Preserves the selected System Recording language before generated Alert
  Call audio begins.
- Discovers prompts recursively within each exact requested locale directory
  while preserving nested Asterisk prompt paths; candidate fallback is explicit.
- Uses the selected Alert Call language when speaking counts, durations, telephone numbers, and DIDs.
- Adds an Alert Call Language table showing installed locales, available codecs,
  resolved playback languages, and clear original, adapted, or fallback status
  independently of the highlighted active FreePBX language.
- Adds per-language sample playback that rotates through Repeat, Invert, and
  acceptance scenarios using the same prompt resolution as live Alert Calls.
- Adds regression coverage for multilingual Alert Call behaviour while
  preserving existing `en` and `en_GB` behaviour.

### 1.0.1, patch release, 6 August 2026

- Renamed the reminder terminology to Alert Reminders throughout the module,
  and documented that Alert Reminder scheduling is distinct from Repeat
  detection mode.
- Prevented reminder-cycle overlap while an unfinished Ordered Alert Call cycle
  is active, pending, or deferred.
- Documented that each Alert Reminder cycle restarts Ordered Alert Call delivery
  from stage 0 and that reminder cycles never overlap.
- Documented that Alert Reminder timing begins only after the previous alert
  cycle completes without acceptance.
- Rule explanation-row styling is now consistent across enabled, disabled,
  temporary Status, and editing states.

#### Rule editor reliability

- Caller include/exclude lists now save and reload correctly from the rule
  editor while preserving list semantics.
- Legacy plain-text caller list entry remains supported for compatibility.
- Alert Call destination Add now behaves the same whether triggered by Add or
  Enter.
- Alert Call destination changes now keep the associated Ignore these callers
  entry consistent without creating duplicate destinations.
- Alert Call self-trigger warnings now remain visible for approximately
  6 seconds without changing global FreePBX toast behaviour.
- Run Now now initializes from the current bootstrap state so initial
  availability is shown correctly on load.

#### DID scope controls

- Allows individual inbound routes to be excluded when All DIDs is selected.
- Keeps Selected DIDs only mode limited to explicit route inclusions.
- Clears stale opposite-mode route selections when the DID scope changes.

#### Accepted incident suppression

- Corrected in 1.1.1: accepted incidents cannot reserve or deliver further
  notifications, whether suppression is active, expired, or disabled.
- Preserves normal Alert Reminders for active unaccepted incidents.
- After observed clearing and rearming, a genuinely new qualifying episode can
  create a new incident if suppression is disabled or expired. Suppression
  expiry alone does not rearm an incident or resume accepted-incident reminders.

#### Snooze controls

- Adds 30-minute, 3-hour, 6-hour, 12-hour, and 24-hour global Snooze Monitoring options.

#### Global controls

- Renames the global Enable Monitoring and Disable Monitoring buttons to
  Enable All Rules and Disable All Rules for clearer rule-processing terminology.
- These bulk controls now change both the global Repeat Caller engine state and
  every non-deleted rule's enabled state.

#### Alert Call self-trigger safeguard

- Adding an Alert Call destination now auto-adds the same value to Ignore
  these callers as a safe default, with a one-time warning in the editor.
- Administrators can remove the Ignore entry if needed; it is not silently
  re-added during save, reload, or normal rendering.
- When Repeat Caller sets Alert Call Caller ID, that Caller ID may also be
  added to Ignore these callers as an additional external return-path
  safeguard.
- Internal Repeat Caller originated Alert Call legs are marked and excluded
  from detection; this marker does not survive external PSTN hairpin
  leave-and-return paths.

#### Alert Call Caller ID handling

- Caller ID managed elsewhere is enabled by default for new rules and appears
  directly above Alert Call Caller ID in the rule editor.
- While enabled, Repeat Caller does not set Alert Call Caller ID, leaves the
  field blank and disabled, and hides the example placeholder.
- Unticking restores the previous unsaved editor value and makes Alert Call
  Caller ID mandatory when Alert Call is enabled.
- Saving while enabled persists a blank Caller ID and forgets the previous
  value.
- Caller presentation remains the responsibility of PBX routing, trunks, or
  providers.

#### Ordered Alert Call progression

- Ordered stages add one new destination at a time.
- Earlier destinations remain eligible in later stages only when their own
  Keep Trying option is enabled.
- Each completed stage pauses for 60 seconds before the next stage.
- A multi-destination stage advances only after every attempt in that stage
  finishes without acceptance.
- Only explicit ACCEPTED responses stop progression; other outcomes do not.
- Ring All includes every enabled destination on every cycle, while Keep
  Trying is not applicable and remains unticked and disabled.
- Ignore Callers applies only to inbound detection and never filters Alert
  Call destinations.
- Acceptance cancels pending future attempts and late callbacks cannot restart
  escalation.

#### Invert activation timing

- Newly created enabled Invert rules begin their first observation window from
  activation time, subject to schedule.
- Completed windows from before creation or activation are not retrospectively
  evaluated.
- Re-enabling an Invert rule creates a new activation boundary.
- Changing an enabled rule from Repeat to Invert, or changing an enabled
  Invert rule's schedule or observation window, re-anchors the Invert
  observation window from the time of change.
- Persisted Invert observation state is retained across normal monitor runs
  and upgrades.

## Validation

For operator workflows, see [USER_GUIDE.md](USER_GUIDE.md). For test scope and
execution notes, see [TESTING.md](TESTING.md).

Useful local checks:

```sh
php -l Repeatcaller.class.php
php -l Job.php
php -l page.repeatcaller.php
php -l install.php
php -l uninstall.php
php -l views/main.php
php -l src/Schema.php
php -l src/RepeatCallerRepository.php
php -l src/BackgroundProcessor.php
php -l src/CdrScanner.php
php -l src/IncidentAlertProcessor.php
php -r '$xml = simplexml_load_file("module.xml"); echo $xml ? "module.xml parsed\n" : "module.xml failed\n";'
php tests/repeat_install_contract.php
php tests/repeat_repository_contract.php
php tests/repeat_runtime_contract.php
php tests/repeat_admin_contract.php
php tests/repeat_release_contract.php
```

On a real FreePBX/PBXact system:

```sh
fwconsole ma list | grep -i repeatcaller
fwconsole reload
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
tail -f /var/log/asterisk/full | grep -i repeatcaller
```

## Uninstalling

Uninstall removes Repeat Caller job registration, Repeat Caller tables, managed
dialplan include/fragment, and the deployed AGI callback script. Back up first
if you need existing rules/history. These commands run from a neutral
directory, and the module path removal uses an absolute path.

```sh
cd ~
fwconsole ma uninstall repeatcaller --force
rm -rf /var/www/html/admin/modules/repeatcaller
fwconsole chown
fwconsole reload
```

## Licence

GPLv3+. See LICENSE.

## Support and Feedback

Repeat Caller is maintained by FreePBX UK. For installation
help, configuration questions, or troubleshooting, contact
[FreePBX UK Support](https://freepbx.uk/support/).

The source repository and public issue tracker are
[freepbxUK/repeatcaller](https://github.com/freepbxUK/repeatcaller).

When requesting support or reporting a problem, include relevant information:

- FreePBX/PBXact, PHP, and Asterisk versions.
- Repeat Caller version and installation source (signed release files,
  unpacked module files, or Git checkout).
- The output of `fwconsole ma list | grep -i repeatcaller`.
- The steps needed to reproduce the issue, expected behaviour, and actual
  behaviour.
- Relevant Repeat Caller Alert History status or sanitised log excerpts.

Remove telephone numbers, caller details, email addresses, credentials,
customer data, and other personal or sensitive information unless it is
necessary and safe to share. Never include passwords, SIP secrets, API
credentials, private keys, or complete unredacted call records.

For suspected security vulnerabilities, do not post exploit details or
vulnerability reports to the public issue tracker. Contact FreePBX UK through
its support channel and request private security handling. Include only the
minimum sanitised information needed to assess the report.

## AI-Assisted Contributions and Disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. From 26 August 2026, generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

The 1.1.0 pagination work was assisted by GitHub Copilot GPT-6 Luna. Record
that assistance using the established trailer:

```text
Assisted-by: GitHub-Copilot:gpt-6-luna
```

The human contributor remains solely responsible for the contribution. AI
tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)
