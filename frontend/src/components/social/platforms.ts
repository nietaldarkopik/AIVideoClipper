import type { SocialPlatform } from "@/lib/types";

export const PLATFORM_LABELS: Record<string, string> = {
  tiktok: "TikTok",
  youtube: "YouTube",
  instagram: "Instagram",
  facebook: "Facebook",
  twitter: "X",
  linkedin: "LinkedIn",
};

export const PLATFORM_COLORS: Record<string, string> = {
  tiktok: "#ff0050",
  youtube: "#ff0000",
  instagram: "#e1306c",
  facebook: "#1877f2",
  twitter: "#000000",
  linkedin: "#0a66c2",
};

export const PLATFORMS: SocialPlatform[] = [
  "tiktok",
  "youtube",
  "instagram",
  "facebook",
  "twitter",
  "linkedin",
];

// Platforms with a real OAuth integration wired up on the backend — connecting
// (or reconnecting) these redirects the browser to the platform's own consent
// screen instead of showing a form. Add a platform here once its SocialProvider
// stops extending AbstractMockSocialProvider.
export const REAL_OAUTH_PLATFORMS = new Set(["youtube", "facebook"]);

// Platforms that connect with a real username/password instead of OAuth (browser
// automation on the backend — see tools/instagram-automation). No redirect: the
// existing mock "connect" endpoint is reused, just with different payload fields.
export const CREDENTIAL_PLATFORMS = new Set(["instagram"]);
