# Roundcube SMTP sending

cPanel Roundcube plugin. **Settings → SMTP sending** lets one mailbox save custom SMTP (test on save) or switch back to the server default.

Send and reply in Roundcube then use those details. Outlook / phones are unchanged.

## Install (cPanel, root)

In SSH / PuTTY as **root**:

```bash
curl -fsSL https://raw.githubusercontent.com/darkxiider/roundcube-smtp-choice/main/install.sh | bash
```

After install: log into Roundcube → Settings → **SMTP sending**.

## Optional: only one mailbox

```bash
cp /usr/local/cpanel/base/3rdparty/roundcube/plugins/smtp_choice/config.inc.php.dist \
   /usr/local/cpanel/base/3rdparty/roundcube/plugins/smtp_choice/config.inc.php
```

Edit `config.inc.php`:

```php
$config['smtp_choice_users'] = ['sales@yourdomain.com'];
```

Empty array = every Roundcube user sees the menu (each login has its own saved SMTP).

## Uninstall

```bash
rm -rf /usr/local/cpanel/base/3rdparty/roundcube/plugins/smtp_choice
rm -f /usr/local/cpanel/base/3rdparty/roundcube/config/inc.d/990-smtp_choice.inc.php
```

A cPanel update can remove custom Roundcube plugins. Run the install command again if the menu disappears.
