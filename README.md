# Repeat Caller 1.2.0 for FreePBX 16 and 17

Repeat Caller turns qualifying inbound call activity into actionable incidents
on FreePBX/PBXact. Repeat mode detects when the same caller reaches a configured
threshold; Invert mode detects when fewer than the expected number of matching
calls occur. Incidents can be reviewed in the GUI and can trigger optional
email and Alert Call notifications.

## Release Status

The last published release is 1.0.2. Version 1.1.0 was used for development
work but was never published as a release. Version 1.2.0 is under development
on the `1-2-0_Dev` branch and is intended to be the next release, consolidating
all development since 1.0.2. It has not been published, signed, or approved for
production use.

FreePBX module signing uses a developer GPG key certified by the FreePBX
Module Signing v2 master key. The signing guidance below documents how to
verify that trust chain; it does not assert that a particular release was
signed.

The official project repository is
[freepbxUK/repeatcaller](https://github.com/freepbxUK/repeatcaller). Use a
verified 1.0.2 archive for the last published release. The repository branches
contain development code; use `1-2-0_Dev` only on a test system.

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
Upgrade compatibility starts from the published 1.0.2 baseline. A live upgrade
from 1.0.2 to 1.2.0 has not yet been verified; test that path before production
use.

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

Repeat Caller is not listed in the FreePBX online module repository. Select
the source deliberately: use a verified 1.0.2 archive for the last published
release, or use the `1-2-0_Dev` branch for testing. Do not install a development
checkout on a production PBX. Place local module files at
`/var/www/html/admin/modules/repeatcaller/`, then run
`fwconsole ma installlocal repeatcaller` to register/install or upgrade the
local files, enable the module if needed, and reload FreePBX. Run fwconsole
commands from a neutral directory
(`cd ~`).

### Option 1: Install the published 1.0.2 release

Use the verified 1.0.2 release archive from your trusted source. The local Git
history identifies 1.0.2 as the last published version, but does not establish
the signature status of every distributed archive. Stage the module files in
`/var/www/html/admin/modules/repeatcaller/`, then run:

```sh
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
fwconsole chown
fwconsole reload
```

### Option 2: Install the 1.2.0 development branch for testing

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

Clone the development branch only on a non-production test PBX. Then run the
following commands as root:

```sh
cd /var/www/html/admin/modules
git clone --branch 1-2-0_Dev https://github.com/freepbxUK/repeatcaller.git repeatcaller
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
fwconsole chown
fwconsole reload
```

### Option 3: Install a future signed 1.2.0 package or another local copy

After 1.2.0 is formally released and signed, stage that verified package here.
Until then, do not treat the development checkout as a signed release. To use
another local development copy, copy or symlink its `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
fwconsole chown
fwconsole reload
```

The module appears under Reports > Repeat Caller.

## Updating Repeat Caller

Do not uninstall during an update. Uninstall removes Repeat Caller tables,
rules, settings, incidents, alert state, alert history, suppression history,
the module's generated Alert Call dialplan contribution, and the deployed AGI
script. Legacy module-owned dialplan artefacts are also cleaned up selectively;
unrelated administrator dialplan content is retained.
Uninstalling therefore deletes stored module data; use an update operation
instead.

1.1.0 was not released and is not an upgrade prerequisite. The intended direct
upgrade to the forthcoming 1.2.0 is from 1.0.2, but this path has not yet been
verified on a live PBX; validate it on a FreePBX/PBXact test system before
production use.

Migration ordering: on FreePBX 16.0.45, the BMO `install()` hook runs before
legacy `install.php`. BMO now performs a read-only legacy-include preflight
before schema creation; `install.php` repeats the preflight before its own
schema call. The legacy hook then ensures the schema, deploys the AGI, removes
the module-owned legacy dialplan, and registers the job. Apply Config or reload
is still required to write the BMO-generated contexts. The FreePBX 17 hook
ordering has not been verified live.

The hooks are not one transaction. If the first preflight finds an unmanaged
reference, it stops before the BMO schema changes. If configuration changes
after that check, or a later filesystem operation fails, the BMO schema may
already have been created or migrated; the legacy cleanup rechecks before
editing, and no database rollback is attempted. Schema installation is
idempotent and existing rules/history are preserved. Resolve the reported
administrator include, then rerun the local module upgrade, enable the module
if needed, and reload; cleanup and job registration can then complete. An AGI
deployment completed before a later cleanup conflict is harmless to the
administrator dialplan and is replaced on retry.

Check version before and after updating:

```sh
fwconsole ma list | grep -i repeatcaller
grep "<version>" /var/www/html/admin/modules/repeatcaller/module.xml
```

### Option 1: Update from the 1.0.2 archive or a future signed 1.2.0 package

Replace the module files in `/var/www/html/admin/modules/repeatcaller/`, then
run the local module upgrade, enablement check, and reload from a neutral
directory:

```sh
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
fwconsole chown
fwconsole reload
```

### Option 2: Update a development Git checkout for testing

After the first installation, `fwconsole chown` may cause Git to reject the
module directory when it is owned by the FreePBX web user rather than root.
Run the Git commands as the same administrative account used to update the
module, and add the directory to that account's safe-directory list once:

```sh
git config --global --get-all safe.directory | grep -Fxq '/var/www/html/admin/modules/repeatcaller' \
  || git config --global --add safe.directory /var/www/html/admin/modules/repeatcaller
```

Check for local changes before updating and preserve any changes you need. Use
`git checkout` rather than `git switch` for compatibility with older Git
versions found on some FreePBX 16 systems. The following updates the development
branch for testing; it is not an update to a published release:

```sh
cd /var/www/html/admin/modules/repeatcaller
git status --short --branch
git fetch origin
git checkout 1-2-0_Dev
git pull --ff-only origin 1-2-0_Dev
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
fwconsole chown
fwconsole reload
```

Do not use an online repository upgrade for this development version; it is not
published there. `installlocal` runs install or upgrade hooks against the
checked-out files, `enable` ensures the module is enabled, and `reload` applies
generated dialplan. Run Git commands only when the installed module directory
contains a `.git` directory. A Git fast-forward is not evidence that a 1.0.2 to
1.2.0 live PBX upgrade has been verified; use a test PBX and confirm rules,
incidents, alert history and generated dialplan before production use.

### Option 3: Update from a local copy

Re-copy or re-link your local `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma installlocal repeatcaller
fwconsole ma enable repeatcaller
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

### If the Git update did not complete

If the installed version is still old, verify the Git branch and update result
before running FreePBX module commands. Preserve local changes; do not use a
hard reset to force the checkout to a remote branch. After a successful source
update, run `fwconsole ma installlocal repeatcaller`, enable the module if
needed, reload, and verify both the module listing and installed `module.xml`.
Do not proceed to signature troubleshooting until the expected published
version and signed files are installed. This applies only to a published
package that is independently expected to be signed; development checkouts are
not release packages.

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

Confirm that the intended remote branch is present. Preserve local changes and
use the non-destructive checkout/pull procedure above; do not reset the working
tree to a remote branch.

If `git fetch origin` fails, fix the Git checkout before running
`fwconsole ma installlocal`; otherwise, FreePBX will install whatever older module
files are already on disk. Verify the installed version again:

```sh
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
```

### Signature warnings for a published package

Use this procedure only for a published package whose signing status has been
independently established. It does not imply that development version 1.1.0 or
1.2.0 was published or signed.

First confirm that the signed release files were actually installed. Check the
FreePBX module listing, the installed `module.xml`, and the signature file:

```sh
fwconsole ma list | grep -i repeatcaller
grep -m1 '<version>' /var/www/html/admin/modules/repeatcaller/module.xml
ls -l /var/www/html/admin/modules/repeatcaller/module.sig
```

If the installed version does not match the published package you intended to
install, resolve the source update before continuing. Confirm that the files
match that package; a `module.sig` file alone does not establish publication or
signature validity.

Once the correct signed files and version are installed, first try:

```sh
fwconsole ma refreshsignatures
fwconsole chown
fwconsole reload
```

Then recheck Module Admin. If it still says unsigned, continue with the
`Module is Unsigned` procedure below.

#### Module is Unsigned

If files from a published package expected to be signed are installed but
Module Admin still reports the module as unsigned after the signature refresh,
repeat the version, metadata, and signature-file checks above. If the package
identity or signing status is uncertain, resolve that first; do not infer it
from an untagged development checkout.

#### Signed by unknown or untrusted key

A package signed by the Repeat Caller developer key can be reported as signed
by an unknown or untrusted key if the Asterisk GnuPG keyring lacks the
certification needed to trust that key. The FreePBX Module Signing v2 master key
certifies the developer's public key. That certification may be absent from
the local Asterisk GnuPG keyring even when the developer public key itself is
already present.

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
   that the intended source branch exists. A repository branch is not, by
   itself, evidence of a published release.
4. Only for a published package independently known to be signed, investigate
  an unsigned status and refresh FreePBX's module signatures.
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

Any caller includes recognised anonymous/withheld identities unless Exclude
withheld callers is selected. Withheld-only selects those identities; specific
telephone-number lists do not identify anonymous callers. Missing Caller ID and
standard anonymous, withheld, restricted, unavailable, private, or unknown
markers, including formatted CDR name/number fields, share one withheld subject
per rule and inbound route. They are counted as separate call journeys, not as
separately identifiable people. A visible number in the CDR remains a visible
caller even if its display name says Anonymous. Malformed or provider-specific
identities and missing DID data still require inspection of the original CDRs.

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

Marked Repeat Caller Alert Calls establish `CALLERID(name)=Repeat Caller` on
the originating launch channel before dialling the existing FreePBX destination.
This follows the live-tested application-originated call approach, without
pretending to be an extension. No caller number is generated or changed, and
privacy flags are not modified. An explicitly configured Alert Call Caller ID
number remains in place; managed-elsewhere number selection is unchanged.

FreePBX can replace the full identity while processing an internal call. An
additive BMO call at its `macro-user-callerid` continuation re-establishes the
originating name after that rewrite and before the next Dial. Before normal
external user/trunk identity processing, the saved original name is restored;
the external leg is then left to the existing PBX policy. Inherited bookkeeping
is per channel, so an external child does not rename its internal siblings.
Only `CALLERID(name)` is assigned; the old connected-line handler is removed.
FreePBX's SIP-header handler and all caller numbers, privacy settings, routes,
destinations, playback and response handling remain unchanged.

The expected live result is: extension rings, primary name `Repeat Caller`.
A secondary IP address is acceptable and is not removed by inventing a number.
All Repeat Caller Alert Call contexts are contributed through FreePBX BMO
generation and appear in `extensions_additional.conf` after reload. Repeat
Caller owns no custom Asterisk `.conf` file and does not add an include to or
depend on `extensions_custom.conf`. Upgrade and uninstall remove only the
legacy module fragment and its exact managed include block, preserving all
other administrator configuration. If an active unmanaged include still
references the legacy fragment after the exact managed block is removed in
memory, cleanup aborts before changing either file; resolve that administrator
reference and retry the upgrade or uninstall.
Forwarding and SIP redirects require live validation. Independent channel
snapshots do not establish real forwarding behavior or identity isolation.
Test CF, Follow Me, redirects, queues, Ring Groups and mixed destinations against
baseline; external identities must remain unchanged. Custom dialplan that bypasses
FreePBX's normal user/trunk identity contexts needs particular live verification.
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
native non-English generated Alert Call profile.

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

### 1.2.0, under development

This planned release consolidates development since the published 1.0.2
release, including work originally tracked under 1.1.0. Version 1.1.0 was not
published; 1.2.0 itself remains unpublished and unsigned while in development.

#### Administration, usability and compatibility

- Adds independent 25-record server-side pagination for Active Incidents,
  Recent Incidents, Suppressed Incidents, and Alert History, with persistent
  per-table sorting across the full result set. Each table's sorting preference is
  persisted independently in the database. Rules and Schedule retain their
  existing row batching.
- Adds a per-rule Test Email action using saved recipients, the production mail
  transport and resolved From identity, and the configured FreePBX System
  Identifier.
- Improves DID route selection, including exclusive include/exclude semantics
  and compatibility for existing route configurations.
- Preserves configured email From display names, including FreePBX 17 encoded
  identities; bare addresses use the FreePBX dashboard name with `Repeat
  Caller` as fallback.
- Improves Run Now feedback with execution and incident counts, explains when
  monitoring is disabled, and does not replay already processed call journeys.
- Updates publisher, licence, repository metadata, and compatibility
  declarations for FreePBX UK and FreePBX/PBXact 16 and 17.

#### Detection and incident lifecycle

- Recognises anonymous, withheld, restricted, unavailable, private, unknown,
  and formatted CDR caller identities. Any Caller rules include withheld calls
  unless explicitly excluded; withheld calls share a subject per rule and
  inbound route while remaining separate call journeys.
- Preserves caller/route filters, thresholds, time windows, schedules, rolling
  counts, and call-journey deduplication with the corrected normalisation.
- Prevents further GUI, email, and Alert Call notifications after acceptance,
  regardless of suppression duration. Queued work is cancelled where possible
  and email state is rechecked before delivery; work already handed to an
  external transport cannot be recalled.
- Keeps accepted incident state and subject linkage authoritative during
  suppression reconciliation. Observed clearing and rearming still allow a new
  qualifying episode according to the configured suppression period.

#### Alert Calls and upgrade reliability

- Moves Alert Call contexts to FreePBX BMO generation and selectively removes
  the legacy module fragment and exact managed include. Cleanup refuses to
  delete a fragment still referenced by an unmanaged include; unrelated
  administrator dialplan is preserved.
- Sets the originating `CALLERID(name)` to `Repeat Caller` before destination
  dialling, reapplies it after normal internal FreePBX identity rewriting, and
  restores the saved name before external identity processing. Caller numbers,
  privacy flags and routing are not intentionally changed; the obsolete
  connected-line handler is removed.
- Removes the unused legacy summary helper and its undefined acceptance-check
  Gosub references. The active AGI session handles summary playback, DTMF
  acceptance/decline, retries and no-response outcomes, persisting a response
  before closing prompts.
- Expands regression coverage across detection, acceptance, queued notification
  races, generated dialplan, installation and release documentation. Actual
  BMO generation, upgrade preservation, SIP identity, forwarding and handset
  presentation still require live FreePBX/PBXact testing.

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

- Accepted incidents cannot reserve or deliver further notifications, whether
  suppression is active, expired, or disabled.
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
php tests/repeat_detection_contract.php
php tests/repeat_repository_contract.php
php tests/repeat_runtime_contract.php
php tests/repeat_admin_contract.php
php tests/repeat_alerting_contract.php
php tests/repeat_completion_contract.php
php tests/repeat_email_from_contract.php
php tests/repeat_release_contract.php
php tests/repeat_test_email_contract.php
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

Uninstall removes Repeat Caller job registration, Repeat Caller tables, the
generated dialplan contribution on reload, and the deployed AGI callback script.
It also selectively removes any legacy module fragment/managed include block,
without deleting `extensions_custom.conf` or changing unrelated content. Back up first
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

The pagination work developed during the unreleased 1.1.0 development period
was assisted by GitHub Copilot GPT-6 Luna. Record that assistance using the
established trailer:

```text
Assisted-by: GitHub-Copilot:gpt-6-luna
```

The human contributor remains solely responsible for the contribution. AI
tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)
