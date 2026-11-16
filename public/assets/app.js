// Adds a Show / Hide button beside every password box so people can check what they typed.
(function () {
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', 'Show password');
    btn.addEventListener('click', function () {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      btn.textContent = hidden ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(btn);
  });
})();
