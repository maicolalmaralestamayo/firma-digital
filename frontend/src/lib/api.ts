import type {
  CertificateData,
  Collection,
  ConsentResource,
  DeletionRequestResource,
  DocumentResource,
  EventResource,
  JsonApiError,
  SignatureResource,
  UserResource,
  VerificationResult,
} from './types';

const BASE_URL: string = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') || '/api/v1';
const TOKEN_KEY = 'firma-digital.token';

export function getToken(): string | null {
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (token === null) {
    window.localStorage.removeItem(TOKEN_KEY);
    return;
  }

  window.localStorage.setItem(TOKEN_KEY, token);
}

export class ApiError extends Error {
  readonly status: number;

  readonly code: string;

  readonly errors: JsonApiError[];

  constructor(status: number, errors: JsonApiError[]) {
    const first = errors[0];
    super(first?.detail || first?.title || `Error ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.code = first?.code ?? 'unknown_error';
    this.errors = errors;
  }

  /** First message for a given attribute pointer, e.g. `/data/attributes/email`. */
  fieldError(field: string): string | undefined {
    return this.errors.find((error) => error.source?.pointer === `/data/attributes/${field}`)?.detail;
  }
}

async function toApiError(response: Response): Promise<ApiError> {
  try {
    const payload = (await response.json()) as { errors?: JsonApiError[] };
    return new ApiError(response.status, payload.errors ?? []);
  } catch {
    return new ApiError(response.status, [
      {
        status: String(response.status),
        code: 'http_error',
        title: 'Error',
        detail: `La solicitud falló con estado ${response.status}.`,
      },
    ]);
  }
}

interface RequestOptions {
  method?: string;
  body?: FormData | unknown;
  signal?: AbortSignal;
}

async function rawRequest(path: string, { method = 'GET', body, signal }: RequestOptions = {}): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/vnd.api+json' };
  const token = getToken();

  if (token !== null) {
    headers.Authorization = `Bearer ${token}`;
  }

  let payload: BodyInit | undefined;

  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  const response = await fetch(`${BASE_URL}${path}`, { method, headers, body: payload, signal });

  if (!response.ok) {
    throw await toApiError(response);
  }

  return response;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await rawRequest(path, options);

  if (response.status === 204) {
    return null as T;
  }

  return (await response.json()) as T;
}

/* ------------------------------------------------------------------------- */
/* Authentication                                                            */
/* ------------------------------------------------------------------------- */

interface AuthPayload {
  token: string;
  user: UserResource;
}

export const authApi = {
  async register(payload: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Promise<AuthPayload> {
    const response = await request<{ data: UserResource; meta: { token: string } }>('/auth/register', {
      method: 'POST',
      body: { ...payload, device_name: 'web' },
    });

    return { token: response.meta.token, user: response.data };
  },

  async login(payload: { email: string; password: string }): Promise<AuthPayload> {
    const response = await request<{ data: UserResource; meta: { token: string } }>('/auth/login', {
      method: 'POST',
      body: { ...payload, device_name: 'web' },
    });

    return { token: response.meta.token, user: response.data };
  },

  async me(): Promise<UserResource> {
    const response = await request<{ data: UserResource }>('/auth/me');
    return response.data;
  },

  async logout(): Promise<void> {
    await request<void>('/auth/logout', { method: 'POST' });
  },
};

/* ------------------------------------------------------------------------- */
/* Documents                                                                 */
/* ------------------------------------------------------------------------- */

export interface DocumentListParams {
  role?: 'all' | 'owner' | 'signer';
  status?: 'open' | 'sealed' | '';
  search?: string;
  page?: number;
}

function documentQuery(params: DocumentListParams): string {
  const search = new URLSearchParams();
  search.set('page.size', '50');

  if (params.role && params.role !== 'all') {
    search.set('filter[role]', params.role);
  }

  if (params.status) {
    search.set('filter[status]', params.status);
  }

  if (params.search?.trim()) {
    search.set('filter[search]', params.search.trim());
  }

  if (params.page) {
    search.set('page.number', String(params.page));
  }

  return search.toString();
}

export interface DocumentDetailPayload {
  data: DocumentResource;
  included?: Array<{ type: string; id: string; attributes: Record<string, unknown> }>;
}

export interface SignResponseMeta {
  document_status: 'open' | 'sealed';
  sealed: boolean;
  signatures_required: number;
  signature_count: number;
  remaining_signatures: number;
  current_sha256: string;
}

export const documentsApi = {
  list(params: DocumentListParams = {}): Promise<Collection<DocumentResource>> {
    return request<Collection<DocumentResource>>(`/documents?${documentQuery(params)}`);
  },

  get(uuid: string): Promise<DocumentDetailPayload> {
    return request<DocumentDetailPayload>(
      `/documents/${uuid}?include=owner,signers,signatures,deletion_requests`,
    );
  },

  create(form: FormData): Promise<DocumentDetailPayload> {
    return request<DocumentDetailPayload>('/documents', { method: 'POST', body: form });
  },

  async remove(uuid: string): Promise<void> {
    await request<void>(`/documents/${uuid}`, { method: 'DELETE' });
  },

  async file(uuid: string, disposition: 'inline' | 'attachment'): Promise<Blob> {
    const response = await rawRequest(`/documents/${uuid}/file?disposition=${disposition}`);
    return response.blob();
  },

  verification(uuid: string): Promise<{ data: { id: string; attributes: VerificationResult } }> {
    return request<{ data: { id: string; attributes: VerificationResult } }>(
      `/documents/${uuid}/verification`,
    );
  },

  events(uuid: string): Promise<Collection<EventResource>> {
    return request<Collection<EventResource>>(`/documents/${uuid}/events?page.size=100`);
  },

  signatures(uuid: string): Promise<Collection<SignatureResource>> {
    return request<Collection<SignatureResource>>(`/documents/${uuid}/signatures?page.size=100`);
  },

  sign(uuid: string, form: FormData): Promise<{ data: SignatureResource; meta: SignResponseMeta }> {
    return request<{ data: SignatureResource; meta: SignResponseMeta }>(`/documents/${uuid}/signatures`, {
      method: 'POST',
      body: form,
    });
  },

  requestDeletion(
    uuid: string,
    reason: string | null,
  ): Promise<{ data: DeletionRequestResource; included?: ConsentResource[] }> {
    return request<{ data: DeletionRequestResource; included?: ConsentResource[] }>(
      `/documents/${uuid}/deletion-requests`,
      { method: 'POST', body: { reason } },
    );
  },

  consent(
    uuid: string,
    requestId: string,
    decision: 'approved' | 'rejected',
    comment: string | null,
  ): Promise<{
    data: DeletionRequestResource;
    included?: ConsentResource[];
    meta: { document_status: string; document_deleted: boolean };
  }> {
    return request<{
      data: DeletionRequestResource;
      included?: ConsentResource[];
      meta: { document_status: string; document_deleted: boolean };
    }>(`/documents/${uuid}/deletion-requests/${requestId}/consents`, {
      method: 'POST',
      body: { decision, comment },
    });
  },
};

/* ------------------------------------------------------------------------- */
/* Anonymous signing                                                         */
/* ------------------------------------------------------------------------- */

export const anonymousApi = {
  async inspectCertificate(form: FormData): Promise<CertificateData> {
    const response = await request<{ data: { attributes: CertificateData } }>('/certificates/inspect', {
      method: 'POST',
      body: form,
    });

    return response.data.attributes;
  },

  sign(form: FormData): Promise<Response> {
    return rawRequest('/signatures', { method: 'POST', body: form });
  },
};

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

export function extractFilename(response: Response, fallback = 'documento-firmado.pdf'): string {
  const header = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";]+)"?/i.exec(header);

  return match?.[1] ?? fallback;
}

export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 4000);
}

export function printBlob(blob: Blob): void {
  const url = URL.createObjectURL(blob);
  const opened = window.open(url, '_blank', 'noopener,noreferrer');
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);

  if (!opened) {
    // Popup blocked: fall back to a download so the user can print locally.
    saveBlob(blob, 'documento.pdf');
  }
}
