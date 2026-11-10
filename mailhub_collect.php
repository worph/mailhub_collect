<?php

/**
 * MailHub: fill "Collected recipients" from the mail already in the mailbox, and
 * remember where each address was found.
 *
 * Roundcube only collects the recipients of mail sent from the webmail itself,
 * from the day it was set up. MailHub mirrors accounts that were used from other
 * clients for years, so the address book starts nearly empty. This plugin adds a
 * "Collect from mailbox" button to Contacts which scans:
 *   - every Sent folder (the To, Cc and Bcc of each message): people you wrote to;
 *   - every other folder, for messages flagged \Answered (their From): people you
 *     replied to.
 * Trash, Junk/Spam, Drafts and the Merged views (virtual copies) are skipped, and
 * so are your own addresses (identities and mirrored accounts).
 *
 * Addresses already in an address book are not added again (as Roundcube's own
 * collection does). Each folder is scanned from where the last run stopped (its
 * highest UID, per UIDVALIDITY), so the button can be pressed again later and the
 * counts stay right.
 *
 * Where an address came from is kept in its own table, mailhub_collect_origin
 * (kind: sent / replied / webmail, folder, count, first and last message date),
 * and shown as "Collected from" in the contact view. Mail sent from the webmail
 * from now on is recorded as "webmail".
 */
class mailhub_collect extends rcube_plugin
{
    public $task = 'mail|addressbook';

    /** Messages read per request. */
    private const CHUNK = 250;
    private const STATE = 'mailhub_collect_state';
    private const TABLE = 'mailhub_collect_origin';

