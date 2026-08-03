import axios, { type AxiosRequestConfig } from 'axios';

import type { ApiEnvelope } from '@/types/sign';

export const AUTH_TOKEN_KEY = 'tf-sign-access-token';
export const AUTH_EXPIRED_EVENT = 'tf-sign-auth-expired';

export class ApiError extends Error {
  constructor (
    message: string,
    public readonly code = 'REQUEST_FAILED',
    public readonly status = 0,
    public readonly requestId = ''
  ) {
    super(message);
  }
}

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  timeout: 30000,
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/+$/, '');

function redirectToInstaller (code?: string) {
  if (code === 'INSTALL_REQUIRED' && window.location.pathname !== '/install') {
    window.location.replace('/install');
  }
}

client.interceptors.request.use((config) => {
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = Number(error.response?.status || 0);
    const envelope = error.response?.data as Partial<ApiEnvelope<unknown>> | undefined;
    redirectToInstaller(envelope?.code);
    if (status === 401 && localStorage.getItem(AUTH_TOKEN_KEY)) {
      localStorage.removeItem(AUTH_TOKEN_KEY);
      window.dispatchEvent(new Event(AUTH_EXPIRED_EVENT));
    }
    return Promise.reject(new ApiError(
      envelope?.message || error.message || '请求失败',
      envelope?.code || 'REQUEST_FAILED',
      status,
      envelope?.request_id || ''
    ));
  }
);

export async function apiRequest<T> (config: AxiosRequestConfig): Promise<T> {
  const response = await client.request<ApiEnvelope<T>>(config);
  if (response.data.code !== 'SUCCESS') {
    redirectToInstaller(response.data.code);
    throw new ApiError(response.data.message, response.data.code, response.status, response.data.request_id);
  }
  return response.data.data;
}

export async function apiStream<T> (
  path: string,
  data: unknown,
  onEvent: (event: T) => void,
  signal?: AbortSignal
): Promise<void> {
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  const response = await fetch(`${apiBaseUrl}/${path.replace(/^\/+/, '')}`, {
    method: 'POST',
    credentials: 'include',
    signal,
    headers: {
      Accept: 'application/x-ndjson',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify(data),
  });

  if (!response.ok) {
    const envelope = await response.json().catch(() => null) as Partial<ApiEnvelope<unknown>> | null;
    redirectToInstaller(envelope?.code);
    if (response.status === 401 && token) {
      localStorage.removeItem(AUTH_TOKEN_KEY);
      window.dispatchEvent(new Event(AUTH_EXPIRED_EVENT));
    }
    throw new ApiError(
      envelope?.message || `请求失败（HTTP ${response.status}）`,
      envelope?.code || 'REQUEST_FAILED',
      response.status,
      envelope?.request_id || ''
    );
  }
  if (!response.body) {
    throw new ApiError('浏览器未收到流式响应', 'STREAM_UNAVAILABLE', response.status);
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  const consumeLine = (line: string) => {
    const value = line.trim();
    if (!value) return;
    try {
      onEvent(JSON.parse(value) as T);
    } catch {
      throw new ApiError('智能助手返回了无法识别的流式数据', 'STREAM_INVALID_RESPONSE', response.status);
    }
  };

  while (true) {
    const { done, value } = await reader.read();
    buffer += decoder.decode(value, { stream: !done });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';
    lines.forEach(consumeLine);
    if (done) break;
  }
  consumeLine(buffer);
}

export default client;
