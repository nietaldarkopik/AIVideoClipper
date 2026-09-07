import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Privacy Policy — ExistCode",
  description: "How ExistCode collects, uses, and protects your data.",
};

const LAST_UPDATED = "September 5, 2026";

export default function PrivacyPolicyPage() {
  return (
    <div className="mx-auto max-w-3xl px-6 py-16">
      <h1 className="text-2xl font-semibold text-foreground">Privacy Policy</h1>
      <p className="mt-2 text-sm text-muted">Last updated: {LAST_UPDATED}</p>

      <div className="mt-10 space-y-10 text-sm leading-relaxed text-foreground">
        <section>
          <p>
            ExistCode (&quot;we&quot;, &quot;our&quot;, &quot;us&quot;) provides a web application that helps
            creators turn long-form videos into short, social-ready clips and publish
            them to third-party platforms such as TikTok, YouTube, Facebook, and
            Instagram. This Privacy Policy explains what information we collect, how we
            use it, and the choices you have.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">1. Information we collect</h2>
          <ul className="mt-3 list-disc space-y-2 pl-5 text-muted">
            <li>
              <span className="text-foreground">Account information</span> — your name,
              email address, and password (stored hashed) when you register.
            </li>
            <li>
              <span className="text-foreground">Content you upload</span> — source
              videos, rendered clips, captions, titles, and hashtags you create or
              import within the app.
            </li>
            <li>
              <span className="text-foreground">Connected social account data</span> —
              when you connect a TikTok, YouTube, Facebook, or Instagram account, we
              store the OAuth access/refresh tokens, your public profile fields
              (display name, username, avatar), and publishing permissions you grant.
              We use these solely to publish content and fetch performance metrics on
              your behalf.
            </li>
            <li>
              <span className="text-foreground">Usage data</span> — basic technical
              logs (timestamps, request metadata) used for debugging and reliability,
              and post performance metrics (views, likes, comments, shares) fetched
              from the platforms you connect.
            </li>
          </ul>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">2. How we use your information</h2>
          <ul className="mt-3 list-disc space-y-2 pl-5 text-muted">
            <li>To operate the core features of the app: importing, editing, rendering, and scheduling video clips.</li>
            <li>To publish clips to the social accounts you explicitly connect and authorize, using the exact title, caption, and privacy settings you configure.</li>
            <li>To retrieve post-performance metrics from platforms you connect, so we can display analytics back to you.</li>
            <li>To send you account-related notifications (e.g. render or publish status).</li>
            <li>To maintain, secure, and improve the service.</li>
          </ul>
          <p className="mt-3 text-muted">
            We do not sell your personal information or your connected accounts&apos;
            data to third parties, and we do not use your TikTok content or data to
            train generative AI models.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">3. Third-party platforms</h2>
          <p className="text-muted">
            When you connect a social account (e.g. via TikTok&apos;s Login Kit), you
            authorize us to act on your behalf within the scopes you approve — for
            example, publishing a video or reading basic profile and post metrics. You
            can revoke this access at any time from within ExistCode&apos;s Settings page,
            or directly from the third-party platform&apos;s own app-permissions settings.
            Revoking access deletes the stored access/refresh tokens for that account.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">4. Data retention</h2>
          <p className="text-muted">
            We retain your account data and content for as long as your account is
            active. You may request deletion of your account and associated data at
            any time by contacting us (see below); disconnecting a social account
            removes its stored tokens immediately.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">5. Data security</h2>
          <p className="text-muted">
            We use industry-standard measures (encrypted transport, access controls,
            hashed passwords) to protect your information. No method of transmission
            or storage is 100% secure, and we cannot guarantee absolute security.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">6. Your rights</h2>
          <p className="text-muted">
            You may access, correct, export, or delete your data, and disconnect any
            connected social account, at any time from within the app or by
            contacting us directly.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">7. Changes to this policy</h2>
          <p className="text-muted">
            We may update this Privacy Policy from time to time. Material changes will
            be reflected by updating the &quot;Last updated&quot; date above.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">8. Contact us</h2>
          <p className="text-muted">
            Questions about this policy or your data? Contact us at{" "}
            <a href="mailto:nietaldarkopik@gmail.com" className="text-accent hover:underline">
              nietaldarkopik@gmail.com
            </a>
            .
          </p>
        </section>
      </div>
    </div>
  );
}
