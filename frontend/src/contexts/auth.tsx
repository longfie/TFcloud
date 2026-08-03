import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { AUTH_EXPIRED_EVENT, AUTH_TOKEN_KEY, apiRequest } from '@/api/client';
import type { LoginResult, QqExchangeResult, User } from '@/types/sign';

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  isAuthenticated: boolean;
  login: (username: string, password: string, challengeId?: string) => Promise<User>;
  loginWithEmailCode: (email: string, emailCode: string) => Promise<User>;
  registerAccount: (username: string, email: string, password: string, challengeId?: string, emailCode?: string) => Promise<User>;
  loginWithQqExchange: (exchangeCode: string) => Promise<QqExchangeResult>;
  completeQqRegistration: (onboardingToken: string, username: string, email: string, emailCode?: string) => Promise<User>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<User | null>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider ({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshUser = useCallback(async () => {
    if (!localStorage.getItem(AUTH_TOKEN_KEY)) {
      setUser(null);
      setLoading(false);
      return null;
    }
    try {
      const current = await apiRequest<User>({ url: '/me' });
      setUser(current);
      return current;
    } catch {
      localStorage.removeItem(AUTH_TOKEN_KEY);
      setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refreshUser();
    const expire = () => {
      setUser(null);
      setLoading(false);
    };
    window.addEventListener(AUTH_EXPIRED_EVENT, expire);
    return () => window.removeEventListener(AUTH_EXPIRED_EVENT, expire);
  }, [refreshUser]);

  const login = useCallback(async (username: string, password: string, challengeId?: string) => {
    const result = await apiRequest<LoginResult>({
      method: 'POST',
      url: '/auth/login',
      data: { username, password, challenge_id: challengeId || '' },
    });
    localStorage.setItem(AUTH_TOKEN_KEY, result.access_token);
    setUser(result.user);
    return result.user;
  }, []);

  const loginWithEmailCode = useCallback(async (email: string, emailCode: string) => {
    const result = await apiRequest<LoginResult>({
      method: 'POST',
      url: '/auth/login/email',
      data: { email, email_code: emailCode },
    });
    localStorage.setItem(AUTH_TOKEN_KEY, result.access_token);
    setUser(result.user);
    return result.user;
  }, []);

  const completeQqRegistration = useCallback(async (onboardingToken: string, username: string, email: string, emailCode?: string) => {
    const result = await apiRequest<LoginResult>({
      method: 'POST',
      url: '/auth/qq/register',
      data: { onboarding_token: onboardingToken, username, email, email_code: emailCode || '' },
    });
    localStorage.setItem(AUTH_TOKEN_KEY, result.access_token);
    setUser(result.user);
    return result.user;
  }, []);

  const registerAccount = useCallback(async (username: string, email: string, password: string, challengeId?: string, emailCode?: string) => {
    const result = await apiRequest<LoginResult>({
      method: 'POST',
      url: '/auth/register',
      data: { username, email, password, challenge_id: challengeId || '', email_code: emailCode || '' },
    });
    localStorage.setItem(AUTH_TOKEN_KEY, result.access_token);
    setUser(result.user);
    return result.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiRequest<null>({ method: 'POST', url: '/auth/logout' });
    } finally {
      localStorage.removeItem(AUTH_TOKEN_KEY);
      setUser(null);
    }
  }, []);

  const loginWithQqExchange = useCallback(async (exchangeCode: string) => {
    const result = await apiRequest<QqExchangeResult>({
      method: 'POST',
      url: '/auth/qq/exchange',
      data: { exchange_code: exchangeCode },
    });
    if ('access_token' in result) {
      localStorage.setItem(AUTH_TOKEN_KEY, result.access_token);
      setUser(result.user);
    }
    return result;
  }, []);

  const value = useMemo<AuthContextValue>(() => ({
    user,
    loading,
    isAuthenticated: Boolean(user),
    login,
    loginWithEmailCode,
    registerAccount,
    loginWithQqExchange,
    completeQqRegistration,
    logout,
    refreshUser,
  }), [user, loading, login, loginWithEmailCode, registerAccount, loginWithQqExchange, completeQqRegistration, logout, refreshUser]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useSignAuth () {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useSignAuth must be used inside AuthProvider');
  return context;
}
