import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';

import { GLOBAL_LOADING_EVENT, getActiveRequestCount } from '@/api/client';

const ROUTE_PULSE_MS = 420;

interface LoadingEventDetail {
  active?: number;
}

export function usePageLoading () {
  const location = useLocation();
  const [activeRequests, setActiveRequests] = useState(getActiveRequestCount);
  const [routeLoading, setRouteLoading] = useState(true);

  useEffect(() => {
    const onLoadingChange = (event: Event) => {
      const detail = (event as CustomEvent<LoadingEventDetail>).detail;
      setActiveRequests(Math.max(0, Number(detail?.active || 0)));
    };
    window.addEventListener(GLOBAL_LOADING_EVENT, onLoadingChange);
    return () => window.removeEventListener(GLOBAL_LOADING_EVENT, onLoadingChange);
  }, []);

  useEffect(() => {
    setRouteLoading(true);
    const timer = window.setTimeout(() => setRouteLoading(false), ROUTE_PULSE_MS);
    return () => window.clearTimeout(timer);
  }, [location.pathname, location.search]);

  return routeLoading || activeRequests > 0;
}
