/*
 * Registers the proof-of-work workers of the ALTCHA widget (the "external"
 * build ships without them). The worker URLs come from the data attributes
 * of this script tag, see Altcha::scriptTag().
 */
(function () {
  var urls = document.currentScript ? document.currentScript.dataset : {};
  var altcha = globalThis.$altcha;

  if (!altcha || !altcha.algorithms) {
    return;
  }

  var register = function (url, algorithms) {
    if (!url) {
      return;
    }

    algorithms.forEach(function (algorithm) {
      altcha.algorithms.set(algorithm, function () {
        return new Worker(url);
      });
    });
  };

  register(urls.pbkdf2, ['PBKDF2/SHA-256', 'PBKDF2/SHA-384', 'PBKDF2/SHA-512']);
  register(urls.sha, ['SHA-256', 'SHA-384', 'SHA-512']);
  register(urls.argon2id, ['ARGON2ID']);
})();
