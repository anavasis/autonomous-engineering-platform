export type ApiUser = {
  id: string;
  username: string;
  displayName: string;
  role: string;
  createdAtUtc: string;
  active: boolean;
};

export type ApiError = {
  title: string;
  status: number;
  detail: string;
};

let csrfToken = '';

export function setCsrfToken(token: string) {
  csrfToken = token;
}

export function getCsrfToken() {
  return csrfToken;
}

async function parse<T>(res: Response): Promise<T> {
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = data as Partial<ApiError>;
    throw new Error(err.detail || err.title || `Request failed (${res.status})`);
  }
  return data as T;
}

export async function api<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  if (!headers.has('Content-Type') && init.body) {
    headers.set('Content-Type', 'application/json');
  }
  if (csrfToken && init.method && init.method !== 'GET') {
    headers.set('X-CSRF-Token', csrfToken);
  }

  const res = await fetch(`/api/v1${path}`, {
    ...init,
    headers,
    credentials: 'include',
  });
  return parse<T>(res);
}
