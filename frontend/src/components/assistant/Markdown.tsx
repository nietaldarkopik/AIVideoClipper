import { Fragment, ReactNode } from "react";

/**
 * Small markdown renderer for assistant replies. It builds React elements directly and
 * never injects HTML, so whatever the model (or an attached file it quoted) emits can
 * only ever render as text — there is no markup to escape or to get wrong.
 *
 * Supported: paragraphs, #/##/### headings, **bold**, *italic*, `code`, fenced code
 * blocks, flat bullet/numbered lists, http(s) links and GitHub-style tables.
 */

const INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+?\*\*)|(\*[^*\s][^*\n]*?\*)|(\[[^\]\n]+\]\(https?:\/\/[^)\s]+\))/;

function renderInline(text: string, keyBase: string): ReactNode[] {
  const out: ReactNode[] = [];
  let rest = text;
  let i = 0;

  while (rest.length > 0) {
    const m = INLINE.exec(rest);
    if (!m) {
      out.push(rest);
      break;
    }
    if (m.index > 0) out.push(rest.slice(0, m.index));

    const token = m[0];
    const key = `${keyBase}-${i++}`;

    if (m[1]) {
      out.push(
        <code key={key} className="rounded bg-white/10 px-1 py-0.5 font-mono text-[0.85em]">
          {token.slice(1, -1)}
        </code>
      );
    } else if (m[2]) {
      out.push(<strong key={key}>{renderInline(token.slice(2, -2), key)}</strong>);
    } else if (m[3]) {
      out.push(<em key={key}>{renderInline(token.slice(1, -1), key)}</em>);
    } else {
      const link = /^\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)$/.exec(token);
      out.push(
        link ? (
          <a key={key} href={link[2]} target="_blank" rel="noopener noreferrer" className="text-accent-2 underline underline-offset-2 hover:brightness-125">
            {link[1]}
          </a>
        ) : (
          token
        )
      );
    }

    rest = rest.slice(m.index + token.length);
  }

  return out;
}

const isTableSeparator = (line: string) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line);
const splitRow = (line: string) =>
  line
    .trim()
    .replace(/^\|/, "")
    .replace(/\|$/, "")
    .split("|")
    .map((c) => c.trim());
const isBlockStart = (line: string) =>
  /^\s*(```|#{1,3}\s|[-*+]\s|\d+[.)]\s)/.test(line) || (line.includes("|") && line.trim().startsWith("|"));

export function Markdown({ text }: { text: string }) {
  const lines = text.replace(/\r\n/g, "\n").split("\n");
  const blocks: ReactNode[] = [];
  let i = 0;
  let n = 0;

  while (i < lines.length) {
    const line = lines[i];
    const key = `b${n++}`;

    if (line.trim() === "") {
      i++;
      continue;
    }

    // Fenced code block (an unclosed fence runs to the end, as in most renderers).
    if (/^\s*```/.test(line)) {
      const code: string[] = [];
      i++;
      while (i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i++]);
      i++;
      blocks.push(
        <pre key={key} className="my-2 overflow-x-auto rounded-lg bg-black/40 p-3 text-xs leading-relaxed">
          <code className="font-mono">{code.join("\n")}</code>
        </pre>
      );
      continue;
    }

    const heading = /^(#{1,3})\s+(.*)$/.exec(line);
    if (heading) {
      const size = ["text-base", "text-[0.95rem]", "text-sm"][heading[1].length - 1];
      blocks.push(
        <p key={key} className={`mb-1 mt-3 font-semibold ${size}`}>
          {renderInline(heading[2], key)}
        </p>
      );
      i++;
      continue;
    }

    // Table: header row, separator row, then body rows.
    if (line.includes("|") && i + 1 < lines.length && isTableSeparator(lines[i + 1])) {
      const head = splitRow(line);
      i += 2;
      const rows: string[][] = [];
      while (i < lines.length && lines[i].includes("|") && lines[i].trim() !== "") rows.push(splitRow(lines[i++]));
      blocks.push(
        <div key={key} className="my-2 overflow-x-auto rounded-lg border border-border-subtle">
          <table className="w-full min-w-max border-collapse text-xs">
            <thead className="bg-white/5">
              <tr>
                {head.map((h, c) => (
                  <th key={c} className="px-3 py-2 text-left font-semibold">
                    {renderInline(h, `${key}h${c}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, ri) => (
                <tr key={ri} className="border-t border-border-subtle">
                  {r.map((cell, c) => (
                    <td key={c} className="px-3 py-1.5 align-top">
                      {renderInline(cell, `${key}r${ri}c${c}`)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
      continue;
    }

    const bullet = /^\s*[-*+]\s+/;
    const numbered = /^\s*\d+[.)]\s+/;
    if (bullet.test(line) || numbered.test(line)) {
      const ordered = numbered.test(line);
      const pattern = ordered ? numbered : bullet;
      const items: string[] = [];
      while (i < lines.length && pattern.test(lines[i])) items.push(lines[i++].replace(pattern, ""));
      const Tag = ordered ? "ol" : "ul";
      blocks.push(
        <Tag key={key} className={`my-1.5 space-y-0.5 pl-5 ${ordered ? "list-decimal" : "list-disc"}`}>
          {items.map((it, ii) => (
            <li key={ii}>{renderInline(it, `${key}i${ii}`)}</li>
          ))}
        </Tag>
      );
      continue;
    }

    // Paragraph: consecutive plain lines, single newlines kept as line breaks.
    const para: string[] = [];
    while (i < lines.length && lines[i].trim() !== "" && (para.length === 0 || !isBlockStart(lines[i]))) para.push(lines[i++]);
    blocks.push(
      <p key={key} className="my-1.5">
        {para.map((p, pi) => (
          <Fragment key={pi}>
            {pi > 0 && <br />}
            {renderInline(p, `${key}p${pi}`)}
          </Fragment>
        ))}
      </p>
    );
  }

  return <div className="break-words text-sm leading-relaxed [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">{blocks}</div>;
}
