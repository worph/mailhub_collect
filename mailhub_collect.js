/**
 * MailHub: "Collect from mailbox" in Contacts. Asks the server for the folders to
 * scan, then scans them one chunk per request, showing progress in a dialog.
 */
window.rcmail && rcmail.addEventListener('init', function () {
    var run = null;

    function esc(s) {
        return $('<div>').text(s).html();
    }

    function status(text) {
        $('#mh-collect-status').text(text);
    }

    function finish(stopped) {
        var r = run;
        run = null;
        $('#mh-collect-bar').css('width', '100%');
        status(rcmail.get_label(stopped ? 'mailhub_collect.stopped' : 'mailhub_collect.done')
            .replace('$added', r.added).replace('$messages', r.messages));
        $('.ui-dialog .mh-collect-start').prop('disabled', false);
        if (r.added && rcmail.env.source !== undefined) {
            rcmail.list_contacts(rcmail.env.source, rcmail.env.group);
        }
    }

    function next() {
        if (!run || run.stop) {
            return run && finish(true);
        }
        var job = run.jobs[run.i];
        if (!job) {
            return finish(false);
        }
        $('#mh-collect-bar').css('width', Math.round(100 * run.i / run.jobs.length) + '%');
        status(rcmail.get_label('mailhub_collect.scanning')
            .replace('$folder', job.name).replace('$added', run.added));
        rcmail.http_post('plugin.mailhub_collect.scan', { _folder: job.folder }, false);
    }

    rcmail.addEventListener('plugin.mailhub_collect_plan', function (data) {
        if (!run) {
            return;
        }
        run.jobs = data.jobs || [];
        run.i = 0;
        next();
    });

    rcmail.addEventListener('plugin.mailhub_collect_step', function (data) {
        if (!run) {
            return;
        }
        run.added += data.added || 0;
        run.messages += data.messages || 0;
        if (data.error) {
            run.errors++;
        }
        if (data.done || data.error) {
            run.i++;
        }
        next();
    });

    function start() {
        if (run) {
            return false;
        }
        run = { jobs: [], i: 0, added: 0, messages: 0, errors: 0 };
        $('.ui-dialog .mh-collect-start').prop('disabled', true);
        status(rcmail.get_label('mailhub_collect.listing'));
        rcmail.http_post('plugin.mailhub_collect.plan', {}, false);
        return false;  // keep the dialog open
    }

    rcmail.register_command('plugin.mailhub_collect', function () {
        var html = '<div class="mh-collect">'
            + '<p>' + esc(rcmail.get_label('mailhub_collect.intro')) + '</p>'
            + '<div class="mh-collect-progress"><div id="mh-collect-bar"></div></div>'
            + '<p id="mh-collect-status" class="hint"></p></div>';

        rcmail.simple_dialog(html, 'mailhub_collect.title', start, {
            button: 'mailhub_collect.start',
            button_class: 'mh-collect-start',
            cancel_button: 'close',
            cancel_func: function () {
                if (run) {
                    run.stop = true;
                }
            },
            width: 520,
        });
    }, true);
});
