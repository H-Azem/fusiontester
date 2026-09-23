import { NextResponse, type NextRequest } from "next/server";

const API_URL = process.env.API_URL ?? "http://127.0.0.1:4000";
const SESSION_COOKIE = "ft_session";

export async function middleware(request: NextRequest) {
  const session = request.cookies.get(SESSION_COOKIE);

  if (!session) {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  // Validate against the API rather than trusting cookie presence, so a
  // revoked or expired session cannot reach a protected page.
  const response = await fetch(`${API_URL}/auth/me`, {
    headers: { cookie: `${SESSION_COOKIE}=${session.value}` },
  });

  if (!response.ok) {
    const redirect = NextResponse.redirect(new URL("/login", request.url));
    redirect.cookies.delete(SESSION_COOKIE);
    return redirect;
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!api|login|_next/static|_next/image|favicon.ico).*)"],
};
