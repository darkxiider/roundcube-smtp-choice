<?php

/**
 * Roundcube plugin: per-login SMTP (test on save, send/reply via saved details).
 * Compatible with Roundcube 1.6 (cPanel).
 */

use PHPMailer\PHPMailer\PHPMailer;

class smtp_choice extends rcube_plugin
{
    public $task = 'settings|mail';
    public $noajax = false;

    private $rc;

    function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config();
        $this->add_texts('localization/', true);

        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action('plugin.smtp_choice', [$this, 'settings_page']);
        $this->register_action('plugin.smtp_choice.save', [$this, 'action_save']);
        $this->register_action('plugin.smtp_choice.test', [$this, 'action_test']);

        if ($this->rc->task === 'mail') {
            $this->add_hook('smtp_connect', [$this, 'smtp_connect']);
            $this->add_hook('message_before_send', [$this, 'message_before_send']);
        }
    }

    function settings_actions($args)
    {
        if (!$this->allowed()) {
            return $args;
        }

        if (!isset($args['actions']) || !is_array($args['actions'])) {
            $args['actions'] = [];
        }

        $args['actions'][] = [
            'action' => 'plugin.smtp_choice',
            'class'  => 'smtp-choice',
            'label'  => 'smtp_choice',
            'title'  => 'smtp_choice',
            'domain' => 'smtp_choice',
        ];

        return $args;
    }

    function settings_page()
    {
        $this->register_handler('plugin.body', [$this, 'settings_form']);
        $this->include_script('smtp_choice.js');
        $this->include_stylesheet($this->local_skin_path() . '/smtp_choice.css');
        $this->rc->output->set_pagetitle($this->gettext('smtp_choice'));
        $this->rc->output->send('plugin');
    }

    function settings_form()
    {
        $prefs = $this->prefs();
        $login = $this->login_email();
        $custom = !empty($prefs['enabled']);

        $email = $custom && !empty($prefs['email']) ? $prefs['email'] : $login;
        $from_name = $prefs['from_name'] ?? '';
        $host = $prefs['host'] ?? '';
        $port = isset($prefs['port']) && $prefs['port'] ? (string) $prefs['port'] : '465';
        $user = $prefs['user'] ?? '';
        $secure = $prefs['secure'] ?? 'ssl';

        $out = html::div(
            ['id' => 'smtp-choice-page', 'class' => 'formcontainer scroller'],
            html::div(['class' => 'formcontent'], $this->render_form([
                'custom'    => $custom,
                'email'     => $email,
                'from_name' => $from_name,
                'host'      => $host,
                'port'      => $port,
                'user'      => $user,
                'secure'    => $secure,
                'has_pass'  => !empty($prefs['pass']),
            ]))
        );

        return $out;
    }

    function action_save()
    {
        if (!$this->allowed()) {
            $this->ajax_error($this->gettext('not_allowed'));
            return;
        }

        $mode = (string) rcube_utils::get_input_value('_mode', rcube_utils::INPUT_POST);
        if ($mode !== 'custom') {
            $this->rc->user->save_prefs(['smtp_choice' => ['enabled' => false]]);
            $this->remember_pass('');
            $this->rc->output->command('display_message', $this->gettext('reset_ok'), 'confirmation');
            $this->rc->output->send();
            return;
        }

        $input = $this->posted_smtp();
        if (!empty($input['error'])) {
            $this->ajax_error($input['error']);
            return;
        }

        $this->rc->user->save_prefs([
            'smtp_choice' => [
                'enabled'   => true,
                'email'     => $input['email'],
                'from_name' => $input['from_name'],
                'host'      => $input['host'],
                'port'      => $input['port'],
                'user'      => $input['user'],
                'pass'      => $this->encode_pass($input['pass']),
                'secure'    => $input['secure'],
            ],
        ]);
        $this->remember_pass($input['pass']);

        $this->rc->output->command('display_message', $this->gettext('saved'), 'confirmation');
        $this->rc->output->send();
    }

    function action_test()
    {
        if (!$this->allowed()) {
            $this->ajax_error($this->gettext('not_allowed'));
            return;
        }

        $input = $this->posted_smtp();
        if (!empty($input['error'])) {
            $this->ajax_error($input['error']);
            return;
        }

        $test = $this->test_smtp($input['host'], $input['port'], $input['user'], $input['pass'], $input['secure']);
        if (empty($test['ok']) && $this->is_auth_fail($test['error'])) {
            $saved = $this->current_pass($this->prefs());
            if ($saved !== '' && $saved !== $input['pass']) {
                $retry = $this->test_smtp($input['host'], $input['port'], $input['user'], $saved, $input['secure']);
                if (!empty($retry['ok'])) {
                    $test = $retry;
                    $input['pass'] = $saved;
                }
            }
        }
        if (empty($test['ok'])) {
            $this->ajax_error($this->gettext('smtp_failed') . ' ' . $test['error']);
            return;
        }

        $this->remember_pass($input['pass']);
        $this->rc->output->command('display_message', $this->gettext('tested_ok'), 'confirmation');
        $this->rc->output->send();
    }

    private function posted_smtp()
    {
        $email = trim((string) rcube_utils::get_input_value('_email', rcube_utils::INPUT_POST));
        $from_name = trim((string) rcube_utils::get_input_value('_from_name', rcube_utils::INPUT_POST));
        $host = $this->normalize_host((string) rcube_utils::get_input_value('_host', rcube_utils::INPUT_POST));
        $port = (int) rcube_utils::get_input_value('_port', rcube_utils::INPUT_POST);
        $user = trim((string) rcube_utils::get_input_value('_user', rcube_utils::INPUT_POST));
        $pass = $this->posted_pass();
        $secure = (string) rcube_utils::get_input_value('_secure', rcube_utils::INPUT_POST);
        $secure = in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'tls';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => $this->gettext('invalid_email')];
        }
        if ($from_name === '') {
            return ['error' => $this->gettext('invalid_from_name')];
        }
        if ($host === '' || $user === '') {
            return ['error' => $this->gettext('missing_smtp')];
        }
        if ($port < 1 || $port > 65535) {
            return ['error' => $this->gettext('invalid_port')];
        }

        $prefs = $this->prefs();
        $saved = $this->current_pass($prefs);
        $imap = $this->mailbox_pass();
        // Browsers often paste the Roundcube mailbox password into this field.
        if ($pass !== '' && $imap !== '' && hash_equals($pass, $imap)) {
            $pass = '';
        }
        if ($pass === '') {
            $pass = $saved;
        }
        if ($pass === '') {
            return ['error' => $this->gettext('missing_password')];
        }

        return [
            'error'     => '',
            'email'     => $email,
            'from_name' => $from_name,
            'host'      => $host,
            'port'      => $port,
            'user'      => $user,
            'pass'      => $pass,
            'secure'    => $secure,
        ];
    }

    private function posted_pass()
    {
        $pass = (string) rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST, true);
        return str_replace(["\r", "\n"], '', $pass);
    }

    private function mailbox_pass()
    {
        if (method_exists($this->rc, 'get_user_password')) {
            return (string) $this->rc->get_user_password();
        }
        return '';
    }

    private function is_auth_fail($error)
    {
        return (bool) preg_match('/\b535\b|incorrect authentication|authentication failed/i', (string) $error);
    }

    function smtp_connect($args)
    {
        $prefs = $this->prefs();
        if (empty($prefs['enabled']) || empty($prefs['host']) || empty($prefs['user'])) {
            return $args;
        }

        $pass = $this->current_pass($prefs);
        if ($pass === '') {
            return $args;
        }

        // Compose uses smtp_deliver() instead. If another path still hits
        // Roundcube SMTP, never pass tls:// — Roundcube STARTTLS in connect()
        // and again in auth(), and SMTP2GO then returns 503.
        $host = $this->normalize_host((string) $prefs['host']);
        $port = (int) ($prefs['port'] ?? 587);
        $secure = (string) ($prefs['secure'] ?? 'tls');
        $args['smtp_host'] = $this->rcube_smtp_host($host, $port, $secure);
        $args['smtp_user'] = (string) $prefs['user'];
        $args['smtp_pass'] = $pass;

        return $args;
    }

    function message_before_send($args)
    {
        $prefs = $this->prefs();
        if (empty($prefs['enabled'])) {
            return $args;
        }

        $email = trim((string) ($prefs['email'] ?? $this->login_email()));
        $name = trim((string) ($prefs['from_name'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $args;
        }

        $from = $name !== '' ? format_email_recipient($email, $name) : $email;
        $args['from'] = $from;

        if (isset($args['message']) && is_object($args['message'])) {
            if (method_exists($args['message'], 'setFrom')) {
                $args['message']->setFrom($email, $name);
            }
            elseif (method_exists($args['message'], 'headers')) {
                $args['message']->headers(['From' => $from], true);
            }
        }

        $pass = $this->current_pass($prefs);
        if ($pass === '') {
            $args['abort'] = true;
            $args['result'] = false;
            $args['error'] = 'SMTP password is missing. Save SMTP settings again.';
            return $args;
        }

        $raw = $this->message_rfc822($args['message'] ?? null);
        if ($raw === '') {
            $args['abort'] = true;
            $args['result'] = false;
            $args['error'] = 'Could not build the message.';
            return $args;
        }

        $rcpts = $this->rcpt_list((string) ($args['mailto'] ?? ''), $raw);
        $sent = $this->smtp_deliver(
            $this->normalize_host((string) $prefs['host']),
            (int) ($prefs['port'] ?? 587),
            (string) $prefs['user'],
            $pass,
            (string) ($prefs['secure'] ?? 'tls'),
            $email,
            $rcpts,
            $raw
        );

        $args['abort'] = true;
        if (!empty($sent['ok'])) {
            $args['result'] = true;
        } else {
            $args['result'] = false;
            $args['error'] = $sent['error'] !== '' ? $sent['error'] : 'SMTP send failed.';
        }

        return $args;
    }

    private function render_form($data)
    {
        $attr = ['id' => 'smtp-choice-form', 'class' => 'propform', 'method' => 'post', 'action' => '#', 'autocomplete' => 'off'];
        $hidden = html::tag('input', [
            'type'  => 'hidden',
            'name'  => '_token',
            'value' => $this->rc->get_request_token(),
        ]);

        $mode = html::div(['class' => 'propform-field'],
            html::label([], rcube::Q($this->gettext('mode')))
            . html::div(['class' => 'smtp-choice-radios'],
                html::tag('label', ['class' => 'smtp-choice-radio'],
                    html::tag('input', [
                        'type'    => 'radio',
                        'name'    => '_mode',
                        'value'   => 'default',
                        'id'      => 'sc-mode-default',
                    ] + (empty($data['custom']) ? ['checked' => true] : []))
                    . ' ' . rcube::Q($this->gettext('mode_default'))
                )
                . html::tag('label', ['class' => 'smtp-choice-radio'],
                    html::tag('input', [
                        'type'    => 'radio',
                        'name'    => '_mode',
                        'value'   => 'custom',
                        'id'      => 'sc-mode-custom',
                    ] + (!empty($data['custom']) ? ['checked' => true] : []))
                    . ' ' . rcube::Q($this->gettext('mode_custom'))
                )
            )
        );

        $fields = html::div(['id' => 'smtp-choice-fields', 'class' => empty($data['custom']) ? 'disabled' : ''],
            $this->field('email', '_email', $data['email'], 'text')
            . $this->field('from_name', '_from_name', $data['from_name'], 'text')
            . $this->field('smtp_host', '_host', $data['host'], 'text', 'smtp.example.com')
            .             $this->field('smtp_user', '_user', $data['user'], 'text')
            . html::tag('input', [
                'type'         => 'text',
                'name'         => 'sc_trap_user',
                'value'        => '',
                'autocomplete' => 'username',
                'tabindex'     => '-1',
                'aria-hidden'  => 'true',
                'style'        => 'position:absolute;left:-9999px;height:0;width:0;opacity:0',
            ])
            . $this->field('smtp_pass', '_pass', '', 'password', !empty($data['has_pass']) ? $this->gettext('password_keep') : '')
            . html::div(['class' => 'propform-field'],
                html::label(['for' => 'sc-secure'], rcube::Q($this->gettext('smtp_secure')))
                . html::tag('select', [
                    'name'     => '_secure',
                    'id'       => 'sc-secure',
                    'onchange' => 'window.smtpChoiceSetPort && window.smtpChoiceSetPort(this.value)',
                ],
                    $this->option('tls', $data['secure'], $this->gettext('secure_tls'))
                    . $this->option('ssl', $data['secure'], $this->gettext('secure_ssl'))
                    . $this->option('none', $data['secure'], $this->gettext('secure_none'))
                )
            )
            . $this->field('smtp_port', '_port', $data['port'], 'text')
        );

        $buttons = html::p(['class' => 'formbuttons'],
            html::tag('button', [
                'type'  => 'button',
                'id'    => 'sc-test',
                'class' => 'button',
            ], rcube::Q($this->gettext('test')))
            . ' '
            . html::tag('button', [
                'type'  => 'submit',
                'id'    => 'sc-save',
                'class' => 'button',
            ], rcube::Q($this->gettext('save')))
        );

        return html::tag('form', $attr, $hidden . $mode . $fields . $buttons);
    }

    private function field($label_key, $name, $value, $type, $placeholder = '')
    {
        $id = 'sc-' . preg_replace('/^_/', '', $name);
        $input_attr = [
            'type'  => $type,
            'name'  => $name,
            'id'    => $id,
            'value' => $value,
        ];
        if ($placeholder !== '') {
            $input_attr['placeholder'] = $placeholder;
        }
        if ($name === '_port') {
            $input_attr['inputmode'] = 'numeric';
            $input_attr['pattern'] = '[0-9]*';
            $input_attr['autocomplete'] = 'off';
        }
        if ($type === 'password') {
            $input_attr['autocomplete'] = 'new-password';
            $input_attr['readonly'] = 'readonly';
            $input_attr['data-lpignore'] = 'true';
            $input_attr['data-1p-ignore'] = 'true';
        }

        return html::div(['class' => 'propform-field'],
            html::label(['for' => $id], rcube::Q($this->gettext($label_key)))
            . html::tag('input', $input_attr)
        );
    }

    private function option($value, $selected, $label)
    {
        $attr = ['value' => $value];
        if ($value === $selected) {
            $attr['selected'] = true;
        }
        return html::tag('option', $attr, rcube::Q($label));
    }

    private function prefs()
    {
        $prefs = $this->rc->config->get('smtp_choice', []);
        return is_array($prefs) ? $prefs : [];
    }

    private function pass_key()
    {
        $des = (string) $this->rc->config->get('des_key', '');
        $user = (string) $this->rc->get_user_name();
        return hash('sha256', 'smtp_choice|' . $des . '|' . $user, true);
    }

    private function encode_pass($plain)
    {
        $iv = random_bytes(16);
        $raw = openssl_encrypt($plain, 'AES-256-CBC', $this->pass_key(), OPENSSL_RAW_DATA, $iv);
        if ($raw === false) {
            return 'b64:' . base64_encode($plain);
        }
        return 'v1:' . base64_encode($iv . $raw);
    }

    private function decode_pass($stored)
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        if (strncmp($stored, 'v1:', 3) === 0) {
            $bin = base64_decode(substr($stored, 3), true);
            if ($bin === false || strlen($bin) < 17) {
                return '';
            }
            $plain = openssl_decrypt(substr($bin, 16), 'AES-256-CBC', $this->pass_key(), OPENSSL_RAW_DATA, substr($bin, 0, 16));
            return is_string($plain) ? $plain : '';
        }
        if (strncmp($stored, 'b64:', 4) === 0) {
            $plain = base64_decode(substr($stored, 4), true);
            return is_string($plain) ? $plain : '';
        }
        $dec = $this->rc->decrypt($stored);
        if (is_string($dec) && $dec !== '' && $dec !== $stored) {
            return $dec;
        }
        return '';
    }

    private function remember_pass($plain)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if ($plain === '') {
            unset($_SESSION['smtp_choice_pass']);
            return;
        }
        $_SESSION['smtp_choice_pass'] = $plain;
    }

    private function current_pass($prefs)
    {
        $stored = $this->decode_pass((string) ($prefs['pass'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['smtp_choice_pass'])) {
            return (string) $_SESSION['smtp_choice_pass'];
        }
        return '';
    }

    private function login_email()
    {
        $user = (string) $this->rc->get_user_name();
        if (strpos($user, '@') !== false) {
            return $user;
        }
        $identity = $this->rc->user ? $this->rc->user->get_identity() : null;
        if (is_array($identity) && !empty($identity['email'])) {
            return (string) $identity['email'];
        }
        return $user;
    }

    private function allowed()
    {
        $list = $this->rc->config->get('smtp_choice_users', []);
        if (!is_array($list) || $list === []) {
            return true;
        }
        $login = strtolower($this->login_email());
        foreach ($list as $item) {
            if (strtolower((string) $item) === $login) {
                return true;
            }
        }
        return false;
    }

    private function normalize_host($host)
    {
        $host = trim((string) $host);
        $host = preg_replace('#^(ssl|tls|smtp|smtps)://#i', '', $host);
        $host = preg_replace('#:\d+$#', '', $host);
        return strtolower(rtrim($host, '.'));
    }

    private function rcube_smtp_host($host, $port, $secure)
    {
        if ($secure === 'ssl') {
            return 'ssl://' . $host . ':' . $port;
        }
        return $host . ':' . $port;
    }

    private function load_phpmailer()
    {
        $dir = $this->home . '/lib/phpmailer';
        require_once $dir . '/Exception.php';
        require_once $dir . '/SMTP.php';
        require_once $dir . '/PHPMailer.php';
    }

    // Same setup as Desktop/send smtp_mailer().
    private function phpmailer($host, $user, $pass, $port, $secure)
    {
        $this->load_phpmailer();
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = true;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Timeout = 20;
        $mail->SMTPDebug = 0;
        return $mail;
    }

    // Same order as Desktop/send config.php endpoints, with the form choice first.
    private function smtp_endpoints($port, $secure)
    {
        $list = [
            ['port' => (int) $port, 'secure' => (string) $secure],
            ['port' => 465, 'secure' => 'ssl'],
            ['port' => 8465, 'secure' => 'ssl'],
            ['port' => 443, 'secure' => 'ssl'],
            ['port' => 2525, 'secure' => 'tls'],
            ['port' => 587, 'secure' => 'tls'],
            ['port' => 2525, 'secure' => 'none'],
        ];
        $seen = [];
        $out = [];
        foreach ($list as $ep) {
            $key = $ep['secure'] . ':' . $ep['port'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $ep;
        }
        return $out;
    }

    private function smtp_host_ok($host)
    {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return $this->gettext('invalid_host');
        }
        $ips = @gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            return $this->gettext('host_unresolved');
        }
        foreach ($ips as $ip) {
            if (!$this->public_ip($ip)) {
                return $this->gettext('host_blocked');
            }
        }
        return '';
    }

    private function test_smtp($host, $port, $user, $pass, $secure)
    {
        $bad = $this->smtp_host_ok($host);
        if ($bad !== '') {
            return ['ok' => false, 'error' => $bad];
        }

        $last = 'Could not reach SMTP.';
        foreach ($this->smtp_endpoints($port, $secure) as $ep) {
            try {
                $mail = $this->phpmailer($host, $user, $pass, (int) $ep['port'], (string) $ep['secure']);
                $mail->smtpConnect();
                $mail->smtpClose();
                return ['ok' => true, 'error' => '', 'port' => (int) $ep['port'], 'secure' => (string) $ep['secure']];
            } catch (Throwable $e) {
                $last = $e->getMessage();
            }
        }
        return ['ok' => false, 'error' => $last];
    }

    private function smtp_deliver($host, $port, $user, $pass, $secure, $from, $rcpts, $raw)
    {
        if (!is_array($rcpts) || $rcpts === []) {
            return ['ok' => false, 'error' => 'No recipients.'];
        }
        $bad = $this->smtp_host_ok($host);
        if ($bad !== '') {
            return ['ok' => false, 'error' => $bad];
        }

        $payload = preg_replace("/(?<!\r)\n/", "\r\n", $raw);
        $last = 'Send failed.';
        foreach ($this->smtp_endpoints($port, $secure) as $ep) {
            try {
                $mail = $this->phpmailer($host, $user, $pass, (int) $ep['port'], (string) $ep['secure']);
                $mail->smtpConnect();
                $smtp = $mail->getSMTPInstance();
                if (!$smtp->mail($from)) {
                    $err = $smtp->getError();
                    $last = !empty($err['error']) ? $err['error'] : 'MAIL FROM failed.';
                    $mail->smtpClose();
                    continue;
                }
                foreach ($rcpts as $rcpt) {
                    if (!$smtp->recipient($rcpt)) {
                        $err = $smtp->getError();
                        $last = !empty($err['error']) ? $err['error'] : 'RCPT failed.';
                        $mail->smtpClose();
                        continue 2;
                    }
                }
                if (!$smtp->data($payload)) {
                    $err = $smtp->getError();
                    $last = !empty($err['error']) ? $err['error'] : 'DATA failed.';
                    $mail->smtpClose();
                    continue;
                }
                $mail->smtpClose();
                return ['ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $last = $e->getMessage();
            }
        }
        return ['ok' => false, 'error' => $last];
    }

    private function message_rfc822($message)
    {
        if (!is_object($message)) {
            return '';
        }
        if (method_exists($message, 'getMessage')) {
            $raw = $message->getMessage();
            if (is_string($raw) && trim($raw) !== '') {
                return $raw;
            }
        }
        $headers = method_exists($message, 'txtHeaders') ? (string) $message->txtHeaders() : '';
        $body = '';
        if (method_exists($message, 'get')) {
            $body = (string) $message->get();
        } elseif (method_exists($message, 'getTXTBody')) {
            $body = (string) $message->getTXTBody();
        }
        $raw = trim($headers) !== '' ? rtrim($headers) . "\r\n\r\n" . $body : $body;
        return is_string($raw) ? $raw : '';
    }

    private function rcpt_list($mailto, $raw)
    {
        $found = [];
        $chunks = preg_split('/[,;]+/', (string) $mailto);
        foreach ($chunks as $chunk) {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $chunk, $m)) {
                $found[strtolower($m[0])] = $m[0];
            }
        }
        if (preg_match_all('/^(To|Cc|Bcc):(.+)$/im', (string) $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $row[2], $em)) {
                    foreach ($em[0] as $email) {
                        $found[strtolower($email)] = $email;
                    }
                }
            }
        }
        return array_values($found);
    }

    private function public_ip($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }

    private function ajax_error($message)
    {
        $this->rc->output->command('display_message', $message, 'error');
        $this->rc->output->send();
    }
}
