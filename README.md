# Roundcube SMTP sending

cPanel Roundcube plugin. **Settings → SMTP sending** appears for **every** mailbox.

When you fill the form and save, those SMTP details are stored only for **the email you logged in with**. Other mailboxes still use the server until they save their own SMTP.

Send and reply in Roundcube then use that mailbox’s saved details. Outlook / phones are unchanged.

## Install (cPanel, root)

Log in as root, then:

```bash
curl -fsSL https://raw.githubusercontent.com/darkxiider/roundcube-smtp-choice/main/install.sh | bash
```

After install: log into any Roundcube mailbox → Settings → **SMTP sending**.

## Uninstall

```bash
rm -rf /usr/local/cpanel/base/3rdparty/roundcube/plugins/smtp_choice
rm -f /usr/local/cpanel/base/3rdparty/roundcube/config/inc.d/990-smtp_choice.inc.php
```

A cPanel update can remove custom Roundcube plugins. Run the install command again if the menu disappears.
