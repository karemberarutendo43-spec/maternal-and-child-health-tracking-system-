export async function apiGet(path) {
  const res = await fetch(path, { credentials: 'include' });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data || data.ok !== true) {
    const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

export async function apiPostForm(path, fields, csrfContext) {
  let csrf = null;
  if (csrfContext) {
    const t = await apiGet(`/mch_system/api/csrf.php?context=${encodeURIComponent(csrfContext)}`);
    csrf = t.csrf_token;
  }

  const params = new URLSearchParams();
  if (csrfContext) params.set('csrf_token', csrf || '');
  Object.entries(fields || {}).forEach(([k, v]) => {
    if (v === undefined || v === null) return;
    params.set(k, String(v));
  });

  const res = await fetch(path, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: params.toString()
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data || data.ok !== true) {
    const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}
