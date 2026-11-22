// JSON requests to the backend API with the CSRF token. Errors come back as
// { error: "<code>", ...details } (see Controller::jsonError).

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content
  ?? document.querySelector('input[name="_csrf"]')?.value
  ?? '';

/** GET without body, POST with a JSON body. Resolves to { ok, status, data }. */
export const api = async (url, body) => {
  const csrf = csrfToken();
  const response = await fetch(url, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
    body: body === undefined ? undefined : JSON.stringify({ ...body, _csrf: csrf }),
    credentials: 'same-origin',
  });
  if (response.status === 401) {
    window.location.href = '/admin/login';
    throw new Error('logged_out');
  }
  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
};

/** Multipart upload (files). Resolves to { ok, status, data }. */
export const send = async (url, formData) => {
  const response = await fetch(url, {
    method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() }, body: formData, credentials: 'same-origin',
  });
  if (response.status === 401) {
    window.location.href = '/admin/login';
    throw new Error('logged_out');
  }
  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
};
