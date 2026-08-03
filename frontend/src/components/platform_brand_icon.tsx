import clsx from "clsx";
import type { ReactNode } from "react";

import bilibiliLogo from "@/assets/platforms/bilibili.jpeg";
import cloud189Logo from "@/assets/platforms/cloud189.jpeg";
import iqiyiLogo from "@/assets/platforms/iqiyi.jpeg";
import picacomicLogo from "@/assets/platforms/picacomic.jpeg";
import sportLogo from "@/assets/platforms/sport.jpeg";
import tiebaLogo from "@/assets/platforms/tieba.jpeg";

export const platformLogoByCode: Partial<Record<string, string>> = {
  tieba: tiebaLogo,
  bilibili: bilibiliLogo,
  iqiyi: iqiyiLogo,
  sport: sportLogo,
  cloud189: cloud189Logo,
  picacomic: picacomicLogo,
};

export default function PlatformBrandIcon({
  code,
  name,
  avatarUrl,
  fallback,
  className,
  fallbackClassName,
}: {
  code: string;
  name?: string;
  avatarUrl?: string | null;
  fallback?: ReactNode;
  className?: string;
  fallbackClassName?: string;
}) {
  const accountAvatar = typeof avatarUrl === "string" ? avatarUrl.trim() : "";
  const source = accountAvatar || platformLogoByCode[code];
  const usingAccountAvatar = accountAvatar !== "";

  return (
    <span
      className={clsx(
        "inline-flex shrink-0 items-center justify-center overflow-hidden bg-white",
        className,
      )}
    >
      {source ? (
        <img
          src={source}
          alt={usingAccountAvatar ? `${name || code} 头像` : `${name || code} Logo`}
          className="h-full w-full object-cover"
          draggable={false}
          referrerPolicy="no-referrer"
          onError={(event) => {
            if (!usingAccountAvatar) return;
            const image = event.currentTarget;
            const platformLogo = platformLogoByCode[code];
            if (platformLogo && image.src !== platformLogo) {
              image.src = platformLogo;
              image.alt = `${name || code} Logo`;
            }
          }}
        />
      ) : (
        <span
          className={clsx(
            "flex h-full w-full items-center justify-center",
            fallbackClassName,
          )}
        >
          {fallback || "账"}
        </span>
      )}
    </span>
  );
}
