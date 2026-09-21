"use client";

/**
 * Last-resort boundary for errors thrown by the ROOT layout itself, which
 * error.tsx sits inside of and therefore can't catch. It replaces the whole
 * document, so it has to render its own <html>/<body> — and for the same reason
 * it uses inline styles rather than the app's Tailwind classes and components,
 * none of which are guaranteed to have loaded at this point.
 */
export default function GlobalError({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return (
    <html lang="en">
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          gap: "12px",
          background: "#0a0a0d",
          color: "#f4f4f5",
          fontFamily: "system-ui, sans-serif",
          textAlign: "center",
          padding: "24px",
        }}
      >
        <h1 style={{ fontSize: "18px", fontWeight: 600, margin: 0 }}>The app failed to start</h1>
        <p style={{ fontSize: "14px", color: "#9a9aa3", margin: 0, maxWidth: "28rem" }}>
          Reload the page. If it keeps happening, check that the API server is running.
        </p>
        {error.digest && (
          <p style={{ fontSize: "11px", color: "#9a9aa3", fontFamily: "monospace", margin: 0 }}>
            Reference: {error.digest}
          </p>
        )}
        <button
          onClick={() => retry()}
          style={{
            marginTop: "8px",
            padding: "8px 16px",
            borderRadius: "12px",
            border: "none",
            background: "#7c5cff",
            color: "#fff",
            fontSize: "14px",
            fontWeight: 500,
            cursor: "pointer",
          }}
        >
          Try again
        </button>
      </body>
    </html>
  );
}
