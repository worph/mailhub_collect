# mailhub_collect

Roundcube plugin of the Yundera store's **MailHub** app (built into
[`ghcr.io/worph/mailhub-roundcube`](https://github.com/worph/mailhub-roundcube)): fills
*Collected recipients* from the mail already in the mailbox, and shows where each address was found.

Roundcube only collects the recipients of mail sent from the webmail itself, from the day it is set
up. MailHub mirrors accounts used from other clients for years, so the address book starts nearly
empty and compose autocomplete finds nobody.

## What it does

- **Contacts → Collect** (toolbar) opens *Collect from mailbox*. It scans, one chunk of 250 messages
  per request with a progress bar:
  - every **Sent** folder (`Sent`, `[Gmail]/Sent Mail`, `Sent Items`, `Sent Messages`, `Envoyés`…,
    or the configured `sent_mbox`): the To, Cc and Bcc of each message, the people you wrote to;
  - every other folder, for messages flagged `\Answered`: their From, the people you replied to.

  Trash, Junk/Spam, Drafts and the `Merged/` views (virtual copies) are skipped, and so are your own
  addresses (identities, mirrored accounts `Live/<address>`, the login).
- An address already in an address book (as Roundcube's own collection checks: the default book and
  Collected recipients) is not added again.
- **Incremental:** each folder is scanned from where the last run stopped (highest UID, per
  UIDVALIDITY; pref `mailhub_collect_state`). Running it again only reads new mail, and the counts stay right.
  Closing the dialog stops the run; the next one continues.
- **"Collected from"** in the contact view (any address book): one line per origin, e.g.
  *You wrote to them (12×) — Live/a@b/[Gmail]/Sent Mail (2019-03-01 → 2026-10-02)*. Kinds:
  `sent`, `replied`, and `webmail` (mail sent from MailHub from now on, recorded on `message_sent`).
  Stored in the table `mailhub_collect_origin` (user, email, kind, folder, hits, first and last
  message date).

## Install

Needs Roundcube 1.6+ (developed on 1.7.5). Put the folder in `plugins/mailhub_collect`, add
`mailhub_collect` to `plugins`, and create the table from `SQL/<engine>.initial.sql` (it records
`mailhub_collect-version` in `system`). In mailhub-roundcube the Dockerfile clones a tag of this repo
and `post-setup/10-plugin-schemas.php` creates the table.

English and French texts in `localization/`.
