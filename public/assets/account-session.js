(function () {
  if (window.top !== window.self) return;

  var statusUrl = 'account_status.php';
  var loginUrl = 'login.php';
  var checking = false;

  function redirectToLogin(suspended) {
    var destination = loginUrl + (suspended ? '?error=suspended' : '');
    window.location.replace(destination);
  }

  function checkAccountStatus() {
    if (checking || document.visibilityState === 'hidden') return;
    checking = true;

    fetch(statusUrl, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) return null;
        return response.json();
      })
      .then(function (status) {
        if (!status) return;
        if (status.suspended) {
          redirectToLogin(true);
        } else if (!status.authenticated) {
          redirectToLogin(false);
        }
      })
      .catch(function () {})
      .finally(function () {
        checking = false;
      });
  }

  checkAccountStatus();
  window.setInterval(checkAccountStatus, 2000);
  document.addEventListener('visibilitychange', checkAccountStatus);
})();
