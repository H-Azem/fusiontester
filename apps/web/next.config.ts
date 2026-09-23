import type { NextConfig } from "next";

const API_URL = process.env.API_URL ?? "http://127.0.0.1:4000";

const nextConfig: NextConfig = {
  /**
   * Proxy the API through the dashboard's own origin. This keeps the session
   * cookie first-party, so SameSite=Lax works over plain HTTP in development —
   * a cross-origin cookie would need SameSite=None; Secure and therefore HTTPS.
   */
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${API_URL}/:path*`,
      },
    ];
  },
};

export default nextConfig;
