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
