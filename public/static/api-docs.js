/*
 * Swagger UI initializer for /api/docs (external file: the page CSP forbids inline scripts).
 * Assets: public/static/swagger-ui (swagger-ui-dist 5.17.14, Apache-2.0, see LICENSE).
 */
(function () {
  'use strict';
  function start() {
    if (typeof window.SwaggerUIBundle !== 'function') {
      return;
    }
    var url = document.body.getAttribute('data-spec-url') || '/api/v1/openapi.json';
    window.ui = window.SwaggerUIBundle({
      url: url,
      dom_id: '#swagger-ui',
      deepLinking: true,
      persistAuthorization: false,
      tryItOutEnabled: false,
      displayRequestDuration: true,
      presets: [window.SwaggerUIBundle.presets.apis],
      plugins: [window.SwaggerUIBundle.plugins.DownloadUrl],
      layout: 'BaseLayout'
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
