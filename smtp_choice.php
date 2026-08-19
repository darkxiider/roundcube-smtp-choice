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
        if (in_array($port, [465, 8465, 443], true)) {
            return 'ssl://' . $host . ':' . $port;
        }
        if (in_array($port, [587, 2525, 2587, 8025], true)) {
            return 'tls://' . $host . ':' . $port;
        }
        if ($secure === 'ssl') {
            return 'ssl://' . $host . ':' . $port;
        }
        if ($secure === 'tls') {
            return 'tls://' . $host . ':' . $port;
        }
        return $host . ':' . $port;
    }

    private function test_smtp($host, $port, $user, $pass, $secure)
    {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return ['ok' => false, 'error' => $this->gettext('invalid_host')];
        }

        $ips = @gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            return ['ok' => false, 'error' => $this->gettext('host_unresolved')];
        }
        foreach ($ips as $ip) {
            if (!$this->public_ip($ip)) {
                return ['ok' => false, 'error' => $this->gettext('host_blocked')];
            }
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['ok' => false, 'error' => trim($errstr !== '' ? $errstr : "connect failed ($errno)")];
        }
        stream_set_timeout($fp, 12);

        $banner = $this->smtp_read($fp);
        if ($banner['code'] !== 220) {
            fclose($fp);
            return ['ok' => false, 'error' => $banner['text']];
        }

        $this->smtp_write($fp, 'EHLO ' . $this->ehlo_name());
        $ehlo = $this->smtp_read($fp);
        if ($ehlo['code'] !== 250) {
            fclose($fp);
            return ['ok' => false, 'error' => $ehlo['text']];
        }

        if ($secure === 'tls') {
            $this->smtp_write($fp, 'STARTTLS');
            $tls = $this->smtp_read($fp);
            if ($tls['code'] !== 220) {
                fclose($fp);
                return ['ok' => false, 'error' => $tls['text']];
            }
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                fclose($fp);
                return ['ok' => false, 'error' => $this->gettext('tls_failed')];
            }
            $this->smtp_write($fp, 'EHLO ' . $this->ehlo_name());
            $ehlo2 = $this->smtp_read($fp);
            if ($ehlo2['code'] !== 250) {
                fclose($fp);
                return ['ok' => false, 'error' => $ehlo2['text']];
            }
        }

        $this->smtp_write($fp, 'AUTH LOGIN');
        $auth = $this->smtp_read($fp);
        if ($auth['code'] !== 334) {
            fclose($fp);
            return ['ok' => false, 'error' => $auth['text']];
        }
        $this->smtp_write($fp, base64_encode($user));
        $u = $this->smtp_read($fp);
        if ($u['code'] !== 334) {
            fclose($fp);
            return ['ok' => false, 'error' => $u['text']];
        }
        $this->smtp_write($fp, base64_encode($pass));
        $p = $this->smtp_read($fp);
        $this->smtp_write($fp, 'QUIT');
        fclose($fp);

        if ($p['code'] !== 235) {
            return ['ok' => false, 'error' => $p['text']];
        }

        return ['ok' => true, 'error' => ''];
    }

    private function public_ip($ip)
    {
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
