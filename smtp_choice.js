window.smtpChoiceSetPort = function (enc) {
  var port = document.getElementById('sc-port') || document.querySelector('#smtp-choice-form input[name="_port"]');
  var map = { tls: '587', ssl: '465', none: '25' };
  if (port) {
    port.value = map[enc] || '587';
  }
};

(function () {
  var bound = false;

  function boot() {
    var form = document.getElementById('smtp-choice-form');
    if (!form || bound) {
      return;
    }
    bound = true;

    var fields = document.getElementById('smtp-choice-fields');
    var modeDefault = document.getElementById('sc-mode-default');
    var modeCustom = document.getElementById('sc-mode-custom');
    var testBtn = document.getElementById('sc-test');
    var passEl = document.getElementById('sc-pass');
    if (passEl) {
      passEl.addEventListener('focus', function () {
        passEl.removeAttribute('readonly');
      });
      passEl.value = '';
      setTimeout(function () {
        passEl.value = '';
      }, 300);
    }
    var secure = document.getElementById('sc-secure') || form.querySelector('select[name="_secure"]');

    function setMode() {
      var custom = modeCustom && modeCustom.checked;
      if (fields) {
        fields.classList.toggle('disabled', !custom);
      }
      var inputs = fields ? fields.querySelectorAll('input, select') : [];
      for (var i = 0; i < inputs.length; i++) {
        inputs[i].disabled = !custom;
      }
    }

    function formData() {
      var portEl = document.getElementById('sc-port') || form.querySelector('input[name="_port"]');
      var secureEl = document.getElementById('sc-secure') || form.querySelector('select[name="_secure"]');
      return {
        _token: window.rcmail ? rcmail.env.request_token : '',
        _mode: modeCustom && modeCustom.checked ? 'custom' : 'default',
        _email: document.getElementById('sc-email') ? document.getElementById('sc-email').value : '',
        _from_name: document.getElementById('sc-from_name') ? document.getElementById('sc-from_name').value : '',
        _host: document.getElementById('sc-host') ? document.getElementById('sc-host').value : '',
        _port: portEl ? portEl.value : '',
        _user: document.getElementById('sc-user') ? document.getElementById('sc-user').value : '',
        _pass: document.getElementById('sc-pass') ? document.getElementById('sc-pass').value : '',
        _secure: secureEl ? secureEl.value : 'tls'
      };
    }

    if (modeDefault) {
      modeDefault.addEventListener('change', setMode);
    }
    if (modeCustom) {
      modeCustom.addEventListener('change', setMode);
    }
    setMode();

    form.addEventListener('change', function (e) {
      var t = e.target;
      if (t && (t.id === 'sc-secure' || t.name === '_secure')) {
        window.smtpChoiceSetPort(t.value);
      }
    });

    if (secure) {
      secure.addEventListener('change', function () {
        window.smtpChoiceSetPort(secure.value);
        if (passEl) {
          passEl.value = '';
        }
      });
      secure.addEventListener('input', function () {
        window.smtpChoiceSetPort(secure.value);
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!window.rcmail) {
        return;
      }
      var data = formData();
      if (data._mode !== 'custom') {
        data._email = '';
        data._from_name = '';
        data._host = '';
        data._user = '';
        data._pass = '';
      } else {
        var inputs = fields ? fields.querySelectorAll('input, select') : [];
        for (var i = 0; i < inputs.length; i++) {
          inputs[i].disabled = false;
        }
        data = formData();
        data._mode = 'custom';
      }
      rcmail.http_post('plugin.smtp_choice.save', data, rcmail.set_busy(true, 'loading'));
      setMode();
    });

    if (testBtn) {
      testBtn.addEventListener('click', function () {
        if (!window.rcmail) {
          return;
        }
        if (!modeCustom || !modeCustom.checked) {
          rcmail.display_message(rcmail.gettext('test_need_custom', 'smtp_choice'), 'error');
          return;
        }
        var inputs = fields ? fields.querySelectorAll('input, select') : [];
        for (var i = 0; i < inputs.length; i++) {
          inputs[i].disabled = false;
        }
        var data = formData();
        data._mode = 'custom';
        rcmail.http_post('plugin.smtp_choice.test', data, rcmail.set_busy(true, 'loading'));
        setMode();
      });
    }

    function removeFooterClones() {
      var testClone = document.getElementById('sc-test-clone');
      var saveClone = document.getElementById('sc-save-clone');
      var wrap = (testClone && testClone.parentNode) || (saveClone && saveClone.parentNode);
      if (wrap && wrap.classList && wrap.classList.contains('buttons')) {
        if (wrap.parentNode) {
          wrap.parentNode.removeChild(wrap);
        }
        return;
      }
      if (testClone && testClone.parentNode) {
        testClone.parentNode.removeChild(testClone);
      }
      if (saveClone && saveClone.parentNode) {
        saveClone.parentNode.removeChild(saveClone);
      }
    }

    removeFooterClones();
    setTimeout(removeFooterClones, 0);
    setTimeout(removeFooterClones, 250);
  }

  if (window.rcmail) {
    rcmail.addEventListener('init', boot);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
