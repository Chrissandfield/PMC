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

(function(){var f=document.getElementById('club-filter');if(!f)return;var items=document.querySelectorAll('#club-list li'),none=document.getElementById('club-none');
f.addEventListener('input',function(){var q=f.value.trim().toLowerCase(),n=0;items.forEach(function(li){var ok=!q||li.getAttribute('data-find').indexOf(q)>-1;li.hidden=!ok;if(ok)n++;});if(none)none.hidden=n>0;});})();
