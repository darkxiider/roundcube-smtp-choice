<?php

/**
 * Roundcube plugin: per-login SMTP (test on save, send/reply via saved details).
 * Compatible with Roundcube 1.6 (cPanel).
 */

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
        $port = isset($prefs['port']) && $prefs['port'] ? (string) $prefs['port'] : '587';
        $user = $prefs['user'] ?? '';
        $secure = $prefs['secure'] ?? 'tls';

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
        $pass = (string) rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST, true);
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
        if ($pass === '') {
            $pass = $this->current_pass($prefs);
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
            . $this->field('smtp_user', '_user', $data['user'], 'text')
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

    private function test_smtp($host, $port, $user, $pass, $secure)
    {
        $open = $this->smtp_open($host, $port, $user, $pass, $secure);
        if (empty($open['ok'])) {
            return ['ok' => false, 'error' => $open['error']];
        }
        $this->smtp_write($open['fp'], 'QUIT');
        fclose($open['fp']);
        return ['ok' => true, 'error' => ''];
    }

    private function smtp_open($host, $port, $user, $pass, $secure)
    {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return ['ok' => false, 'error' => $this->gettext('invalid_host'), 'fp' => null];
        }

        $ips = @gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            return ['ok' => false, 'error' => $this->gettext('host_unresolved'), 'fp' => null];
        }
        foreach ($ips as $ip) {
            if (!$this->public_ip($ip)) {
                return ['ok' => false, 'error' => $this->gettext('host_blocked'), 'fp' => null];
            }
        }

        $last = 'connect failed';
        foreach ([true, false] as $verify) {
            $fp = $this->smtp_connect_stream($host, $port, $secure, $verify, $last);
            if (!$fp) {
                continue;
            }
            stream_set_timeout($fp, 20);

            $banner = $this->smtp_read($fp);
            if ($banner['code'] !== 220) {
                fclose($fp);
                $last = $banner['text'] !== '' ? $banner['text'] : 'no SMTP banner';
                continue;
            }

            $ehlo = $this->smtp_ehlo($fp);
            if ($ehlo['code'] !== 250) {
                fclose($fp);
                $last = $ehlo['text'];
                continue;
            }

            if ($secure === 'tls') {
                $this->smtp_write($fp, 'STARTTLS');
                $tls = $this->smtp_read($fp);
                if ($tls['code'] !== 220) {
                    fclose($fp);
                    $last = $tls['text'];
                    continue;
                }
                $crypto = @stream_socket_enable_crypto($fp, true, $this->crypto_method());
                if ($crypto !== true) {
                    fclose($fp);
                    $last = $this->gettext('tls_failed');
                    continue;
                }
                $ehlo = $this->smtp_ehlo($fp);
                if ($ehlo['code'] !== 250) {
                    fclose($fp);
                    $last = $ehlo['text'];
                    continue;
                }
            }

            $auth = $this->smtp_auth($fp, $user, $pass);
            if (empty($auth['ok'])) {
                $this->smtp_write($fp, 'QUIT');
                fclose($fp);
                $last = $auth['error'];
                continue;
            }

            return ['ok' => true, 'error' => '', 'fp' => $fp];
        }

        return ['ok' => false, 'error' => $last, 'fp' => null];
    }

    private function smtp_connect_stream($host, $port, $secure, $verify, &$last)
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => $verify,
                'verify_peer_name'  => $verify,
                'allow_self_signed' => !$verify,
                'peer_name'         => $host,
                'SNI_enabled'       => true,
                'crypto_method'     => $this->crypto_method(),
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $timeout = 15;

        if ($secure === 'ssl') {
            $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if ($fp) {
                return $fp;
            }
            $last = trim($errstr !== '' ? $errstr : "connect failed ($errno)");
            $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if ($fp) {
                $ok = @stream_socket_enable_crypto($fp, true, $this->crypto_method());
                if ($ok === true) {
                    return $fp;
                }
                fclose($fp);
                $last = $this->gettext('tls_failed');
            }
            return false;
        }

        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $last = trim($errstr !== '' ? $errstr : "connect failed ($errno)");
            return false;
        }
        return $fp;
    }

    private function smtp_ehlo($fp)
    {
        $this->smtp_write($fp, 'EHLO ' . $this->ehlo_name());
        return $this->smtp_read($fp);
    }

    private function smtp_auth($fp, $user, $pass)
    {
        $this->smtp_write($fp, 'AUTH LOGIN');
        $auth = $this->smtp_read($fp);
        if ($auth['code'] === 334) {
            $this->smtp_write($fp, base64_encode($user));
            $u = $this->smtp_read($fp);
            if ($u['code'] !== 334) {
                return ['ok' => false, 'error' => $u['text']];
            }
            $this->smtp_write($fp, base64_encode($pass));
            $p = $this->smtp_read($fp);
            if ($p['code'] === 235) {
                return ['ok' => true, 'error' => ''];
            }
            $login_err = $p['text'];
        } else {
            $login_err = $auth['text'];
        }

        $this->smtp_write($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
        $plain = $this->smtp_read($fp);
        if ($plain['code'] === 235) {
            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => $login_err !== '' ? $login_err : $plain['text']];
    }

    private function smtp_deliver($host, $port, $user, $pass, $secure, $from, $rcpts, $raw)
    {
        if (!is_array($rcpts) || $rcpts === []) {
            return ['ok' => false, 'error' => 'No recipients.'];
        }

        $open = $this->smtp_open($host, $port, $user, $pass, $secure);
        if (empty($open['ok'])) {
            return ['ok' => false, 'error' => $open['error']];
        }
        $fp = $open['fp'];

        $this->smtp_write($fp, 'MAIL FROM:<' . $from . '>');
        $mail = $this->smtp_read($fp);
        if ($mail['code'] !== 250) {
            $this->smtp_write($fp, 'QUIT');
            fclose($fp);
            return ['ok' => false, 'error' => $mail['text']];
        }

        foreach ($rcpts as $rcpt) {
            $this->smtp_write($fp, 'RCPT TO:<' . $rcpt . '>');
            $to = $this->smtp_read($fp);
            if ($to['code'] !== 250 && $to['code'] !== 251) {
                $this->smtp_write($fp, 'QUIT');
                fclose($fp);
                return ['ok' => false, 'error' => $to['text']];
            }
        }

        $this->smtp_write($fp, 'DATA');
        $data = $this->smtp_read($fp);
        if ($data['code'] !== 354) {
            $this->smtp_write($fp, 'QUIT');
            fclose($fp);
            return ['ok' => false, 'error' => $data['text']];
        }

        $payload = preg_replace("/(?<!\r)\n/", "\r\n", $raw);
        $payload = preg_replace('/^\./m', '..', $payload);
        if (substr($payload, -2) !== "\r\n") {
            $payload .= "\r\n";
        }
        fwrite($fp, $payload . ".\r\n");
        $done = $this->smtp_read($fp);
        $this->smtp_write($fp, 'QUIT');
        fclose($fp);

        if ($done['code'] !== 250) {
            return ['ok' => false, 'error' => $done['text']];
        }
        return ['ok' => true, 'error' => ''];
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

    private function crypto_method()
    {
        $method = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        return $method ?: STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }

    private function public_ip($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }

    private function ehlo_name()
    {
        $name = (string) $this->rc->config->get('smtp_helo_host', gethostname());
        $name = preg_replace('/[^A-Za-z0-9.-]+/', '', $name);
        return $name !== '' ? $name : 'localhost';
    }

    private function smtp_write($fp, $line)
    {
        fwrite($fp, $line . "\r\n");
    }

    private function smtp_read($fp)
    {
        $text = '';
        $code = 0;
        while (($line = fgets($fp, 2048)) !== false) {
            $text .= $line;
            if (preg_match('/^(\d{3})([\s-])/', $line, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return ['code' => $code, 'text' => trim($text)];
    }

    private function ajax_error($message)
    {
        $this->rc->output->command('display_message', $message, 'error');
        $this->rc->output->send();
    }
}
