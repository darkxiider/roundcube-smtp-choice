window.rcmail && rcmail.addEventListener('init', function () {
  if (rcmail.env.task !== 'settings') {
    return;
  }

  var form = document.getElementById('smtp-choice-form');
  if (!form) {
    return;
  }

  var fields = document.getElementById('smtp-choice-fields');
  var modeDefault = document.getElementById('sc-mode-default');
  var modeCustom = document.getElementById('sc-mode-custom');
  var resetBtn = document.getElementById('sc-reset');

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

  if (modeDefault) {
    modeDefault.addEventListener('change', setMode);
  }
  if (modeCustom) {
    modeCustom.addEventListener('change', setMode);
  }
  setMode();

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (modeDefault && modeDefault.checked) {
      rcmail.http_post('plugin.smtp_choice.reset', { _token: rcmail.env.request_token }, rcmail.set_busy(true, 'loading'));
      return;
    }

    var data = {
      _token: rcmail.env.request_token,
      _email: document.getElementById('sc-email') ? document.getElementById('sc-email').value : '',
      _from_name: document.getElementById('sc-from_name') ? document.getElementById('sc-from_name').value : '',
      _host: document.getElementById('sc-host') ? document.getElementById('sc-host').value : '',
      _port: document.getElementById('sc-port') ? document.getElementById('sc-port').value : '',
      _user: document.getElementById('sc-user') ? document.getElementById('sc-user').value : '',
      _pass: document.getElementById('sc-pass') ? document.getElementById('sc-pass').value : '',
      _secure: document.getElementById('sc-secure') ? document.getElementById('sc-secure').value : 'tls'
    };
    rcmail.http_post('plugin.smtp_choice.save', data, rcmail.set_busy(true, 'loading'));
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      rcmail.http_post('plugin.smtp_choice.reset', { _token: rcmail.env.request_token }, rcmail.set_busy(true, 'loading'));
    });
  }
});