    /** Last path segment (lower case) of a Sent folder. */
    private const SENT = ['sent', 'sent mail', 'sent items', 'sent messages', 'sent-mail', 'outbox',
        'envoyés', 'messages envoyés', 'éléments envoyés', 'gesendet', 'gesendete elemente',
        'enviados', 'inviati', 'verzonden'];
    /** ...of a folder that is never scanned. */
    private const SKIP = ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'corbeille',
        'junk', 'spam', 'confirmed-spam', 'drafts', 'brouillons', 'templates'];

    private $self = null;
    private $known = [];

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_hook('message_sent', [$this, 'message_sent']);

        if ($rcmail->task != 'addressbook') {
            return;
        }

        $this->add_hook('contact_form', [$this, 'contact_form']);
        $this->register_action('plugin.mailhub_collect.plan', [$this, 'plan']);
        $this->register_action('plugin.mailhub_collect.scan', [$this, 'scan']);

        if ($rcmail->action == '' || $rcmail->action == 'index') {
            $this->add_texts('localization/', true);
            $this->include_script('mailhub_collect.js');
            $this->include_stylesheet('mailhub_collect.css');
            $this->add_button([
                'type' => 'link',
                'label' => 'button',
                'title' => 'buttontitle',
                'domain' => $this->ID,
                'command' => 'plugin.mailhub_collect',
                'class' => 'button mh-collect',
                'classact' => 'button mh-collect',
                'innerclass' => 'inner',
            ], 'toolbar');
        }
    }

    // --- folders ------------------------------------------------------------------

    /** The folder's name, decoded, and its last segment in lower case. */
    private static function leaf($folder, $delim)
    {
        $name = rcube_charset::convert($folder, 'UTF7-IMAP');
        $parts = explode($delim, $name);

        return mb_strtolower(end($parts));
    }

    /** 'sent', 'replied' or null (not scanned). */
    private function kind($folder, $delim)
    {
        $parts = explode($delim, $folder);
        if ($parts[0] === 'Merged') {
            return null;
        }
        $leaf = self::leaf($folder, $delim);
        $sent_mbox = rcmail::get_instance()->config->get('sent_mbox');
        if ($folder === $sent_mbox || in_array($leaf, self::SENT, true)) {
            return 'sent';
        }
        foreach (array_slice($parts, $parts[0] === 'Live' ? 2 : 0) as $p) {
            $p = mb_strtolower(rcube_charset::convert($p, 'UTF7-IMAP'));
            if (in_array($p, self::SKIP, true)) {
                return null;
            }
        }

        return 'replied';
    }

    /** The user's own addresses: identities, mirrored accounts, login. */
    private function self_addresses()
    {
        if ($this->self !== null) {
            return $this->self;
        }
        $rcmail = rcmail::get_instance();
        $self = [];
        foreach ($rcmail->user->list_emails() as $identity) {
            $self[] = $identity['email'];
        }
        $storage = $rcmail->get_storage();
        $delim = $storage->get_hierarchy_delimiter();
        foreach ($storage->list_folders('', 'Live' . $delim . '%') as $f) {
            $self[] = substr($f, 5);
        }
        $self[] = $rcmail->user->get_username();
        $this->self = array_flip(array_filter(array_map('mb_strtolower', $self)));

        return $this->self;
    }

    // --- actions ------------------------------------------------------------------

    /** The folders to scan, in order: Sent ones first. */
    public function plan()
    {
        $rcmail = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $delim = $storage->get_hierarchy_delimiter();
        $sent = $replied = [];

        foreach ($storage->list_folders('', '*', null, null, true) as $folder) {
            $kind = $this->kind($folder, $delim);
            $job = ['folder' => $folder, 'kind' => $kind,
                'name' => rcube_charset::convert($folder, 'UTF7-IMAP')];
            if ($kind === 'sent') {
                $sent[] = $job;
            } elseif ($kind === 'replied') {
                $replied[] = $job;
            }
        }

        $rcmail->output->command('plugin.mailhub_collect_plan', ['jobs' => array_merge($sent, $replied)]);
        $rcmail->output->send();
    }

    /** One chunk of one folder; the client calls again until done. */
    public function scan()
    {
        $rcmail = rcmail::get_instance();
        $folder = rcube_utils::get_input_string('_folder', rcube_utils::INPUT_POST, true);
        $delim = $rcmail->get_storage()->get_hierarchy_delimiter();
        $kind = $this->kind($folder, $delim);
        $out = ['folder' => $folder, 'added' => 0, 'messages' => 0, 'done' => true, 'left' => 0];

        if ($kind !== null) {
            try {
                $out = array_merge($out, $this->scan_chunk($folder, $kind));
            } catch (Exception $e) {
                rcube::raise_error($e, true, false);
                $out['error'] = true;
            }
        }

        $rcmail->output->command('plugin.mailhub_collect_step', $out);
        $rcmail->output->send();
    }

    private function scan_chunk($folder, $kind)
    {
        $rcmail = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $storage->check_connection();
        $conn = $storage->conn;

        if (!$conn->select($folder)) {
            return ['done' => true];  // \Noselect containers ([Gmail], Live/<address>)
        }
        $validity = (string) ($conn->data['UIDVALIDITY'] ?? '');
        $state = (array) $rcmail->config->get(self::STATE, []);
        $from = 1;
        if (isset($state[$folder]) && (string) $state[$folder]['v'] === $validity) {
            $from = (int) $state[$folder]['u'] + 1;
        }

        $criteria = "UID $from:*" . ($kind === 'replied' ? ' ANSWERED' : '');
        $index = $conn->search($folder, $criteria, true);
        // "n:*" always matches the highest UID, even below n
        $uids = array_values(array_filter(array_map('intval', $index->get()), static fn ($u) => $u >= $from));
        sort($uids);
        if (!$uids) {
            return ['done' => true];
        }

        $chunk = array_slice($uids, 0, self::CHUNK);
        $headers = $conn->fetchHeaders($folder, $chunk, true, false, ['BCC']);
        $added = 0;
        foreach ((array) $headers as $h) {
            $time = $h->timestamp ?: rcube_utils::strtotime($h->date ?: $h->internaldate);
            $list = $kind === 'sent'
                ? [$h->to, $h->cc, $h->others['bcc'] ?? '']
                : [$h->from];
            $added += $this->collect(implode(', ', array_filter($list)), $kind, $folder, $time);
        }

        $state[$folder] = ['v' => $validity, 'u' => max($chunk)];
        $rcmail->user->save_prefs([self::STATE => $state]);

        return ['added' => $added, 'messages' => count($chunk),
            'done' => count($uids) <= self::CHUNK, 'left' => max(0, count($uids) - self::CHUNK)];
    }

    // --- collecting ---------------------------------------------------------------

    /**
     * Record each address of $list as found ($kind, $folder, at $time), and add it
     * to Collected recipients when no address book has it. Returns how many were added.
     */
    private function collect($list, $kind, $folder, $time)
    {
        $rcmail = rcmail::get_instance();
        if (trim((string) $list) === '') {
            return 0;
        }
        $self = $this->self_addresses();
        $added = 0;

        foreach (rcube_mime::decode_address_list($list, null, true) as $addr) {
            $email = mb_strtolower(trim((string) ($addr['mailto'] ?? '')));
            if ($email === '' || isset($self[$email]) || !rcube_utils::check_email($email, false)) {
                continue;
            }
            $this->record_origin($email, $kind, $folder, $time);

            if (isset($this->known[$email])) {
                continue;
            }
            $this->known[$email] = true;
            $type = rcube_addressbook::TYPE_DEFAULT | rcube_addressbook::TYPE_RECIPIENT;
            if ($rcmail->contact_exists($email, $type)) {
                continue;
            }
            $book = $this->collected_book();
            $name = trim((string) ($addr['name'] ?? ''), " \t\"'");
            if ($name === $email) {
                $name = '';
            }
            if ($book && $book->insert(['name' => $name, 'email' => $email], true)) {
                $added++;
            }
        }

        return $added;
    }

    private function collected_book()
    {
        $rcmail = rcmail::get_instance();
        $id = (string) $rcmail->config->get('collected_recipients');

        return strlen($id) ? $rcmail->get_address_book($id) : null;
    }

    private function record_origin($email, $kind, $folder, $time)
    {
        $rcmail = rcmail::get_instance();
        $db = $rcmail->get_dbh();
        $table = $db->table_name(self::TABLE, true);
        $date = $db->quote(date('Y-m-d H:i:s', $time ?: time()));
        $user = $rcmail->user->ID;

        $res = $db->query(
            "UPDATE $table SET `hits` = `hits` + 1,"
            . " `first_seen` = CASE WHEN `first_seen` > $date THEN $date ELSE `first_seen` END,"
            . " `last_seen` = CASE WHEN `last_seen` < $date THEN $date ELSE `last_seen` END"
            . ' WHERE `user_id` = ? AND `email` = ? AND `kind` = ? AND `folder` = ?',
            $user, $email, $kind, $folder
        );
        if (!$db->affected_rows($res)) {
            $db->query(
                "INSERT INTO $table (`user_id`, `email`, `kind`, `folder`, `hits`, `first_seen`, `last_seen`)"
                . " VALUES (?, ?, ?, ?, 1, $date, $date)",
                $user, $email, $kind, $folder
            );
        }
    }

    /** Mail sent from the webmail: Roundcube collects it, we note where from. */
    public function message_sent($args)
    {
        $headers = (array) ($args['headers'] ?? []);
        $list = implode(', ', array_filter([$headers['To'] ?? '', $headers['Cc'] ?? '', $headers['Bcc'] ?? '']));
        try {
            // nothing to add (core collection runs right after): just the origin
            $this->known = [];
            $self = $this->self_addresses();
            foreach (rcube_mime::decode_address_list($list, null, true) as $addr) {
                $email = mb_strtolower(trim((string) ($addr['mailto'] ?? '')));
                if ($email !== '' && !isset($self[$email])) {
                    $this->record_origin($email, 'webmail', '', time());
                }
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return $args;
    }

    // --- contact view -------------------------------------------------------------

    /** "Collected from" lines in the contact view, for any address book. */
    public function contact_form($args)
    {
        $rcmail = rcmail::get_instance();
        if ($rcmail->action != 'show' || empty($args['record']) || !isset($args['form']['contact'])) {
            return $args;
        }
        $emails = array_map('mb_strtolower', (array) rcube_addressbook::get_col_values('email', $args['record'], true));
        if (!$emails) {
            return $args;
        }

        $this->add_texts('localization/');
        $db = $rcmail->get_dbh();
        $res = $db->query(
            'SELECT `kind`, `folder`, `hits`, `first_seen`, `last_seen` FROM ' . $db->table_name(self::TABLE, true)
            . ' WHERE `user_id` = ? AND `email` IN (' . implode(',', array_map([$db, 'quote'], $emails)) . ')'
            . ' ORDER BY `hits` DESC',
            $rcmail->user->ID
        );
        $lines = [];
        while ($row = $db->fetch_assoc($res)) {
            $where = $row['folder'] === '' ? '' : ' — ' . rcube_charset::convert($row['folder'], 'UTF7-IMAP');
            $first = substr($row['first_seen'], 0, 10);
            $last = substr($row['last_seen'], 0, 10);
            $lines[] = $this->gettext(['name' => 'origin_' . $row['kind'], 'vars' => ['n' => $row['hits']]])
                . $where . ' (' . ($first === $last ? $last : "$first → $last") . ')';
        }
        if (!$lines) {
            return $args;
        }

        $args['form']['mailhub_collect'] = [
            'name' => $this->gettext('collectedfrom'),
            'content' => ['mailhub_origin' => ['label' => $this->gettext('origin'), 'value' => $lines]],
        ];

        return $args;
    }
}
