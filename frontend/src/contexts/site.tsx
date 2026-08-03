import {
  createContext,
  type PropsWithChildren,
  useContext,
  useEffect,
  useState,
} from "react";

import { apiRequest } from "@/api/client";
import type { SiteSettings } from "@/types/sign";

const defaultSite: SiteSettings = {
  name: "天方云签",
  title: "天方云签",
  description: "轻量化多平台自动签到中心",
  base_url: "",
  logo_url: "",
  home_background_url: "",
  home_background_type: "auto",
  contact_email: "",
  icp_number: "",
  announcement: "",
  home_announcement: "",
  dashboard_announcement: "",
  maintenance_mode: false,
  registration_enabled: false,
  login_challenge_enabled: true,
  registration_challenge_enabled: true,
  registration_email_verification: false,
  default_quota: 2,
  qq_login_enabled: false,
  assistant_enabled: false,
  assistant_name: "天方助手",
};
const SiteContext = createContext<SiteSettings>(defaultSite);

export function SiteProvider({ children }: PropsWithChildren) {
  const [site, setSite] = useState(defaultSite);
  useEffect(() => {
    apiRequest<SiteSettings>({ url: "/site" })
      .then((value) => {
        setSite({ ...defaultSite, ...value });
        document.title = value.title || value.name || defaultSite.title;
      })
      .catch(() => undefined);
  }, []);
  return <SiteContext.Provider value={site}>{children}</SiteContext.Provider>;
}

export const useSite = () => useContext(SiteContext);
