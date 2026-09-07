import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Terms of Service — ExistCode",
  description: "The terms that govern your use of ExistCode.",
};

const LAST_UPDATED = "September 5, 2026";

export default function TermsOfServicePage() {
  return (
    <div className="mx-auto max-w-3xl px-6 py-16">
      <h1 className="text-2xl font-semibold text-foreground">Terms of Service</h1>
      <p className="mt-2 text-sm text-muted">Last updated: {LAST_UPDATED}</p>

      <div className="mt-10 space-y-10 text-sm leading-relaxed text-foreground">
        <section>
          <p>
            These Terms of Service (&quot;Terms&quot;) govern your access to and use of
            ExistCode (&quot;the Service&quot;), a web application for creating short-form
            video clips and publishing them to social platforms. By using the Service,
            you agree to these Terms.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">1. Your account</h2>
          <p className="text-muted">
            You must provide accurate information to register and are responsible for
            safeguarding your login credentials and for all activity under your
            account.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">2. Connected social accounts</h2>
          <p className="text-muted">
            The Service lets you connect third-party social accounts (TikTok, YouTube,
            Facebook, Instagram) via each platform&apos;s official authorization flow. You
            control what gets published: titles, captions, hashtags, privacy level, and
            timing are set by you before any post goes out. We only publish content
            you explicitly submit through the Service, and only to accounts you have
            connected and authorized. You can disconnect any account at any time,
            which immediately stops us from publishing to it.
          </p>
          <p className="mt-3 text-muted">
            You are solely responsible for the content you upload and publish, and for
            complying with each connected platform&apos;s own terms of service and
            community guidelines (including TikTok&apos;s).
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">3. Acceptable use</h2>
          <ul className="mt-3 list-disc space-y-2 pl-5 text-muted">
            <li>You will not upload or publish content that is unlawful, infringing, or that you do not have the rights to use.</li>
            <li>You will not use the Service to spam, harass, or mislead others.</li>
            <li>You will not attempt to circumvent the Service&apos;s security, rate limits, or access controls.</li>
          </ul>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">4. Content ownership</h2>
          <p className="text-muted">
            You retain all ownership rights to the videos and content you upload. By
            using the Service, you grant us a limited license to store, process,
            render, and transmit that content solely to provide the features you
            request (e.g. clipping, rendering, and publishing to accounts you connect).
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">5. Service availability</h2>
          <p className="text-muted">
            The Service is provided &quot;as is&quot; and &quot;as available&quot;. Publishing to
            third-party platforms depends on those platforms&apos; own APIs and policies
            (for example, TikTok restricts posts from unaudited developer apps to
            private visibility), which are outside our control.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">6. Termination</h2>
          <p className="text-muted">
            You may stop using the Service and delete your account at any time. We may
            suspend or terminate access for accounts that violate these Terms.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">7. Limitation of liability</h2>
          <p className="text-muted">
            To the maximum extent permitted by law, ExistCode is not liable for
            indirect, incidental, or consequential damages arising from your use of
            the Service, including actions taken by third-party platforms on content
            you publish through it.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">8. Changes to these Terms</h2>
          <p className="text-muted">
            We may update these Terms from time to time. Continued use of the Service
            after changes take effect constitutes acceptance of the updated Terms.
          </p>
        </section>

        <section>
          <h2 className="text-base font-semibold text-foreground">9. Contact us</h2>
          <p className="text-muted">
            Questions about these Terms? Contact us at{" "}
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
