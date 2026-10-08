import type { Metadata, Viewport } from "next";
import { Roboto, Roboto_Mono } from "next/font/google";
import "./globals.css";

/**
 * Roboto is the typeface Flutter renders with, so the dashboard reads as part of
 * the same family as the apps it tests. Roboto Mono carries ids, branches and
 * command output.
 *
 * Weights are limited to the four the M3 scale actually uses, because loading
 * more costs every visitor bytes they never see.
 */
const sans = Roboto({
  subsets: ["latin"],
  weight: ["400", "500"],
  variable: "--font-roboto",
  display: "swap",
});

const mono = Roboto_Mono({
  subsets: ["latin"],
  weight: ["400", "500"],
  variable: "--font-roboto-mono",
  display: "swap",
});

export const metadata: Metadata = {
  title: "Fusion Tester",
  description: "Maestro flow orchestration for Flutter apps",
  applicationName: "Fusion Tester",
};

/** Paints the notch area so the mobile navigation bar can sit in the safe zone. */
export const viewport: Viewport = {
  themeColor: "#0b1220",
  colorScheme: "dark",
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className={`${sans.variable} ${mono.variable}`}>
      <body>{children}</body>
    </html>
  );
}
